<?php

namespace App\Filament\Resources\ExchangeDeals;

use App\Enums\DealPayment;
use App\Enums\DealStatus;
use App\Enums\TradeSide;
use App\Enums\UserRole;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\ExchangeDeals\Pages\CreateExchangeDeal;
use App\Filament\Resources\ExchangeDeals\Pages\EditExchangeDeal;
use App\Filament\Resources\ExchangeDeals\Pages\ListExchangeDeals;
use App\Filament\Resources\ExchangeDeals\Pages\ViewExchangeDeal;
use App\Filament\Resources\ExchangeDeals\RelationManagers\ExchangeDealLogsRelationManager;
use App\Models\CashierVault;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\ExchangeDeal;
use App\Models\MainVault;
use App\Models\MainVaultBalance;
use App\Models\User;
use App\Models\WorkDay;
use App\Services\ExchangeTradeService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ExchangeDealResource extends Resource
{
    protected static ?string $model = ExchangeDeal::class;

    protected static ?string $navigationLabel = 'البيع والشراء';

    protected static ?string $modelLabel = 'عملية';

    protected static ?string $pluralModelLabel = 'البيع والشراء';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'الصرافة';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->role === UserRole::Cashier;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof ExchangeDeal && static::canRevise($record);
    }

    public static function canRevise(ExchangeDeal $deal): bool
    {
        $user = Auth::user();
        $day = WorkDay::current();

        return $user instanceof User
            && $user->role === UserRole::Cashier
            && $deal->type === TradeSide::Sell
            && $deal->status !== DealStatus::Cancelled
            && $day instanceof WorkDay
            && (int) $day->id === (int) $deal->work_day_id
            && (int) CashierVault::query()->where('user_id', $user->id)->where('is_active', true)->value('id') === (int) $deal->cashier_vault_id;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $day = WorkDay::current();

        if (! $day instanceof WorkDay) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('work_day_id', $day->id);
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('إلغاء')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('إلغاء عملية البيع')
            ->modalDescription('تُعاد العملة إلى العهدة ويُخصم مقابل الدينار، ويُحفظ سجل الإلغاء.')
            ->visible(fn (ExchangeDeal $record): bool => static::canRevise($record))
            ->action(function (ExchangeDeal $record): void {
                $user = Auth::user();

                try {
                    ExchangeTradeService::cancelSale($record, $user instanceof User ? $user : null);
                    Notification::make()->title('أُلغيت عملية البيع')->success()->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(collect($exception->errors())->flatten()->first())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Flex::make([
                Radio::make('type')
                    ->label('النوع')
                    ->options(TradeSide::options())
                    ->default(TradeSide::Sell->value)
                    ->inline()
                    ->live()
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        static::fillRate($get, $set);
                    }),
                Radio::make('payment')
                    ->label('الدفع')
                    ->options(DealPayment::options())
                    ->default(DealPayment::Cash->value)
                    ->inline()
                    ->live()
                    ->required()
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        static::fillRate($get, $set);
                    }),
            ])->columnSpanFull(),
            Select::make('customer_id')
                ->label('الزبون')
                ->relationship('customer', 'name', fn (Builder $query): Builder => $query->orderBy('name'))
                ->default(fn (): mixed => Customer::query()->where('is_general', true)->value('id') ?? 1)
                ->searchable()
                ->preload()
                ->required()
                ->manageOptionForm(fn (): array => CustomerResource::formFields())
                ->createOptionAction(fn (Action $action): Action => $action
                    ->label('زبون جديد')
                    ->modalHeading('زبون جديد')
                    ->modalSubmitActionLabel('حفظ'))
                ->editOptionAction(fn (Action $action): Action => $action
                    ->label('تعديل الزبون')
                    ->modalHeading('تعديل الزبون')
                    ->modalSubmitActionLabel('حفظ')),
            Select::make('currency_id')
                ->label('العملة')
                ->allowHtml()
                ->options(fn (): array => Currency::query()
                    ->where('is_active', true)
                    ->where('code', '!=', 'LYD')
                    ->orderBy('code')
                    ->get()
                    ->mapWithKeys(fn (Currency $currency): array => [
                        $currency->id => $currency->decoratedLabel($currency->code.' — '.$currency->name),
                    ])
                    ->all())
                ->searchable()
                ->live()
                ->default(fn (): mixed => Currency::query()->where('code', 'USD')->value('id'))
                ->required()
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    static::fillRate($get, $set);
                }),
            TextInput::make('quantity')
                ->label('الكمية')
                ->numeric()
                ->minValue(0.001)
                ->live()
                ->required()
                ->autofocus()
                ->extraInputAttributes(['data-initial-focus' => '1']),
            TextInput::make('rate')
                ->label('السعر')
                ->numeric()
                ->minValue(0.000001)
                ->live()
                ->required()
                ->default(fn (): mixed => Currency::query()->where('code', 'USD')->value('sell_cash_rate'))
                ->helperText('يأتي من سعر العملة حسب البيع أو الشراء وكاش أو صك، ويمكن تغييره لهذه العملية.'),
            Placeholder::make('lyd_preview')
                ->label('مقابل الدينار')
                ->visible(fn (string $operation): bool => $operation === 'create')
                ->content(fn (Get $get): string => number_format(static::lydPreview($get), 3, '.', '')),
            Placeholder::make('cost_preview')
                ->label('سعر التكلفة')
                ->visible(fn (Get $get, string $operation): bool => $operation === 'create' && (string) $get('type') === TradeSide::Sell->value)
                ->content(fn (Get $get): string => static::branchCost($get('currency_id')))
                ->helperText('من الخزينة الرئيسية للفرع. رصيد الخزينة لا يتغير.'),
            Placeholder::make('profit_preview')
                ->label('الربح أو الخسارة')
                ->visible(fn (Get $get, string $operation): bool => $operation === 'create' && (string) $get('type') === TradeSide::Sell->value)
                ->content(function (Get $get): string {
                    $cost = (float) static::branchCost($get('currency_id'));
                    $profit = round(((float) ($get('rate') ?? 0) - $cost) * (float) ($get('quantity') ?? 0), 3);

                    return number_format($profit, 3, '.', '');
                }),
            Placeholder::make('buy_cost_notice')
                ->label('تكلفة الخزينة')
                ->visible(fn (Get $get, string $operation): bool => $operation === 'create' && (string) $get('type') === TradeSide::Buy->value)
                ->content('عند الحفظ يُعاد احتساب متوسط تكلفة هذه العملة في الخزينة الرئيسية من الكمية الجديدة ورصيد الخزينة ورصيد عهد اليوم.'),
            TextInput::make('lyd_amount')
                ->label('مقابل الدينار')
                ->visible(fn (string $operation): bool => $operation !== 'create'),
            TextInput::make('cost_price')
                ->label('سعر التكلفة')
                ->visible(fn (string $operation): bool => $operation !== 'create'),
            TextInput::make('profit')
                ->label('الربح أو الخسارة')
                ->visible(fn (string $operation): bool => $operation !== 'create'),
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
                TextColumn::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i'),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->formatStateUsing(fn (DealStatus|string|null $state): string => $state instanceof DealStatus ? $state->label() : (string) $state)
                    ->color(fn (DealStatus|string|null $state): ?string => ($state === DealStatus::Cancelled || $state === DealStatus::Cancelled->value) ? 'danger' : null),
                TextColumn::make('type')
                    ->label('النوع')
                    ->formatStateUsing(fn (TradeSide|string|null $state): string => $state instanceof TradeSide ? $state->label() : (string) $state)
                    ->color(fn (TradeSide|string|null $state): string => match (true) {
                        $state === TradeSide::Sell || $state === TradeSide::Sell->value => 'success',
                        $state === TradeSide::Buy || $state === TradeSide::Buy->value => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('payment')
                    ->label('الدفع')
                    ->formatStateUsing(fn (DealPayment|string|null $state): string => $state instanceof DealPayment ? $state->label() : (string) $state),
                TextColumn::make('customer.name')->label('الزبون'),
                TextColumn::make('currency.code')->label('العملة'),
                TextColumn::make('quantity')->label('الكمية')->numeric(decimalPlaces: 3),
                TextColumn::make('rate')->label('السعر')->numeric(decimalPlaces: 6),
                TextColumn::make('lyd_amount')
                    ->label('الدينار')
                    ->numeric(decimalPlaces: 3)
                    ->summarize(static::dayTotal(3)),
                TextColumn::make('cost_price')->label('التكلفة')->numeric(decimalPlaces: 6),
                TextColumn::make('profit')
                    ->label('الربح')
                    ->numeric(decimalPlaces: 3)
                    ->summarize(static::dayTotal(3)),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                static::cancelAction(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ExchangeDealLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExchangeDeals::route('/'),
            'create' => CreateExchangeDeal::route('/create'),
            'view' => ViewExchangeDeal::route('/{record}'),
            'edit' => EditExchangeDeal::route('/{record}/edit'),
        ];
    }

    private static function dayTotal(int $decimals): Sum
    {
        return Sum::make()
            ->hiddenLabel()
            ->numeric(decimalPlaces: $decimals)
            ->query(fn ($query) => $query->where('status', '!=', DealStatus::Cancelled->value));
    }

    public static function fillRate(Get $get, Set $set): void
    {
        $currency = Currency::query()->find($get('currency_id'));

        if (! $currency instanceof Currency) {
            return;
        }

        $side = TradeSide::tryFrom((string) $get('type')) ?? TradeSide::Sell;
        $payment = DealPayment::tryFrom((string) $get('payment')) ?? DealPayment::Cash;
        $set('rate', $currency->{match (true) {
            $side === TradeSide::Buy && $payment === DealPayment::Check => 'buy_check_rate',
            $side === TradeSide::Buy => 'buy_cash_rate',
            $payment === DealPayment::Check => 'sell_check_rate',
            default => 'sell_cash_rate',
        }});
    }

    public static function lydPreview(Get $get): float
    {
        return round((float) ($get('quantity') ?? 0) * (float) ($get('rate') ?? 0), 3);
    }

    public static function branchCost(mixed $currencyId): string
    {
        $user = Auth::user();

        if (! $user instanceof User || blank($currencyId)) {
            return '0.000000';
        }

        $branchId = CashierVault::query()->where('user_id', $user->id)->where('is_active', true)->value('branch_id');

        if (blank($branchId)) {
            return '0.000000';
        }

        $mainVaultId = MainVault::query()->where('branch_id', $branchId)->where('is_active', true)->value('id');

        if (blank($mainVaultId)) {
            return '0.000000';
        }

        $cost = MainVaultBalance::query()
            ->where('main_vault_id', $mainVaultId)
            ->where('currency_id', $currencyId)
            ->value('cost_price');

        return number_format((float) $cost, 6, '.', '');
    }
}
