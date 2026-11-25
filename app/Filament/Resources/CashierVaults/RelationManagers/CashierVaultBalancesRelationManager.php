<?php

namespace App\Filament\Resources\CashierVaults\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CashierVaultBalancesRelationManager extends RelationManager
{
    protected static string $relationship = 'balances';

    protected static ?string $title = 'رصيد العهدة';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('currency.code')->label('العملة'),
                TextColumn::make('currency.name')->label('الاسم'),
                TextColumn::make('current_balance')->label('الرصيد')->numeric(decimalPlaces: 3),
            ])
            ->headerActions([])
            ->recordActions([]);
    }
}
