<?php

namespace App\Support;

use App\Models\AylikFaaliyet;
use App\Models\User;
use Filament\Forms\Get;
use Filament\Resources\Pages\EditRecord;

/**
 * Aylık rapor repeater: müdürlük hesabı kayıtlı satırları değiştiremez; yeni satır ekleyip revize işaretler.
 */
final class AylikFaaliyetRepeaterLock
{
    /** @var list<string> */
    private const LOCKED_ROW_EDITABLE_KEYS = [
        'gerceklesen',
        'bekleyen_is',
        'sapma_nedeni',
        'risk_engel',
        'karar_ihtiyaci',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function stampOrigIndexes(array $data): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        $i = 0;
        foreach ($data['faaliyetler'] as $key => $row) {
            if (is_array($row)) {
                $data['faaliyetler'][$key]['_orig_index'] = $i++;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function enforceMudurlukLocks(AylikFaaliyet $record, ?User $user, array $data): array
    {
        if (! $user instanceof User || ! $user->isMudurlukReportingAccount()) {
            return $data;
        }

        if (! self::actorOwnsAylikFaaliyetRecord($record, $user)) {
            return $data;
        }

        $orig = $record->faaliyetler;
        if (! is_array($orig)) {
            $orig = [];
        }
        $orig = array_values($orig);

        $rows = $data['faaliyetler'] ?? null;
        if (! is_array($rows)) {
            return $data;
        }

        $out = [];
        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rawIdx = $row['_orig_index'] ?? null;
            if ($rawIdx !== null && $rawIdx !== '' && is_numeric((string) $rawIdx)) {
                $idx = (int) $rawIdx;
                if (array_key_exists($idx, $orig)) {
                    $out[] = self::mergeLockedRowWithAllowedInputs($orig[$idx], $row);

                    continue;
                }
            }

            unset($row['_orig_index']);
            $out[] = $row;
        }

        $data['faaliyetler'] = $out;

        return $data;
    }

    /**
     * Kayıtlı satırlarda yalnızca sınırlı alanların güncellenmesine izin ver.
     *
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private static function mergeLockedRowWithAllowedInputs(array $original, array $incoming): array
    {
        $performansKilitli = (bool) ($original['ay_sonu_performans_kilitli'] ?? false);

        $incomingKv = $incoming['kapsam_verileri'] ?? null;
        $originalKv = $original['kapsam_verileri'] ?? null;
        $hasOpenPending = false;
        if (is_array($originalKv)) {
            foreach ($originalKv as $line) {
                if (is_array($line) && AylikFaaliyetWeeklyCarryover::kapsamPendingAmount($line) > 0.0) {
                    $hasOpenPending = true;
                    break;
                }
            }
        }
        if (! $hasOpenPending && is_numeric($original['bekleyen_is'] ?? null) && (float) $original['bekleyen_is'] > 0.0) {
            $hasOpenPending = true;
        }

        // Açık iş varken kapatma / tamamlanan güncellemesine izin ver (eski kilitli kayıtlar dahil).
        $allowKapsamProgress = (! $performansKilitli) || $hasOpenPending;

        if ($allowKapsamProgress) {
            foreach (self::LOCKED_ROW_EDITABLE_KEYS as $key) {
                if (array_key_exists($key, $incoming)) {
                    $original[$key] = $incoming[$key];
                }
            }
        }

        if ($allowKapsamProgress && is_array($incomingKv) && is_array($originalKv)) {
            foreach (array_keys($originalKv) as $idx) {
                if (! isset($incomingKv[$idx], $originalKv[$idx]) || ! is_array($incomingKv[$idx]) || ! is_array($originalKv[$idx])) {
                    continue;
                }
                if (! $performansKilitli && array_key_exists('ongorulen', $incomingKv[$idx])
                    && ! self::isNumericFormScalar($original['kapsam_verileri'][$idx]['ongorulen'] ?? $original['kapsam_verileri'][$idx]['deger'] ?? null)) {
                    $original['kapsam_verileri'][$idx]['ongorulen'] = $incomingKv[$idx]['ongorulen'];
                }
                $yapilanKayitli = self::isNumericFormScalar($original['kapsam_verileri'][$idx]['ongorulen'] ?? $original['kapsam_verileri'][$idx]['deger'] ?? null)
                    || self::isNumericFormScalar($incomingKv[$idx]['ongorulen'] ?? $incomingKv[$idx]['deger'] ?? null);
                if ($yapilanKayitli && self::isNumericFormScalar($incomingKv[$idx]['gerceklesen'] ?? null)) {
                    $original['kapsam_verileri'][$idx]['gerceklesen'] = $incomingKv[$idx]['gerceklesen'];
                }
                if (array_key_exists('haftalik_kayitlar', $incomingKv[$idx])
                    && is_array($incomingKv[$idx]['haftalik_kayitlar'])) {
                    $original['kapsam_verileri'][$idx]['haftalik_kayitlar'] = $incomingKv[$idx]['haftalik_kayitlar'];
                }
                if (array_key_exists('son_yapilma_tarihi', $incomingKv[$idx])) {
                    $original['kapsam_verileri'][$idx]['son_yapilma_tarihi'] = $incomingKv[$idx]['son_yapilma_tarihi'];
                }
                foreach (['baslangic_tarihi', 'bitis_tarihi'] as $dateKey) {
                    if (! array_key_exists($dateKey, $incomingKv[$idx])) {
                        continue;
                    }
                    $existingDate = trim((string) ($original['kapsam_verileri'][$idx][$dateKey] ?? ''));
                    $incomingDate = trim((string) ($incomingKv[$idx][$dateKey] ?? ''));
                    if ($existingDate === '' || $incomingDate !== '') {
                        $original['kapsam_verileri'][$idx][$dateKey] = $incomingKv[$idx][$dateKey];
                    }
                }
                if (array_key_exists('islem_turu', $incomingKv[$idx])) {
                    $incomingTur = KapsamIslemTuru::normalize($incomingKv[$idx]['islem_turu'] ?? null);
                    $existingTur = KapsamIslemTuru::normalize($original['kapsam_verileri'][$idx]['islem_turu'] ?? null);
                    if ($existingTur === null || $incomingTur !== null) {
                        $original['kapsam_verileri'][$idx]['islem_turu'] = $incomingTur;
                    }
                }
                $incomingKapatildi = (bool) ($incomingKv[$idx]['acikta_kapatildi'] ?? false);
                foreach (['acikta_revize_tarihi', 'acikta_revize_notu'] as $revizeKey) {
                    if (! array_key_exists($revizeKey, $incomingKv[$idx])) {
                        continue;
                    }
                    $existing = trim((string) ($original['kapsam_verileri'][$idx][$revizeKey] ?? ''));
                    if ($existing !== '' && ! $incomingKapatildi) {
                        continue;
                    }
                    $incomingVal = trim((string) ($incomingKv[$idx][$revizeKey] ?? ''));
                    if ($incomingVal !== '') {
                        $original['kapsam_verileri'][$idx][$revizeKey] = $incomingKv[$idx][$revizeKey];
                    }
                }
                foreach (['acikta_kapatildi', 'acikta_kapatma_notu', 'acikta_is_kapatiliyor', 'kalan_acik_tamamla', 'acikta_kapanis_miktar', 'acikta_not_kapat_miktar', 'not_ile_kapatilan'] as $kapatmaKey) {
                    if (array_key_exists($kapatmaKey, $incomingKv[$idx])) {
                        $original['kapsam_verileri'][$idx][$kapatmaKey] = $incomingKv[$idx][$kapatmaKey];
                    }
                }
                if ((bool) ($original['kapsam_verileri'][$idx]['acikta_kapatildi'] ?? false)) {
                    $original['kapsam_verileri'][$idx]['acikta_kalan'] = 0;
                } else {
                    $original['kapsam_verileri'][$idx]['acikta_kalan'] = self::kapsamSatirAciktaKalan(
                        $original['kapsam_verileri'][$idx]
                    );
                }
            }
        }

        $original['ay_sonu_performans_kilitli'] = (bool) ($original['ay_sonu_performans_kilitli'] ?? false)
            || (bool) ($incoming['ay_sonu_performans_kilitli'] ?? false);

        if (array_key_exists('_orig_index', $incoming)) {
            $original['_orig_index'] = $incoming['_orig_index'];
        }

        foreach (['gerekli_revize', 'revize_sebebi'] as $revKey) {
            if (array_key_exists($revKey, $incoming)) {
                $original[$revKey] = $incoming[$revKey];
            }
        }

        return $original;
    }

    /**
     * Kısmi tamamlanan sonrası yanlışlıkla kilitlenen satırlarda açık iş varken kilidi gevşet.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function relaxPerformansKilitWhilePending(array $data): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row) || ! (bool) ($row['ay_sonu_performans_kilitli'] ?? false)) {
                continue;
            }

            $hasPending = false;
            $bekleyen = $row['bekleyen_is'] ?? null;
            if (is_numeric($bekleyen) && (float) $bekleyen > 0.0) {
                $hasPending = true;
            }

            $kv = $row['kapsam_verileri'] ?? null;
            if (! $hasPending && is_array($kv)) {
                foreach ($kv as $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    if (AylikFaaliyetWeeklyCarryover::kapsamPendingAmount($line) > 0.0) {
                        $hasPending = true;
                        break;
                    }
                }
            }

            if ($hasPending) {
                $data['faaliyetler'][$i]['ay_sonu_performans_kilitli'] = false;
            }
        }

        return $data;
    }

    /**
     * Müdürlük ilk kez satır ay sonu (gerçekleşen + bekleyen) ve varsa her kapsam satırı (gerçekleşen + açıkta kalan)
     * doldurulduktan sonra bir daha değiştiremesin.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyAySonuPerformansKilitAfterMudurlukSave(AylikFaaliyet $record, User $user, array $data): array
    {
        if (! $user->isMudurlukReportingAccount() || ! self::actorOwnsAylikFaaliyetRecord($record, $user)) {
            return $data;
        }

        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        $origRows = is_array($record->faaliyetler) ? array_values($record->faaliyetler) : [];

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $rawIdx = $row['_orig_index'] ?? null;
            if ($rawIdx === null || $rawIdx === '' || ! is_numeric((string) $rawIdx)) {
                continue;
            }
            $idx = (int) $rawIdx;
            if (! array_key_exists($idx, $origRows)) {
                continue;
            }
            $wasLocked = (bool) (($origRows[$idx]['ay_sonu_performans_kilitli'] ?? false));
            if ($wasLocked) {
                continue;
            }
            $g = $row['gerceklesen'] ?? null;
            $b = $row['bekleyen_is'] ?? null;
            if (! filled($g) || ! filled($b)) {
                continue;
            }

            // Açıkta kalan iş varken kilitleme: kapatma / miktar girişi mümkün kalsın.
            if (is_numeric($b) && (float) $b > 0.0) {
                continue;
            }

            $kapsamOk = true;
            $kv = $row['kapsam_verileri'] ?? null;
            if (is_array($kv) && $kv !== []) {
                foreach ($kv as $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    if (! self::kapsamSatirindaAySonuGerceklesenGirilmis($line)) {
                        $kapsamOk = false;
                        break;
                    }
                    if (AylikFaaliyetWeeklyCarryover::kapsamPendingAmount($line) > 0.0) {
                        $kapsamOk = false;
                        break;
                    }
                }
            }

            if ($kapsamOk) {
                $data['faaliyetler'][$i]['ay_sonu_performans_kilitli'] = true;
            }
        }

        return $data;
    }

    /**
     * Eski kayıtlar: değerler var ama kilit bayrağı yoksa kilitli kabul et (tek seferlik model).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function hydrateAySonuPerformansKilitFromLegacy(array $data): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['ay_sonu_performans_kilitli'] ?? false)) {
                continue;
            }
            if (! filled($row['gerceklesen'] ?? null) || ! filled($row['bekleyen_is'] ?? null)) {
                continue;
            }

            $bekleyen = $row['bekleyen_is'] ?? null;
            if (is_numeric($bekleyen) && (float) $bekleyen > 0.0) {
                continue;
            }

            $kapsamOk = true;
            $kv = $row['kapsam_verileri'] ?? null;
            if (is_array($kv) && $kv !== []) {
                foreach ($kv as $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    if (! self::kapsamSatirindaAySonuGerceklesenGirilmis($line)) {
                        $kapsamOk = false;
                        break;
                    }
                    if (AylikFaaliyetWeeklyCarryover::kapsamPendingAmount($line) > 0.0) {
                        $kapsamOk = false;
                        break;
                    }
                }
            }

            if ($kapsamOk) {
                $data['faaliyetler'][$i]['ay_sonu_performans_kilitli'] = true;
            }
        }

        return $data;
    }

    /**
     * Form yardım alanı; JSON’da saklanmamalı.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function stripInternalKeysFromFaaliyetler(array $data): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        foreach ($data['faaliyetler'] as $i => $row) {
            if (is_array($row)) {
                unset($data['faaliyetler'][$i]['_orig_index'], $data['faaliyetler'][$i]['miktar']);
                $kv = $data['faaliyetler'][$i]['kapsam_verileri'] ?? null;
                if (is_array($kv)) {
                    foreach (array_keys($kv) as $j) {
                        if (is_array($data['faaliyetler'][$i]['kapsam_verileri'][$j] ?? null)) {
                            unset($data['faaliyetler'][$i]['kapsam_verileri'][$j]['acikta_is_kapatiliyor']);
                            unset($data['faaliyetler'][$i]['kapsam_verileri'][$j]['acikta_kapanis_miktar']);
                            unset($data['faaliyetler'][$i]['kapsam_verileri'][$j]['acikta_not_kapat_miktar']);
                            unset($data['faaliyetler'][$i]['kapsam_verileri'][$j]['kalan_acik_tamamla']);
                        }
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Sayısal performans alanlarında negatif değerleri 0 yapar (form dışı gönderim / eski veri).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function clampNonNegativeNumericFaaliyetler(array $data): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        $rowKeys = ['hedef', 'gerceklesen', 'bekleyen_is'];
        $kapsamKeys = ['ongorulen', 'deger', 'gerceklesen', 'acikta_kalan'];

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($rowKeys as $key) {
                self::clampArrayKeyNonNegative($data['faaliyetler'][$i], $key);
            }
            $kv = $data['faaliyetler'][$i]['kapsam_verileri'] ?? null;
            if (! is_array($kv)) {
                continue;
            }
            foreach (array_keys($kv) as $j) {
                if (! is_array($data['faaliyetler'][$i]['kapsam_verileri'][$j] ?? null)) {
                    continue;
                }
                foreach ($kapsamKeys as $key) {
                    self::clampArrayKeyNonNegative($data['faaliyetler'][$i]['kapsam_verileri'][$j], $key);
                }
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function clampArrayKeyNonNegative(array &$row, string $key): void
    {
        if (! array_key_exists($key, $row)) {
            return;
        }
        $row[$key] = NonNegativeInput::normalizeScalar($row[$key]);
    }

    /**
     * Kapsam kalemleri tanımlıysa satır düzeyindeki gerçekleşen / açıkta bekleyen, kalemlerdeki
     * gerçekleşen ve açıkta kalan değerlerinin toplamı olarak yazılır (formda bu alanlar gösterilmez).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function syncRowAySonuTotalsFromKapsamVerileri(array $data): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $kv = $row['kapsam_verileri'] ?? null;
            if (! is_array($kv) || $kv === []) {
                continue;
            }

            foreach (array_keys($kv) as $j) {
                if (! is_array($data['faaliyetler'][$i]['kapsam_verileri'][$j] ?? null)) {
                    continue;
                }
                $data['faaliyetler'][$i]['kapsam_verileri'][$j]['acikta_kalan'] = self::kapsamSatirAciktaKalan(
                    $data['faaliyetler'][$i]['kapsam_verileri'][$j]
                );
            }
            $kv = $data['faaliyetler'][$i]['kapsam_verileri'];

            $sumG = 0.0;
            $sumB = 0.0;
            $anyAySonu = false;

            foreach ($kv as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $g = $line['gerceklesen'] ?? null;
                $b = $line['acikta_kalan'] ?? null;
                if (filled($g) || filled($b)) {
                    $anyAySonu = true;
                }
                if (filled($g) && is_numeric($g)) {
                    $sumG += (float) $g;
                }
                if (filled($b) && is_numeric($b)) {
                    $sumB += (float) $b;
                }
            }

            if ($anyAySonu) {
                $data['faaliyetler'][$i]['gerceklesen'] = $sumG;
                $data['faaliyetler'][$i]['bekleyen_is'] = $sumB;
            }
        }

        return $data;
    }

    /**
     * Eski şema: kapsam satırında yalnızca "deger" vardı → "ongorulen" olarak taşınır.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function migrateLegacyKapsamVerileriKeys(array $data): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row) || ! isset($row['kapsam_verileri']) || ! is_array($row['kapsam_verileri'])) {
                continue;
            }
            foreach ($row['kapsam_verileri'] as $j => $kv) {
                if (! is_array($kv)) {
                    continue;
                }
                if (! array_key_exists('ongorulen', $kv) && array_key_exists('deger', $kv)) {
                    $data['faaliyetler'][$i]['kapsam_verileri'][$j]['ongorulen'] = $kv['deger'];
                    unset($data['faaliyetler'][$i]['kapsam_verileri'][$j]['deger']);
                }
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function stripNestedKapsamGerceklesenFromFaaliyetRows(array $data): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row) || ! isset($row['kapsam_verileri']) || ! is_array($row['kapsam_verileri'])) {
                continue;
            }
            foreach (array_keys($row['kapsam_verileri']) as $j) {
                if (! is_array($data['faaliyetler'][$i]['kapsam_verileri'][$j] ?? null)) {
                    continue;
                }
                unset(
                    $data['faaliyetler'][$i]['kapsam_verileri'][$j]['gerceklesen'],
                    $data['faaliyetler'][$i]['kapsam_verileri'][$j]['acikta_kalan'],
                );
            }
        }

        return $data;
    }

    /**
     * İlk plan kaydında ay sonu performans alanları tutulmaz; sonradan düzenlemede girilir.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function stripAySonuFieldsFromPlanOnlySave(array $data): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            unset(
                $data['faaliyetler'][$i]['gerceklesen'],
                $data['faaliyetler'][$i]['bekleyen_is'],
                $data['faaliyetler'][$i]['sapma_nedeni'],
            );
        }

        return self::stripNestedKapsamGerceklesenFromFaaliyetRows($data);
    }

    /**
     * Müdürlük sahibi yeni (revize) satırda plan dışı alanları gönderemesin.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function stripAySonuFieldsFromUnpersistedMudurlukRows(AylikFaaliyet $record, User $user, array $data): array
    {
        if (! $user->isMudurlukReportingAccount() || ! self::actorOwnsAylikFaaliyetRecord($record, $user)) {
            return $data;
        }

        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $v = $row['_orig_index'] ?? null;
            if ($v !== null && $v !== '') {
                continue;
            }
            unset(
                $data['faaliyetler'][$i]['gerceklesen'],
                $data['faaliyetler'][$i]['bekleyen_is'],
                $data['faaliyetler'][$i]['sapma_nedeni'],
            );
            if (isset($data['faaliyetler'][$i]['kapsam_verileri']) && is_array($data['faaliyetler'][$i]['kapsam_verileri'])) {
                foreach (array_keys($data['faaliyetler'][$i]['kapsam_verileri']) as $j) {
                    if (! is_array($data['faaliyetler'][$i]['kapsam_verileri'][$j] ?? null)) {
                        continue;
                    }
                    unset(
                        $data['faaliyetler'][$i]['kapsam_verileri'][$j]['gerceklesen'],
                        $data['faaliyetler'][$i]['kapsam_verileri'][$j]['acikta_kalan'],
                    );
                }
            }
        }

        return $data;
    }

    /**
     * faaliyetler.* satırındaki _orig_index; kapsam_verileri.* alt alanındayken üst faaliyet satırına çıkılır.
     */
    public static function resolveFaaliyetRowOrigIndex(Get $get): mixed
    {
        $v = $get('_orig_index');
        if ($v !== null && $v !== '') {
            return $v;
        }

        return $get('../../_orig_index');
    }

    /**
     * Ay sonu performans kilidi: iç içe repeater içinde üst satırdaki bayrak.
     */
    public static function resolveFaaliyetRowAySonuPerformansKilitli(Get $get): bool
    {
        return (bool) ($get('ay_sonu_performans_kilitli') ?? $get('../../ay_sonu_performans_kilitli') ?? false);
    }

    public static function mudurlukOwnsRecordAndRowIsLocked(Get $get, mixed $livewire): bool
    {
        if (! auth()->user()?->isMudurlukReportingAccount()) {
            return false;
        }

        if (! $livewire instanceof EditRecord) {
            return false;
        }

        $record = $livewire->getRecord();
        if (! $record instanceof AylikFaaliyet) {
            return false;
        }

        $user = auth()->user();
        if (! $user instanceof User || ! self::actorOwnsAylikFaaliyetRecord($record, $user)) {
            return false;
        }

        $v = self::resolveFaaliyetRowOrigIndex($get);

        return ! ($v === null || $v === '');
    }

    public static function actorOwnsAylikFaaliyetRecord(AylikFaaliyet $record, User $user): bool
    {
        if ((int) $record->user_id === (int) $user->id) {
            return true;
        }

        if ($user->hasActiveVekaletFullAuthority()
            && (int) $user->vekalet_mudurluk_user_id === (int) $record->user_id) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function kapsamSatirAciktaKalan(array $line): mixed
    {
        if ((bool) ($line['acikta_kapatildi'] ?? false)) {
            return 0;
        }

        $ong = $line['ongorulen'] ?? $line['deger'] ?? null;
        $ger = $line['gerceklesen'] ?? null;
        if (self::isNumericFormScalar($ong) && self::isNumericFormScalar($ger)) {
            $notClosed = AylikFaaliyetWeeklyCarryover::notIleKapatilanToplam($line);

            return max(0, (float) $ong - (float) $ger - $notClosed);
        }

        return null;
    }

    /**
     * Faaliyet satırındaki kapsam kalemlerinde toplam açıkta kalan (satır başına).
     *
     * @param  array<string, mixed>  $faaliyetRow
     */
    public static function faaliyetKapsamToplamAciktaKalan(array $faaliyetRow): float
    {
        $kv = $faaliyetRow['kapsam_verileri'] ?? null;
        if (! is_array($kv)) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($kv as $line) {
            if (! is_array($line)) {
                continue;
            }
            $a = self::kapsamSatirAciktaKalan($line);
            if ($a !== null && is_numeric($a)) {
                $sum += (float) $a;
            }
        }

        return $sum;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function kapsamSatirindaAySonuGerceklesenGirilmis(array $line): bool
    {
        return self::isNumericFormScalar($line['gerceklesen'] ?? null);
    }

    private static function isNumericFormScalar(mixed $v): bool
    {
        if ($v === null || $v === '') {
            return false;
        }

        return is_numeric($v);
    }
}
