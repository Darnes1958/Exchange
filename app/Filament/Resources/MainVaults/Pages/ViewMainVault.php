<?php

namespace App\Filament\Resources\MainVaults\Pages;

use App\Filament\Resources\MainVaults\MainVaultResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMainVault extends ViewRecord
{
    protected static string $resource = MainVaultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
