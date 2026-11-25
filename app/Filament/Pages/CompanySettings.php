<?php

namespace App\Filament\Pages;

use App\Enums\BranchMode;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CompanySettings extends Page
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user instanceof User && $user->role === UserRole::Cashier) {
            return false;
        }

        return parent::canAccess();
    }

    protected static ?string $navigationLabel = 'إعدادات الشركة';

    protected static ?string $title = 'إعدادات الشركة';

    protected static string|\UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'branch_mode' => CompanySetting::current()->branch_mode->value,
            'branch_name' => Branch::query()->orderBy('id')->value('name'),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('حفظ')
                            ->submit('save')
                            ->visible(fn (): bool => static::canEditSettings()),
                    ]),
                ]),
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('branch_mode')
                ->label('فروع الشركة')
                ->options(BranchMode::options())
                ->native(false)
                ->required()
                ->live()
                ->disabled(fn (): bool => ! static::canEditSettings())
                ->helperText('فرع واحد يخفي اختيار الفرع في الشاشات. متعددة الفروع تطلب الفرع للمدير والصراف والخزينة، ولكل فرع خزينة رئيسية واحدة ومدير واحد على الأقل.'),
            TextInput::make('branch_name')
                ->label('اسم الفرع')
                ->maxLength(255)
                ->visible(fn (Get $get): bool => $get('branch_mode') === BranchMode::Single->value)
                ->required(fn (Get $get): bool => $get('branch_mode') === BranchMode::Single->value)
                ->disabled(fn (): bool => ! static::canEditSettings()),
        ]);
    }

    public function save(): void
    {
        if (! static::canEditSettings()) {
            return;
        }

        $data = $this->form->getState();
        $settings = CompanySetting::current();
        $settings->branch_mode = $data['branch_mode'];
        $settings->save();

        if ($settings->isSingle() && filled($data['branch_name'] ?? null)) {
            $branch = Branch::query()->orderBy('id')->first();

            if ($branch instanceof Branch) {
                $branch->name = $data['branch_name'];
                $branch->save();
            }
        }

        Notification::make()->title('تم حفظ إعدادات الشركة')->success()->send();
    }

    public static function canEditSettings(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageCompanySettings();
    }
}
