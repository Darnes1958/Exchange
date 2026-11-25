<?php

namespace App\Enums;

enum BankAccountType: string
{
    case Local = 'local';
    case Foreign = 'foreign';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'محلي',
            self::Foreign => 'أجنبي',
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
