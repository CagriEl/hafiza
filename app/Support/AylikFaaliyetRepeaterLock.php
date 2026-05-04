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
        foreach (self::LOCKED_ROW_EDITABLE_KEYS as $key) {
            if (array_key_exists($key, $incoming)) {
                $original[$key] = $incoming[$key];
            }
        }

        $incomingKv = $incoming['kapsam_verileri'] ?? null;
        $originalKv = $original['kapsam_verileri'] ?? null;
        if (is_array($incomingKv) && is_array($originalKv)) {
            foreach (array_keys($originalKv) as $idx) {
                if (! isset($incomingKv[$idx], $originalKv[$idx]) || ! is_array($incomingKv[$idx]) || ! is_array($originalKv[$idx])) {
                    continue;
                }
                if (array_key_exists('gerceklesen', $incomingKv[$idx])) {
                    $original['kapsam_verileri'][$idx]['gerceklesen'] = $incomingKv[$idx]['gerceklesen'];
                }
            }
        }

        return $original;
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
                unset($data['faaliyetler'][$i]['kapsam_verileri'][$j]['gerceklesen']);
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
                    unset($data['faaliyetler'][$i]['kapsam_verileri'][$j]['gerceklesen']);
                }
            }
        }

        return $data;
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

        $v = $get('_orig_index');

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
}
