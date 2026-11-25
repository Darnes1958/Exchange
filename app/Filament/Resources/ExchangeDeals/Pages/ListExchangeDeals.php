<?php

namespace App\Filament\Resources\ExchangeDeals\Pages;

use App\Filament\Resources\ExchangeDeals\ExchangeDealResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExchangeDeals extends ListRecords
{
    protected static string $resource = ExchangeDealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
