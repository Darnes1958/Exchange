<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustodyHandoverLine extends CompanyModel
{
    protected $fillable = [
        'custody_handover_id',
        'currency_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
        ];
    }

    public function handover(): BelongsTo
    {
        return $this->belongsTo(CustodyHandover::class, 'custody_handover_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
