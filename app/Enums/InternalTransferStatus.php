<?php

namespace App\Enums;

enum InternalTransferStatus: string
{
    case Pending = 'pending';
    case Received = 'received';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'معلقة',
            self::Received => 'مستلمة',
            self::Delivered => 'مسلّمة',
            self::Cancelled => 'ملغاة',
        };
    }
}
