<?php

namespace App\Filament\Resources\InternalTransfers\Pages;

use App\Filament\Resources\InternalTransfers\InternalTransferResource;
use App\Models\User;
use App\Services\InternalTransferService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateInternalTransfer extends CreateRecord
{
    protected static string $resource = InternalTransferResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();

        try {
            return InternalTransferService::post($data, $user instanceof User ? $user : null);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();

            throw new Halt;
        }
    }
}
