<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class CashierVault extends CompanyModel
{
    protected $fillable = [
        'branch_id',
        'user_id',
        'name',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CashierVault $vault): void {
            $cashier = User::query()->find($vault->user_id);

            if ($cashier instanceof User && filled($cashier->branch_id)) {
                $vault->branch_id = $cashier->branch_id;
            }

            if (blank($vault->branch_id) && CompanySetting::hidesBranchChoice()) {
                $vault->branch_id = Branch::query()->orderBy('id')->value('id');
            }

            if (blank($vault->branch_id)) {
                throw ValidationException::withMessages([
                    'branch_id' => 'حدد فرع الصراف.',
                ]);
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(CashierVaultBalance::class);
    }
}
