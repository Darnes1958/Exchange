<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'connection_name',
        'database_name',
        'name',
        'is_active',
    ];

    public function getConnectionName(): ?string
    {
        return (string) config('exchange.central_connection', config('database.default'));
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'company', 'connection_name');
    }
}
