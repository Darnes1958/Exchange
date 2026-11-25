<?php

namespace App\Models;

use App\Enums\VoucherType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

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

    protected static function booted(): void
    {
        static::saving(function (Voucher $voucher): void {
            $customer = $voucher->customer;

            if (! $customer instanceof Customer && filled($voucher->customer_id)) {
                $customer = Customer::query()->find($voucher->customer_id);
            }

            if ($customer instanceof Customer && $customer->is_general) {
                throw ValidationException::withMessages([
                    'customer_id' => 'الزبون العام يسدد بالكامل ولا تُسجل له إيصالات قبض أو دفع.',
                ]);
            }
        });
    }

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
