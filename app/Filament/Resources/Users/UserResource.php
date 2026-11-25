<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Concerns\ExceptCashiers;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    use ExceptCashiers;

    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'المستخدمون';

    protected static ?string $modelLabel = 'مستخدم';

    protected static ?string $pluralModelLabel = 'المستخدمون';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('الاسم')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label('البريد')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            TextInput::make('password')
                ->label('كلمة السر')
                ->password()
                ->revealable()
                ->minLength(8)
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->required(fn (string $operation): bool => $operation === 'create'),
            Select::make('role')
                ->label('التصنيف')
                ->options(function (?User $record): array {
                    $options = UserRole::assignableOptions(static::actorRole());

                    if ($record instanceof User && ! array_key_exists($record->role->value, $options)) {
                        $options = [$record->role->value => $record->role->label()] + $options;
                    }

                    return $options;
                })
                ->default(UserRole::Cashier->value)
                ->disabled(function (string $operation, ?User $record): bool {
                    if ($operation === 'view') {
                        return true;
                    }

                    if ($operation !== 'edit' || ! $record instanceof User) {
                        return false;
                    }

                    return ! $record->role->canBeAssignedBy(static::actorRole());
                })
                ->dehydrated(function (string $operation, ?User $record): bool {
                    if ($operation === 'view') {
                        return false;
                    }

                    if ($operation !== 'edit' || ! $record instanceof User) {
                        return true;
                    }

                    return $record->role->canBeAssignedBy(static::actorRole());
                })
                ->required(fn (string $operation): bool => $operation !== 'view')
                ->native(false)
                ->live(),
            Select::make('company')
                ->label('الشركة')
                ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'connection_name')->all())
                ->native(false)
                ->required(function (Get $get): bool {
                    return static::roleWorksInBranch($get('role'));
                }),
            Select::make('branch_id')
                ->label('الفرع')
                ->options(fn (): array => Branch::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn (Get $get): bool => static::roleWorksInBranch($get('role')) && ! CompanySetting::hidesBranchChoice())
                ->required(fn (Get $get): bool => static::roleWorksInBranch($get('role')) && ! CompanySetting::hidesBranchChoice())
                ->dehydrated(fn (Get $get): bool => static::roleWorksInBranch($get('role')) && ! CompanySetting::hidesBranchChoice())
                ->native(false)
                ->helperText('لكل فرع مدير واحد على الأقل، والصراف يتبع فرعه.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('email')->label('البريد')->searchable(),
                TextColumn::make('role')
                    ->label('التصنيف')
                    ->formatStateUsing(fn (UserRole|string|null $state): string => $state instanceof UserRole ? $state->label() : (string) $state),
                TextColumn::make('companyRecord.name')->label('الشركة'),
                TextColumn::make('branch_id')
                    ->label('الفرع')
                    ->formatStateUsing(fn (mixed $state): string => filled($state) ? (string) Branch::query()->whereKey($state)->value('name') : '')
                    ->visible(fn (): bool => ! CompanySetting::hidesBranchChoice()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    protected static function roleWorksInBranch(mixed $role): bool
    {
        $value = $role instanceof UserRole ? $role->value : $role;

        return in_array($value, [UserRole::Admin->value, UserRole::Cashier->value], true);
    }

    protected static function actorRole(): ?UserRole
    {
        $user = auth()->user();

        return $user instanceof User ? $user->role : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
