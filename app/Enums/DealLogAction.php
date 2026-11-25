<?php

namespace App\Enums;

enum DealLogAction: string
{
    case Edit = 'edit';
    case Cancel = 'cancel';

    public function label(): string
    {
        return match ($this) {
            self::Edit => 'تعديل',
            self::Cancel => 'إلغاء',
        };
    }
}
