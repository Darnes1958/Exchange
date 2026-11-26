<?php

namespace App\Filament\Resources\ExternalWithdrawals\Pages;

use App\Filament\Resources\ExternalWithdrawals\ExternalWithdrawalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExternalWithdrawals extends ListRecords
{
    protected static string $resource = ExternalWithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
