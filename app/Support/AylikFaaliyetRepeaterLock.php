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
                    $out[] = $orig[$idx];

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
                unset($data['faaliyetler'][$i]['_orig_index']);
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
