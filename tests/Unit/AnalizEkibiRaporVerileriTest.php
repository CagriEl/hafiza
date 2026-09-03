<?php

namespace Tests\Unit;

use App\Support\AnalizEkibiRaporVerileri;
use Tests\TestCase;

class AnalizEkibiRaporVerileriTest extends TestCase
{
    public function test_build_prefill_computes_olgunluk_and_risk(): void
    {
        $data = AnalizEkibiRaporVerileri::buildPrefill([
            'summary' => ['hedef' => 10, 'gerceklesen' => 7, 'kalan' => 3],
            'faaliyet_row' => [
                'gerekli_revize' => true,
                'karar_ihtiyaci' => 'Ek personel',
                'sapma_nedeni' => 'Tedarik gecikti',
                'kapsam_verileri' => [
                    ['kalem' => 'Stok planı', 'ongorulen' => 4, 'gerceklesen' => 1],
                    ['kalem' => 'Operasyon', 'ongorulen' => 6, 'gerceklesen' => 6],
                ],
            ],
            'gecen_ay_fark' => 2,
        ]);

        $this->assertSame(70, $data['ozet']['tamamlanma_orani']);
        $this->assertCount(2, $data['kalem_analizi']);
        $this->assertCount(2, $data['risk_haritasi']);
        $this->assertSame('Yüksek', $data['risk_haritasi'][0]['seviye']);
        $this->assertNotNull($data['olgunluk']['veri_kalitesi']['deger']);
        $this->assertNotEmpty($data['aksiyonlar']);
    }

    public function test_mudurluk_ozet_prefill_sets_tamamlanma(): void
    {
        $kalemler = [
            ['kalem' => 'A', 'gerceklesen' => 5, 'acikta' => 0, 'durum' => 'Tamamlandı'],
        ];
        $olgunluk = AnalizEkibiRaporVerileri::computeOlgunluk($kalemler, null);
        $this->assertSame(100, $olgunluk['zamaninda_kapanis']['deger']);
    }
}
