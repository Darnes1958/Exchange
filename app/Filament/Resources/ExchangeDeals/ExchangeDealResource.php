<?php

namespace App\Filament\Resources\ExchangeDeals;

use App\Enums\DealPayment;
use App\Enums\DealStatus;
use App\Enums\TradeSide;
use App\Enums\UserRole;
use App\Filament\Forms\TextInput;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\ExchangeDeals\Pages\CreateExchangeDeal;
use App\Filament\Resources\ExchangeDeals\Pages\EditExchangeDeal;
use App\Filament\Resources\ExchangeDeals\Pages\ListExchangeDeals;
use App\Filament\Resources\ExchangeDeals\Pages\ViewExchangeDeal;
use App\Filament\Resources\ExchangeDeals\RelationManagers\ExchangeDealLogsRelationManager;
use App\Models\BankAccount;
use App\Models\CashierVault;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\ExchangeDeal;
use App\Models\User;
use App\Models\WorkDay;
use App\Services\ExchangeTradeService;
use App\Support\Numbers;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
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
            View::make('filament.deal-summary')
                ->viewData(fn (Get $get): array => [
                    'rate' => Numbers::format($get('rate') ?? 0, 3),
                    'total' => Numbers::format(static::lydPreview($get), 3),
                ])
                ->columnSpanFull(),
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
                        if ((string) $get('type') !== TradeSide::Sell->value) {
                            $set('bank_account_id', null);
                        }

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
                        if ((string) $get('payment') !== DealPayment::Check->value) {
                            $set('bank_account_id', null);
                        }

                        static::fillRate($get, $set);
                    }),
            ])->columnSpanFull(),
            Grid::make(2)
                ->columnSpanFull()
                ->schema([
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
                    Select::make('bank_account_id')
                        ->label('الحساب المصرفي')
                        ->options(fn (): array => BankAccount::query()
                            ->where('is_active', true)
                            ->orderBy('bank_name')
                            ->get()
                            ->mapWithKeys(fn (BankAccount $account): array => [
                                $account->id => $account->label(),
                            ])
                            ->all())
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get): bool => (string) $get('type') === TradeSide::Sell->value && (string) $get('payment') === DealPayment::Check->value)
                        ->required(fn (Get $get): bool => (string) $get('type') === TradeSide::Sell->value && (string) $get('payment') === DealPayment::Check->value)
                        ->dehydrated(fn (Get $get): bool => (string) $get('type') === TradeSide::Sell->value && (string) $get('payment') === DealPayment::Check->value)
                        ->disabled(fn (string $operation): bool => $operation === 'view')
                        ->afterStateUpdatedJs(<<<'JS'
                            setTimeout(() => {
                                const quantity = document.querySelector('[data-initial-focus]');
                                if (! (quantity instanceof HTMLElement) || quantity.disabled) {
                                    return;
                                }
                                quantity.focus();
                                if (typeof quantity.select === 'function') {
                                    quantity.select();
                                }
                            }, 50);
                        JS),
                ]),
            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    Select::make('currency_id')
                        ->label('العملة')
                        ->hiddenLabel()
                        ->prefix('العملة')
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
                        ->hiddenLabel()
                        ->prefix('الكمية')
                        ->numeric()
                        ->minValue(0.001)
                        ->step(0.001)
                        ->live()
                        ->required()
                        ->autofocus()
                        ->extraInputAttributes(['data-initial-focus' => '1', 'data-number-decimals' => '3']),
                    TextInput::make('rate')
                        ->label('السعر')
                        ->hiddenLabel()
                        ->prefix('السعر')
                        ->numeric()
                        ->minValue(0.001)
                        ->step(0.001)
                        ->live()
                        ->required()
                        ->default(fn (): mixed => Numbers::format(Currency::query()->where('code', 'USD')->value('sell_cash_rate'), 3))
                        ->extraInputAttributes(['data-number-decimals' => '3']),
                ]),
            TextInput::make('notes')
                ->label('ملاحظات')
                ->maxLength(255)
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
                TextColumn::make('bankAccount.bank_name')->label('الحساب')->placeholder('—'),
                TextColumn::make('currency.code')->label('العملة'),
                TextColumn::make('quantity')->label('الكمية')->numeric(decimalPlaces: 3),
                TextColumn::make('rate')->label('السعر')->numeric(decimalPlaces: 3),
                TextColumn::make('lyd_amount')
                    ->label('الدينار')
                    ->numeric(decimalPlaces: 3)
                    ->summarize(static::dayTotal(3)),
                TextColumn::make('cost_price')->label('التكلفة')->numeric(decimalPlaces: 3),
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
        $set('rate', Numbers::format($currency->{match (true) {
            $side === TradeSide::Buy && $payment === DealPayment::Check => 'buy_check_rate',
            $side === TradeSide::Buy => 'buy_cash_rate',
            $payment === DealPayment::Check => 'sell_check_rate',
            default => 'sell_cash_rate',
        }}, 3));
    }

    public static function lydPreview(Get $get): float
    {
        return round(static::numericValue($get('quantity')) * static::numericValue($get('rate')), 3);
    }

    private static function numericValue(mixed $value): float
    {
        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        return (float) $value;
    }
}
