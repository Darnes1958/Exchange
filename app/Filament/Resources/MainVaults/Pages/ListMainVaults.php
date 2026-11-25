<?php

namespace App\Filament\Resources\MainVaults\Pages;

use App\Filament\Resources\MainVaults\MainVaultResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMainVaults extends ListRecords
{
    protected static string $resource = MainVaultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->visible(fn (): bool => MainVaultResource::canCreate()),
        ];
    }
}
