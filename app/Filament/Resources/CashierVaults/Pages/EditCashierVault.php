<?php

namespace App\Filament\Resources\CashierVaults\Pages;

use App\Filament\Resources\CashierVaults\CashierVaultResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCashierVault extends EditRecord
{
    protected static string $resource = CashierVaultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
