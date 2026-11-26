<?php

namespace App\Filament\Resources\InternalTransfers\Pages;

use App\Filament\Resources\InternalTransfers\InternalTransferResource;
use App\Models\InternalTransfer;
use App\Models\User;
use App\Services\InternalTransferService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditInternalTransfer extends EditRecord
{
    protected static string $resource = InternalTransferResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();

        try {
            return InternalTransferService::update(
                $record instanceof InternalTransfer ? $record : new InternalTransfer,
                $data,
                $user instanceof User ? $user : null,
            );
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();

            throw new Halt;
        }
    }
}
