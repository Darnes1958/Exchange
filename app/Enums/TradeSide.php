<?php

namespace App\Enums;

enum TradeSide: string
{
    case Sell = 'sell';
    case Buy = 'buy';

    public function label(): string
    {
        return match ($this) {
            self::Sell => 'بيع',
            self::Buy => 'شراء',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
