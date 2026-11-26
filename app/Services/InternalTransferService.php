<?php

namespace App\Services;

use App\Enums\BankAccountType;
use App\Enums\IncomingCommission;
use App\Enums\InternalTransferDirection;
use App\Enums\InternalTransferSettlement;
use App\Enums\InternalTransferStatus;
use App\Enums\OutgoingCommission;
use App\Enums\UserRole;
use App\Enums\WorkDayStatus;
use App\Models\BankAccount;
use App\Models\BankAccountBalance;
use App\Models\CashierVault;
use App\Models\CashierVaultBalance;
use App\Models\Currency;
use App\Models\InternalTransfer;
use App\Models\TransferOffice;
use App\Models\User;
use App\Models\WorkDay;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InternalTransferService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function post(array $data, ?User $user): InternalTransfer
    {
        $connection = (new InternalTransfer)->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($data, $user): InternalTransfer {
            [$user, $workDay, $vault] = self::cashierContext($user);
            $direction = self::direction($data['direction'] ?? null);
            $figures = self::figures($direction, (string) ($data['commission_type'] ?? ''), $data['amount'] ?? 0, $data['commission'] ?? 0);
            $office = self::office($data['transfer_office_id'] ?? null);
            $party = self::party($direction, $data);
            $settlement = $direction === InternalTransferDirection::Outgoing
                ? self::settlement($data['settlement'] ?? null)
                : InternalTransferSettlement::Cash;
            $bank = $settlement === InternalTransferSettlement::Bank
                ? self::localBank($data['bank_account_id'] ?? null)
                : null;

            $transfer = new InternalTransfer([
                'work_day_id' => $workDay->id,
                'transfer_office_id' => $office->id,
                'cashier_vault_id' => $vault->id,
                'direction' => $direction,
                'status' => $direction === InternalTransferDirection::Incoming
                    ? InternalTransferStatus::Delivered
                    : InternalTransferStatus::Pending,
                'reference' => $party['reference'],
                'sender_name' => $party['sender_name'],
                'sender_phone' => $party['sender_phone'],
                'sender_identity' => $party['sender_identity'],
                'amount' => $figures['amount'],
                'commission' => $figures['commission'],
                'commission_type' => $figures['commission_type'],
                'cash_amount' => $figures['cash_amount'],
                'office_amount' => $figures['office_amount'],
                'profit' => $figures['profit'],
                'settlement' => $settlement,
                'bank_account_id' => $bank?->id,
                'recipient_name' => $party['recipient_name'],
                'recipient_identity' => $party['recipient_identity'],
                'recipient_phone' => $party['recipient_phone'],
                'user_id' => $user->id,
                'user_name' => $user->name,
            ]);

            self::applyCash($transfer, true);

            if ($direction === InternalTransferDirection::Incoming) {
                self::applyOffice($transfer);
            }

            $transfer->save();

            return $transfer;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function update(InternalTransfer $transfer, array $data, ?User $user): InternalTransfer
    {
        $connection = (new InternalTransfer)->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($transfer, $data, $user): InternalTransfer {
            $locked = self::lockedPending($transfer, $user, true);
            self::applyCash($locked, false);

            $figures = self::figures($locked->direction, (string) ($data['commission_type'] ?? ''), $data['amount'] ?? 0, $data['commission'] ?? 0);
            $office = self::office($data['transfer_office_id'] ?? null);
            $party = self::party($locked->direction, $data);
            $settlement = self::settlement($data['settlement'] ?? null);
            $bank = $settlement === InternalTransferSettlement::Bank
                ? self::localBank($data['bank_account_id'] ?? null)
                : null;

            $locked->fill([
                'transfer_office_id' => $office->id,
                'reference' => $party['reference'],
                'sender_name' => $party['sender_name'],
                'sender_phone' => $party['sender_phone'],
                'sender_identity' => $party['sender_identity'],
                'amount' => $figures['amount'],
                'commission' => $figures['commission'],
                'commission_type' => $figures['commission_type'],
                'cash_amount' => $figures['cash_amount'],
                'office_amount' => $figures['office_amount'],
                'profit' => $figures['profit'],
                'settlement' => $settlement,
                'bank_account_id' => $bank?->id,
                'recipient_name' => $party['recipient_name'],
                'recipient_identity' => $party['recipient_identity'],
                'recipient_phone' => $party['recipient_phone'],
            ]);
            self::applyCash($locked, true);
            $locked->save();

            return $locked;
        });
    }

    public static function cancel(InternalTransfer $transfer, ?User $user): InternalTransfer
    {
        $connection = (new InternalTransfer)->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($transfer, $user): InternalTransfer {
            $locked = self::lockedPending($transfer, $user, true);
            self::applyCash($locked, false);
            $locked->status = InternalTransferStatus::Cancelled;
            $locked->save();

            return $locked;
        });
    }

    public static function receive(InternalTransfer $transfer, ?User $user): InternalTransfer
    {
        $connection = (new InternalTransfer)->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($transfer, $user): InternalTransfer {
            $locked = self::lockedPending($transfer, $user, false);
            self::applyOffice($locked);
            $locked->status = InternalTransferStatus::Received;
            $locked->save();

            return $locked;
        });
    }

    /**
     * @return array{amount: string, commission: string, commission_type: string, cash_amount: string, office_amount: string, profit: string}
     */
    public static function figures(InternalTransferDirection $direction, string $commissionType, mixed $amount, mixed $commission): array
    {
        $amount = self::money($amount);
        $commission = self::money($commission);

        if ((float) $amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'أدخل مبلغ الحوالة أكبر من صفر.',
            ]);
        }

        if ((float) $commission < 0) {
            throw ValidationException::withMessages([
                'commission' => 'العمولة لا تكون سالبة.',
            ]);
        }

        if ($direction === InternalTransferDirection::Incoming) {
            $type = IncomingCommission::tryFrom($commissionType);

            if (! $type instanceof IncomingCommission) {
                throw ValidationException::withMessages([
                    'commission_type' => 'حدد نوع العمولة.',
                ]);
            }

            if ($type === IncomingCommission::Deducted && (float) $commission > (float) $amount) {
                throw ValidationException::withMessages([
                    'commission' => 'العمولة المخصومة لا تتجاوز مبلغ الحوالة.',
                ]);
            }

            $cash = $type === IncomingCommission::Deducted
                ? self::money((float) $amount - (float) $commission)
                : $amount;
            $office = $type === IncomingCommission::Deducted
                ? $amount
                : self::money((float) $amount + (float) $commission);

            return [
                'amount' => $amount,
                'commission' => $commission,
                'commission_type' => $type->value,
                'cash_amount' => $cash,
                'office_amount' => $office,
                'profit' => $commission,
            ];
        }

        $type = OutgoingCommission::tryFrom($commissionType);

        if (! $type instanceof OutgoingCommission) {
            throw ValidationException::withMessages([
                'commission_type' => 'حدد نوع العمولة.',
            ]);
        }

        if ($type === OutgoingCommission::Deducted && (float) $commission > (float) $amount) {
            throw ValidationException::withMessages([
                'commission' => 'العمولة المخصومة لا تتجاوز المبلغ المدفوع.',
            ]);
        }

        $cash = $type === OutgoingCommission::Added
            ? self::money((float) $amount + (float) $commission)
            : $amount;
        $office = $type === OutgoingCommission::Deducted
            ? self::money((float) $commission - (float) $amount)
            : self::money(0 - (float) $amount);

        return [
            'amount' => $amount,
            'commission' => $commission,
            'commission_type' => $type->value,
            'cash_amount' => $cash,
            'office_amount' => $office,
            'profit' => $commission,
        ];
    }

    public static function preview(InternalTransferDirection $direction, ?string $commissionType, mixed $amount, mixed $commission, ?string $settlement = null): string
    {
        try {
            $figures = self::figures($direction, (string) $commissionType, $amount, $commission);
        } catch (ValidationException $exception) {
            return (string) (collect($exception->errors())->flatten()->first() ?? '');
        }

        $cash = $figures['cash_amount'];
        $office = self::money(abs((float) $figures['office_amount']));
        $profit = $figures['profit'];

        if ($direction === InternalTransferDirection::Incoming) {
            return 'يُسلّم للزبون '.$cash.' نقداً من عهدة الصراف، ويُسجّل '.$office.' على المكتب. الربح '.$profit.'.';
        }

        $paid = ($settlement === InternalTransferSettlement::Bank->value)
            ? 'يُستلم من الزبون '.$cash.' ويُضاف إلى المصرف المحلي'
            : 'يُستلم من الزبون '.$cash.' ويُضاف إلى عهدة الصراف';

        return $paid.'. يُسجّل '.$office.' على رصيد المكتب عند تأكيد الاستلام. الربح '.$profit.'.';
    }

    /**
     * @return array{0: User, 1: WorkDay, 2: CashierVault}
     */
    private static function cashierContext(?User $user): array
    {
        if (! $user instanceof User || $user->role !== UserRole::Cashier) {
            throw ValidationException::withMessages([
                'direction' => 'الحوالات الداخلية من صلاحية الصراف فقط.',
            ]);
        }

        $workDay = WorkDay::query()->lockForUpdate()->where('status', WorkDayStatus::Open)->latest('id')->first();

        if (! $workDay instanceof WorkDay) {
            throw ValidationException::withMessages([
                'direction' => 'لا يمكن تسجيل حوالة قبل افتتاح العمل اليومي.',
            ]);
        }

        $vault = CashierVault::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $vault instanceof CashierVault) {
            throw ValidationException::withMessages([
                'direction' => 'لا توجد عهدة نشطة لهذا الصراف.',
            ]);
        }

        return [$user, $workDay, $vault];
    }

    private static function lockedPending(InternalTransfer $transfer, ?User $user, bool $ownerOnly): InternalTransfer
    {
        if (! $user instanceof User || $user->role !== UserRole::Cashier) {
            throw ValidationException::withMessages([
                'status' => 'الحوالات الداخلية من صلاحية الصراف فقط.',
            ]);
        }

        $workDay = WorkDay::query()->lockForUpdate()->where('status', WorkDayStatus::Open)->latest('id')->first();

        if (! $workDay instanceof WorkDay) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن متابعة الحوالة قبل افتتاح العمل اليومي.',
            ]);
        }

        $locked = InternalTransfer::query()->lockForUpdate()->find($transfer->id);

        if (! $locked instanceof InternalTransfer || $locked->direction !== InternalTransferDirection::Outgoing || $locked->status !== InternalTransferStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'الحوالة المستلمة لا تُعدَّل ولا تُلغى.',
            ]);
        }

        if ($ownerOnly && ((int) $workDay->id !== (int) $locked->work_day_id || (int) $locked->user_id !== (int) $user->id)) {
            throw ValidationException::withMessages([
                'status' => 'تعديل الحوالة المعلقة وإلغاؤها لصرافها وفي يوم عملها المفتوح فقط.',
            ]);
        }

        return $locked;
    }

    private static function direction(mixed $value): InternalTransferDirection
    {
        $direction = $value instanceof InternalTransferDirection
            ? $value
            : InternalTransferDirection::tryFrom((string) $value);

        if (! $direction instanceof InternalTransferDirection) {
            throw ValidationException::withMessages([
                'direction' => 'حدد حوالة واردة أو صادرة.',
            ]);
        }

        return $direction;
    }

    private static function settlement(mixed $value): InternalTransferSettlement
    {
        $settlement = $value instanceof InternalTransferSettlement
            ? $value
            : InternalTransferSettlement::tryFrom((string) $value);

        if (! $settlement instanceof InternalTransferSettlement) {
            throw ValidationException::withMessages([
                'settlement' => 'حدد كاش أو مصرفي.',
            ]);
        }

        return $settlement;
    }

    private static function office(mixed $id): TransferOffice
    {
        $office = TransferOffice::query()->lockForUpdate()->find($id);

        if (! $office instanceof TransferOffice) {
            throw ValidationException::withMessages([
                'transfer_office_id' => 'حدد المكتب.',
            ]);
        }

        return $office;
    }

    private static function localBank(mixed $id): BankAccount
    {
        $bank = BankAccount::query()->lockForUpdate()->find($id);

        if (! $bank instanceof BankAccount || ! $bank->is_active || $bank->type !== BankAccountType::Local) {
            throw ValidationException::withMessages([
                'bank_account_id' => 'اختر مصرفاً محلياً نشطاً.',
            ]);
        }

        return $bank;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{reference: string, sender_name: string, sender_phone: string, sender_identity: ?string, recipient_name: ?string, recipient_identity: ?string, recipient_phone: ?string}
     */
    private static function party(InternalTransferDirection $direction, array $data): array
    {
        $reference = trim((string) ($data['reference'] ?? ''));
        $name = trim((string) ($data['sender_name'] ?? ''));
        $phone = trim((string) ($data['sender_phone'] ?? ''));
        $identity = trim((string) ($data['sender_identity'] ?? ''));

        if ($reference === '') {
            throw ValidationException::withMessages([
                'reference' => 'أدخل إشاري الحوالة.',
            ]);
        }

        if ($name === '') {
            throw ValidationException::withMessages([
                'sender_name' => 'أدخل الاسم.',
            ]);
        }

        if ($phone === '') {
            throw ValidationException::withMessages([
                'sender_phone' => 'أدخل رقم الهاتف.',
            ]);
        }

        if ($direction === InternalTransferDirection::Outgoing && $identity === '') {
            throw ValidationException::withMessages([
                'sender_identity' => 'أدخل إثبات الهوية.',
            ]);
        }

        $recipientName = null;
        $recipientIdentity = null;
        $recipientPhone = null;

        if ($direction === InternalTransferDirection::Outgoing) {
            $recipientName = trim((string) ($data['recipient_name'] ?? ''));
            $recipientIdentity = trim((string) ($data['recipient_identity'] ?? ''));
            $recipientPhone = trim((string) ($data['recipient_phone'] ?? ''));

            if ($recipientName === '' || $recipientIdentity === '' || $recipientPhone === '') {
                throw ValidationException::withMessages([
                    'recipient_name' => 'أدخل اسم المستلم وإثباته وهاتفه.',
                ]);
            }
        }

        return [
            'reference' => $reference,
            'sender_name' => $name,
            'sender_phone' => $phone,
            'sender_identity' => $identity === '' ? null : $identity,
            'recipient_name' => $recipientName,
            'recipient_identity' => $recipientIdentity,
            'recipient_phone' => $recipientPhone,
        ];
    }

    private static function applyCash(InternalTransfer $transfer, bool $forward): void
    {
        $lyd = self::lyd();
        $cashIn = $transfer->direction === InternalTransferDirection::Outgoing;

        if (! $forward) {
            $cashIn = ! $cashIn;
        }

        $cash = self::money($transfer->cash_amount);
        $vault = CashierVault::query()->lockForUpdate()->find($transfer->cashier_vault_id);

        if (! $vault instanceof CashierVault) {
            throw ValidationException::withMessages([
                'direction' => 'لا توجد عهدة نشطة لهذا الصراف.',
            ]);
        }

        if ($transfer->settlement === InternalTransferSettlement::Bank && $transfer->direction === InternalTransferDirection::Outgoing) {
            $bank = $transfer->bank_account_id
                ? BankAccount::query()->lockForUpdate()->find($transfer->bank_account_id)
                : null;

            if (! $bank instanceof BankAccount) {
                throw ValidationException::withMessages([
                    'bank_account_id' => 'اختر مصرفاً محلياً نشطاً.',
                ]);
            }

            self::changeBankLyd($bank, (int) $lyd->id, $cash, $cashIn);

            return;
        }

        self::changeCashierBalance($vault, (int) $lyd->id, $cash, $cashIn);
    }

    private static function applyOffice(InternalTransfer $transfer): void
    {
        $office = TransferOffice::query()->lockForUpdate()->find($transfer->transfer_office_id);

        if (! $office instanceof TransferOffice) {
            throw ValidationException::withMessages([
                'transfer_office_id' => 'حدد المكتب.',
            ]);
        }

        $office->balance = self::money((float) $office->balance + (float) $transfer->office_amount);
        $office->save();
    }

    private static function lyd(): Currency
    {
        $lyd = Currency::query()->where('code', 'LYD')->first();

        if (! $lyd instanceof Currency) {
            throw ValidationException::withMessages([
                'amount' => 'الدينار الليبي غير معرّف.',
            ]);
        }

        return $lyd;
    }

    private static function changeCashierBalance(CashierVault $vault, int $currencyId, string $amount, bool $add): void
    {
        if ((float) $amount === 0.0) {
            return;
        }

        $balance = CashierVaultBalance::query()
            ->where('cashier_vault_id', $vault->id)
            ->where('currency_id', $currencyId)
            ->lockForUpdate()
            ->first();

        $available = self::money($balance?->current_balance ?? 0);

        if (! $add && self::exceeds($amount, $available)) {
            throw ValidationException::withMessages([
                'amount' => 'رصيد عهدة الصراف من الدينار هو '.$available.' ولا يكفي لتسليم '.$amount.'.',
            ]);
        }

        if (! $balance instanceof CashierVaultBalance) {
            $balance = new CashierVaultBalance;
            $balance->cashier_vault_id = $vault->id;
            $balance->currency_id = $currencyId;
            $balance->current_balance = '0.000';
        }

        $balance->current_balance = $add
            ? self::money((float) $available + (float) $amount)
            : self::money((float) $available - (float) $amount);
        $balance->save();
    }

    private static function changeBankLyd(BankAccount $account, int $lydId, string $amount, bool $add): void
    {
        if ((float) $amount === 0.0) {
            return;
        }

        $balance = BankAccountBalance::query()
            ->where('bank_account_id', $account->id)
            ->where('currency_id', $lydId)
            ->lockForUpdate()
            ->first();

        $available = self::money($balance?->current_balance ?? 0);

        if (! $add && self::exceeds($amount, $available)) {
            throw ValidationException::withMessages([
                'bank_account_id' => 'رصيد المصرف المحلي من الدينار هو '.$available.' ولا يكفي لخصم '.$amount.'.',
            ]);
        }

        if (! $balance instanceof BankAccountBalance) {
            $balance = new BankAccountBalance;
            $balance->bank_account_id = $account->id;
            $balance->currency_id = $lydId;
            $balance->opening_balance = 0;
            $balance->save();
            $available = self::money($balance->current_balance);
        }

        $balance->current_balance = $add
            ? self::money((float) $available + (float) $amount)
            : self::money((float) $available - (float) $amount);
        $balance->save();
    }

    private static function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private static function exceeds(string $amount, string $available): bool
    {
        return round((float) $amount, 3) > round((float) $available, 3);
    }
}
