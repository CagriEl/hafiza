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
        'gerekli_revize',
        'revize_sebebi',
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

        if (! $performansKilitli) {
            foreach (self::LOCKED_ROW_EDITABLE_KEYS as $key) {
                if (array_key_exists($key, $incoming)) {
                    $original[$key] = $incoming[$key];
                }
            }
        }

        $incomingKv = $incoming['kapsam_verileri'] ?? null;
        $originalKv = $original['kapsam_verileri'] ?? null;
        if (! $performansKilitli && is_array($incomingKv) && is_array($originalKv)) {
            foreach (array_keys($originalKv) as $idx) {
                if (! isset($incomingKv[$idx], $originalKv[$idx]) || ! is_array($incomingKv[$idx]) || ! is_array($originalKv[$idx])) {
                    continue;
                }
                if (array_key_exists('gerceklesen', $incomingKv[$idx])) {
                    $original['kapsam_verileri'][$idx]['gerceklesen'] = $incomingKv[$idx]['gerceklesen'];
                }
                $original['kapsam_verileri'][$idx]['acikta_kalan'] = self::kapsamSatirAciktaKalan(
                    $original['kapsam_verileri'][$idx]
                );
            }
        }

        $original['ay_sonu_performans_kilitli'] = (bool) ($original['ay_sonu_performans_kilitli'] ?? false)
            || (bool) ($incoming['ay_sonu_performans_kilitli'] ?? false);

        if (array_key_exists('_orig_index', $incoming)) {
            $original['_orig_index'] = $incoming['_orig_index'];
        }

        return $original;
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
        $ong = $line['ongorulen'] ?? $line['deger'] ?? null;
        $ger = $line['gerceklesen'] ?? null;
        if (self::isNumericFormScalar($ong) && self::isNumericFormScalar($ger)) {
            return max(0, (float) $ong - (float) $ger);
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
