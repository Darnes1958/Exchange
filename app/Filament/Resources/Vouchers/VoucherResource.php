<?php

namespace App\Filament\Resources\Vouchers;

use App\Enums\VoucherType;
use App\Filament\Resources\Vouchers\Pages\CreateVoucher;
use App\Filament\Resources\Vouchers\Pages\EditVoucher;
use App\Filament\Resources\Vouchers\Pages\ListVouchers;
use App\Filament\Resources\Vouchers\Pages\ViewVoucher;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Voucher;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VoucherResource extends Resource
{
    protected static ?string $model = Voucher::class;

    protected static ?string $navigationLabel = 'إيصالات القبض والدفع';

    protected static ?string $modelLabel = 'إيصال';

    protected static ?string $pluralModelLabel = 'إيصالات القبض والدفع';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'الزبائن';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label('النوع')
                ->options(VoucherType::options())
                ->required()
                ->native(false),
            Select::make('customer_id')
                ->label('الزبون')
                ->options(fn (): array => Customer::query()
                    ->where('is_general', false)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required()
                ->helperText('الزبون عام يسدد بالكامل عند البيع والشراء، ولا تُسجل له إيصالات.'),
            Select::make('currency_id')
                ->label('العملة')
                ->allowHtml()
                ->options(fn (): array => Currency::query()
                    ->orderBy('code')
                    ->get()
                    ->mapWithKeys(fn (Currency $currency): array => [
                        $currency->id => $currency->decoratedLabel($currency->code.' — '.$currency->name),
                    ])
                    ->all())
                ->searchable()
                ->required(),
            TextInput::make('amount')
                ->label('المبلغ')
                ->numeric()
                ->minValue(0.001)
                ->required(),
            DatePicker::make('voucher_date')
                ->label('التاريخ')
                ->default(now())
                ->required()
                ->native(false),
            Textarea::make('notes')
                ->label('ملاحظات')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('الرقم'),
                TextColumn::make('voucher_date')->label('التاريخ')->date('Y-m-d'),
                TextColumn::make('type')
                    ->label('النوع')
                    ->formatStateUsing(fn (VoucherType|string|null $state): string => $state instanceof VoucherType ? $state->label() : (string) $state),
                TextColumn::make('customer.name')->label('الزبون')->searchable(),
                TextColumn::make('currency.code')->label('العملة'),
                TextColumn::make('amount')->label('المبلغ')->numeric(decimalPlaces: 3),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVouchers::route('/'),
            'create' => CreateVoucher::route('/create'),
            'view' => ViewVoucher::route('/{record}'),
            'edit' => EditVoucher::route('/{record}/edit'),
        ];
    }
}
