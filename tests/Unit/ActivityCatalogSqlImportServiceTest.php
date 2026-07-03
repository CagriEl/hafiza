<?php

namespace Tests\Unit;

use App\Services\ActivityCatalogSqlImportService;
use PHPUnit\Framework\TestCase;

class ActivityCatalogSqlImportServiceTest extends TestCase
{
    public function test_parses_server_sql_dump_when_available(): void
    {
        $path = '/Users/cagriel/Downloads/activity_catalogs.sql';
        if (! is_readable($path)) {
            $this->markTestSkipped('SQL dump not available');

            return;
        }

        $service = new ActivityCatalogSqlImportService;
        $rows = $service->parseInsertRows((string) file_get_contents($path));

        $this->assertGreaterThanOrEqual(130, count($rows));
        $this->assertSame('1', $rows[0]['id']);
        $this->assertSame('OKM-01', $rows[0]['faaliyet_kodu']);
        $this->assertSame('Özel Kalem Müdürlüğü', $rows[0]['mudurluk']);
    }

    public function test_parses_insert_block_with_numeric_id_and_escaped_quotes(): void
    {
        $sql = <<<'SQL'
INSERT INTO `activity_catalogs` (`id`, `mudurluk`, `faaliyet_kodu`, `faaliyet_ailesi`, `kategori`, `kapsam`, `olcu_birimi`, `kpi_sla`, `raporlama_sikligi`, `baskanlik_bilgilendirme_seviyesi`, `created_at`, `updated_at`) VALUES
(14, 'İK Müdürlüğü', 'IKM-03', 'Bordro', 'Destek', 'Yeni kapsam', 'personel', 'zamanında', 'Aylık', 'Kritik', '2026-01-01 00:00:00', '2026-01-02 00:00:00'),
(999, 'Yeni', 'YEN-99', 'O''Reilly', 'Kat', 'Kapsam', 'adet', 'sla', 'Haftalık', 'Takip', '2026-01-01 00:00:00', '2026-01-02 00:00:00');
ALTER TABLE `activity_catalogs`
SQL;

        $service = new ActivityCatalogSqlImportService;
        $rows = $service->parseInsertRows($sql);

        $this->assertCount(2, $rows);
        $this->assertSame('IKM-03', $rows[0]['faaliyet_kodu']);
        $this->assertSame('Aylık', $rows[0]['raporlama_sikligi']);
        $this->assertSame("O'Reilly", $rows[1]['faaliyet_ailesi']);
    }

    public function test_builds_snapshot_payload_from_sql_rows(): void
    {
        $sql = <<<'SQL'
INSERT INTO `activity_catalogs` (`id`, `mudurluk`, `faaliyet_kodu`, `faaliyet_ailesi`, `kategori`, `kapsam`, `olcu_birimi`, `kpi_sla`, `raporlama_sikligi`, `baskanlik_bilgilendirme_seviyesi`, `created_at`, `updated_at`) VALUES
(2, 'Özel Kalem Müdürlüğü', 'OKM-02', 'Aile', 'Kat', 'Kapsam', 'adet', 'sla', NULL, '', '2026-01-01 00:00:00', '2026-01-02 00:00:00'),
(1, 'Özel Kalem Müdürlüğü', 'OKM-01', 'Protokol', 'Kat', 'Kapsam', 'adet', 'sla', 'Haftalık', 'Kritik', '2026-01-01 00:00:00', '2026-01-02 00:00:00');
ALTER TABLE `activity_catalogs`
SQL;

        $service = new ActivityCatalogSqlImportService;
        $payload = $service->buildSnapshotPayload($service->parseInsertRows($sql));

        $this->assertSame(2, $payload['row_count']);
        $this->assertSame('OKM-01', $payload['rows'][0]['faaliyet_kodu']);
        $this->assertSame('OKM-02', $payload['rows'][1]['faaliyet_kodu']);
        $this->assertSame('Haftalık', $payload['rows'][0]['raporlama_sikligi']);
        $this->assertSame('', $payload['rows'][1]['raporlama_sikligi']);
    }

    public function test_reads_bundled_server_snapshot_file(): void
    {
        $path = dirname(__DIR__, 2).'/resources/data/activity_catalog_server_snapshot.json';
        if (! is_readable($path)) {
            $this->markTestSkipped('Bundled snapshot not available');

            return;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);
        $this->assertGreaterThan(100, count($decoded['rows'] ?? []));
        $codes = array_column($decoded['rows'], 'faaliyet_kodu');
        $this->assertContains('OKM-01', $codes);
        $sorted = $codes;
        sort($sorted);
        $this->assertSame($sorted, $codes);
    }
}
