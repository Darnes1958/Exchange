<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class CentralBankRateService
{
    /**
     * @return array{date: ?string, rows: list<array<string, string>>, error: ?string}
     */
    public function latest(): array
    {
        $cached = Cache::get('cbl.exchange-rates.v2');

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(12)
                ->withHeaders(['User-Agent' => 'ExchangeRatesWidget/1.0'])
                ->get('https://cbl.gov.ly/currency-exchange-rates/');

            if (! $response->successful()) {
                return $this->failure();
            }

            $parsed = $this->parse($response->body());

            if ($parsed['rows'] === []) {
                return $this->failure();
            }

            Cache::put('cbl.exchange-rates.v2', $parsed, now()->addHour());

            return $parsed;
        } catch (Throwable) {
            return $this->failure();
        }
    }

    /**
     * @return array{date: ?string, rows: list<array<string, string>>, error: ?string}
     */
    public function parse(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $rows = [];
        $date = null;

        foreach ($document->getElementsByTagName('tr') as $tr) {
            $cells = [];

            foreach ($tr->getElementsByTagName('td') as $td) {
                $cells[] = $this->cellText($td->textContent);
            }

            if (count($cells) < 6) {
                continue;
            }

            $date ??= $cells[0];
            $rows[] = [
                '__key' => $cells[1],
                'date' => $cells[0],
                'currency' => $cells[1],
                'unit' => $cells[2],
                'average' => $this->amount($cells[3]),
                'sell' => $this->amount($cells[4]),
                'buy' => $this->amount($cells[5]),
            ];
        }

        return [
            'date' => $date,
            'rows' => $rows,
            'error' => null,
        ];
    }

    private function cellText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\u{00A0}", '&nbsp;', '&nbsp'], ' ', $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return trim((string) preg_replace('/^(التاريخ|العملة|الوحدة|المتوسط|بيع|شراء)\s*:\s*/u', '', $value));
    }

    private function amount(string $value): string
    {
        $value = str_replace(['د.ل', 'د.ل.', 'LYD'], '', $value);

        return trim($value);
    }

    /**
     * @return array{date: ?string, rows: list<array<string, string>>, error: ?string}
     */
    private function failure(): array
    {
        return [
            'date' => null,
            'rows' => [],
            'error' => 'تعذر جلب أسعار مصرف ليبيا المركزي.',
        ];
    }
}
