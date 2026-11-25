<?php

namespace App\Enums;

enum VoucherType: string
{
    case Receipt = 'receipt';
    case Payment = 'payment';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'قبض',
            self::Payment => 'دفع',
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
