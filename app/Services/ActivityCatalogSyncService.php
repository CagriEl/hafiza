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

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($decoded as $row) {
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
    public function regenerateActivitySetsJson(?string $path = null): void
    {
        $path ??= $this->resolveFullJsonPath();
        if (! File::isReadable($path)) {
            throw new RuntimeException("activity_sets.json için kaynak okunamadı: {$path}");
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Geçersiz JSON.');
        }

        /** @var array<string, array{label: string, activities: list<array<string, string>>}> $byNormKey */
        $byNormKey = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = $this->mapRowToCatalogAttributes($row);
            if ($mapped === null) {
                continue;
            }
            $mudurluk = $mapped['mudurluk'];
            $norm = TurkishString::normalizeForFuzzyMatch($mudurluk);
            if ($norm === '') {
                continue;
            }
            if (! isset($byNormKey[$norm])) {
                $byNormKey[$norm] = ['label' => $mudurluk, 'activities' => []];
            }
            $byNormKey[$norm]['activities'][] = [
                'faaliyet_kodu' => $mapped['faaliyet_kodu'],
                'faaliyet_ailesi' => $mapped['faaliyet_ailesi'],
                'kategori' => $mapped['kategori'],
                'kapsam' => $mapped['kapsam'],
                'olcu_birimi' => $mapped['olcu_birimi'],
                'kpi_sla' => $mapped['kpi_sla'],
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
}
