<?php

namespace App\Filament\Resources\ExchangeDeals\Pages;

use App\Filament\Resources\ExchangeDeals\ExchangeDealResource;
use App\Models\User;
use App\Services\ExchangeTradeService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateExchangeDeal extends CreateRecord
{
    protected static string $resource = ExchangeDealResource::class;

    protected static bool $canCreateAnother = false;

    public function getHeading(): string|Htmlable|null
    {
        return '';
    }

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'deal-create-compact',
        ];
    }

    public function create(bool $another = false): void
    {
        parent::create(another: true);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();

        try {
            return ExchangeTradeService::trade($data, $user instanceof User ? $user : null);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();

            throw new Halt;
        }
    }
}
