<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Concerns\ExceptCashiers;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Customer;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CustomerResource extends Resource
{
    use ExceptCashiers;

    protected static ?string $model = Customer::class;

    protected static ?string $navigationLabel = 'الزبائن';

    protected static ?string $modelLabel = 'زبون';

    protected static ?string $pluralModelLabel = 'الزبائن';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'الزبائن';

    protected static ?int $navigationSort = 1;

    public static function canEdit(Model $record): bool
    {
        return ! ($record instanceof Customer && $record->is_general);
    }

    public static function canDelete(Model $record): bool
    {
        return ! ($record instanceof Customer && $record->is_general);
    }

    /**
     * @return list<TextInput|Textarea>
     */
    public static function formFields(): array
    {
        return [
            TextInput::make('name')
                ->label('الاسم')
                ->required()
                ->maxLength(255),
            TextInput::make('phone')
                ->label('الهاتف')
                ->tel()
                ->maxLength(30),
            Textarea::make('notes')
                ->label('ملاحظات')
                ->columnSpanFull(),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(static::formFields());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('الرقم'),
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('phone')->label('الهاتف')->searchable(),
                TextColumn::make('is_general')
                    ->label('النوع')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'عام — سداد كامل' : 'معاملات'),
                TextColumn::make('notes')->label('ملاحظات')->limit(40),
            ])
            ->defaultSort('id')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
