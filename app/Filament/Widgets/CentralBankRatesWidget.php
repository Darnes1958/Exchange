<?php

namespace App\Filament\Widgets;

use App\Services\CentralBankRateService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class CentralBankRatesWidget extends TableWidget
{
    protected static ?int $sort = -20;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $rates = app(CentralBankRateService::class)->latest();

        return $table
            ->heading('أسعار مصرف ليبيا المركزي')
            ->description($rates['date']
                ? 'السعر الرسمي المنشور بتاريخ '.$rates['date'].'. الشراء والبيع والمتوسط مقابل الدينار.'
                : ($rates['error'] ?? 'لا توجد أسعار.'))
            ->records(fn (): array => $rates['rows'])
            ->columns([
                TextColumn::make('currency')->label('العملة'),
                TextColumn::make('unit')->label('الوحدة'),
                TextColumn::make('buy')->label('شراء'),
                TextColumn::make('average')->label('المتوسط'),
                TextColumn::make('sell')->label('بيع'),
            ])
            ->paginated(false)
            ->emptyStateHeading($rates['error'] ?? 'لا توجد أسعار');
    }
}
