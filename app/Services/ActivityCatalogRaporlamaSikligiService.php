<?php

namespace App\Services;

use App\Models\ActivityCatalog;
use App\Support\ActivityCatalogMetadataByCode;
use App\Support\ReportingFrequencyNormalizer;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * activity_catalogs’ta boş kalan raporlama_sikligi alanlarını CSV’den tamamlar (mevcut değerler korunur).
 */
final class ActivityCatalogRaporlamaSikligiService
{
    public const DEFAULT_CSV_PATH = 'resources/data/activity_catalog_raporlama_sikligi.csv';

    public const PUBLIC_CSV_FILENAME = 'activity_catalog_raporlama_sikligi.csv';

    public function resolveDefaultCsvPath(): string
    {
        return base_path(self::DEFAULT_CSV_PATH);
    }

    public function resolvePublicCsvPath(): string
    {
        return public_path(self::PUBLIC_CSV_FILENAME);
    }

    /**
     * @return array{path: string, row_count: int}
     */
    public function exportCsvFromSnapshotAndModel(?string $outPath = null): array
    {
        $import = app(ActivityCatalogSqlImportService::class);
        $rows = $import->readSnapshotRows();
        if ($rows === []) {
            throw new RuntimeException('Sunucu snapshot okunamadı; önce activity_catalog_server_snapshot.json oluşturun.');
        }

        $outPath ??= $this->resolveDefaultCsvPath();
        $lines = ['Faaliyet Kodu,Kategori,Raporlama Sıklığı'];

        foreach ($rows as $row) {
            $code = trim((string) ($row['faaliyet_kodu'] ?? ''));
            if ($code === '') {
                continue;
            }

            $existing = trim((string) ($row['raporlama_sikligi'] ?? ''));
            $kategori = trim((string) ($row['kategori'] ?? ''));
            $derived = ReportingFrequencyNormalizer::fromKategori($kategori) ?? '';
            $frequency = ($existing !== '' && self::isCanonicalFrequency($existing))
                ? $existing
                : ($derived !== '' ? $derived : $existing);

            $lines[] = $this->csvLine([
                $code,
                $kategori,
                $frequency,
            ]);
        }

        File::ensureDirectoryExists(dirname($outPath));
        File::put($outPath, implode("\n", $lines)."\n");

        return [
            'path' => $outPath,
            'row_count' => count($lines) - 1,
        ];
    }

    /**
     * @return array<string, string> faaliyet_kodu => raporlama_sikligi
     */
    public function parseCsvFile(string $path): array
    {
        if (! File::isReadable($path)) {
            throw new RuntimeException("CSV dosyası okunamadı: {$path}");
        }

        return $this->parseCsvString(File::get($path));
    }

    /**
     * @return array<string, string>
     */
    public function parseCsvString(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        if (! $handle) {
            throw new RuntimeException('CSV belleğe açılamadı.');
        }

        fwrite($handle, $csv);
        rewind($handle);

        $headers = fgetcsv($handle);
        if (! is_array($headers) || $headers === []) {
            fclose($handle);
            throw new RuntimeException('CSV başlık satırı bulunamadı.');
        }

        $headers = array_map(fn ($h): string => $this->normalizeHeader((string) $h), $headers);
        $codeIndex = $this->headerIndex($headers, ['faaliyet_kodu', 'faaliyet kodu']);
        $freqIndex = $this->headerIndex($headers, ['raporlama_sikligi', 'raporlama sikligi', 'raporlama sıklığı']);

        if ($codeIndex === null || $freqIndex === null) {
            fclose($handle);
            throw new RuntimeException('CSV’de Faaliyet Kodu ve Raporlama Sıklığı sütunları gerekli.');
        }

        $map = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (! is_array($values)) {
                continue;
            }
            $code = trim((string) ($values[$codeIndex] ?? ''));
            $freq = trim((string) ($values[$freqIndex] ?? ''));
            if ($code === '' || $freq === '') {
                continue;
            }
            $map[$code] = $freq;
        }

        fclose($handle);

        return $map;
    }

    /**
     * @return array{updated_db: int, updated_snapshot: int, skipped_existing: int, skipped_unknown: int, parsed: int}
     */
    public function fillMissingFromCsvFile(string $path, bool $writeSnapshot = true): array
    {
        $frequencies = $this->parseCsvFile($path);

        return $this->fillMissingFromMap($frequencies, $writeSnapshot);
    }

    /**
     * @param  array<string, string>  $frequencies
     * @return array{updated_db: int, updated_snapshot: int, skipped_existing: int, skipped_unknown: int, parsed: int}
     */
    public function fillMissingFromMap(array $frequencies, bool $writeSnapshot = true): array
    {
        $updatedDb = 0;
        $updatedSnapshot = 0;
        $skippedExisting = 0;
        $skippedUnknown = 0;

        $import = app(ActivityCatalogSqlImportService::class);
        $snapshotPath = $import->resolveDefaultSnapshotPath();
        $snapshotRows = $import->readSnapshotRows();
        $snapshotByCode = [];
        foreach ($snapshotRows as $i => $row) {
            $code = trim((string) ($row['faaliyet_kodu'] ?? ''));
            if ($code !== '') {
                $snapshotByCode[$code] = $i;
            }
        }

        foreach ($frequencies as $code => $frequency) {
            $frequency = trim($frequency);
            if ($frequency === '') {
                continue;
            }

            $catalog = ActivityCatalog::query()->where('faaliyet_kodu', $code)->first();
            if (! $catalog instanceof ActivityCatalog) {
                $skippedUnknown++;

                continue;
            }

            if (trim((string) ($catalog->raporlama_sikligi ?? '')) !== '' && self::isCanonicalFrequency((string) $catalog->raporlama_sikligi)) {
                $skippedExisting++;

                continue;
            }

            $catalog->raporlama_sikligi = $frequency;
            $catalog->save();
            $updatedDb++;

            if (isset($snapshotByCode[$code])) {
                $index = $snapshotByCode[$code];
                $snapshotExisting = trim((string) ($snapshotRows[$index]['raporlama_sikligi'] ?? ''));
                if ($snapshotExisting === '' || ! self::isCanonicalFrequency($snapshotExisting)) {
                    $snapshotRows[$index]['raporlama_sikligi'] = $frequency;
                    $updatedSnapshot++;
                }
            }
        }

        if ($writeSnapshot && $updatedSnapshot > 0) {
            $this->writeSnapshotRows($snapshotPath, $snapshotRows);
        }

        app(ActivityService::class)->forgetCache();
        ActivityCatalogMetadataByCode::forgetCache();

        return [
            'updated_db' => $updatedDb,
            'updated_snapshot' => $updatedSnapshot,
            'skipped_existing' => $skippedExisting,
            'skipped_unknown' => $skippedUnknown,
            'parsed' => count($frequencies),
        ];
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function writeSnapshotRows(string $path, array $rows): void
    {
        $payload = app(ActivityCatalogSqlImportService::class)->buildSnapshotPayload(
            array_map(fn (array $row): array => [
                'faaliyet_kodu' => $row['faaliyet_kodu'] ?? '',
                'mudurluk' => $row['mudurluk'] ?? '',
                'faaliyet_ailesi' => $row['faaliyet_ailesi'] ?? '',
                'kategori' => $row['kategori'] ?? '',
                'kapsam' => $row['kapsam'] ?? '',
                'olcu_birimi' => $row['olcu_birimi'] ?? '',
                'kpi_sla' => $row['kpi_sla'] ?? '',
                'raporlama_sikligi' => $row['raporlama_sikligi'] ?? '',
                'baskanlik_bilgilendirme_seviyesi' => $row['baskanlik_bilgilendirme_seviyesi'] ?? '',
            ], $rows)
        );

        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * @param  list<string>  $values
     */
    private function csvLine(array $values): string
    {
        $escaped = array_map(function (string $value): string {
            if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
                return '"'.str_replace('"', '""', $value).'"';
            }

            return $value;
        }, $values);

        return implode(',', $escaped);
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = trim($header);

        return mb_strtolower($header, 'UTF-8');
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $candidates
     */
    private function headerIndex(array $headers, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $index = array_search(mb_strtolower($candidate, 'UTF-8'), $headers, true);
            if ($index !== false) {
                return (int) $index;
            }
        }

        return null;
    }

    public static function isCanonicalFrequency(string $value): bool
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        if ($normalized === '') {
            return false;
        }

        foreach (['haftalık', 'haftalik', 'aylık', 'aylik', 'yıllık', 'yillik', '3 aylık', '6 aylık'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
