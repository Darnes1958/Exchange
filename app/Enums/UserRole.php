<?php

namespace App\Enums;

enum UserRole: string
{
    case Prog = 'prog';
    case SupperAdmin = 'SupperAdmin';
    case Admin = 'admin';
    case Cashier = 'cashier';

    public function label(): string
    {
        return match ($this) {
            self::Prog => 'مبرمج',
            self::SupperAdmin => 'مدير عام',
            self::Admin => 'مدير',
            self::Cashier => 'صراف',
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

    public function canBeAssignedBy(?self $actorRole): bool
    {
        if (! $actorRole instanceof self) {
            return false;
        }

        return match ($this) {
            self::Prog => false,
            self::SupperAdmin => $actorRole === self::Prog,
            self::Admin => in_array($actorRole, [self::Prog, self::SupperAdmin], true),
            self::Cashier => in_array($actorRole, [self::Prog, self::SupperAdmin, self::Admin], true),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function assignableOptions(?self $actorRole): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case->canBeAssignedBy($actorRole)) {
                $options[$case->value] = $case->label();
            }
        }

        return $options;
    }
}
