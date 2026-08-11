<?php

namespace App\Services;

use App\Models\ActivityCatalog;
use App\Support\ActivityCatalogMetadataByCode;
use App\Support\TurkishString;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * faaliyet_seti_full.json → activity_catalogs tablosu: faaliyet_kodu üzerinden upsert.
 * Kayıt silinmez; raporlar ve kullanıcı verileri etkilenmez.
 */
class ActivityCatalogSyncService
{
    public function __construct(
        private readonly ?string $activitySetsOutputPath = null,
    ) {}

    /**
     * Önce proje kökü, yoksa resources/data altındaki tam dosya yolu.
     */
    public function resolveFullJsonPath(): string
    {
        $candidates = [
            base_path('faaliyet_seti_full.json'),
            resource_path('data/faaliyet_seti_full.json'),
        ];
        foreach ($candidates as $path) {
            if (File::isReadable($path)) {
                return $path;
            }
        }

        return $candidates[0];
    }

    public function resolveServerSnapshotPath(): string
    {
        return app(ActivityCatalogSqlImportService::class)->resolveDefaultSnapshotPath();
    }

    /**
     * Filament admin katalog kaydını JSON kaynaklarına yazar; otomatik/manuel sync geri almasın.
     * Canlıda resources/data yazılamazsa hata fırlatmaz — DB kaydı yeterlidir.
     */
    public function persistAdminCatalogChange(ActivityCatalog $catalog): void
    {
        try {
            $import = app(ActivityCatalogSqlImportService::class);
            $import->upsertCatalogIntoSnapshot($catalog);
            $this->tryRegenerateActivitySetsJsonFromCatalog();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Katalog JSON senkronu atlandı (DB kaydı korundu).', [
                'faaliyet_kodu' => $catalog->faaliyet_kodu,
                'error' => $e->getMessage(),
            ]);
        }

        ActivityCatalogMetadataByCode::forgetCache();
        app(ActivityService::class)->forgetCache();
    }

    public function removeAdminCatalogChange(string $faaliyetKodu): void
    {
        try {
            app(ActivityCatalogSqlImportService::class)->removeCatalogFromSnapshot($faaliyetKodu);
            $this->tryRegenerateActivitySetsJsonFromCatalog();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Katalog JSON silme senkronu atlandı (DB kaydı korundu).', [
                'faaliyet_kodu' => $faaliyetKodu,
                'error' => $e->getMessage(),
            ]);
        }

        ActivityCatalogMetadataByCode::forgetCache();
        app(ActivityService::class)->forgetCache();
    }

    /**
     * Yazma izni yoksa sessizce atlar (admin kaydını bozmaz).
     */
    public function tryRegenerateActivitySetsJsonFromCatalog(): bool
    {
        $out = $this->activitySetsOutputPath ?? resource_path('data/activity_sets.json');
        $import = app(ActivityCatalogSqlImportService::class);
        if (! $import->canWriteCatalogDataFile($out)) {
            \Illuminate\Support\Facades\Log::warning('activity_sets.json yazılamıyor; DB katalog kaydı korundu.', [
                'path' => $out,
            ]);

            return false;
        }

        try {
            $this->regenerateActivitySetsJsonFromCatalog();

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('activity_sets.json yenilenemedi; DB katalog kaydı korundu.', [
                'path' => $out,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return list<array<string, string>>
     */
    public function readServerSnapshotRows(?string $path = null): array
    {
        return app(ActivityCatalogSqlImportService::class)->readSnapshotRows($path);
    }

    /**
     * Sunucu snapshot JSON → activity_sets.json (yeni kod eklemez; snapshot’taki tüm satırlar dosyaya yazılır).
     */
    public function regenerateActivitySetsJsonFromServerSnapshot(?string $path = null): void
    {
        $rows = $this->readServerSnapshotRows($path);
        if ($rows === []) {
            throw new RuntimeException('Sunucu katalog snapshot dosyası okunamadı veya boş.');
        }

        /** @var array<string, array{label: string, activities: list<array<string, string>>}> $byNormKey */
        $byNormKey = [];

        foreach ($rows as $row) {
            $mudurluk = trim((string) ($row['mudurluk'] ?? ''));
            $norm = TurkishString::normalizeForFuzzyMatch($mudurluk);
            if ($norm === '') {
                continue;
            }
            if (! isset($byNormKey[$norm])) {
                $byNormKey[$norm] = ['label' => $mudurluk, 'activities' => []];
            }
            $byNormKey[$norm]['activities'][] = [
                'faaliyet_kodu' => (string) ($row['faaliyet_kodu'] ?? ''),
                'faaliyet_ailesi' => (string) ($row['faaliyet_ailesi'] ?? ''),
                'kategori' => (string) ($row['kategori'] ?? ''),
                'kapsam' => (string) ($row['kapsam'] ?? ''),
                'olcu_birimi' => (string) ($row['olcu_birimi'] ?? ''),
                'kpi_sla' => (string) ($row['kpi_sla'] ?? ''),
                'raporlama_sikligi' => (string) ($row['raporlama_sikligi'] ?? ''),
                'baskanlik_bilgilendirme_seviyesi' => (string) ($row['baskanlik_bilgilendirme_seviyesi'] ?? ''),
            ];
        }

        $sets = [];
        foreach ($byNormKey as $bucket) {
            usort($bucket['activities'], fn (array $a, array $b): int => strcmp($a['faaliyet_kodu'], $b['faaliyet_kodu']));
            $sets[] = [
                'mudurluk' => $bucket['label'],
                'activities' => array_values($bucket['activities']),
            ];
        }

        usort($sets, fn (array $a, array $b): int => strcmp($a['mudurluk'], $b['mudurluk']));

        $out = $this->activitySetsOutputPath ?? resource_path('data/activity_sets.json');
        $payload = [
            'version' => 1,
            'source' => 'activity_catalog_server_snapshot',
            'sets' => $sets,
        ];

        File::put($out, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");
        app(ActivityService::class)->forgetCache();
    }

    /**
     * @deprecated Sunucu snapshot kullanın: regenerateActivitySetsJsonFromServerSnapshot()
     */
    public function regenerateActivitySetsJson(?string $path = null): void
    {
        $this->regenerateActivitySetsJsonFromServerSnapshot($path);
    }

    /**
     * Sunucu snapshot (SQL) verisini mevcut faaliyet_seti_full.json satırlarına yazar; yeni kod eklemez.
     * Veri Kaynağı gibi SQL’de olmayan alanlar korunur.
     *
     * @param  list<array<string, string>>  $snapshotRows
     * @return array{path: string, updated: int, skipped: int}
     */
    public function mergeServerSnapshotIntoFaaliyetSetiFull(array $snapshotRows, ?string $path = null): array
    {
        $path ??= $this->resolveFullJsonPath();
        if (! File::isReadable($path)) {
            throw new RuntimeException("faaliyet_seti_full.json okunamadı: {$path}");
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('faaliyet_seti_full.json geçersiz.');
        }

        $byCode = [];
        foreach ($snapshotRows as $row) {
            $code = trim((string) ($row['faaliyet_kodu'] ?? ''));
            if ($code !== '') {
                $byCode[$code] = $row;
            }
        }

        $updated = 0;
        $skipped = 0;

        foreach ($decoded as $index => $entry) {
            if (! is_array($entry)) {
                $skipped++;

                continue;
            }

            $code = trim((string) ($entry['Faaliyet Kodu'] ?? $entry['faaliyet_kodu'] ?? ''));
            if ($code === '' || ! isset($byCode[$code])) {
                $skipped++;

                continue;
            }

            $src = $byCode[$code];
            $decoded[$index] = $this->mergeSnapshotRowIntoFaaliyetSetiEntry($entry, $src);
            $updated++;
        }

        File::put(
            $path,
            json_encode(array_values($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n"
        );

        app(ActivityService::class)->forgetCache();
        ActivityCatalogMetadataByCode::forgetCache();

        return [
            'path' => $path,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, string>  $snapshotRow
     * @return array<string, mixed>
     */
    private function mergeSnapshotRowIntoFaaliyetSetiEntry(array $entry, array $snapshotRow): array
    {
        $entry['Müdürlük'] = $snapshotRow['mudurluk'] ?? $entry['Müdürlük'] ?? '';
        $entry['Faaliyet Kodu'] = $snapshotRow['faaliyet_kodu'] ?? $entry['Faaliyet Kodu'] ?? '';
        $entry['Faaliyet Ailesi'] = $snapshotRow['faaliyet_ailesi'] ?? $entry['Faaliyet Ailesi'] ?? '';
        $entry['Kategori'] = $snapshotRow['kategori'] ?? $entry['Kategori'] ?? '';
        $entry['Kapsam'] = $snapshotRow['kapsam'] ?? $entry['Kapsam'] ?? '';
        $entry['Ölçü Birimi'] = $snapshotRow['olcu_birimi'] ?? $entry['Ölçü Birimi'] ?? '';
        $entry['Ana KPI / SLA'] = $snapshotRow['kpi_sla'] ?? $entry['Ana KPI / SLA'] ?? '';

        $raporlama = trim((string) ($snapshotRow['raporlama_sikligi'] ?? ''));
        if ($raporlama !== '') {
            $entry['Raporlama Sıklığı'] = $raporlama;
        }

        $baskanlik = trim((string) ($snapshotRow['baskanlik_bilgilendirme_seviyesi'] ?? ''));
        if ($baskanlik !== '') {
            $entry['Başkanlık Bilgilendirme Seviyesi'] = $baskanlik;
        }

        return $entry;
    }

    /**
     * @param  array{fill_raporlama?: bool, update_full_json?: bool, update_activity_sets?: bool}  $options
     * @return array<string, mixed>
     */
    public function syncAllFromPublicSql(string $sqlPath, array $options = []): array
    {
        $fillRaporlama = $options['fill_raporlama'] ?? true;
        $updateFullJson = $options['update_full_json'] ?? true;
        $updateActivitySets = $options['update_activity_sets'] ?? true;

        $import = app(ActivityCatalogSqlImportService::class);
        $raporlama = app(ActivityCatalogRaporlamaSikligiService::class);

        $written = $import->writeSnapshotFromSqlFile($sqlPath);
        $snapshotPath = $written['path'];

        $dbStats = $import->updateExistingFromSnapshotFile($snapshotPath);

        $raporlamaStats = null;
        if ($fillRaporlama) {
            $csvPath = is_readable($raporlama->resolvePublicCsvPath())
                ? $raporlama->resolvePublicCsvPath()
                : $raporlama->resolveDefaultCsvPath();

            if (! is_readable($csvPath)) {
                $raporlama->exportCsvFromSnapshotAndModel();
                $csvPath = $raporlama->resolveDefaultCsvPath();
            }

            $raporlamaStats = $raporlama->fillMissingFromCsvFile($csvPath);
        }

        $fullJsonStats = null;
        if ($updateFullJson) {
            $snapshotRows = $import->readSnapshotRows($snapshotPath);
            $fullJsonStats = $this->mergeServerSnapshotIntoFaaliyetSetiFull($snapshotRows);
        }

        if ($updateActivitySets) {
            $this->regenerateActivitySetsJsonFromServerSnapshot($snapshotPath);
        }

        return [
            'sql_path' => $sqlPath,
            'snapshot_path' => $snapshotPath,
            'snapshot_rows' => $written['row_count'],
            'db' => $dbStats,
            'raporlama' => $raporlamaStats,
            'faaliyet_seti_full' => $fullJsonStats,
        ];
    }

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function syncFromFile(?string $path = null): array
    {
        $path ??= $this->resolveFullJsonPath();
        if (! File::isReadable($path)) {
            throw new RuntimeException("faaliyet_seti_full.json okunamadı: {$path}");
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Geçersiz JSON: kök dizi bekleniyordu.');
        }

        return $this->syncFromRows($decoded);
    }

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function syncFromCsvFile(string $path): array
    {
        if (! File::isReadable($path)) {
            throw new RuntimeException("CSV dosyası okunamadı: {$path}");
        }

        return $this->syncFromCsvString(File::get($path));
    }

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function syncFromCsvString(string $csv): array
    {
        $rows = $this->parseCsvRows($csv);

        return $this->syncFromRows($rows);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array{created: int, updated: int, skipped: int}
     */
    public function syncFromRows(array $rows): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;

                continue;
            }
            $mapped = $this->mapRowToCatalogAttributes($row);
            if ($mapped === null) {
                $skipped++;

                continue;
            }

            $existed = ActivityCatalog::query()
                ->where('faaliyet_kodu', $mapped['faaliyet_kodu'])
                ->exists();

            ActivityCatalog::updateOrCreate(
                ['faaliyet_kodu' => $mapped['faaliyet_kodu']],
                $mapped
            );

            if ($existed) {
                $updated++;
            } else {
                $created++;
            }
        }

        return compact('created', 'updated', 'skipped');
    }

    /**
     * ActivityService ile uyumlu activity_sets.json üretir (silme yok, dosyanın tamamı yeniden yazılır).
     */
    public function regenerateActivitySetsJsonFromCatalog(): void
    {
        /** @var array<string, array{label: string, activities: list<array<string, string>>}> $byNormKey */
        $byNormKey = [];
        $catalogRows = ActivityCatalog::query()
            ->orderBy('mudurluk')
            ->orderBy('faaliyet_kodu')
            ->get();

        foreach ($catalogRows as $catalog) {
            $mudurluk = trim((string) $catalog->mudurluk);
            $norm = TurkishString::normalizeForFuzzyMatch($mudurluk);
            if ($norm === '') {
                continue;
            }
            if (! isset($byNormKey[$norm])) {
                $byNormKey[$norm] = ['label' => $mudurluk, 'activities' => []];
            }
            $byNormKey[$norm]['activities'][] = [
                'faaliyet_kodu' => (string) $catalog->faaliyet_kodu,
                'faaliyet_ailesi' => (string) $catalog->faaliyet_ailesi,
                'kategori' => (string) $catalog->kategori,
                'kapsam' => (string) $catalog->kapsam,
                'olcu_birimi' => (string) $catalog->olcu_birimi,
                'kpi_sla' => (string) $catalog->kpi_sla,
                'raporlama_sikligi' => (string) $catalog->raporlama_sikligi,
                'baskanlik_bilgilendirme_seviyesi' => (string) $catalog->baskanlik_bilgilendirme_seviyesi,
            ];
        }

        $sets = [];
        foreach ($byNormKey as $bucket) {
            $sets[] = [
                'mudurluk' => $bucket['label'],
                'activities' => array_values($bucket['activities']),
            ];
        }

        $out = $this->activitySetsOutputPath ?? resource_path('data/activity_sets.json');
        $payload = [
            'version' => 1,
            'source' => 'activity_catalog_server_snapshot',
            'sets' => $sets,
        ];

        File::put($out, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        app(ActivityService::class)->forgetCache();
    }

    public function buildGoogleSheetsCsvExportUrl(string $sheetUrl, string $gid = '0'): string
    {
        if (preg_match('~spreadsheets/d/([a-zA-Z0-9-_]+)~', $sheetUrl, $matches) !== 1) {
            throw new RuntimeException('Google Sheets linkinden spreadsheet id ayrıştırılamadı.');
        }

        $spreadsheetId = $matches[1];
        $gid = trim($gid) === '' ? '0' : trim($gid);

        return "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/gviz/tq?tqx=out:csv&gid={$gid}";
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>|null
     */
    private function mapRowToCatalogAttributes(array $row): ?array
    {
        $kod = trim((string) ($row['Faaliyet Kodu'] ?? ''));
        if ($kod === '') {
            return null;
        }

        return [
            'mudurluk' => trim((string) ($row['Müdürlük'] ?? '')),
            'faaliyet_kodu' => $kod,
            'faaliyet_ailesi' => trim((string) ($row['Faaliyet Ailesi'] ?? '')),
            'kategori' => trim((string) ($row['Kategori'] ?? '')),
            'kapsam' => trim((string) ($row['Kapsam'] ?? '')),
            'olcu_birimi' => trim((string) ($row['Ölçü Birimi'] ?? '')),
            'kpi_sla' => trim((string) ($row['Ana KPI / SLA'] ?? '')),
            'raporlama_sikligi' => trim((string) ($row['Raporlama Sıklığı'] ?? '')),
            'baskanlik_bilgilendirme_seviyesi' => trim((string) ($row['Başkanlık Bilgilendirme Seviyesi'] ?? '')),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsvRows(string $csv): array
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

        // UTF-8 BOM temizliği
        $headers = array_map(function ($header): string {
            $h = (string) $header;
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h;

            return trim($h);
        }, $headers);

        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (! is_array($values)) {
                continue;
            }

            $values = array_pad($values, count($headers), '');
            $assoc = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $assoc[$header] = trim((string) ($values[$index] ?? ''));
            }
            $rows[] = $assoc;
        }

        fclose($handle);

        return $rows;
    }
}
