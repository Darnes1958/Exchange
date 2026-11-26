<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class TransferOffice extends CompanyModel
{
    protected $fillable = [
        'name',
        'balance',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:3',
        ];
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(InternalTransfer::class);
    }
}
