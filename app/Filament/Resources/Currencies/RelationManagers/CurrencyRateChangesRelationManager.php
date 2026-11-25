<?php

namespace App\Filament\Resources\Currencies\RelationManagers;

use App\Models\CurrencyRateChange;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CurrencyRateChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'rateChanges';

    protected static ?string $title = 'سجل تعديل الأسعار';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('user_name')->label('المدير'),
                TextColumn::make('buy_cash_rate_after')
                    ->label('شراء كاش')
                    ->state(fn (CurrencyRateChange $record): string => $record->rateChangeText('buy_cash_rate')),
                TextColumn::make('buy_check_rate_after')
                    ->label('شراء صك')
                    ->state(fn (CurrencyRateChange $record): string => $record->rateChangeText('buy_check_rate')),
                TextColumn::make('sell_cash_rate_after')
                    ->label('بيع كاش')
                    ->state(fn (CurrencyRateChange $record): string => $record->rateChangeText('sell_cash_rate')),
                TextColumn::make('sell_check_rate_after')
                    ->label('بيع صك')
                    ->state(fn (CurrencyRateChange $record): string => $record->rateChangeText('sell_check_rate')),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([])
            ->recordActions([]);
    }
}
