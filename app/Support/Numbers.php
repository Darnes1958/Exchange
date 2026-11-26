<?php

namespace App\Support;

class Numbers
{
    public static function format(mixed $value, ?int $decimals = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $raw = str_replace([',', ' '], '', trim((string) $value));

        if ($raw === '' || ! is_numeric($raw)) {
            return (string) $value;
        }

        if ($decimals === null) {
            $decimals = str_contains($raw, '.') ? strlen(explode('.', $raw, 2)[1]) : 0;
        }

        return number_format((float) $raw, $decimals, '.', ',');
    }
}
