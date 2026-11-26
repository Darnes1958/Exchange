<?php

namespace App\Filament\Resources\ExternalWithdrawals;

use App\Enums\ExternalBankDirection;
use App\Enums\ExternalBankPurpose;
use App\Enums\ExternalSettlement;
use App\Filament\Concerns\ExceptCashiers;
use App\Filament\Resources\ExternalBankMovements\ExternalBankMovementForm;
use App\Filament\Resources\ExternalWithdrawals\Pages\CreateExternalWithdrawal;
use App\Filament\Resources\ExternalWithdrawals\Pages\ListExternalWithdrawals;
use App\Filament\Resources\ExternalWithdrawals\Pages\ViewExternalWithdrawal;
use App\Models\ExternalBankMovement;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ExternalWithdrawalResource extends Resource
{
    use ExceptCashiers;

    protected static ?string $model = ExternalBankMovement::class;

    protected static ?string $navigationLabel = 'السحب من الحساب الخارجي';

    protected static ?string $modelLabel = 'سحب خارجي';

    protected static ?string $pluralModelLabel = 'السحب من الحساب الخارجي';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static string|\UnitEnum|null $navigationGroup = 'المصارف';

    protected static ?int $navigationSort = 3;

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
        return parent::getEloquentQuery()->where('direction', ExternalBankDirection::Withdrawal->value);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(ExternalBankMovementForm::components(ExternalBankDirection::Withdrawal));
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
                        ? $state->label(ExternalBankDirection::Withdrawal)
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
                TextColumn::make('beneficiary.name')->label('المستفيد'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExternalWithdrawals::route('/'),
            'create' => CreateExternalWithdrawal::route('/create'),
            'view' => ViewExternalWithdrawal::route('/{record}'),
        ];
    }
}
