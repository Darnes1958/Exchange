<?php

namespace App\Filament\Resources\WorkDays\Pages;

use App\Filament\Resources\WorkDays\WorkDayResource;
use Filament\Resources\Pages\ViewRecord;

class ViewWorkDay extends ViewRecord
{
    protected static string $resource = WorkDayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            WorkDayResource::closeAction(),
        ];
    }
}
