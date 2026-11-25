<?php

namespace App\Filament\Resources\Branches;

use App\Enums\UserRole;
use App\Filament\Resources\Branches\Pages\CreateBranch;
use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Filament\Resources\Branches\Pages\ViewBranch;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static ?string $navigationLabel = 'الفروع';

    protected static ?string $modelLabel = 'فرع';

    protected static ?string $pluralModelLabel = 'الفروع';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string|\UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 4;

    public static function shouldRegisterNavigation(): bool
    {
        return ! CompanySetting::hidesBranchChoice();
    }

    public static function canCreate(): bool
    {
        return ! CompanySetting::hidesBranchChoice();
    }

    public static function canDelete(Model $record): bool
    {
        return ! CompanySetting::hidesBranchChoice();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('اسم الفرع')
                ->required()
                ->maxLength(255),
            Toggle::make('is_active')
                ->label('نشط')
                ->default(true)
                ->helperText('لكل فرع خزينة رئيسية واحدة، وصرافوه، ومدير واحد على الأقل يُعيَّن من المستخدمين.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('الفرع')->searchable(),
                TextColumn::make('managers_label')
                    ->label('المديرون')
                    ->state(function (Branch $record): string {
                        $names = User::query()
                            ->where('role', UserRole::Admin)
                            ->where('branch_id', $record->id)
                            ->orderBy('name')
                            ->pluck('name');

                        return $names->isEmpty() ? 'لم يُعيَّن مدير' : $names->implode('، ');
                    }),
                TextColumn::make('cashiers_label')
                    ->label('الصرافون')
                    ->state(function (Branch $record): string {
                        $names = User::query()
                            ->where('role', UserRole::Cashier)
                            ->where('branch_id', $record->id)
                            ->orderBy('name')
                            ->pluck('name');

                        return $names->isEmpty() ? '—' : $names->implode('، ');
                    }),
                IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'view' => ViewBranch::route('/{record}'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }
}
