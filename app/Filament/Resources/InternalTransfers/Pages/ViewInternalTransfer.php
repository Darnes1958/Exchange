<?php

namespace App\Filament\Resources\InternalTransfers\Pages;

use App\Filament\Resources\InternalTransfers\InternalTransferResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInternalTransfer extends ViewRecord
{
    protected static string $resource = InternalTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            InternalTransferResource::receiveAction(),
            InternalTransferResource::cancelAction(),
        ];
    }
}
