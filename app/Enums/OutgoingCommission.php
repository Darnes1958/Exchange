<?php

namespace App\Enums;

enum OutgoingCommission: string
{
    case Deducted = 'deducted';
    case Added = 'added';

    public function label(): string
    {
        return match ($this) {
            self::Deducted => 'مخصومة',
            self::Added => 'مضافة',
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
