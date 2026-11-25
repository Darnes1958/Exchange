<?php

namespace App\Filament\Resources\CustodyHandovers\Pages;

use App\Filament\Resources\CustodyHandovers\CustodyHandoverResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustodyHandovers extends ListRecords
{
    protected static string $resource = CustodyHandoverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
