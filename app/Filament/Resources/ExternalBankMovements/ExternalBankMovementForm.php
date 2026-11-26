<?php

namespace App\Filament\Resources\ExternalBankMovements;

use App\Enums\BankAccountType;
use App\Enums\ExternalBankDirection;
use App\Enums\ExternalBankPurpose;
use App\Enums\ExternalSettlement;
use App\Filament\Forms\TextInput;
use App\Models\BankAccount;
use App\Models\Currency;
use App\Models\User;
use App\Services\ExternalBankMovementService;
use Illuminate\Validation\ValidationException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;

class ExternalBankMovementForm
{
    /**
     * @return list<Radio|Select|TextInput|DatePicker|Placeholder>
     */
    public static function components(ExternalBankDirection $direction): array
    {
        $party = $direction === ExternalBankDirection::Deposit
            ? 'external_source_id'
            : 'external_beneficiary_id';

        return [
            Radio::make('purpose')
                ->label('النوع')
                ->options(ExternalBankPurpose::options($direction))
                ->default(ExternalBankPurpose::Funds->value)
                ->inline()
                ->live()
                ->required()
                ->columnSpanFull(),
            Radio::make('settlement')
                ->label('طريقة الدفع')
                ->options(ExternalSettlement::options())
                ->default(ExternalSettlement::Cash->value)
                ->inline()
                ->live()
                ->required(fn (Get $get): bool => $get('purpose') === ExternalBankPurpose::Currency->value)
                ->visible(fn (Get $get): bool => $get('purpose') === ExternalBankPurpose::Currency->value)
                ->dehydrated(fn (Get $get): bool => $get('purpose') === ExternalBankPurpose::Currency->value)
                ->columnSpanFull(),
            Placeholder::make('manager_vault')
                ->label('الخزينة الرئيسية')
                ->visible(fn (Get $get): bool => $get('purpose') === ExternalBankPurpose::Currency->value
                    && ($get('settlement') ?: ExternalSettlement::Cash->value) === ExternalSettlement::Cash->value)
                ->content(function (): string {
                    $user = auth()->user();

                    try {
                        return ExternalBankMovementService::managerVault($user instanceof User ? $user : null)->name;
                    } catch (ValidationException $exception) {
                        return (string) (collect($exception->errors())->flatten()->first() ?? 'لا توجد خزينة رئيسية تابعة للمدير.');
                    }
                }),
            Select::make('local_bank_account_id')
                ->label('المصرف المحلي')
                ->options(fn (): array => BankAccount::query()
                    ->where('type', BankAccountType::Local->value)
                    ->where('is_active', true)
                    ->orderBy('bank_name')
                    ->get()
                    ->mapWithKeys(fn (BankAccount $account): array => [$account->id => $account->label()])
                    ->all())
                ->default(fn (): mixed => BankAccount::query()
                    ->where('type', BankAccountType::Local->value)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->value('id'))
                ->searchable()
                ->preload()
                ->native(false)
                ->required(fn (Get $get): bool => $get('purpose') === ExternalBankPurpose::Currency->value
                    && $get('settlement') === ExternalSettlement::Bank->value)
                ->visible(fn (Get $get): bool => $get('purpose') === ExternalBankPurpose::Currency->value
                    && $get('settlement') === ExternalSettlement::Bank->value)
                ->dehydrated(fn (Get $get): bool => $get('purpose') === ExternalBankPurpose::Currency->value
                    && $get('settlement') === ExternalSettlement::Bank->value),
            Select::make('bank_account_id')
                ->label('الحساب الخارجي')
                ->options(fn (): array => BankAccount::query()
                    ->where('type', BankAccountType::Foreign->value)
                    ->where('is_active', true)
                    ->orderBy('bank_name')
                    ->get()
                    ->mapWithKeys(fn (BankAccount $account): array => [$account->id => $account->label()])
                    ->all())
                ->default(fn (): mixed => BankAccount::query()
                    ->where('type', BankAccountType::Foreign->value)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->value('id'))
                ->searchable()
                ->preload()
                ->required()
                ->native(false),
            TextInput::make('reference')
                ->label('الإشاري')
                ->required()
                ->maxLength(255)
                ->autofocus()
                ->extraInputAttributes(['data-initial-focus' => '1']),
            DatePicker::make('movement_date')
                ->label('التاريخ')
                ->default(now())
                ->required()
                ->native(false),
            TextInput::make('amount')
                ->label('المبلغ')
                ->numeric()
                ->minValue(0.001)
                ->live()
                ->required()
                ->helperText('بالعملة الأجنبية المحددة.'),
            Select::make('currency_id')
                ->label('العملة')
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
                ->default(fn (): mixed => Currency::query()->where('code', 'USD')->value('id'))
                ->searchable()
                ->required(),
            TextInput::make('rate')
                ->label('السعر المقابل')
                ->numeric()
                ->minValue(0.000001)
                ->required()
                ->live()
                ->extraInputAttributes(['data-number-decimals' => '6']),
            Placeholder::make('counter_preview')
                ->label('المقابل')
                ->content(fn (Get $get): string => number_format(
                    round((float) ($get('amount') ?? 0) * (float) ($get('rate') ?? 0), 3),
                    3,
                    '.',
                    '',
                )),
            Select::make($party)
                ->label($direction === ExternalBankDirection::Deposit ? 'المصدر' : 'المستفيد')
                ->relationship(
                    $direction === ExternalBankDirection::Deposit ? 'source' : 'beneficiary',
                    'name',
                    fn (Builder $query): Builder => $query->orderBy('name'),
                )
                ->searchable()
                ->preload()
                ->required()
                ->manageOptionForm(fn (): array => [
                    TextInput::make('name')
                        ->label('الاسم')
                        ->required()
                        ->maxLength(255),
                ])
                ->createOptionAction(fn (Action $action): Action => $action
                    ->label($direction === ExternalBankDirection::Deposit ? 'مصدر جديد' : 'مستفيد جديد')
                    ->modalHeading($direction === ExternalBankDirection::Deposit ? 'مصدر جديد' : 'مستفيد جديد')
                    ->modalSubmitActionLabel('حفظ'))
                ->editOptionAction(fn (Action $action): Action => $action
                    ->label('تعديل')
                    ->modalHeading($direction === ExternalBankDirection::Deposit ? 'تعديل المصدر' : 'تعديل المستفيد')
                    ->modalSubmitActionLabel('حفظ')),
        ];
    }
}
