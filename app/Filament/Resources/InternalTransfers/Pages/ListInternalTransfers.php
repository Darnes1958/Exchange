<?php

namespace App\Filament\Resources\InternalTransfers\Pages;

use App\Enums\InternalTransferDirection;
use App\Filament\Resources\InternalTransfers\InternalTransferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInternalTransfers extends ListRecords
{
    protected static string $resource = InternalTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'incoming' => Tab::make('واردة')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('direction', InternalTransferDirection::Incoming->value)),
            'outgoing' => Tab::make('صادرة')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('direction', InternalTransferDirection::Outgoing->value)),
        ];
    }
}
