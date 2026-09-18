<?php

namespace Tests\Unit;

use App\Services\ActivityCatalogSyncService;
use Tests\TestCase;

class ActivityCatalogSyncServiceFaaliyetSetiTest extends TestCase
{
    public function test_merges_snapshot_row_into_faaliyet_seti_full_entry(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'faaliyet_seti_');
        $this->assertNotFalse($tmp);

        $path = $tmp.'.json';
        rename($tmp, $path);

        file_put_contents($path, json_encode([
            [
                'Müdürlük' => 'Eski Müdürlük',
                'Faaliyet Kodu' => 'OKM-01',
                'Faaliyet Ailesi' => 'Eski Aile',
                'Kategori' => 'Kategori',
                'Kapsam' => 'Eski kapsam',
                'Ölçü Birimi' => 'adet',
                'Ana KPI / SLA' => 'sla',
                'Veri Kaynağı' => 'EBYS',
            ],
        ], JSON_UNESCAPED_UNICODE));

        $sync = new ActivityCatalogSyncService;
        $stats = $sync->mergeServerSnapshotIntoFaaliyetSetiFull([
            [
                'faaliyet_kodu' => 'OKM-01',
                'mudurluk' => 'Özel Kalem Müdürlüğü',
                'faaliyet_ailesi' => 'Protokol ve Başkanlık Takvimi',
                'kategori' => 'İletişim / Memnuniyet / Paydaş',
                'kapsam' => 'Yeni kapsam',
                'olcu_birimi' => 'adet / hafta',
                'kpi_sla' => 'zamanında gerçekleşme oranı',
                'raporlama_sikligi' => 'Haftalık',
                'baskanlik_bilgilendirme_seviyesi' => '',
            ],
        ], $path);

        $this->assertSame(1, $stats['updated']);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertSame('Özel Kalem Müdürlüğü', $decoded[0]['Müdürlük']);
        $this->assertSame('Yeni kapsam', $decoded[0]['Kapsam']);
        $this->assertSame('Haftalık', $decoded[0]['Raporlama Sıklığı']);
        $this->assertSame('EBYS', $decoded[0]['Veri Kaynağı']);

        @unlink($path);
    }
}
