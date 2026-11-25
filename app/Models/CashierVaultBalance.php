<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashierVaultBalance extends CompanyModel
{
    protected $fillable = [
        'cashier_vault_id',
        'currency_id',
    ];

    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:3',
        ];
    }

    public function cashierVault(): BelongsTo
    {
        return $this->belongsTo(CashierVault::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
