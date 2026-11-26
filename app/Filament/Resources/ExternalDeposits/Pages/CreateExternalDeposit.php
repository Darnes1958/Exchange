<?php

namespace App\Filament\Resources\ExternalDeposits\Pages;

use App\Enums\ExternalBankDirection;
use App\Filament\Resources\ExternalDeposits\ExternalDepositResource;
use App\Models\User;
use App\Services\ExternalBankMovementService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateExternalDeposit extends CreateRecord
{
    protected static string $resource = ExternalDepositResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data['direction'] = ExternalBankDirection::Deposit->value;
        $user = auth()->user();

        try {
            return ExternalBankMovementService::post($data, $user instanceof User ? $user : null);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();

            throw new Halt;
        }
    }
}
