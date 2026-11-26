<?php

namespace App\Models;

use App\Enums\BankAccountType;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class BankAccount extends CompanyModel
{
    protected $fillable = [
        'bank_name',
        'account_number',
        'type',
        'is_active',
        'notes',
    ];

    protected static function booted(): void
    {
        static::updating(function (BankAccount $account): void {
            if (! $account->isDirty('type')) {
                return;
            }

            $type = $account->type instanceof BankAccountType
                ? $account->type
                : BankAccountType::tryFrom((string) $account->type);

            if (! $type instanceof BankAccountType) {
                return;
            }

            $hasInvalidBalance = $account->balances()
                ->whereHas('currency', function ($query) use ($type): void {
                    if ($type === BankAccountType::Local) {
                        $query->whereNotIn('code', ['LYD', 'USD']);

                        return;
                    }

                    $query->where('code', 'LYD');
                })
                ->exists();

            if ($hasInvalidBalance) {
                throw ValidationException::withMessages([
                    'type' => 'لا يمكن تغيير النوع لوجود أرصدة بعملات لا تناسب النوع الجديد.',
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => BankAccountType::class,
            'is_active' => 'boolean',
        ];
    }

    public function balances(): HasMany
    {
        return $this->hasMany(BankAccountBalance::class);
    }

    public function label(): string
    {
        $type = $this->type instanceof BankAccountType
            ? $this->type->label()
            : (string) $this->type;

        return $this->bank_name.' — '.$this->account_number.' ('.$type.')';
    }

    public function allowsCurrency(Currency $currency): bool
    {
        $type = $this->type instanceof BankAccountType
            ? $this->type
            : BankAccountType::tryFrom((string) $this->type);

        return $type instanceof BankAccountType && $type->allowsCurrencyCode($currency->code);
    }
}
