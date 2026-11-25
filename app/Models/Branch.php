<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class Branch extends CompanyModel
{
    protected $fillable = [
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Branch $branch): void {
            $connection = $branch->getConnectionName();
            $hasPeople = User::query()
                ->when(
                    is_string($connection) && $connection !== '',
                    fn ($query) => $query->where('company', $connection),
                )
                ->where('branch_id', $branch->id)
                ->exists();

            if (
                $hasPeople
                || $branch->mainVault()->exists()
                || $branch->cashierVaults()->exists()
                || static::query()->count() <= 1
            ) {
                throw ValidationException::withMessages([
                    'name' => 'لا يمكن حذف الفرع ما دام مرتبطاً بخزينة أو صراف أو مدير، أو كان الفرع الوحيد.',
                ]);
            }
        });
    }

    public function mainVault(): HasOne
    {
        return $this->hasOne(MainVault::class);
    }

    public function cashierVaults(): HasMany
    {
        return $this->hasMany(CashierVault::class);
    }
}
