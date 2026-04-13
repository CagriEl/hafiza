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
    public static function selectOptionsForMudurluk(string $mudurlukAdi): array
    {
        $raw = app(ActivityService::class)->getCatalogOptionsForMudurluk($mudurlukAdi);
        if ($raw === []) {
            return [];
        }

        $ids = array_map('intval', array_keys($raw));
        $rows = ActivityCatalog::query()
            ->whereIn('id', $ids)
            ->get(['id', 'faaliyet_kodu', 'faaliyet_ailesi']);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->id] = trim((string) $row->faaliyet_kodu).' - '.trim((string) $row->faaliyet_ailesi);
        }

        return $out;
    }

    public static function labelForCatalogId(?int $id): ?string
    {
        if ($id === null || $id <= 0) {
            return null;
        }
        $row = ActivityCatalog::query()->find($id);
        if (! $row) {
            return null;
        }

        return trim((string) $row->faaliyet_kodu).' - '.trim((string) $row->faaliyet_ailesi);
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
}
