<?php

namespace App\Filament\Resources\CashierVaults\Pages;

use App\Filament\Resources\CashierVaults\CashierVaultResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCashierVault extends ViewRecord
{
    protected static string $resource = CashierVaultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
