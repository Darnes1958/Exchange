<?php

namespace App\Filament\Resources\BankAccounts;

use App\Enums\BankAccountType;
use App\Filament\Concerns\ExceptCashiers;
use App\Filament\Resources\BankAccounts\Pages\CreateBankAccount;
use App\Filament\Resources\BankAccounts\Pages\EditBankAccount;
use App\Filament\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Resources\BankAccounts\Pages\ViewBankAccount;
use App\Models\BankAccount;
use App\Models\Currency;
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
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BankAccountResource extends Resource
{
    use ExceptCashiers;

    protected static ?string $model = BankAccount::class;

    protected static ?string $navigationLabel = 'الحسابات المصرفية';

    protected static ?string $modelLabel = 'حساب مصرفي';

    protected static ?string $pluralModelLabel = 'الحسابات المصرفية';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|\UnitEnum|null $navigationGroup = 'المصارف';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('bank_name')
                ->label('اسم المصرف')
                ->required()
                ->maxLength(255),
            TextInput::make('account_number')
                ->label('رقم الحساب')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            Select::make('type')
                ->label('النوع')
                ->options(BankAccountType::options())
                ->default(BankAccountType::Local->value)
                ->required()
                ->native(false)
                ->live(),
            Toggle::make('is_active')
                ->label('نشط')
                ->default(true),
            Textarea::make('notes')
                ->label('ملاحظات')
                ->columnSpanFull(),
            Repeater::make('balances')
                ->label('أرصدة العملات')
                ->helperText('الرصيد الحالي ناتج الرصيد المنقول وحركات الإيداع والسحب، ولا يُعدَّل يدوياً. الرصيد المنقول يعدّله المبرمج فقط، بطلب من مدير الشركة. الحساب المحلي بالدينار الليبي، والحساب الأجنبي بالعملات الأجنبية.')
                ->relationship()
                ->addActionLabel('إضافة عملة')
                ->deletable(fn (): bool => static::canAdjustCarriedBalance())
                ->reorderable(false)
                ->columnSpanFull()
                ->table([
                    TableColumn::make('العملة')->markAsRequired(),
                    TableColumn::make('الرصيد المنقول'),
                    TableColumn::make('الرصيد الحالي'),
                ])
                ->components([
                    Select::make('currency_id')
                        ->label('العملة')
                        ->hiddenLabel()
                        ->allowHtml()
                        ->options(function (Get $get): array {
                            $type = $get('../../type');
                            $typeValue = $type instanceof BankAccountType ? $type->value : $type;
                            $isLocal = $typeValue !== BankAccountType::Foreign->value;

                            return Currency::query()
                                ->when(
                                    $isLocal,
                                    fn ($query) => $query->where('code', 'LYD'),
                                    fn ($query) => $query->where('code', '!=', 'LYD'),
                                )
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (Currency $currency): array => [
                                    $currency->id => $currency->decoratedLabel($currency->code.' — '.$currency->name),
                                ])
                                ->all();
                        })
                        ->searchable()
                        ->required()
                        ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    TextInput::make('opening_balance')
                        ->label('الرصيد المنقول')
                        ->hiddenLabel()
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
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
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bank_name')->label('المصرف')->searchable(),
                TextColumn::make('account_number')->label('رقم الحساب')->searchable(),
                TextColumn::make('type')
                    ->label('النوع')
                    ->formatStateUsing(fn (BankAccountType|string|null $state): string => $state instanceof BankAccountType ? $state->label() : (string) $state),
                TextColumn::make('balances_count')->counts('balances')->label('عدد العملات'),
                IconColumn::make('is_active')->label('نشط')->boolean(),
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

    public static function getPages(): array
    {
        return [
            'index' => ListBankAccounts::route('/'),
            'create' => CreateBankAccount::route('/create'),
            'view' => ViewBankAccount::route('/{record}'),
            'edit' => EditBankAccount::route('/{record}/edit'),
        ];
    }
}
