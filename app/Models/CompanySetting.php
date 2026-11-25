<?php

namespace App\Models;

use App\Enums\BranchMode;

class CompanySetting extends CompanyModel
{
    protected $fillable = [
        'branch_mode',
    ];

    protected function casts(): array
    {
        return [
            'branch_mode' => BranchMode::class,
        ];
    }

    public function isSingle(): bool
    {
        return $this->branch_mode === BranchMode::Single;
    }

    public static function current(): self
    {
        $setting = static::query()->first();

        if ($setting instanceof self) {
            return $setting;
        }

        return static::query()->create([
            'branch_mode' => BranchMode::Single,
        ]);
    }

    public static function hidesBranchChoice(): bool
    {
        return static::current()->isSingle();
    }
}
