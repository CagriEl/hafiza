<?php

namespace App\Support;

use App\Models\AylikFaaliyet;
use Illuminate\Support\Facades\Cache;

/**
 * Koordinasyon satırlarında hedef olarak seçilen müdürlük kullanıcılarına gelen rapor erişimi.
 */
final class CoordinationAccess
{
    private const INCOMING_IDS_CACHE_TTL_SECONDS = 120;

    /** @var array<int, list<int>> */
    private static array $incomingIdsMemo = [];

    /**
     * @param  mixed  $faaliyetler
     */
    public static function recordHasCoordinationLine($faaliyetler): bool
    {
        if (! is_array($faaliyetler)) {
            return false;
        }

        foreach ($faaliyetler as $item) {
            if (is_array($item) && (($item['faaliyet_turu'] ?? '') === 'Koordinasyon')) {
                return true;
            }
        }

        return false;
    }

    public static function resetRequestMemo(): void
    {
        self::$incomingIdsMemo = [];
    }

    /**
     * @return list<int>
     */
    public static function incomingAylikFaaliyetIdsForUserIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return [];
        }

        $out = [];
        $missing = [];
        foreach ($userIds as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $known = self::rememberedIncomingIds($uid);
            if ($known !== null) {
                $out = array_merge($out, $known);

                continue;
            }
            $missing[] = $uid;
        }

        if ($missing !== []) {
            $scanned = self::scanIncomingIdsForUsers($missing);
            foreach ($missing as $uid) {
                $ids = $scanned[$uid] ?? [];
                self::storeIncomingIds($uid, $ids);
                $out = array_merge($out, $ids);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Gelen koordinasyon ortağı: rapor sahibi değil; satırda hedef müdürlük olarak seçilmiş.
     */
    public static function isIncomingPartnerOnRecord(AylikFaaliyet $record, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if ((int) $record->user_id === $userId) {
            return false;
        }

        return self::recordTargetsUserInCoordination($record->faaliyetler, $userId);
    }

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

        $known = self::rememberedIncomingIds($userId);
        if ($known !== null) {
            return $known;
        }

        $ids = Cache::remember(self::incomingIdsCacheKey($userId), self::INCOMING_IDS_CACHE_TTL_SECONDS, function () use ($userId): array {
            return self::scanIncomingIdsForUsers([$userId])[$userId] ?? [];
        });
        $ids = self::normalizeIdList(is_array($ids) ? $ids : []);
        self::$incomingIdsMemo[$userId] = $ids;

        return $ids;
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

    /**
     * @return list<int>|null
     */
    private static function rememberedIncomingIds(int $userId): ?array
    {
        if (array_key_exists($userId, self::$incomingIdsMemo)) {
            return self::$incomingIdsMemo[$userId];
        }

        $cached = Cache::get(self::incomingIdsCacheKey($userId));
        if (! is_array($cached)) {
            return null;
        }

        $ids = self::normalizeIdList($cached);
        self::$incomingIdsMemo[$userId] = $ids;

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     */
    private static function storeIncomingIds(int $userId, array $ids): void
    {
        $ids = self::normalizeIdList($ids);
        self::$incomingIdsMemo[$userId] = $ids;
        Cache::put(self::incomingIdsCacheKey($userId), $ids, self::INCOMING_IDS_CACHE_TTL_SECONDS);
    }

    private static function incomingIdsCacheKey(int $userId): string
    {
        return 'coordination.incoming.aylik_faaliyet_ids.v1.'.$userId;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, list<int>>
     */
    private static function scanIncomingIdsForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            fn (int $id): bool => $id > 0
        )));

        $found = [];
        foreach ($userIds as $uid) {
            $found[$uid] = [];
        }
        if ($userIds === []) {
            return $found;
        }

        $wanted = array_fill_keys($userIds, true);
        $query = AylikFaaliyet::query()
            ->select(['id', 'user_id', 'faaliyetler'])
            ->orderBy('id');

        if (self::supportsMysqlJsonSearch()) {
            $query->whereRaw("JSON_SEARCH(faaliyetler, 'one', 'Koordinasyon', NULL, '$[*].faaliyet_turu') IS NOT NULL");
        }

        foreach ($query->cursor() as $record) {
            $ownerId = (int) $record->user_id;
            $faaliyetler = $record->faaliyetler;
            if (! is_array($faaliyetler)) {
                continue;
            }
            if (! self::supportsMysqlJsonSearch() && ! self::recordHasCoordinationLine($faaliyetler)) {
                continue;
            }
            foreach (self::coordinationTargetUserIds($faaliyetler) as $tid) {
                if (! isset($wanted[$tid]) || $tid === $ownerId) {
                    continue;
                }
                $found[$tid][] = (int) $record->id;
            }
        }

        foreach ($found as $uid => $ids) {
            $found[$uid] = self::normalizeIdList($ids);
        }

        return $found;
    }

    /**
     * @param  mixed  $faaliyetler
     * @return list<int>
     */
    private static function coordinationTargetUserIds($faaliyetler): array
    {
        if (! is_array($faaliyetler)) {
            return [];
        }

        $ids = [];
        foreach ($faaliyetler as $item) {
            if (! is_array($item) || ($item['faaliyet_turu'] ?? '') !== 'Koordinasyon') {
                continue;
            }
            $targets = $item['isbirligi_hedef_mudurluk_user_ids'] ?? [];
            if (! is_array($targets)) {
                continue;
            }
            foreach ($targets as $tid) {
                $id = (int) $tid;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int|string, mixed>  $ids
     * @return list<int>
     */
    private static function normalizeIdList(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id): bool => $id > 0
        )));
    }

    private static function supportsMysqlJsonSearch(): bool
    {
        $driver = AylikFaaliyet::query()->getConnection()->getDriverName();

        return in_array($driver, ['mysql', 'mariadb'], true);
    }
}
