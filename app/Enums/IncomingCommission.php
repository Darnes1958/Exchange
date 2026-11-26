<?php

namespace App\Enums;

enum IncomingCommission: string
{
    case Deducted = 'deducted';
    case Prepaid = 'prepaid';

    public function label(): string
    {
        return match ($this) {
            self::Deducted => 'مخصومة من المبلغ',
            self::Prepaid => 'مدفوعة سلفاً',
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
