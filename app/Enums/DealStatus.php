<?php

namespace App\Enums;

enum DealStatus: string
{
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'قائمة',
            self::Cancelled => 'ملغاة',
        };
    }
}
