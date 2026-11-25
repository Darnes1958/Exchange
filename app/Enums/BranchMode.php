<?php

namespace App\Enums;

enum BranchMode: string
{
    case Single = 'single';
    case Multiple = 'multiple';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'فرع واحد',
            self::Multiple => 'متعددة الفروع',
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
