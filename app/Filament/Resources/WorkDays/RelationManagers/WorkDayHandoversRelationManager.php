<?php

namespace App\Filament\Resources\WorkDays\RelationManagers;

use App\Enums\CustodyMovement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkDayHandoversRelationManager extends RelationManager
{
    protected static string $relationship = 'handovers';

    protected static ?string $title = 'حركات العهد';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('الرقم'),
                TextColumn::make('type')
                    ->label('النوع')
                    ->formatStateUsing(fn (CustodyMovement|string|null $state): string => $state instanceof CustodyMovement ? $state->label() : (string) $state),
                TextColumn::make('cashierVault.name')->label('الصراف'),
                TextColumn::make('mainVault.name')->label('الخزينة الرئيسية'),
                TextColumn::make('user_name')->label('بواسطة'),
                TextColumn::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([])
            ->recordActions([]);
    }
}
