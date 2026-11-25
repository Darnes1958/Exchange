<?php

namespace App\Filament\Resources\CustodyHandovers;

use App\Enums\CustodyMovement;
use App\Filament\Concerns\ExceptCashiers;
use App\Filament\Resources\CustodyHandovers\Pages\CreateCustodyHandover;
use App\Filament\Resources\CustodyHandovers\Pages\ListCustodyHandovers;
use App\Filament\Resources\CustodyHandovers\Pages\ViewCustodyHandover;
use App\Models\CashierVault;
use App\Models\Currency;
use App\Models\CustodyHandover;
use App\Models\MainVault;
use App\Models\WorkDay;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CustodyHandoverResource extends Resource
{
    use ExceptCashiers;

    protected static ?string $model = CustodyHandover::class;

    protected static ?string $navigationLabel = 'العهد المالية';

    protected static ?string $modelLabel = 'عهدة';

    protected static ?string $pluralModelLabel = 'العهد المالية';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|\UnitEnum|null $navigationGroup = 'الخزائن';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return WorkDay::current() instanceof WorkDay;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('work_day_notice')
                ->label('العمل اليومي')
                ->content(function (): string {
                    $workDay = WorkDay::current();

                    if (! $workDay instanceof WorkDay) {
                        return 'لا يوجد عمل يومي مفتوح. افتتح العمل اليومي قبل تسليم العهد أو إرجاعها.';
                    }

                    return 'العمل اليومي مفتوح بتاريخ '.$workDay->work_date?->format('Y-m-d').'.';
                }),
            Select::make('type')
                ->label('الحركة')
                ->options(CustodyMovement::options())
                ->default(CustodyMovement::Issue->value)
                ->required()
                ->native(false),
            Select::make('cashier_vault_id')
                ->label('الصراف')
                ->options(fn (): array => CashierVault::query()
                    ->where('is_active', true)
                    ->with('user')
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (CashierVault $vault): array => [
                        $vault->id => $vault->name.($vault->user ? ' — '.$vault->user->name : ''),
                    ])
                    ->all())
                ->searchable()
                ->required()
                ->live(),
            Placeholder::make('branch_vault')
                ->label('الخزينة الرئيسية')
                ->visible(fn (string $operation): bool => $operation === 'create')
                ->content(function (Get $get): string {
                    if (blank($get('cashier_vault_id'))) {
                        return 'تُحدد من فرع الصراف. الخصم لا يتجاوز رصيد خزينته.';
                    }

                    $branchId = CashierVault::query()->whereKey($get('cashier_vault_id'))->value('branch_id');
                    $name = MainVault::query()->where('branch_id', $branchId)->value('name');

                    return $name
                        ? $name.' — يُخصم المبلغ منها ولا يتجاوز رصيدها، ويُعاد إليها عند الإرجاع.'
                        : 'لا توجد خزينة رئيسية لهذا الفرع.';
                }),
            Select::make('main_vault_id')
                ->label('الخزينة الرئيسية')
                ->options(fn (): array => MainVault::query()->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn (string $operation): bool => $operation !== 'create')
                ->disabled()
                ->dehydrated(false),
            Textarea::make('notes')
                ->label('ملاحظات')
                ->columnSpanFull(),
            Repeater::make('lines')
                ->label('العملات')
                ->helperText('كل مبلغ يُضاف إلى رصيد الصراف عند التسليم، أو يُنقص منه عند الإرجاع.')
                ->visible(fn (string $operation): bool => $operation === 'create')
                ->addActionLabel('إضافة عملة')
                ->reorderable(false)
                ->columnSpanFull()
                ->table([
                    TableColumn::make('العملة')->markAsRequired(),
                    TableColumn::make('المبلغ')->markAsRequired(),
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
                        ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    TextInput::make('amount')
                        ->label('المبلغ')
                        ->hiddenLabel()
                        ->numeric()
                        ->minValue(0.001)
                        ->required(),
                ]),
            Repeater::make('stored_lines')
                ->label('العملات')
                ->relationship('lines')
                ->visible(fn (string $operation): bool => $operation !== 'create')
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->columnSpanFull()
                ->table([
                    TableColumn::make('العملة'),
                    TableColumn::make('المبلغ'),
                ])
                ->components([
                    Select::make('currency_id')
                        ->label('العملة')
                        ->hiddenLabel()
                        ->options(fn (): array => Currency::query()->orderBy('code')->pluck('code', 'id')->all())
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('amount')
                        ->label('المبلغ')
                        ->hiddenLabel()
                        ->disabled()
                        ->dehydrated(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('الرقم'),
                TextColumn::make('workDay.work_date')->label('اليوم')->date('Y-m-d'),
                TextColumn::make('type')
                    ->label('الحركة')
                    ->formatStateUsing(fn (CustodyMovement|string|null $state): string => $state instanceof CustodyMovement ? $state->label() : (string) $state),
                TextColumn::make('cashierVault.name')->label('الصراف'),
                TextColumn::make('mainVault.name')->label('الخزينة الرئيسية'),
                TextColumn::make('user_name')->label('بواسطة'),
                TextColumn::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustodyHandovers::route('/'),
            'create' => CreateCustodyHandover::route('/create'),
            'view' => ViewCustodyHandover::route('/{record}'),
        ];
    }
}
