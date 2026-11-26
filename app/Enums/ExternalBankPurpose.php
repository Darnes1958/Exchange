<?php

namespace App\Enums;

enum ExternalBankPurpose: string
{
    case Funds = 'funds';
    case Currency = 'currency';

    public function label(ExternalBankDirection $direction): string
    {
        return match ($direction) {
            ExternalBankDirection::Deposit => match ($this) {
                self::Funds => 'إيداع أموال',
                self::Currency => 'شراء عملة',
            },
            ExternalBankDirection::Withdrawal => match ($this) {
                self::Funds => 'سحب أموال',
                self::Currency => 'بيع عملة',
            },
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(ExternalBankDirection $direction): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label($direction);
        }

        return $options;
    }
}
