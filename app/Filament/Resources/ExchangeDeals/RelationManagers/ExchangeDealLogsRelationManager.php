<?php

namespace App\Filament\Resources\ExchangeDeals\RelationManagers;

use App\Enums\DealLogAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExchangeDealLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    protected static ?string $title = 'سجل التعديل والإلغاء';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i'),
                TextColumn::make('action')
                    ->label('الإجراء')
                    ->formatStateUsing(fn (DealLogAction|string|null $state): string => $state instanceof DealLogAction ? $state->label() : (string) $state),
                TextColumn::make('details')->label('التفاصيل')->wrap(),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([])
            ->recordActions([]);
    }
}
