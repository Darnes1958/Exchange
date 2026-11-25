<?php

namespace App\Services;

use App\Enums\CustodyMovement;
use App\Enums\WorkDayStatus;
use App\Models\CashierVault;
use App\Models\CashierVaultBalance;
use App\Models\Currency;
use App\Models\CustodyHandover;
use App\Models\CustodyHandoverLine;
use App\Models\MainVault;
use App\Models\MainVaultBalance;
use App\Models\User;
use App\Models\WorkDay;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DailyCustodyService
{
    public static function open(?User $user): WorkDay
    {
        $connection = (new WorkDay)->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($user): WorkDay {
            $openDay = WorkDay::query()->lockForUpdate()->where('status', WorkDayStatus::Open)->first();

            if ($openDay instanceof WorkDay) {
                throw ValidationException::withMessages([
                    'work_date' => 'لا يمكن افتتاح عمل يومي جديد قبل إقفال العمل السابق.',
                ]);
            }

            return WorkDay::query()->create([
                'work_date' => now()->toDateString(),
                'status' => WorkDayStatus::Open,
                'opened_at' => now(),
                'opened_by_user_id' => $user?->id,
                'opened_by_name' => $user?->name,
            ]);
        });
    }

    public static function close(?User $user): WorkDay
    {
        $connection = (new WorkDay)->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($user): WorkDay {
            $workDay = WorkDay::query()->lockForUpdate()->where('status', WorkDayStatus::Open)->latest('id')->first();

            if (! $workDay instanceof WorkDay) {
                throw ValidationException::withMessages([
                    'status' => 'لا يوجد عمل يومي مفتوح.',
                ]);
            }

            $held = CashierVaultBalance::query()
                ->where('current_balance', '>', 0)
                ->with(['cashierVault', 'currency'])
                ->lockForUpdate()
                ->get();

            if ($held->isNotEmpty()) {
                $details = $held->map(function (CashierVaultBalance $balance): string {
                    $cashier = $balance->cashierVault?->name ?? 'صراف';
                    $code = $balance->currency?->code ?? '';

                    return $cashier.' / '.$code.' '.$balance->current_balance;
                })->implode('، ');

                throw ValidationException::withMessages([
                    'status' => 'لا يمكن إقفال العمل اليومي قبل تسليم كل الصرافين ما في عهدتهم: '.$details,
                ]);
            }

            $workDay->status = WorkDayStatus::Closed;
            $workDay->closed_at = now();
            $workDay->closed_by_user_id = $user?->id;
            $workDay->closed_by_name = $user?->name;
            $workDay->save();

            return $workDay;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int|string, mixed>  $lines
     */
    public static function handOver(array $data, array $lines, ?User $user): CustodyHandover
    {
        $connection = (new CustodyHandover)->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($data, $lines, $user): CustodyHandover {
            $workDay = WorkDay::query()->lockForUpdate()->where('status', WorkDayStatus::Open)->latest('id')->first();

            if (! $workDay instanceof WorkDay) {
                throw ValidationException::withMessages([
                    'type' => 'لا يمكن تسليم العهد قبل افتتاح العمل اليومي.',
                ]);
            }

            $type = $data['type'] instanceof CustodyMovement
                ? $data['type']
                : CustodyMovement::tryFrom((string) ($data['type'] ?? ''));

            if (! $type instanceof CustodyMovement) {
                throw ValidationException::withMessages([
                    'type' => 'حدد نوع الحركة.',
                ]);
            }

            $cashierVault = CashierVault::query()->lockForUpdate()->find($data['cashier_vault_id'] ?? null);

            if (! $cashierVault instanceof CashierVault || ! $cashierVault->is_active) {
                throw ValidationException::withMessages([
                    'cashier_vault_id' => 'اختر خزينة صراف نشطة.',
                ]);
            }

            if (blank($cashierVault->branch_id)) {
                throw ValidationException::withMessages([
                    'cashier_vault_id' => 'الصراف غير مرتبط بفرع.',
                ]);
            }

            $mainVaultId = $data['main_vault_id'] ?? MainVault::query()
                ->where('branch_id', $cashierVault->branch_id)
                ->value('id');

            $mainVault = MainVault::query()->lockForUpdate()->find($mainVaultId);

            if (! $mainVault instanceof MainVault || ! $mainVault->is_active) {
                throw ValidationException::withMessages([
                    'main_vault_id' => 'لا توجد خزينة رئيسية نشطة لفرع الصراف.',
                ]);
            }

            if ((int) $mainVault->branch_id !== (int) $cashierVault->branch_id) {
                throw ValidationException::withMessages([
                    'main_vault_id' => 'الخزينة الرئيسية يجب أن تكون خزينة فرع الصراف.',
                ]);
            }

            $prepared = self::prepareLines($lines);

            $handover = CustodyHandover::query()->create([
                'work_day_id' => $workDay->id,
                'type' => $type,
                'cashier_vault_id' => $cashierVault->id,
                'main_vault_id' => $mainVault->id,
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($prepared as $line) {
                self::moveAmount($type, $cashierVault, $mainVault, $line['currency_id'], $line['amount']);

                CustodyHandoverLine::query()->create([
                    'custody_handover_id' => $handover->id,
                    'currency_id' => $line['currency_id'],
                    'amount' => $line['amount'],
                ]);
            }

            return $handover;
        });
    }

    /**
     * @param  array<int|string, mixed>  $lines
     * @return list<array{currency_id: int, amount: string}>
     */
    private static function prepareLines(array $lines): array
    {
        $prepared = [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $currencyId = (int) ($line['currency_id'] ?? 0);
            $amount = self::money($line['amount'] ?? 0);

            if ($currencyId === 0 || (float) $amount <= 0) {
                continue;
            }

            if (isset($prepared[$currencyId])) {
                $currency = Currency::query()->find($currencyId);

                throw ValidationException::withMessages([
                    'lines' => 'العملة '.($currency?->code ?? '').' مكررة في نفس العهدة.',
                ]);
            }

            $prepared[$currencyId] = [
                'currency_id' => $currencyId,
                'amount' => $amount,
            ];
        }

        if ($prepared === []) {
            throw ValidationException::withMessages([
                'lines' => 'أضف عملة واحدة على الأقل بمبلغ أكبر من صفر.',
            ]);
        }

        return array_values($prepared);
    }

    private static function moveAmount(CustodyMovement $type, CashierVault $cashierVault, MainVault $mainVault, int $currencyId, string $amount): void
    {
        $currency = Currency::query()->find($currencyId);
        $code = $currency?->code ?? (string) $currencyId;

        $mainBalance = MainVaultBalance::query()
            ->where('main_vault_id', $mainVault->id)
            ->where('currency_id', $currencyId)
            ->lockForUpdate()
            ->first();

        $cashierBalance = CashierVaultBalance::query()
            ->where('cashier_vault_id', $cashierVault->id)
            ->where('currency_id', $currencyId)
            ->lockForUpdate()
            ->first();

        if ($type === CustodyMovement::Issue) {
            $available = self::money($mainBalance?->current_balance ?? 0);

            if (! $mainBalance instanceof MainVaultBalance || self::exceeds($amount, $available)) {
                throw ValidationException::withMessages([
                    'lines' => 'رصيد الخزينة الرئيسية من '.$code.' هو '.$available.' ولا يكفي لخصم '.$amount.'.',
                ]);
            }

            $mainBalance->setCurrentBalance(self::subtract($available, $amount));
            $mainBalance->save();

            if (! $cashierBalance instanceof CashierVaultBalance) {
                $cashierBalance = new CashierVaultBalance;
                $cashierBalance->cashier_vault_id = $cashierVault->id;
                $cashierBalance->currency_id = $currencyId;
                $cashierBalance->current_balance = '0.000';
            }

            $cashierBalance->current_balance = self::add(self::money($cashierBalance->current_balance), $amount);
            $cashierBalance->save();

            return;
        }

        $available = self::money($cashierBalance?->current_balance ?? 0);

        if (! $cashierBalance instanceof CashierVaultBalance || self::exceeds($amount, $available)) {
            throw ValidationException::withMessages([
                'lines' => 'رصيد الصراف من '.$code.' هو '.$available.' ولا يكفي لإرجاع '.$amount.'.',
            ]);
        }

        $cashierBalance->current_balance = self::subtract($available, $amount);
        $cashierBalance->save();

        if (! $mainBalance instanceof MainVaultBalance) {
            $mainBalance = new MainVaultBalance;
            $mainBalance->main_vault_id = $mainVault->id;
            $mainBalance->currency_id = $currencyId;
            $mainBalance->opening_balance = 0;
            $mainBalance->current_balance = '0.000';
            $mainBalance->save();
        }

        $mainBalance->setCurrentBalance(self::add(self::money($mainBalance->current_balance), $amount));
        $mainBalance->save();
    }

    private static function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
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
