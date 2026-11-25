<?php

namespace App\Models;

use App\Enums\DealPayment;
use App\Enums\DealStatus;
use App\Enums\TradeSide;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExchangeDeal extends CompanyModel
{
    protected $fillable = [
        'work_day_id',
        'type',
        'payment',
        'cashier_vault_id',
        'customer_id',
        'currency_id',
        'quantity',
        'rate',
        'lyd_amount',
        'cost_price',
        'profit',
        'user_id',
        'user_name',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => TradeSide::class,
            'payment' => DealPayment::class,
            'status' => DealStatus::class,
            'quantity' => 'decimal:3',
            'rate' => 'decimal:6',
            'lyd_amount' => 'decimal:3',
            'cost_price' => 'decimal:6',
            'profit' => 'decimal:3',
        ];
    }

    public function workDay(): BelongsTo
    {
        return $this->belongsTo(WorkDay::class);
    }

    public function cashierVault(): BelongsTo
    {
        return $this->belongsTo(CashierVault::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ExchangeDealLog::class);
    }
}
