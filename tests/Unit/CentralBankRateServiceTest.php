<?php

namespace Tests\Unit;

use App\Services\CentralBankRateService;
use PHPUnit\Framework\TestCase;

class CentralBankRateServiceTest extends TestCase
{
    public function test_it_reads_the_published_rate_table(): void
    {
        $html = <<<'HTML'
        <table id="currency-table">
            <tbody>
                <tr>
                    <td><span>التاريخ: </span>2026-11-26</td>
                    <td><span>العملة: </span>الدولار الأمريكي</td>
                    <td><span>الوحدة: </span>دولار واحد</td>
                    <td><span>المتوسط: </span>6.473&nbspد.ل</td>
                    <td><span>بيع: </span>6.4892&nbsp;د.ل</td>
                    <td><span>شراء: </span>6.4568&nbsp;د.ل</td>
                </tr>
            </tbody>
        </table>
        HTML;

        $parsed = (new CentralBankRateService)->parse($html);

        $this->assertSame('2026-11-26', $parsed['date']);
        $this->assertSame('الدولار الأمريكي', $parsed['rows'][0]['currency']);
        $this->assertSame('6.4568', $parsed['rows'][0]['buy']);
        $this->assertSame('6.473', $parsed['rows'][0]['average']);
        $this->assertSame('6.4892', $parsed['rows'][0]['sell']);
    }
}
