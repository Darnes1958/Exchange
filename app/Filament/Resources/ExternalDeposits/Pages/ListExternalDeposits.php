<?php

namespace App\Filament\Resources\ExternalDeposits\Pages;

use App\Filament\Resources\ExternalDeposits\ExternalDepositResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExternalDeposits extends ListRecords
{
    protected static string $resource = ExternalDepositResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
