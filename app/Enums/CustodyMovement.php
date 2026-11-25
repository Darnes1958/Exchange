<?php

namespace App\Enums;

enum CustodyMovement: string
{
    case Issue = 'issue';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Issue => 'تسليم للصراف',
            self::Return => 'إرجاع للخزينة',
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
