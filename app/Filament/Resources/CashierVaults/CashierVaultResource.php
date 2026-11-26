<?php

namespace App\Filament\Resources\CashierVaults;

use App\Enums\UserRole;
use App\Filament\Concerns\ExceptCashiers;
use App\Filament\Forms\TextInput;
use App\Filament\Resources\CashierVaults\Pages\CreateCashierVault;
use App\Filament\Resources\CashierVaults\Pages\EditCashierVault;
use App\Filament\Resources\CashierVaults\Pages\ListCashierVaults;
use App\Filament\Resources\CashierVaults\Pages\ViewCashierVault;
use App\Filament\Resources\CashierVaults\RelationManagers\CashierVaultBalancesRelationManager;
use App\Models\Branch;
use App\Models\CashierVault;
use App\Models\CompanySetting;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class CashierVaultResource extends Resource
{
    use ExceptCashiers;

    protected static ?string $model = CashierVault::class;

    protected static ?string $navigationLabel = 'خزائن الصرافين';

    protected static ?string $modelLabel = 'خزينة صراف';

    protected static ?string $pluralModelLabel = 'خزائن الصرافين';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wallet';

    protected static string|\UnitEnum|null $navigationGroup = 'الخزائن';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->label('الصراف')
                ->options(function (): array {
                    $company = Auth::user()?->company;

                    return User::query()
                        ->where('role', UserRole::Cashier)
                        ->when(
                            is_string($company) && $company !== '',
                            fn ($query) => $query->where('company', $company),
                        )
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all();
                })
                ->searchable()
                ->required()
                ->unique(ignoreRecord: true)
                ->live()
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    $set('branch_id', User::query()->whereKey($state)->value('branch_id'));
                })
                ->helperText('خزينة واحدة لكل مستخدم من تصنيف صراف.'),
            Select::make('branch_id')
                ->label('الفرع')
                ->options(fn (): array => Branch::query()->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn (): bool => ! CompanySetting::hidesBranchChoice())
                ->disabled()
                ->dehydrated(false)
                ->native(false)
                ->helperText('يُؤخذ الفرع من بطاقة الصراف.'),
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
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('الخزينة')->searchable(),
                TextColumn::make('user.name')->label('الصراف'),
                TextColumn::make('branch.name')->label('الفرع')->visible(fn (): bool => ! CompanySetting::hidesBranchChoice()),
                IconColumn::make('is_active')->label('نشطة')->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            CashierVaultBalancesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashierVaults::route('/'),
            'create' => CreateCashierVault::route('/create'),
            'view' => ViewCashierVault::route('/{record}'),
            'edit' => EditCashierVault::route('/{record}/edit'),
        ];
    }
}
