<?php

namespace Tests\Unit;

use App\Models\MaliHizmetlerRapor;
use App\Support\MaliHizmetlerOdemeTalep;
use App\Support\MaliHizmetlerPeriod;
use Tests\TestCase;

class MaliHizmetlerRaporTest extends TestCase
{
    public function test_odeme_talepleri_toplam_sums_amounts(): void
    {
        $rapor = new MaliHizmetlerRapor([
            'odeme_talepleri' => [
                ['aciklama' => 'A', 'tutar' => 1000.50, 'firma_arandi' => true],
                ['aciklama' => 'B', 'tutar' => 250.25, 'firma_arandi' => false],
            ],
        ]);

        $this->assertSame(1250.75, $rapor->odemeTalepleriToplam());
        $this->assertSame(1, $rapor->bekleyenTalepSayisi());
    }

    public function test_legacy_durum_text_counts_as_firma_arandi(): void
    {
        $rapor = new MaliHizmetlerRapor([
            'odeme_talepleri' => [
                ['aciklama' => 'A', 'tutar' => 100, 'durum' => 'firma arandı'],
            ],
        ]);

        $this->assertSame(0, $rapor->bekleyenTalepSayisi());
    }

    public function test_normalize_row_persists_tarih_and_arayan_personel(): void
    {
        $row = MaliHizmetlerOdemeTalep::normalizeRow([
            'aciklama' => 'Test',
            'tutar' => 500,
            'firma_arandi' => true,
            'tarih' => '2026-07-09',
            'arayan_personel' => 'Ahmet Y.',
        ]);

        $this->assertTrue($row['firma_arandi']);
        $this->assertSame('2026-07-09', $row['tarih']);
        $this->assertSame('Ahmet Y.', $row['arayan_personel']);
    }

    public function test_bekleyen_talep_toplam_sums_pending_amounts(): void
    {
        $rapor = new MaliHizmetlerRapor([
            'odeme_talepleri' => [
                ['aciklama' => 'A', 'tutar' => 1000, 'firma_arandi' => false],
                ['aciklama' => 'B', 'tutar' => 500, 'firma_arandi' => true],
            ],
        ]);

        $this->assertSame(1000.0, $rapor->bekleyenTalepToplam());
    }

    public function test_normalize_period_attributes_clamps_invalid_hafta(): void
    {
        $period = MaliHizmetlerPeriod::normalizePeriodAttributes([
            'yil' => now()->year,
            'ay' => now()->format('m'),
            'hafta' => 99,
        ]);

        $options = \App\Support\ReportPeriodWeeks::periodSelectOptions(
            $period['yil'],
            (int) $period['ay'],
            'Haftalık',
        );

        $this->assertArrayHasKey($period['hafta'], $options);
    }
}
