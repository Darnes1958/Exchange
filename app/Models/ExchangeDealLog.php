<?php

namespace App\Models;

use App\Enums\DealLogAction;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeDealLog extends CompanyModel
{
    protected $fillable = [
        'exchange_deal_id',
        'action',
        'user_id',
        'user_name',
        'details',
        'before',
        'after',
    ];

    protected function casts(): array
    {
        return [
            'action' => DealLogAction::class,
            'before' => 'array',
            'after' => 'array',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(ExchangeDeal::class, 'exchange_deal_id');
    }
}
