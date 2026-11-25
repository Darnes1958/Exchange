<?php

namespace App\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends CompanyModel
{
    protected $fillable = [
        'name',
        'phone',
        'notes',
    ];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            if ($customer->is_general) {
                $customer->name = 'عام';
                $customer->phone = null;
            }
        });

        static::deleting(function (Customer $customer): void {
            if ($customer->is_general) {
                throw new AuthorizationException('لا يمكن حذف الزبون العام.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_general' => 'boolean',
        ];
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }
}
