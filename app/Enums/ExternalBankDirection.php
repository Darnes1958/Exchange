<?php

namespace App\Enums;

enum ExternalBankDirection: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'إيداع',
            self::Withdrawal => 'سحب',
        };
    }
}
