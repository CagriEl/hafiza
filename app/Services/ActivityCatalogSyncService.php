<?php

namespace App\Services;

use App\Models\ActivityCatalog;
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
            'source' => 'generated_from_faaliyet_seti_full',
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
