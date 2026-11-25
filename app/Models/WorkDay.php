<?php

namespace App\Models;

use App\Enums\WorkDayStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkDay extends CompanyModel
{
    protected $fillable = [
        'work_date',
        'status',
        'opened_at',
        'closed_at',
        'opened_by_user_id',
        'opened_by_name',
        'closed_by_user_id',
        'closed_by_name',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'status' => WorkDayStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function handovers(): HasMany
    {
        return $this->hasMany(CustodyHandover::class);
    }

    public static function current(): ?self
    {
        return static::query()
            ->where('status', WorkDayStatus::Open)
            ->latest('id')
            ->first();
    }
}
