<?php

namespace App\Filament\Resources\Currencies;

use App\Filament\Concerns\ExceptCashiers;
use App\Filament\Forms\TextInput;
use App\Filament\Resources\Currencies\Pages\CreateCurrency;
use App\Filament\Resources\Currencies\Pages\EditCurrency;
use App\Filament\Resources\Currencies\Pages\ListCurrencies;
use App\Filament\Resources\Currencies\Pages\ViewCurrency;
use App\Filament\Resources\Currencies\RelationManagers\CurrencyRateChangesRelationManager;
use App\Models\Currency;
use App\Models\CurrencyRateChange;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CurrencyResource extends Resource
{
    use ExceptCashiers;

    protected static ?string $model = Currency::class;

    protected static ?string $navigationLabel = 'العملات';

    protected static ?string $modelLabel = 'عملة';

    protected static ?string $pluralModelLabel = 'العملات';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'الخزائن';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return static::canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManage();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('الرمز')
                ->required()
                ->maxLength(10)
                ->unique(ignoreRecord: true)
                ->live(onBlur: true),
            Flex::make([
                View::make('filament.currency-image')
                    ->viewData(fn (Get $get): array => [
                        'url' => Currency::imageUrlForCode($get('code')),
                    ])
                    ->grow(false)
                    ->hidden(fn (Get $get): bool => blank(Currency::imageUrlForCode($get('code')))),
                TextInput::make('name')
                    ->label('الاسم')
                    ->required()
                    ->maxLength(255),
            ])->verticalAlignment(VerticalAlignment::End),
            TextInput::make('symbol')
                ->label('الرمز المختصر')
                ->maxLength(10),
            TextInput::make('decimal_places')
                ->label('الخانات العشرية')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(6)
                ->default(3)
                ->required(),
            Toggle::make('is_active')
                ->label('نشطة')
                ->default(true),
            Section::make('الأسعار مقابل الدينار الليبي')
                ->description('سعر الشراء وسعر البيع، كاش وصك. يعدّلها المدير فقط، ويُحفظ سجل لكل تعديل.')
                ->visible(fn (string $operation): bool => $operation !== 'create')
                ->columns(2)
                ->components(static::rateFields()),
        ]);
    }

    /**
     * @return list<TextInput>
     */
    public static function rateFields(bool $editable = false): array
    {
        $fields = [];

        foreach ([
            'buy_cash_rate' => 'سعر الشراء كاش',
            'buy_check_rate' => 'سعر الشراء صك',
            'sell_cash_rate' => 'سعر البيع كاش',
            'sell_check_rate' => 'سعر البيع صك',
        ] as $name => $label) {
            $field = TextInput::make($name)
                ->label($label)
                ->numeric()
                ->minValue(0);

            if ($editable) {
                $field->required();
            } else {
                $field->disabled()->dehydrated(false);
            }

            $fields[] = $field;
        }

        return $fields;
    }

    public static function updateRatesAction(): Action
    {
        return Action::make('updateRates')
            ->label('تعديل الأسعار')
            ->icon('heroicon-o-banknotes')
            ->visible(fn (Currency $record): bool => static::canUpdateRates() && ! $record->isLibyanDinar())
            ->modalHeading('تعديل أسعار العملة')
            ->modalSubmitActionLabel('حفظ')
            ->successNotificationTitle('تم حفظ الأسعار وتسجيل التعديل')
            ->fillForm(fn (Currency $record): array => $record->only(Currency::RATE_ATTRIBUTES))
            ->schema(static::rateFields(editable: true))
            ->action(function (Currency $record, array $data): void {
                $record->getConnection()->transaction(function () use ($record, $data): void {
                    $record->update($data);
                });
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('الرمز')->searchable(),
                ViewColumn::make('name')
                    ->label('الاسم')
                    ->view('filament.tables.currency-name')
                    ->searchable(),
                TextColumn::make('symbol')->label('المختصر'),
                TextColumn::make('buy_cash_rate')->label('شراء كاش')->formatStateUsing(fn (mixed $state): string => CurrencyRateChange::formatRate($state)),
                TextColumn::make('buy_check_rate')->label('شراء صك')->formatStateUsing(fn (mixed $state): string => CurrencyRateChange::formatRate($state)),
                TextColumn::make('sell_cash_rate')->label('بيع كاش')->formatStateUsing(fn (mixed $state): string => CurrencyRateChange::formatRate($state)),
                TextColumn::make('sell_check_rate')->label('بيع صك')->formatStateUsing(fn (mixed $state): string => CurrencyRateChange::formatRate($state)),
                IconColumn::make('is_active')->label('نشطة')->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                static::updateRatesAction(),
            ]);
    }

    protected static function canManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageCurrencies();
    }

    protected static function canUpdateRates(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canUpdateCurrencyRates();
    }

    public static function getRelations(): array
    {
        return [
            CurrencyRateChangesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurrencies::route('/'),
            'create' => CreateCurrency::route('/create'),
            'view' => ViewCurrency::route('/{record}'),
            'edit' => EditCurrency::route('/{record}/edit'),
        ];
    }
}
