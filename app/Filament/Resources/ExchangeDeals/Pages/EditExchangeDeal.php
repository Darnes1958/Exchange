<?php

namespace App\Filament\Resources\ExchangeDeals\Pages;

use App\Filament\Resources\ExchangeDeals\ExchangeDealResource;
use App\Models\ExchangeDeal;
use App\Models\User;
use App\Services\ExchangeTradeService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditExchangeDeal extends EditRecord
{
    protected static string $resource = ExchangeDealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExchangeDealResource::cancelAction(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();

        try {
            return ExchangeTradeService::updateSale(
                $record instanceof ExchangeDeal ? $record : new ExchangeDeal,
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
