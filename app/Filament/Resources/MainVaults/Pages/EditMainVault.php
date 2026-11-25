<?php

namespace App\Filament\Resources\MainVaults\Pages;

use App\Filament\Resources\MainVaults\MainVaultResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMainVault extends EditRecord
{
    protected static string $resource = MainVaultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
