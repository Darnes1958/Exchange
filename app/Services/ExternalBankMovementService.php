<?php

namespace App\Services;

use App\Enums\BankAccountType;
use App\Enums\ExternalBankDirection;
use App\Enums\ExternalBankPurpose;
use App\Enums\ExternalSettlement;
use App\Models\BankAccount;
use App\Models\BankAccountBalance;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\ExternalBankMovement;
use App\Models\ExternalBeneficiary;
use App\Models\ExternalSource;
use App\Models\MainVault;
use App\Models\MainVaultBalance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExternalBankMovementService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function post(array $data, ?User $user): ExternalBankMovement
    {
        $connection = (new ExternalBankMovement)->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($data, $user): ExternalBankMovement {
            $direction = $data['direction'] instanceof ExternalBankDirection
                ? $data['direction']
                : ExternalBankDirection::tryFrom((string) ($data['direction'] ?? ''));

            if (! $direction instanceof ExternalBankDirection) {
                throw ValidationException::withMessages([
                    'purpose' => 'حدد الإيداع أو السحب.',
                ]);
            }

            $purpose = $data['purpose'] instanceof ExternalBankPurpose
                ? $data['purpose']
                : ExternalBankPurpose::tryFrom((string) ($data['purpose'] ?? ''));

            if (! $purpose instanceof ExternalBankPurpose) {
                throw ValidationException::withMessages([
                    'purpose' => 'حدد نوع الحركة.',
                ]);
            }

            $reference = trim((string) ($data['reference'] ?? ''));

            if ($reference === '') {
                throw ValidationException::withMessages([
                    'reference' => 'أدخل الإشاري.',
                ]);
            }

            $account = BankAccount::query()->lockForUpdate()->find($data['bank_account_id'] ?? null);

            if (! $account instanceof BankAccount || ! $account->is_active || $account->type !== BankAccountType::Foreign) {
                throw ValidationException::withMessages([
                    'bank_account_id' => 'اختر حساباً خارجياً نشطاً.',
                ]);
            }

            $currency = Currency::query()->find($data['currency_id'] ?? null);

            if (! $currency instanceof Currency || ! $account->allowsCurrency($currency)) {
                throw ValidationException::withMessages([
                    'currency_id' => 'الحساب الخارجي يقبل العملات الأجنبية فقط.',
                ]);
            }

            $amount = self::money($data['amount'] ?? 0);
            $rate = self::rate($data['rate'] ?? 0);
            $errors = [];

            if ((float) $amount <= 0) {
                $errors['amount'] = 'أدخل مبلغاً أكبر من صفر.';
            }

            if ((float) $rate <= 0) {
                $errors['rate'] = 'أدخل سعراً مقابلاً أكبر من صفر.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $counter = self::money((float) $amount * (float) $rate);
            $settlement = null;
            $localBank = null;
            $vault = null;

            if ($purpose === ExternalBankPurpose::Currency) {
                $settlement = $data['settlement'] instanceof ExternalSettlement
                    ? $data['settlement']
                    : ExternalSettlement::tryFrom((string) ($data['settlement'] ?? ''));

                if (! $settlement instanceof ExternalSettlement) {
                    throw ValidationException::withMessages([
                        'settlement' => 'حدد كاش أو مصرفي.',
                    ]);
                }

                if ($settlement === ExternalSettlement::Bank) {
                    $localBank = BankAccount::query()->lockForUpdate()->find($data['local_bank_account_id'] ?? null);

                    if (! $localBank instanceof BankAccount || ! $localBank->is_active || $localBank->type !== BankAccountType::Local) {
                        throw ValidationException::withMessages([
                            'local_bank_account_id' => 'اختر مصرفاً محلياً نشطاً.',
                        ]);
                    }
                } else {
                    $vault = self::managerVault($user);
                }
            }

            $sourceId = null;
            $beneficiaryId = null;

            if ($direction === ExternalBankDirection::Deposit) {
                $sourceId = (int) ($data['external_source_id'] ?? 0);

                if ($sourceId === 0 || ! ExternalSource::query()->whereKey($sourceId)->exists()) {
                    throw ValidationException::withMessages([
                        'external_source_id' => 'حدد المصدر.',
                    ]);
                }
            } else {
                $beneficiaryId = (int) ($data['external_beneficiary_id'] ?? 0);

                if ($beneficiaryId === 0 || ! ExternalBeneficiary::query()->whereKey($beneficiaryId)->exists()) {
                    throw ValidationException::withMessages([
                        'external_beneficiary_id' => 'حدد المستفيد.',
                    ]);
                }
            }

            self::applyBalance($direction, $account, (int) $currency->id, $amount, $currency->code);

            if ($purpose === ExternalBankPurpose::Currency) {
                $lyd = Currency::query()->where('code', 'LYD')->first();

                if (! $lyd instanceof Currency) {
                    throw ValidationException::withMessages([
                        'settlement' => 'أضف الدينار الليبي حتى تتحرك الخزينة أو المصرف المحلي.',
                    ]);
                }

                $receiveLyd = $direction === ExternalBankDirection::Withdrawal;

                if ($localBank instanceof BankAccount) {
                    self::changeBankLyd($localBank, (int) $lyd->id, $counter, $receiveLyd);
                } elseif ($vault instanceof MainVault) {
                    self::changeVaultLyd($vault, (int) $lyd->id, $counter, $receiveLyd);
                }
            }

            return ExternalBankMovement::query()->create([
                'bank_account_id' => $account->id,
                'direction' => $direction,
                'purpose' => $purpose,
                'reference' => $reference,
                'movement_date' => $data['movement_date'] ?? now()->toDateString(),
                'currency_id' => $currency->id,
                'amount' => $amount,
                'rate' => $rate,
                'counter_amount' => $counter,
                'settlement' => $settlement,
                'local_bank_account_id' => $localBank?->id,
                'main_vault_id' => $vault?->id,
                'external_source_id' => $sourceId,
                'external_beneficiary_id' => $beneficiaryId,
                'user_id' => $user?->id,
                'user_name' => $user?->name,
            ]);
        });
    }

    private static function applyBalance(ExternalBankDirection $direction, BankAccount $account, int $currencyId, string $amount, string $code): void
    {
        $balance = BankAccountBalance::query()
            ->where('bank_account_id', $account->id)
            ->where('currency_id', $currencyId)
            ->lockForUpdate()
            ->first();

        if (! $balance instanceof BankAccountBalance) {
            $balance = new BankAccountBalance;
            $balance->bank_account_id = $account->id;
            $balance->currency_id = $currencyId;
            $balance->opening_balance = 0;
            $balance->save();
        }

        $available = self::money($balance->current_balance);

        if ($direction === ExternalBankDirection::Withdrawal && self::exceeds($amount, $available)) {
            throw ValidationException::withMessages([
                'amount' => 'رصيد الحساب الخارجي من '.$code.' هو '.$available.' ولا يكفي لسحب '.$amount.'.',
            ]);
        }

        $balance->current_balance = $direction === ExternalBankDirection::Deposit
            ? self::money((float) $available + (float) $amount)
            : self::money((float) $available - (float) $amount);
        $balance->save();
    }

    public static function managerVault(?User $user): MainVault
    {
        $branchId = $user?->branch_id;

        if (blank($branchId)) {
            if (! CompanySetting::hidesBranchChoice()) {
                throw ValidationException::withMessages([
                    'settlement' => 'لا توجد خزينة رئيسية تابعة للمدير.',
                ]);
            }

            $branchId = Branch::query()->orderBy('id')->value('id');
        }

        $vault = MainVault::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->first();

        if (! $vault instanceof MainVault) {
            throw ValidationException::withMessages([
                'settlement' => 'لا توجد خزينة رئيسية تابعة للمدير.',
            ]);
        }

        return $vault;
    }

    private static function changeVaultLyd(MainVault $vault, int $lydId, string $amount, bool $add): void
    {
        $balance = MainVaultBalance::query()
            ->where('main_vault_id', $vault->id)
            ->where('currency_id', $lydId)
            ->lockForUpdate()
            ->first();

        $available = self::money($balance?->current_balance ?? 0);

        if (! $add && self::exceeds($amount, $available)) {
            throw ValidationException::withMessages([
                'settlement' => 'رصيد الخزينة الرئيسية من الدينار هو '.$available.' ولا يكفي لخصم '.$amount.'.',
            ]);
        }

        if (! $balance instanceof MainVaultBalance) {
            $balance = new MainVaultBalance;
            $balance->main_vault_id = $vault->id;
            $balance->currency_id = $lydId;
            $balance->opening_balance = 0;
            $balance->save();
            $available = self::money($balance->current_balance);
        }

        $balance->setCurrentBalance($add
            ? self::money((float) $available + (float) $amount)
            : self::money((float) $available - (float) $amount));
        $balance->save();
    }

    private static function changeBankLyd(BankAccount $account, int $lydId, string $amount, bool $add): void
    {
        $balance = BankAccountBalance::query()
            ->where('bank_account_id', $account->id)
            ->where('currency_id', $lydId)
            ->lockForUpdate()
            ->first();

        $available = self::money($balance?->current_balance ?? 0);

        if (! $add && self::exceeds($amount, $available)) {
            throw ValidationException::withMessages([
                'local_bank_account_id' => 'رصيد المصرف المحلي من الدينار هو '.$available.' ولا يكفي لخصم '.$amount.'.',
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

    private static function rate(mixed $value): string
    {
        return number_format((float) $value, 6, '.', '');
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
