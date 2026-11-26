<?php

namespace App\Models;

use App\Enums\BankAccountType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class BankAccountBalance extends CompanyModel
{
    protected $fillable = [
        'bank_account_id',
        'currency_id',
        'opening_balance',
    ];

    protected static function booted(): void
    {
        static::creating(function (BankAccountBalance $balance): void {
            static::assertCurrencyMatchesAccount($balance);

            if (! static::actorCanAdjustCarriedBalance()) {
                $balance->opening_balance = 0;
            }

            $balance->current_balance = $balance->opening_balance ?? 0;
        });

        static::updating(function (BankAccountBalance $balance): void {
            if ($balance->isDirty('currency_id')) {
                static::assertCurrencyMatchesAccount($balance);
            }

            if (! $balance->isDirty('opening_balance')) {
                return;
            }

            if (! static::actorCanAdjustCarriedBalance()) {
                $balance->opening_balance = $balance->getOriginal('opening_balance');

                return;
            }

            $movements = (float) $balance->getOriginal('current_balance') - (float) $balance->getOriginal('opening_balance');
            $balance->current_balance = number_format((float) $balance->opening_balance + $movements, 3, '.', '');
        });
    }

    protected static function assertCurrencyMatchesAccount(BankAccountBalance $balance): void
    {
        $account = $balance->account;

        if (! $account instanceof BankAccount && filled($balance->bank_account_id)) {
            $account = BankAccount::query()->find($balance->bank_account_id);
        }

        $currency = $balance->currency;

        if (! $currency instanceof Currency && filled($balance->currency_id)) {
            $currency = Currency::query()->find($balance->currency_id);
        }

        if (! $account instanceof BankAccount || ! $currency instanceof Currency || $account->allowsCurrency($currency)) {
            return;
        }

        $message = $account->type === BankAccountType::Local
            ? 'الحساب المحلي يقبل الدينار الليبي والدولار الأمريكي فقط.'
            : 'الحساب الأجنبي يقبل العملات الأجنبية فقط.';

        throw ValidationException::withMessages([
            'currency_id' => $message,
        ]);
    }

    protected static function actorCanAdjustCarriedBalance(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return true;
        }

        return $user->canAdjustCarriedBalance();
    }

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:3',
            'current_balance' => 'decimal:3',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
