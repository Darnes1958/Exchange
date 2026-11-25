<?php

namespace App\Filament\Resources\CashierVaults\Pages;

use App\Filament\Resources\CashierVaults\CashierVaultResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCashierVaults extends ListRecords
{
    protected static string $resource = CashierVaultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
