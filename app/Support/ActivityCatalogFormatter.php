<?php

namespace App\Support;

use App\Models\ActivityCatalog;
use App\Services\ActivityService;

/**
 * Faaliyet kataloğu seçimlerinde [Kod] - [Ad] formatı.
 *
 * @return array<int|string, string>
 */
final class ActivityCatalogFormatter
{
    /** @var list<string> */
    private const HIDDEN_ACTIVITY_CODES = [
        'MHM-07',
        'MHM-08',
        'MHM-09',
    ];

    /** @var array<int, string|null> */
    private static array $labelCache = [];

    public static function selectOptionsForMudurluk(string $mudurlukAdi): array
    {
        $raw = app(ActivityService::class)->getCatalogOptionsForMudurluk($mudurlukAdi);
        if ($raw === []) {
            return [];
        }

        $ids = array_map('intval', array_keys($raw));
        $rows = ActivityCatalog::query()
            ->whereIn('id', $ids)
            ->get(['id', 'faaliyet_kodu', 'faaliyet_ailesi', 'raporlama_sikligi']);

        $out = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row->faaliyet_kodu ?? ''));
            if ($code !== '' && in_array($code, self::HIDDEN_ACTIVITY_CODES, true)) {
                continue;
            }
            $label = static::buildCatalogLabel($row);
            $out[(int) $row->id] = $label;
            self::$labelCache[(int) $row->id] = $label;
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     */
    public static function warmLabelCache(array $ids): void
    {
        $missing = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && ! array_key_exists($id, self::$labelCache)) {
                $missing[] = $id;
            }
        }
        if ($missing === []) {
            return;
        }

        $rows = ActivityCatalog::query()
            ->whereIn('id', array_values(array_unique($missing)))
            ->get(['id', 'faaliyet_kodu', 'faaliyet_ailesi', 'raporlama_sikligi']);

        $found = [];
        foreach ($rows as $row) {
            $label = static::buildCatalogLabel($row);
            self::$labelCache[(int) $row->id] = $label;
            $found[(int) $row->id] = true;
        }
        foreach ($missing as $id) {
            if (! isset($found[$id])) {
                self::$labelCache[$id] = null;
            }
        }
    }

    public static function labelForCatalogId(?int $id): ?string
    {
        if ($id === null || $id <= 0) {
            return null;
        }
        if (array_key_exists($id, self::$labelCache)) {
            return self::$labelCache[$id];
        }

        $row = ActivityCatalog::query()->find($id);
        if (! $row) {
            self::$labelCache[$id] = null;

            return null;
        }

        return self::$labelCache[$id] = static::buildCatalogLabel($row);
    }

    /**
     * Eski satırlarda yalnızca faaliyet_kodu varken katalog id boş kalıyor; formda seçim görünsün diye id tamamlanır.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function hydrateActivityCatalogIdsInFaaliyetler(array $data, ?string $raporMudurlukAdi): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        foreach ($data['faaliyetler'] as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((int) ($row['activity_catalog_id'] ?? 0) > 0) {
                continue;
            }
            $kod = trim((string) ($row['faaliyet_kodu'] ?? ''));
            if ($kod === '') {
                continue;
            }

            $candidates = ActivityCatalog::query()->where('faaliyet_kodu', $kod)->get();
            if ($candidates->isEmpty()) {
                continue;
            }

            $picked = null;
            $mudurluk = $raporMudurlukAdi !== null ? trim($raporMudurlukAdi) : '';
            if ($mudurluk !== '') {
                $picked = $candidates->first(fn (ActivityCatalog $r): bool => TurkishString::same((string) $r->mudurluk, $mudurluk));
            }

            $picked ??= $candidates->sortBy('id')->first();
            if ($picked instanceof ActivityCatalog) {
                $data['faaliyetler'][$key]['activity_catalog_id'] = $picked->id;
            }
        }

        return $data;
    }

    private static function buildCatalogLabel(ActivityCatalog $row): string
    {
        $base = trim((string) $row->faaliyet_kodu).' - '.trim((string) $row->faaliyet_ailesi);
        $olcuBirimi = trim((string) ($row->olcu_birimi ?? ''));
        $kpiSla = trim((string) ($row->kpi_sla ?? ''));
        $freq = trim((string) ($row->raporlama_sikligi ?? ''));
        $infoLevel = trim((string) ($row->baskanlik_bilgilendirme_seviyesi ?? ''));

        $parts = [];
        if ($olcuBirimi !== '') {
            $parts[] = 'Ölçü Birimi: '.$olcuBirimi;
        }
        if ($kpiSla !== '') {
            $parts[] = 'KPI/SLA: '.$kpiSla;
        }
        if ($freq !== '') {
            $parts[] = 'Raporlama: '.$freq;
        }
        if ($infoLevel !== '') {
            $parts[] = 'Bilgilendirme: '.$infoLevel;
        }

        return $parts === [] ? $base : $base.' ('.implode(' | ', $parts).')';
    }
}
