<?php

namespace App\Support;

use App\Models\AylikFaaliyet;

/**
 * Koordinasyon satırlarında hedef olarak seçilen müdürlük kullanıcılarına gelen rapor erişimi.
 */
final class CoordinationAccess
{
    /**
     * Başka bir müdürlüğün raporunda, faaliyet türü "Koordinasyon" ve
     * isbirligi_hedef_mudurluk_user_ids içinde $userId geçen aylık faaliyet kayıt id'leri.
     *
     * @return list<int>
     */
    public static function incomingAylikFaaliyetIdsForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $ids = [];
        $query = AylikFaaliyet::query()
            ->select(['id', 'user_id', 'faaliyetler'])
            ->where('user_id', '!=', $userId)
            ->orderBy('id');

        foreach ($query->cursor() as $record) {
            if (! self::recordTargetsUserInCoordination($record->faaliyetler, $userId)) {
                continue;
            }
            $ids[] = (int) $record->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  mixed  $faaliyetler
     */
    public static function recordTargetsUserInCoordination($faaliyetler, int $userId): bool
    {
        if (! is_array($faaliyetler)) {
            return false;
        }

        foreach ($faaliyetler as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (($item['faaliyet_turu'] ?? '') !== 'Koordinasyon') {
                continue;
            }
            $targets = $item['isbirligi_hedef_mudurluk_user_ids'] ?? [];
            if (! is_array($targets)) {
                continue;
            }
            foreach ($targets as $tid) {
                if ((int) $tid === $userId) {
                    return true;
                }
            }
        }

        return false;
    }
}
