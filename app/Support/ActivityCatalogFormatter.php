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
}
