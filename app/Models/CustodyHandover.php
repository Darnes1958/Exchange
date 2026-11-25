<?php

namespace App\Models;

use App\Enums\CustodyMovement;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustodyHandover extends CompanyModel
{
    protected $fillable = [
        'work_day_id',
        'type',
        'cashier_vault_id',
        'main_vault_id',
        'user_id',
        'user_name',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => CustodyMovement::class,
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

    public function mainVault(): BelongsTo
    {
        return $this->belongsTo(MainVault::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustodyHandoverLine::class);
    }
}
