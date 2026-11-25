<?php

namespace App\Filament\Resources\MainVaults;

use App\Filament\Concerns\ExceptCashiers;
use App\Filament\Resources\MainVaults\Pages\CreateMainVault;
use App\Filament\Resources\MainVaults\Pages\EditMainVault;
use App\Filament\Resources\MainVaults\Pages\ListMainVaults;
use App\Filament\Resources\MainVaults\Pages\ViewMainVault;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\MainVault;
use App\Models\MainVaultBalance;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MainVaultResource extends Resource
{
    use ExceptCashiers;

    protected static ?string $model = MainVault::class;

    protected static ?string $navigationLabel = 'الخزينة الرئيسية';

    protected static ?string $modelLabel = 'خزينة رئيسية';

    protected static ?string $pluralModelLabel = 'الخزينة الرئيسية';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';

    protected static string|\UnitEnum|null $navigationGroup = 'الخزائن';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        if (CompanySetting::hidesBranchChoice()) {
            return ! MainVault::query()->exists();
        }

        return MainVault::query()->count() < Branch::query()->where('is_active', true)->count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('branch_id')
                ->label('الفرع')
                ->options(fn (): array => Branch::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->default(fn (): mixed => CompanySetting::hidesBranchChoice() ? Branch::query()->orderBy('id')->value('id') : null)
                ->visible(fn (): bool => ! CompanySetting::hidesBranchChoice())
                ->required(fn (): bool => ! CompanySetting::hidesBranchChoice())
                ->dehydrated(fn (): bool => ! CompanySetting::hidesBranchChoice())
                ->native(false)
                ->helperText('خزينة رئيسية واحدة لكل فرع.'),
            TextInput::make('name')
                ->label('اسم الخزينة')
                ->required()
                ->maxLength(255),
            Toggle::make('is_active')
                ->label('نشطة')
                ->default(true),
            Textarea::make('notes')
                ->label('ملاحظات')
                ->columnSpanFull(),
            Repeater::make('balances')
                ->label('أرصدة العملات')
                ->helperText('الرصيد الحالي ناتج الرصيد المنقول وحركات الإيداع والسحب، ولا يُعدَّل يدوياً. الرصيد المنقول يعدّله المبرمج فقط، بطلب من مدير الشركة. سعر التكلفة يُفتح عند إدخال العملة وعند تعديل الرصيد المنقول، وتكلفة الدينار الليبي 1.')
                ->relationship()
                ->addActionLabel('إضافة عملة')
                ->deletable(fn (): bool => static::canAdjustCarriedBalance())
                ->reorderable(false)
                ->columnSpanFull()
                ->table([
                    TableColumn::make('العملة')->markAsRequired(),
                    TableColumn::make('الرصيد المنقول'),
                    TableColumn::make('الرصيد الحالي'),
                    TableColumn::make('سعر التكلفة'),
                ])
                ->components([
                    Select::make('currency_id')
                        ->label('العملة')
                        ->hiddenLabel()
                        ->allowHtml()
                        ->options(fn (): array => Currency::query()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Currency $currency): array => [
                                $currency->id => $currency->decoratedLabel($currency->code.' — '.$currency->name),
                            ])
                            ->all())
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                            if (static::currencyIsLyd($state)) {
                                $set('cost_price', '1');

                                return;
                            }

                            if (static::currencyIsLyd($old)) {
                                $set('cost_price', null);
                            }
                        })
                        ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    TextInput::make('opening_balance')
                        ->label('الرصيد المنقول')
                        ->hiddenLabel()
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->live(onBlur: true)
                        ->disabled(fn (string $operation): bool => $operation === 'view' || ! static::canAdjustCarriedBalance())
                        ->dehydrated(fn (string $operation): bool => $operation !== 'view' && static::canAdjustCarriedBalance())
                        ->required(fn (string $operation): bool => $operation !== 'view' && static::canAdjustCarriedBalance()),
                    TextInput::make('current_balance')
                        ->label('الرصيد الحالي')
                        ->hiddenLabel()
                        ->numeric()
                        ->disabled()
                        ->dehydrated(false)
                        ->default(0),
                    TextInput::make('cost_price')
                        ->label('سعر التكلفة')
                        ->hiddenLabel()
                        ->numeric()
                        ->minValue(0)
                        ->step(0.000001)
                        ->default(fn (Get $get): ?string => static::currencyIsLyd($get('currency_id')) ? '1' : null)
                        ->disabled(fn (string $operation, Get $get, mixed $record): bool => static::costPriceIsLocked($operation, $get, $record))
                        ->dehydrated(fn (string $operation, Get $get, mixed $record): bool => $operation !== 'view' && (static::currencyIsLyd($get('currency_id')) || ! static::costPriceIsLocked($operation, $get, $record)))
                        ->required(fn (string $operation, Get $get, mixed $record): bool => $operation !== 'view' && ! static::costPriceIsLocked($operation, $get, $record)),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('الخزينة')->searchable(),
                TextColumn::make('balances_count')->counts('balances')->label('عدد العملات'),
                IconColumn::make('is_active')->label('نشطة')->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    protected static function canAdjustCarriedBalance(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canAdjustCarriedBalance();
    }

    protected static function currencyIsLyd(mixed $currencyId): bool
    {
        if (blank($currencyId)) {
            return false;
        }

        $code = Currency::query()->whereKey($currencyId)->value('code');

        return strtoupper((string) $code) === 'LYD';
    }

    protected static function costPriceIsLocked(string $operation, Get $get, mixed $record): bool
    {
        if ($operation === 'view' || static::currencyIsLyd($get('currency_id')) || ! static::canAdjustCarriedBalance()) {
            return true;
        }

        $balanceId = $record instanceof MainVaultBalance ? $record->getKey() : $get('id');

        if (blank($balanceId)) {
            return false;
        }

        $stored = MainVaultBalance::query()->whereKey($balanceId)->first(['opening_balance']);

        if (! $stored instanceof MainVaultBalance) {
            return false;
        }

        return round((float) $get('opening_balance'), 3) === round((float) $stored->opening_balance, 3);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMainVaults::route('/'),
            'create' => CreateMainVault::route('/create'),
            'view' => ViewMainVault::route('/{record}'),
            'edit' => EditMainVault::route('/{record}/edit'),
        ];
    }
}
