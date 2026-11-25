<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrencyRateChange extends CompanyModel
{
    protected $fillable = [
        'currency_id',
        'user_id',
        'user_name',
        'buy_cash_rate_before',
        'buy_cash_rate_after',
        'buy_check_rate_before',
        'buy_check_rate_after',
        'sell_cash_rate_before',
        'sell_cash_rate_after',
        'sell_check_rate_before',
        'sell_check_rate_after',
    ];

    protected function casts(): array
    {
        return [
            'buy_cash_rate_before' => 'decimal:6',
            'buy_cash_rate_after' => 'decimal:6',
            'buy_check_rate_before' => 'decimal:6',
            'buy_check_rate_after' => 'decimal:6',
            'sell_cash_rate_before' => 'decimal:6',
            'sell_cash_rate_after' => 'decimal:6',
            'sell_check_rate_before' => 'decimal:6',
            'sell_check_rate_after' => 'decimal:6',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rateChangeText(string $attribute): string
    {
        $before = $this->{$attribute.'_before'};
        $after = $this->{$attribute.'_after'};

        return 'من '.static::formatRate($before).' إلى '.static::formatRate($after);
    }

    public static function formatRate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return number_format((float) $value, 4, '.', '');
    }
}
