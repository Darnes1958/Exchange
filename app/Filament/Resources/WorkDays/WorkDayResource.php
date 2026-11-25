<?php

namespace App\Filament\Resources\WorkDays;

use App\Enums\WorkDayStatus;
use App\Filament\Resources\WorkDays\Pages\ListWorkDays;
use App\Filament\Resources\WorkDays\Pages\ViewWorkDay;
use App\Filament\Resources\WorkDays\RelationManagers\WorkDayHandoversRelationManager;
use App\Models\User;
use App\Models\WorkDay;
use App\Services\DailyCustodyService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class WorkDayResource extends Resource
{
    protected static ?string $model = WorkDay::class;

    protected static ?string $navigationLabel = 'العمل اليومي';

    protected static ?string $modelLabel = 'عمل يومي';

    protected static ?string $pluralModelLabel = 'العمل اليومي';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'الخزائن';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('work_date')->label('التاريخ')->disabled()->dehydrated(false),
            TextInput::make('status')
                ->label('الحالة')
                ->formatStateUsing(fn (WorkDayStatus|string|null $state): string => static::statusLabel($state))
                ->disabled()
                ->dehydrated(false),
            TextInput::make('opened_at')->label('وقت الافتتاح')->disabled()->dehydrated(false),
            TextInput::make('opened_by_name')->label('افتتحه')->disabled()->dehydrated(false),
            TextInput::make('closed_at')->label('وقت الإقفال')->disabled()->dehydrated(false),
            TextInput::make('closed_by_name')->label('أقفله')->disabled()->dehydrated(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('work_date')->label('التاريخ')->date('Y-m-d'),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->formatStateUsing(fn (WorkDayStatus|string|null $state): string => static::statusLabel($state)),
                TextColumn::make('opened_at')->label('الافتتاح')->dateTime('Y-m-d H:i'),
                TextColumn::make('opened_by_name')->label('افتتحه'),
                TextColumn::make('closed_at')->label('الإقفال')->dateTime('Y-m-d H:i'),
                TextColumn::make('closed_by_name')->label('أقفله'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                static::closeAction(),
            ]);
    }

    public static function openAction(): Action
    {
        return Action::make('openDay')
            ->label('افتتاح العمل اليومي')
            ->icon('heroicon-o-lock-open')
            ->visible(fn (): bool => ! WorkDay::query()->where('status', WorkDayStatus::Open)->exists())
            ->requiresConfirmation()
            ->modalHeading('افتتاح العمل اليومي')
            ->modalDescription('لا يُفتتح يوم جديد إذا كان هناك عمل يوم سابق لم يُقفل.')
            ->action(function (): void {
                try {
                    DailyCustodyService::open(auth()->user() instanceof User ? auth()->user() : null);
                } catch (ValidationException $exception) {
                    Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();

                    return;
                }

                Notification::make()->title('تم افتتاح العمل اليومي')->success()->send();
            });
    }

    public static function closeAction(): Action
    {
        return Action::make('closeDay')
            ->label('إقفال العمل اليومي')
            ->icon('heroicon-o-lock-closed')
            ->visible(fn (WorkDay $record): bool => $record->status === WorkDayStatus::Open)
            ->requiresConfirmation()
            ->modalHeading('إقفال العمل اليومي')
            ->modalDescription('لا يُقفل اليوم إلا بعد أن يسلّم كل الصرافين ما في عهدتهم.')
            ->action(function (): void {
                try {
                    DailyCustodyService::close(auth()->user() instanceof User ? auth()->user() : null);
                } catch (ValidationException $exception) {
                    Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();

                    return;
                }

                Notification::make()->title('تم إقفال العمل اليومي')->success()->send();
            });
    }

    public static function getRelations(): array
    {
        return [
            WorkDayHandoversRelationManager::class,
        ];
    }

    public static function statusLabel(WorkDayStatus|string|null $state): string
    {
        if ($state instanceof WorkDayStatus) {
            return $state->label();
        }

        return WorkDayStatus::tryFrom((string) $state)?->label() ?? (string) $state;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkDays::route('/'),
            'view' => ViewWorkDay::route('/{record}'),
        ];
    }
}
