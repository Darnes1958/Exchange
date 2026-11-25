<?php

namespace App\Filament\Resources\ExchangeDeals\Pages;

use App\Filament\Resources\ExchangeDeals\ExchangeDealResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewExchangeDeal extends ViewRecord
{
    protected static string $resource = ExchangeDealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ExchangeDealResource::cancelAction(),
        ];
    }
}
