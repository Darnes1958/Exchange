<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class MainVault extends CompanyModel
{
    protected $fillable = [
        'branch_id',
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
        static::saving(function (MainVault $vault): void {
            if (blank($vault->branch_id) && CompanySetting::hidesBranchChoice()) {
                $vault->branch_id = Branch::query()->orderBy('id')->value('id');
            }

            if (blank($vault->branch_id)) {
                throw ValidationException::withMessages([
                    'branch_id' => 'حدد الفرع.',
                ]);
            }

            $taken = static::query()
                ->where('branch_id', $vault->branch_id)
                ->when($vault->exists, fn ($query) => $query->whereKeyNot($vault->getKey()))
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'branch_id' => 'لكل فرع خزينة رئيسية واحدة.',
                ]);
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(MainVaultBalance::class);
    }
}
