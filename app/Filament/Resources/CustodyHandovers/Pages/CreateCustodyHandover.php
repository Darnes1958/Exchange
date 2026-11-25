<?php

namespace App\Filament\Resources\CustodyHandovers\Pages;

use App\Filament\Resources\CustodyHandovers\CustodyHandoverResource;
use App\Models\User;
use App\Services\DailyCustodyService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateCustodyHandover extends CreateRecord
{
    protected static string $resource = CustodyHandoverResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $lines = $data['lines'] ?? [];
        unset($data['lines']);

        $user = auth()->user();

        try {
            return DailyCustodyService::handOver($data, is_array($lines) ? $lines : [], $user instanceof User ? $user : null);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();

            throw new Halt;
        }
    }
}
