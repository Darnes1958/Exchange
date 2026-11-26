<?php

namespace App\Filament\Resources\InternalTransfers;

use App\Enums\InternalTransferDirection;
use App\Enums\InternalTransferSettlement;
use App\Enums\InternalTransferStatus;
use App\Enums\UserRole;
use App\Filament\Concerns\CashiersOnly;
use App\Filament\Resources\InternalTransfers\Pages\CreateInternalTransfer;
use App\Filament\Resources\InternalTransfers\Pages\EditInternalTransfer;
use App\Filament\Resources\InternalTransfers\Pages\ListInternalTransfers;
use App\Filament\Resources\InternalTransfers\Pages\ViewInternalTransfer;
use App\Models\InternalTransfer;
use App\Models\User;
use App\Models\WorkDay;
use App\Services\InternalTransferService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class InternalTransferResource extends Resource
{
    use CashiersOnly;

    protected static ?string $model = InternalTransfer::class;

    protected static ?string $navigationLabel = 'الحوالات الداخلية';

    protected static ?string $modelLabel = 'حوالة داخلية';

    protected static ?string $pluralModelLabel = 'الحوالات الداخلية';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string|\UnitEnum|null $navigationGroup = 'الحوالات';

    protected static ?int $navigationSort = 1;

    public static function canEdit(Model $record): bool
    {
        return $record instanceof InternalTransfer && static::canRevise($record);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canRevise(InternalTransfer $transfer): bool
    {
        $user = Auth::user();
        $day = WorkDay::current();

        return $user instanceof User
            && $user->role === UserRole::Cashier
            && $transfer->direction === InternalTransferDirection::Outgoing
            && $transfer->status === InternalTransferStatus::Pending
            && $day instanceof WorkDay
            && (int) $day->id === (int) $transfer->work_day_id
            && (int) $transfer->user_id === (int) $user->id;
    }

    public static function canReceive(InternalTransfer $transfer): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->role === UserRole::Cashier
            && $transfer->direction === InternalTransferDirection::Outgoing
            && $transfer->status === InternalTransferStatus::Pending
            && WorkDay::current() instanceof WorkDay;
    }

    public static function receiveAction(): Action
    {
        return Action::make('receive')
            ->label('مستلمة')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('تحويل الحوالة إلى مستلمة')
            ->modalDescription('يُسجَّل قيد الحوالة على رصيد المكتب. بعد الاستلام لا يمكن التعديل ولا الإلغاء.')
            ->visible(fn (InternalTransfer $record): bool => static::canReceive($record))
            ->action(function (InternalTransfer $record): void {
                $user = Auth::user();

                try {
                    InternalTransferService::receive($record, $user instanceof User ? $user : null);
                    Notification::make()->title('أصبحت الحوالة مستلمة')->success()->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(collect($exception->errors())->flatten()->first())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('إلغاء')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('إلغاء الحوالة الصادرة')
            ->modalDescription('يُعاد المبلغ المقبوض إلى عهدة الصراف أو المصرف. رصيد المكتب لا يتغير لأن القيد يتم عند الاستلام.')
            ->visible(fn (InternalTransfer $record): bool => static::canRevise($record))
            ->action(function (InternalTransfer $record): void {
                $user = Auth::user();

                try {
                    InternalTransferService::cancel($record, $user instanceof User ? $user : null);
                    Notification::make()->title('أُلغيت الحوالة')->success()->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(collect($exception->errors())->flatten()->first())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(InternalTransferForm::components());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->label('الإشاري')->searchable(),
                TextColumn::make('office.name')->label('المكتب')->searchable(),
                TextColumn::make('office.balance')->label('رصيد المكتب')->numeric(decimalPlaces: 3),
                TextColumn::make('sender_name')->label('الاسم')->searchable(),
                TextColumn::make('amount')->label('المبلغ')->numeric(decimalPlaces: 3),
                TextColumn::make('commission_type')
                    ->label('نوع العمولة')
                    ->formatStateUsing(fn (string|null $state, InternalTransfer $record): string => $record->commissionLabel()),
                TextColumn::make('commission')->label('العمولة')->numeric(decimalPlaces: 3),
                TextColumn::make('cash_amount')->label('النقد')->numeric(decimalPlaces: 3),
                TextColumn::make('office_amount')->label('على المكتب')->numeric(decimalPlaces: 3),
                TextColumn::make('profit')
                    ->label('الربح')
                    ->numeric(decimalPlaces: 3)
                    ->summarize(self::profitTotal()),
                TextColumn::make('settlement')
                    ->label('القبض')
                    ->formatStateUsing(fn (InternalTransferSettlement|string|null $state): string => $state instanceof InternalTransferSettlement
                        ? $state->label()
                        : (InternalTransferSettlement::tryFrom((string) $state)?->label() ?? '')),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->formatStateUsing(fn (InternalTransferStatus|string|null $state): string => $state instanceof InternalTransferStatus
                        ? $state->label()
                        : (InternalTransferStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn (InternalTransferStatus|string|null $state): string => match (true) {
                        $state === InternalTransferStatus::Cancelled || $state === InternalTransferStatus::Cancelled->value => 'danger',
                        $state === InternalTransferStatus::Pending || $state === InternalTransferStatus::Pending->value => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('recipient_name')->label('المستلم'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                static::receiveAction(),
                static::cancelAction(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInternalTransfers::route('/'),
            'create' => CreateInternalTransfer::route('/create'),
            'view' => ViewInternalTransfer::route('/{record}'),
            'edit' => EditInternalTransfer::route('/{record}/edit'),
        ];
    }

    private static function profitTotal(): Sum
    {
        return Sum::make()
            ->hiddenLabel()
            ->numeric(decimalPlaces: 3)
            ->query(fn ($query) => $query->where('status', '!=', InternalTransferStatus::Cancelled->value));
    }
}
