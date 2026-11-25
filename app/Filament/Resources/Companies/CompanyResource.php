<?php

namespace App\Filament\Resources\Companies;

use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Models\Company;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $navigationLabel = 'الشركات';

    protected static ?string $modelLabel = 'شركة';

    protected static ?string $pluralModelLabel = 'الشركات';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|\UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('اسم الشركة')
                ->required()
                ->maxLength(255),
            TextInput::make('connection_name')
                ->label('اسم الاتصال')
                ->disabled()
                ->dehydrated(false),
            TextInput::make('database_name')
                ->label('قاعدة البيانات')
                ->disabled()
                ->dehydrated(false),
            Toggle::make('is_active')
                ->label('نشطة')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('اسم الشركة')->searchable(),
                TextColumn::make('connection_name')->label('الاتصال'),
                TextColumn::make('database_name')->label('قاعدة البيانات'),
                IconColumn::make('is_active')->label('نشطة')->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'view' => ViewCompany::route('/{record}'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
