<?php

namespace App\Services;

use App\Enums\DealLogAction;
use App\Enums\DealPayment;
use App\Enums\DealStatus;
use App\Enums\TradeSide;
use App\Enums\UserRole;
use App\Enums\WorkDayStatus;
use App\Models\BankAccount;
use App\Models\CashierVault;
use App\Models\CashierVaultBalance;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\ExchangeDeal;
use App\Models\ExchangeDealLog;
use App\Models\MainVault;
use App\Models\MainVaultBalance;
use App\Models\User;
use App\Models\WorkDay;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExchangeTradeService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function trade(array $data, ?User $user): ExchangeDeal
    {
        $connection = (new ExchangeDeal)->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($data, $user): ExchangeDeal {
            if (! $user instanceof User || $user->role !== UserRole::Cashier) {
                throw ValidationException::withMessages([
                    'type' => 'البيع والشراء يتمان عن طريق الصراف فقط.',
                ]);
            }

            $workDay = WorkDay::query()->lockForUpdate()->where('status', WorkDayStatus::Open)->latest('id')->first();

            if (! $workDay instanceof WorkDay) {
                throw ValidationException::withMessages([
                    'type' => 'لا يمكن البيع أو الشراء قبل افتتاح العمل اليومي.',
                ]);
            }

            $cashierVault = CashierVault::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $cashierVault instanceof CashierVault || blank($cashierVault->branch_id)) {
                throw ValidationException::withMessages([
                    'type' => 'لا توجد عهدة نشطة لهذا الصراف.',
                ]);
            }

            $side = $data['type'] instanceof TradeSide
                ? $data['type']
                : TradeSide::tryFrom((string) ($data['type'] ?? ''));

            if (! $side instanceof TradeSide) {
                throw ValidationException::withMessages([
                    'type' => 'حدد بيعاً أو شراءً.',
                ]);
            }

            $rawPayment = $data['payment'] ?? DealPayment::Cash->value;
            $payment = $rawPayment instanceof DealPayment
                ? $rawPayment
                : DealPayment::tryFrom((string) $rawPayment);

            if (! $payment instanceof DealPayment) {
                throw ValidationException::withMessages([
                    'payment' => 'حدد كاش أو صك.',
                ]);
            }

            $currency = Currency::query()->find($data['currency_id'] ?? null);

            if (! $currency instanceof Currency || $currency->isLibyanDinar()) {
                throw ValidationException::withMessages([
                    'currency_id' => 'اختر عملة صعبة. الدينار الليبي هو مقابل العملية.',
                ]);
            }

            $customerId = (int) ($data['customer_id'] ?? 0);

            if ($customerId === 0 || ! Customer::query()->whereKey($customerId)->exists()) {
                throw ValidationException::withMessages([
                    'customer_id' => 'حدد الزبون.',
                ]);
            }

            $bankAccountId = self::bankAccountId($data, $side, $payment);

            $quantity = self::money($data['quantity'] ?? 0);
            $rate = self::rate($data['rate'] ?? 0);

            if ((float) $quantity <= 0 || (float) $rate <= 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'أدخل كمية وسعراً أكبر من صفر.',
                ]);
            }

            $lydAmount = self::money((float) $quantity * (float) $rate);

            $mainVault = MainVault::query()
                ->where('branch_id', $cashierVault->branch_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $mainVault instanceof MainVault) {
                throw ValidationException::withMessages([
                    'currency_id' => 'لا توجد خزينة رئيسية نشطة لفرع الصراف.',
                ]);
            }

            $lyd = Currency::query()->where('code', 'LYD')->first();

            if (! $lyd instanceof Currency) {
                throw ValidationException::withMessages([
                    'currency_id' => 'الدينار الليبي غير معرّف.',
                ]);
            }

            $mainBalance = MainVaultBalance::query()
                ->where('main_vault_id', $mainVault->id)
                ->where('currency_id', $currency->id)
                ->lockForUpdate()
                ->first();

            $costPrice = null;
            $profit = null;

            if ($side === TradeSide::Sell) {
                self::ensureCashierBalance($cashierVault, (int) $currency->id, $quantity, $currency->code);
                $costPrice = self::rate($mainBalance?->cost_price ?? 0);
                $profit = self::money(((float) $rate - (float) $costPrice) * (float) $quantity);

                self::changeCashierBalance($cashierVault, (int) $currency->id, $quantity, false);
                self::changeCashierBalance($cashierVault, (int) $lyd->id, $lydAmount, true);
            } else {
                self::ensureCashierBalance($cashierVault, (int) $lyd->id, $lydAmount, 'الدينار الليبي');
                self::recalculatePurchaseCost($mainVault, $cashierVault, $currency, $mainBalance, $quantity, $rate);
                self::changeCashierBalance($cashierVault, (int) $lyd->id, $lydAmount, false);
                self::changeCashierBalance($cashierVault, (int) $currency->id, $quantity, true);
            }

            return ExchangeDeal::query()->create([
                'work_day_id' => $workDay->id,
                'type' => $side,
                'payment' => $payment,
                'cashier_vault_id' => $cashierVault->id,
                'customer_id' => $customerId,
                'bank_account_id' => $bankAccountId,
                'currency_id' => $currency->id,
                'quantity' => $quantity,
                'rate' => $rate,
                'lyd_amount' => $lydAmount,
                'cost_price' => $costPrice,
                'profit' => $profit,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'notes' => $data['notes'] ?? null,
                'status' => DealStatus::Posted,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function updateSale(ExchangeDeal $deal, array $data, ?User $user): ExchangeDeal
    {
        $connection = (new ExchangeDeal)->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($deal, $data, $user): ExchangeDeal {
            $locked = self::lockedOpenSale($deal, $user);
            $before = self::snapshot($locked);
            [$currency, $customerId, $quantity, $rate, $payment, $lydAmount, $bankAccountId] = self::saleInput($data);
            $lyd = self::lydCurrency();
            $vault = self::actingVault($user);

            $sameMovement = (int) $locked->currency_id === (int) $currency->id
                && self::money($locked->quantity) === $quantity
                && self::money($locked->lyd_amount) === $lydAmount;

            if (! $sameMovement) {
                self::ensureCashierBalance($vault, (int) $lyd->id, self::money($locked->lyd_amount), 'الدينار الليبي');
                self::changeCashierBalance($vault, (int) $locked->currency_id, self::money($locked->quantity), true);
                self::changeCashierBalance($vault, (int) $lyd->id, self::money($locked->lyd_amount), false);
                self::ensureCashierBalance($vault, (int) $currency->id, $quantity, $currency->code);
                self::changeCashierBalance($vault, (int) $currency->id, $quantity, false);
                self::changeCashierBalance($vault, (int) $lyd->id, $lydAmount, true);
            }

            $costPrice = (int) $locked->currency_id === (int) $currency->id
                ? self::rate($locked->cost_price ?? 0)
                : self::rate(MainVaultBalance::query()
                    ->where('main_vault_id', MainVault::query()->where('branch_id', $vault->branch_id)->where('is_active', true)->value('id'))
                    ->where('currency_id', $currency->id)
                    ->value('cost_price') ?? 0);

            $locked->fill([
                'payment' => $payment,
                'customer_id' => $customerId,
                'bank_account_id' => $bankAccountId,
                'currency_id' => $currency->id,
                'quantity' => $quantity,
                'rate' => $rate,
                'lyd_amount' => $lydAmount,
                'cost_price' => $costPrice,
                'profit' => self::money(((float) $rate - (float) $costPrice) * (float) $quantity),
                'notes' => $data['notes'] ?? null,
            ]);
            $locked->save();
            $locked->refresh();
            self::writeLog($locked, DealLogAction::Edit, $user, $before, self::snapshot($locked));

            return $locked;
        });
    }

    public static function cancelSale(ExchangeDeal $deal, ?User $user): ExchangeDeal
    {
        $connection = (new ExchangeDeal)->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($deal, $user): ExchangeDeal {
            $locked = self::lockedOpenSale($deal, $user);
            $before = self::snapshot($locked);
            $lyd = self::lydCurrency();
            $vault = self::actingVault($user);

            self::ensureCashierBalance($vault, (int) $lyd->id, self::money($locked->lyd_amount), 'الدينار الليبي');
            self::changeCashierBalance($vault, (int) $locked->currency_id, self::money($locked->quantity), true);
            self::changeCashierBalance($vault, (int) $lyd->id, self::money($locked->lyd_amount), false);

            $locked->status = DealStatus::Cancelled;
            $locked->save();
            $locked->refresh();
            self::writeLog($locked, DealLogAction::Cancel, $user, $before, self::snapshot($locked));

            return $locked;
        });
    }

    private static function lockedOpenSale(ExchangeDeal $deal, ?User $user): ExchangeDeal
    {
        if (! $user instanceof User || $user->role !== UserRole::Cashier) {
            throw ValidationException::withMessages([
                'type' => 'التعديل والإلغاء للصراف صاحب عملية البيع فقط.',
            ]);
        }

        $locked = ExchangeDeal::query()->lockForUpdate()->find($deal->id);

        if (! $locked instanceof ExchangeDeal || $locked->type !== TradeSide::Sell || $locked->status === DealStatus::Cancelled) {
            throw ValidationException::withMessages([
                'type' => 'يمكن تعديل أو إلغاء عملية بيع قائمة فقط.',
            ]);
        }

        $workDay = WorkDay::query()->lockForUpdate()->where('status', WorkDayStatus::Open)->latest('id')->first();

        if (! $workDay instanceof WorkDay || (int) $workDay->id !== (int) $locked->work_day_id) {
            throw ValidationException::withMessages([
                'type' => 'التعديل والإلغاء لعمليات يوم العمل المفتوح فقط.',
            ]);
        }

        $vault = self::actingVault($user);

        if ((int) $vault->id !== (int) $locked->cashier_vault_id) {
            throw ValidationException::withMessages([
                'type' => 'هذه العملية لعهدة صراف آخر.',
            ]);
        }

        return $locked;
    }

    private static function actingVault(?User $user): CashierVault
    {
        $vault = CashierVault::query()
            ->where('user_id', $user?->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $vault instanceof CashierVault) {
            throw ValidationException::withMessages([
                'type' => 'لا توجد عهدة نشطة لهذا الصراف.',
            ]);
        }

        return $vault;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Currency, 1: int, 2: string, 3: string, 4: DealPayment, 5: string, 6: int|null}
     */
    private static function saleInput(array $data): array
    {
        $currency = Currency::query()->find($data['currency_id'] ?? null);

        if (! $currency instanceof Currency || $currency->isLibyanDinar()) {
            throw ValidationException::withMessages([
                'currency_id' => 'اختر عملة صعبة. الدينار الليبي هو مقابل العملية.',
            ]);
        }

        $customerId = (int) ($data['customer_id'] ?? 0);

        if ($customerId === 0 || ! Customer::query()->whereKey($customerId)->exists()) {
            throw ValidationException::withMessages([
                'customer_id' => 'حدد الزبون.',
            ]);
        }

        $quantity = self::money($data['quantity'] ?? 0);
        $rate = self::rate($data['rate'] ?? 0);

        if ((float) $quantity <= 0 || (float) $rate <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'أدخل كمية وسعراً أكبر من صفر.',
            ]);
        }

        $rawPayment = $data['payment'] ?? DealPayment::Cash->value;
        $payment = $rawPayment instanceof DealPayment
            ? $rawPayment
            : DealPayment::tryFrom((string) $rawPayment);

        if (! $payment instanceof DealPayment) {
            throw ValidationException::withMessages([
                'payment' => 'حدد كاش أو صك.',
            ]);
        }

        return [$currency, $customerId, $quantity, $rate, $payment, self::money((float) $quantity * (float) $rate), self::bankAccountId($data, TradeSide::Sell, $payment)];
    }

    private static function bankAccountId(array $data, TradeSide $side, DealPayment $payment): ?int
    {
        if ($side !== TradeSide::Sell || $payment !== DealPayment::Check) {
            return null;
        }

        $bankAccountId = (int) ($data['bank_account_id'] ?? 0);

        if ($bankAccountId === 0 || ! BankAccount::query()->whereKey($bankAccountId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'bank_account_id' => 'حدد الحساب المصرفي.',
            ]);
        }

        return $bankAccountId;
    }

    private static function lydCurrency(): Currency
    {
        $lyd = Currency::query()->where('code', 'LYD')->first();

        if (! $lyd instanceof Currency) {
            throw ValidationException::withMessages([
                'currency_id' => 'الدينار الليبي غير معرّف.',
            ]);
        }

        return $lyd;
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(ExchangeDeal $deal): array
    {
        $deal->load(['customer', 'currency', 'bankAccount']);

        return [
            'status' => $deal->status instanceof DealStatus ? $deal->status->value : (string) $deal->status,
            'type' => $deal->type instanceof TradeSide ? $deal->type->value : (string) $deal->type,
            'payment' => $deal->payment instanceof DealPayment ? $deal->payment->value : (string) $deal->payment,
            'customer_id' => $deal->customer_id,
            'customer_name' => $deal->customer?->name,
            'bank_account_id' => $deal->bank_account_id,
            'bank_account_name' => $deal->bankAccount?->label(),
            'currency_id' => $deal->currency_id,
            'currency_code' => $deal->currency?->code,
            'quantity' => (string) $deal->quantity,
            'rate' => (string) $deal->rate,
            'lyd_amount' => (string) $deal->lyd_amount,
            'cost_price' => (string) $deal->cost_price,
            'profit' => (string) $deal->profit,
            'notes' => $deal->notes,
            'work_day_id' => $deal->work_day_id,
            'cashier_vault_id' => $deal->cashier_vault_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>|null  $after
     */
    private static function writeLog(ExchangeDeal $deal, DealLogAction $action, ?User $user, array $before, ?array $after): void
    {
        ExchangeDealLog::query()->create([
            'exchange_deal_id' => $deal->id,
            'action' => $action,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'details' => self::logDetails($action, $before, $after),
            'before' => $before,
            'after' => $after,
        ]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>|null  $after
     */
    private static function logDetails(DealLogAction $action, array $before, ?array $after): string
    {
        $labels = [
            'status' => 'الحالة',
            'payment' => 'الدفع',
            'customer_name' => 'الزبون',
            'bank_account_name' => 'الحساب المصرفي',
            'currency_code' => 'العملة',
            'quantity' => 'الكمية',
            'rate' => 'السعر',
            'lyd_amount' => 'مقابل الدينار',
            'cost_price' => 'التكلفة',
            'profit' => 'الربح',
            'notes' => 'الملاحظات',
        ];

        $lines = $action === DealLogAction::Cancel
            ? ['أُلغيت عملية البيع، وأُعيدت العملة إلى العهدة، وخُصم مقابل الدينار.']
            : ['عُدّلت عملية البيع.'];

        foreach ($labels as $key => $label) {
            $from = self::logValue($key, $before[$key] ?? null);
            $to = self::logValue($key, $after[$key] ?? null);
            $lines[] = $label.': '.$from.($action === DealLogAction::Edit ? ' ← '.$to : '');
        }

        return implode("\n", $lines);
    }

    private static function logValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($key) {
            'status' => DealStatus::tryFrom((string) $value)?->label() ?? (string) $value,
            'payment' => DealPayment::tryFrom((string) $value)?->label() ?? (string) $value,
            default => (string) $value,
        };
    }

    private static function recalculatePurchaseCost(
        MainVault $mainVault,
        CashierVault $cashierVault,
        Currency $currency,
        ?MainVaultBalance $mainBalance,
        string $quantity,
        string $rate,
    ): void {
        $cashierVaultIds = CashierVault::query()
            ->where('branch_id', $cashierVault->branch_id)
            ->pluck('id');

        $custodyHeld = (float) CashierVaultBalance::query()
            ->whereIn('cashier_vault_id', $cashierVaultIds)
            ->where('currency_id', $currency->id)
            ->lockForUpdate()
            ->sum('current_balance');

        $vaultHeld = (float) self::money($mainBalance?->current_balance ?? 0);
        $held = $vaultHeld + $custodyHeld;
        $purchased = (float) $quantity;
        $oldCost = (float) self::rate($mainBalance?->cost_price ?? 0);
        $average = $held <= 0
            ? (float) $rate
            : (($held * $oldCost) + ($purchased * (float) $rate)) / ($held + $purchased);

        if (! $mainBalance instanceof MainVaultBalance) {
            $mainBalance = new MainVaultBalance;
            $mainBalance->main_vault_id = $mainVault->id;
            $mainBalance->currency_id = $currency->id;
            $mainBalance->opening_balance = 0;
            $mainBalance->save();
        }

        $mainBalance->recalculatingCostPrice = true;
        $mainBalance->cost_price = self::rate($average);
        $mainBalance->save();
    }

    private static function ensureCashierBalance(CashierVault $cashierVault, int $currencyId, string $amount, string $label): void
    {
        $available = self::money(CashierVaultBalance::query()
            ->where('cashier_vault_id', $cashierVault->id)
            ->where('currency_id', $currencyId)
            ->lockForUpdate()
            ->value('current_balance') ?? 0);

        if (self::exceeds($amount, $available)) {
            throw ValidationException::withMessages([
                'quantity' => 'رصيد العهدة من '.$label.' هو '.$available.' ولا يكفي لخصم '.$amount.'.',
            ]);
        }
    }

    private static function changeCashierBalance(CashierVault $cashierVault, int $currencyId, string $amount, bool $add): void
    {
        $balance = CashierVaultBalance::query()
            ->where('cashier_vault_id', $cashierVault->id)
            ->where('currency_id', $currencyId)
            ->lockForUpdate()
            ->first();

        $available = self::money($balance?->current_balance ?? 0);

        if (! $balance instanceof CashierVaultBalance) {
            $balance = new CashierVaultBalance;
            $balance->cashier_vault_id = $cashierVault->id;
            $balance->currency_id = $currencyId;
            $balance->current_balance = '0.000';
        }

        $balance->current_balance = $add
            ? self::add($available, $amount)
            : self::subtract($available, $amount);
        $balance->save();
    }

    private static function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private static function rate(mixed $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    private static function add(string $left, string $right): string
    {
        return self::money((float) $left + (float) $right);
    }

    private static function subtract(string $left, string $right): string
    {
        return self::money((float) $left - (float) $right);
    }

    private static function exceeds(string $amount, string $available): bool
    {
        return round((float) $amount, 3) > round((float) $available, 3);
    }
}
