<?php

namespace App\Models;

use App\Enums\ExternalBankDirection;
use App\Enums\ExternalBankPurpose;
use App\Enums\ExternalSettlement;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalBankMovement extends CompanyModel
{
    protected $fillable = [
        'bank_account_id',
        'direction',
        'purpose',
        'reference',
        'movement_date',
        'currency_id',
        'amount',
        'rate',
        'counter_amount',
        'settlement',
        'local_bank_account_id',
        'main_vault_id',
        'external_source_id',
        'external_beneficiary_id',
        'user_id',
        'user_name',
    ];

    protected function casts(): array
    {
        return [
            'direction' => ExternalBankDirection::class,
            'purpose' => ExternalBankPurpose::class,
            'settlement' => ExternalSettlement::class,
            'movement_date' => 'date',
            'amount' => 'decimal:3',
            'rate' => 'decimal:6',
            'counter_amount' => 'decimal:3',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function localBank(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'local_bank_account_id');
    }

    public function mainVault(): BelongsTo
    {
        return $this->belongsTo(MainVault::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ExternalSource::class, 'external_source_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(ExternalBeneficiary::class, 'external_beneficiary_id');
    }
}
