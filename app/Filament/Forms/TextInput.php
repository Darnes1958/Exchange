<?php

namespace App\Filament\Forms;

use Closure;
use Filament\Forms\Components\TextInput as BaseTextInput;

class TextInput extends BaseTextInput
{
    public function numeric(bool|Closure $condition = true): static
    {
        parent::numeric($condition);

        $this->stripCharacters(fn (self $component): array => $component->isNumeric() ? [','] : []);
        $this->type(fn (self $component): ?string => $component->isNumeric() ? 'text' : null);

        return $this;
    }

    public function getDefaultStateCasts(): array
    {
        if ($this->isNumeric()) {
            $decimals = (int) ($this->getExtraInputAttributes()['data-number-decimals'] ?? 0);

            return [
                new FormattedNumberStateCast(
                    isInteger: $this->isInteger(),
                    decimals: $decimals > 0 ? $decimals : null,
                ),
            ];
        }

        return parent::getDefaultStateCasts();
    }

    public function getExtraInputAttributes(): array
    {
        $attributes = parent::getExtraInputAttributes();

        if ($this->isNumeric()) {
            $attributes['data-number-format'] = '1';
        }

        return $attributes;
    }
}
