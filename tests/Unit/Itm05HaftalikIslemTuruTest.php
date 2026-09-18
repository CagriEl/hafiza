<?php

namespace Tests\Unit;

use App\Filament\Resources\AylikFaaliyetResource;
use App\Support\KapsamIslemTuru;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Itm05HaftalikIslemTuruTest extends TestCase
{
    public function test_detects_itm05_code(): void
    {
        $this->assertTrue(AylikFaaliyetResource::faaliyetKoduIsItm05('ITM-05'));
        $this->assertTrue(AylikFaaliyetResource::faaliyetKoduIsItm05('itm-05'));
        $this->assertFalse(AylikFaaliyetResource::faaliyetKoduIsItm05('ITM-04'));
    }

    public function test_enforce_requires_week_dates_for_itm05_haftalik(): void
    {
        $this->expectException(ValidationException::class);

        AylikFaaliyetResource::enforceKapsamDateRanges([
            'faaliyetler' => [[
                'faaliyet_kodu' => 'ITM-05',
                'kapsam_verileri' => [[
                    'kalem' => 'Çağrı',
                    'islem_turu' => KapsamIslemTuru::HAFTALIK,
                ]],
            ]],
        ], 'İtfaiye Müdürlüğü');
    }

    public function test_enforce_accepts_itm05_haftalik_with_dates(): void
    {
        $data = AylikFaaliyetResource::enforceKapsamDateRanges([
            'faaliyetler' => [[
                'faaliyet_kodu' => 'ITM-05',
                'kapsam_verileri' => [[
                    'kalem' => 'Çağrı',
                    'islem_turu' => KapsamIslemTuru::HAFTALIK,
                    'baslangic_tarihi' => '2026-09-01',
                    'bitis_tarihi' => '2026-09-07',
                ]],
            ]],
        ], 'İtfaiye Müdürlüğü');

        $line = $data['faaliyetler'][0]['kapsam_verileri'][0];
        $this->assertSame(KapsamIslemTuru::HAFTALIK, $line['islem_turu']);
        $this->assertSame('2026-09-01', $line['baslangic_tarihi']);
        $this->assertSame('2026-09-07', $line['bitis_tarihi']);
    }

    public function test_enforce_converts_haftalik_to_surec_on_non_itm05_when_dates_present(): void
    {
        $data = AylikFaaliyetResource::enforceKapsamDateRanges([
            'faaliyetler' => [[
                'faaliyet_kodu' => 'ITM-04',
                'kapsam_verileri' => [[
                    'kalem' => 'Eğitim',
                    'islem_turu' => KapsamIslemTuru::HAFTALIK,
                    'baslangic_tarihi' => '2026-09-01',
                    'bitis_tarihi' => '2026-09-07',
                ]],
            ]],
        ], 'İtfaiye Müdürlüğü');

        $line = $data['faaliyetler'][0]['kapsam_verileri'][0];
        $this->assertSame(KapsamIslemTuru::SUREC, $line['islem_turu']);
    }
}
