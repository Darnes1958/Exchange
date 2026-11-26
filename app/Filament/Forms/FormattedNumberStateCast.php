<?php

namespace App\Filament\Forms;

use App\Support\Numbers;
use Filament\Schemas\Components\StateCasts\Contracts\StateCast;

class FormattedNumberStateCast implements StateCast
{
    public function __construct(
        protected bool $isInteger = false,
        protected ?int $decimals = null,
    ) {}

    public function get(mixed $state): int|float|null
    {
        if (is_string($state)) {
            $state = str_replace([',', ' '], '', $state);
        }

        if (blank($state)) {
            return null;
        }

        return $this->isInteger ? intval($state) : floatval($state);
    }

    public function set(mixed $state): mixed
    {
        if (blank($state) && $state !== 0 && $state !== 0.0 && $state !== '0') {
            return null;
        }

        $plain = is_string($state) ? str_replace([',', ' '], '', $state) : $state;

        if (! is_numeric($plain)) {
            return $state;
        }

        return Numbers::format($state, $this->isInteger ? 0 : $this->decimals);
    }
}
