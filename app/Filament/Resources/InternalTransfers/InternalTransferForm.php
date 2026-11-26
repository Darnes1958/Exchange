<?php

namespace App\Filament\Resources\InternalTransfers;

use App\Enums\BankAccountType;
use App\Enums\IncomingCommission;
use App\Enums\InternalTransferDirection;
use App\Enums\InternalTransferSettlement;
use App\Enums\OutgoingCommission;
use App\Filament\Forms\TextInput;
use App\Models\BankAccount;
use App\Models\TransferOffice;
use App\Services\InternalTransferService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;

class InternalTransferForm
{
    /**
     * @return list<Radio|Select|TextInput|Placeholder|Section>
     */
    public static function components(): array
    {
        return [
            Radio::make('direction')
                ->label('النوع')
                ->options(InternalTransferDirection::options())
                ->default(InternalTransferDirection::Incoming->value)
                ->inline()
                ->live()
                ->required()
                ->disabled(fn (string $operation): bool => $operation === 'edit')
                ->dehydrated()
                ->afterStateUpdated(function (Set $set): void {
                    $set('commission_type', IncomingCommission::Deducted->value);
                    $set('settlement', InternalTransferSettlement::Cash->value);
                })
                ->columnSpanFull(),
            Select::make('transfer_office_id')
                ->label('المكتب')
                ->relationship('office', 'name', fn (Builder $query): Builder => $query->orderBy('name'))
                ->getOptionLabelFromRecordUsing(fn (TransferOffice $office): string => $office->name.' — '.number_format((float) $office->balance, 3, '.', ''))
                ->searchable()
                ->preload()
                ->live()
                ->required()
                ->native(false)
                ->manageOptionForm(fn (): array => [
                    TextInput::make('name')
                        ->label('اسم المكتب')
                        ->required()
                        ->maxLength(255),
                ])
                ->createOptionAction(fn (Action $action): Action => $action
                    ->label('مكتب جديد')
                    ->modalHeading('مكتب جديد')
                    ->modalSubmitActionLabel('حفظ'))
                ->editOptionAction(fn (Action $action): Action => $action
                    ->label('تعديل')
                    ->modalHeading('تعديل المكتب')
                    ->modalSubmitActionLabel('حفظ')),
            Placeholder::make('office_balance')
                ->label('رصيد المكتب')
                ->content(function (Get $get): string {
                    $office = TransferOffice::query()->find($get('transfer_office_id'));

                    return $office instanceof TransferOffice
                        ? number_format((float) $office->balance, 3, '.', '')
                        : '—';
                }),
            TextInput::make('reference')
                ->label('إشاري الحوالة')
                ->required()
                ->maxLength(255)
                ->autofocus()
                ->extraInputAttributes(['data-initial-focus' => '1']),
            TextInput::make('sender_name')
                ->label('الاسم')
                ->required()
                ->maxLength(255),
            TextInput::make('sender_phone')
                ->label('رقم الهاتف')
                ->required()
                ->maxLength(30),
            TextInput::make('sender_identity')
                ->label(fn (Get $get): string => self::outgoing($get) ? 'إثبات الهوية' : 'رقم الهوية')
                ->maxLength(255)
                ->required(fn (Get $get): bool => self::outgoing($get)),
            TextInput::make('amount')
                ->label('المبلغ')
                ->numeric()
                ->minValue(0.001)
                ->live()
                ->required()
                ->extraInputAttributes(['data-number-decimals' => '3']),
            TextInput::make('commission')
                ->label('قيمة العمولة')
                ->numeric()
                ->minValue(0)
                ->live()
                ->required()
                ->extraInputAttributes(['data-number-decimals' => '3']),
            Radio::make('commission_type')
                ->label('نوع العمولة')
                ->options(fn (Get $get): array => self::outgoing($get)
                    ? OutgoingCommission::options()
                    : IncomingCommission::options())
                ->default(IncomingCommission::Deducted->value)
                ->inline()
                ->live()
                ->required()
                ->columnSpanFull(),
            Radio::make('settlement')
                ->label('طريقة القبض')
                ->options(InternalTransferSettlement::options())
                ->default(InternalTransferSettlement::Cash->value)
                ->inline()
                ->live()
                ->required(fn (Get $get): bool => self::outgoing($get))
                ->visible(fn (Get $get): bool => self::outgoing($get))
                ->dehydrated(fn (Get $get): bool => self::outgoing($get)),
            Select::make('bank_account_id')
                ->label('المصرف')
                ->options(fn (): array => BankAccount::query()
                    ->where('type', BankAccountType::Local->value)
                    ->where('is_active', true)
                    ->orderBy('bank_name')
                    ->get()
                    ->mapWithKeys(fn (BankAccount $account): array => [$account->id => $account->label()])
                    ->all())
                ->searchable()
                ->preload()
                ->native(false)
                ->required(fn (Get $get): bool => self::outgoing($get) && $get('settlement') === InternalTransferSettlement::Bank->value)
                ->visible(fn (Get $get): bool => self::outgoing($get) && $get('settlement') === InternalTransferSettlement::Bank->value)
                ->dehydrated(fn (Get $get): bool => self::outgoing($get) && $get('settlement') === InternalTransferSettlement::Bank->value),
            Placeholder::make('transfer_preview')
                ->label('النتيجة')
                ->columnSpanFull()
                ->content(function (Get $get): string {
                    $direction = InternalTransferDirection::tryFrom((string) $get('direction')) ?? InternalTransferDirection::Incoming;

                    return InternalTransferService::preview(
                        $direction,
                        (string) $get('commission_type'),
                        $get('amount'),
                        $get('commission'),
                        (string) $get('settlement'),
                    );
                }),
            Section::make('المستلم')
                ->visible(fn (Get $get): bool => self::outgoing($get))
                ->schema([
                    TextInput::make('recipient_name')
                        ->label('الاسم')
                        ->required(fn (Get $get): bool => self::outgoing($get))
                        ->dehydrated(fn (Get $get): bool => self::outgoing($get))
                        ->maxLength(255),
                    TextInput::make('recipient_identity')
                        ->label('الإثبات')
                        ->required(fn (Get $get): bool => self::outgoing($get))
                        ->dehydrated(fn (Get $get): bool => self::outgoing($get))
                        ->maxLength(255),
                    TextInput::make('recipient_phone')
                        ->label('الهاتف')
                        ->required(fn (Get $get): bool => self::outgoing($get))
                        ->dehydrated(fn (Get $get): bool => self::outgoing($get))
                        ->maxLength(30),
                ])
                ->columns(3)
                ->columnSpanFull(),
        ];
    }

    private static function outgoing(Get $get): bool
    {
        return $get('direction') === InternalTransferDirection::Outgoing->value;
    }
}
