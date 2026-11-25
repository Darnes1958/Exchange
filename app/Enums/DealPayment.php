<?php

namespace App\Enums;

enum DealPayment: string
{
    case Cash = 'cash';
    case Check = 'check';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'كاش',
            self::Check => 'صك',
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
