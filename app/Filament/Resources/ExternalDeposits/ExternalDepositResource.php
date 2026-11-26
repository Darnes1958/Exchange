<?php

namespace App\Filament\Resources\ExternalDeposits;

use App\Enums\ExternalBankDirection;
use App\Enums\ExternalBankPurpose;
use App\Enums\ExternalSettlement;
use App\Filament\Concerns\ExceptCashiers;
use App\Filament\Resources\ExternalBankMovements\ExternalBankMovementForm;
use App\Filament\Resources\ExternalDeposits\Pages\CreateExternalDeposit;
use App\Filament\Resources\ExternalDeposits\Pages\ListExternalDeposits;
use App\Filament\Resources\ExternalDeposits\Pages\ViewExternalDeposit;
use App\Models\ExternalBankMovement;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ExternalDepositResource extends Resource
{
    use ExceptCashiers;

    protected static ?string $model = ExternalBankMovement::class;

    protected static ?string $navigationLabel = 'إيداع في الحساب الخارجي';

    protected static ?string $modelLabel = 'إيداع خارجي';

    protected static ?string $pluralModelLabel = 'الإيداع في الحساب الخارجي';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static string|\UnitEnum|null $navigationGroup = 'المصارف';

    protected static ?int $navigationSort = 2;

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('direction', ExternalBankDirection::Deposit->value);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(ExternalBankMovementForm::components(ExternalBankDirection::Deposit));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->label('الإشاري')->searchable(),
                TextColumn::make('movement_date')->label('التاريخ')->date('Y-m-d'),
                TextColumn::make('purpose')
                    ->label('النوع')
                    ->formatStateUsing(fn (ExternalBankPurpose|string|null $state): string => $state instanceof ExternalBankPurpose
                        ? $state->label(ExternalBankDirection::Deposit)
                        : (string) $state),
                TextColumn::make('bankAccount.bank_name')->label('المصرف'),
                TextColumn::make('currency.code')->label('العملة'),
                TextColumn::make('amount')->label('المبلغ')->numeric(decimalPlaces: 3),
                TextColumn::make('rate')->label('السعر المقابل')->numeric(decimalPlaces: 6),
                TextColumn::make('counter_amount')->label('المقابل')->numeric(decimalPlaces: 3),
                TextColumn::make('settlement')
                    ->label('طريقة الدفع')
                    ->formatStateUsing(fn (ExternalSettlement|string|null $state): string => $state instanceof ExternalSettlement
                        ? $state->label()
                        : (ExternalSettlement::tryFrom((string) $state)?->label() ?? '')),
                TextColumn::make('mainVault.name')->label('الخزينة'),
                TextColumn::make('localBank.bank_name')->label('المصرف المحلي'),
                TextColumn::make('source.name')->label('المصدر'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExternalDeposits::route('/'),
            'create' => CreateExternalDeposit::route('/create'),
            'view' => ViewExternalDeposit::route('/{record}'),
        ];
    }
}
