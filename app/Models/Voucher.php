<?php

namespace App\Models;

use App\Enums\VoucherType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voucher extends CompanyModel
{
    protected $fillable = [
        'type',
        'customer_id',
        'currency_id',
        'amount',
        'voucher_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => VoucherType::class,
            'amount' => 'decimal:3',
            'voucher_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
