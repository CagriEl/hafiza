<?php

namespace Tests\Unit;

use App\Services\ActivityCatalogRaporlamaSikligiService;
use PHPUnit\Framework\TestCase;

class ActivityCatalogRaporlamaSikligiServiceTest extends TestCase
{
    public function test_parses_csv_with_turkish_headers(): void
    {
        $csv = <<<'CSV'
Faaliyet Kodu,Kategori,Raporlama Sıklığı
OKM-01,İletişim / Memnuniyet / Paydaş,Haftalık
IKM-03,Destek / İç İşleyiş,Aylık
CSV;

        $service = new ActivityCatalogRaporlamaSikligiService;
        $map = $service->parseCsvString($csv);

        $this->assertSame([
            'OKM-01' => 'Haftalık',
            'IKM-03' => 'Aylık',
        ], $map);
    }
}
