<?php

namespace App\Support;

use App\Models\AylikFaaliyet;
use App\Models\ControlTeamAuditNote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aynı müdürlük + yıl + ay için birden fazla AylikFaaliyet kaydını tek raporda birleştirir.
 * Hafta / tarih ayrımı faaliyet satırlarında (hafta + haftalik_kayitlar) korunur.
 */
final class AylikFaaliyetPeriodMerge
{
    /**
     * @return list<string>
     */
    public static function ayQueryVariants(string|int $ay): array
    {
        $digits = preg_replace('/\D/', '', (string) $ay) ?: '';
        if ($digits === '') {
            return [];
        }

        $n = (int) $digits;
        if ($n < 1 || $n > 12) {
            return [trim((string) $ay)];
        }

        return array_values(array_unique([
            str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            (string) $n,
        ]));
    }

    public static function normalizeAy(string|int $ay): string
    {
        $digits = preg_replace('/\D/', '', (string) $ay) ?: '';
        $n = (int) $digits;

        return ($n >= 1 && $n <= 12)
            ? str_pad((string) $n, 2, '0', STR_PAD_LEFT)
            : trim((string) $ay);
    }

    /**
     * @return Collection<int, Collection<int, AylikFaaliyet>>
     */
    public static function duplicateGroups(): Collection
    {
        $all = AylikFaaliyet::query()
            ->orderBy('id')
            ->get();

        return $all
            ->groupBy(function (AylikFaaliyet $record): string {
                $ay = self::normalizeAy((string) ($record->ay ?? ''));
                $hafta = \App\Support\ReportPeriodWeeks::normalizeReportHafta($record->hafta ?? null) ?? '1';

                return ((int) $record->user_id).'|'.((int) $record->yil).'|'.$ay.'|'.$hafta;
            })
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->values();
    }

    /**
     * Tüm yinelenen dönemleri birleştirir. Dönüş: birleştirilen grup sayısı.
     */
    public static function mergeAllDuplicates(): int
    {
        $mergedGroups = 0;

        foreach (self::duplicateGroups() as $group) {
            if (self::mergeGroup($group) !== null) {
                $mergedGroups++;
            }
        }

        return $mergedGroups;
    }

    /**
     * @param  Collection<int, AylikFaaliyet>  $group
     */
    public static function mergeGroup(Collection $group): ?AylikFaaliyet
    {
        $records = $group->sortBy('id')->values();
        if ($records->count() < 2) {
            return $records->first();
        }

        /** @var AylikFaaliyet $canonical */
        $canonical = $records->first();
        $extras = $records->slice(1)->values();

        return DB::transaction(function () use ($canonical, $extras, $records): AylikFaaliyet {
            $canonical->ay = self::normalizeAy((string) $canonical->ay);

            $faaliyetLists = [];
            foreach ($records as $record) {
                $rows = $record->faaliyetler;
                if (is_array($rows) && $rows !== []) {
                    $faaliyetLists[] = $rows;
                }
            }

            $mergedFaaliyetler = self::mergeFaaliyetLists($faaliyetLists);
            $canonical->faaliyetler = $mergedFaaliyetler['faaliyetler'] ?? [];

            foreach (['memur', 'sozlesmeli_memur', 'kadrolu_isci', 'sirket_personeli', 'gecici_isci'] as $field) {
                $max = 0;
                foreach ($records as $record) {
                    $max = max($max, (int) ($record->{$field} ?? 0));
                }
                $canonical->{$field} = $max;
            }

            // Snapshot / durum alanlarında en güncel dolu değeri koru.
            $latest = $records->sortByDesc(fn (AylikFaaliyet $r) => $r->updated_at?->timestamp ?? 0)->first();
            foreach (['durum', 'gecikme_gerekcesi', 'vice_mayor_notu', 'vice_mayor_onay_tarihi', 'son_tarih'] as $field) {
                if (! Schema::hasColumn($canonical->getTable(), $field)) {
                    continue;
                }
                $value = $latest?->{$field} ?? null;
                if ($value !== null && $value !== '') {
                    $canonical->{$field} = $value;
                }
            }

            $canonical->save();

            $extraIds = $extras->pluck('id')->map(fn ($id) => (int) $id)->all();
            self::repointForeignKeys((int) $canonical->id, $extraIds);

            AylikFaaliyet::query()->whereIn('id', $extraIds)->delete();

            return $canonical->fresh() ?? $canonical;
        });
    }

    /**
     * @param  list<list<array<string, mixed>>>  $lists
     * @return array{faaliyetler: list<array<string, mixed>>}
     */
    public static function mergeFaaliyetLists(array $lists): array
    {
        $combined = [];
        foreach ($lists as $list) {
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $row) {
                if (is_array($row)) {
                    $combined[] = $row;
                }
            }
        }

        $data = AylikFaaliyetWeeklyCarryover::consolidateFaaliyetRowsByCatalog([
            'faaliyetler' => $combined,
        ]);

        $data['faaliyetler'] = self::dedupeHaftalikKayitlarInRows(
            is_array($data['faaliyetler'] ?? null) ? $data['faaliyetler'] : []
        );

        // Aynı katalog + farklı hafta satırları ayrı kalır; aynı haftadakiler birleşir.
        return $data;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function dedupeHaftalikKayitlarInRows(array $rows): array
    {
        foreach ($rows as &$row) {
            if (! is_array($row) || ! is_array($row['kapsam_verileri'] ?? null)) {
                continue;
            }
            foreach ($row['kapsam_verileri'] as &$kapsam) {
                if (! is_array($kapsam) || ! is_array($kapsam['haftalik_kayitlar'] ?? null)) {
                    continue;
                }
                $seen = [];
                $unique = [];
                foreach ($kapsam['haftalik_kayitlar'] as $kayit) {
                    if (! is_array($kayit)) {
                        continue;
                    }
                    $hash = md5(json_encode([
                        $kayit['hafta'] ?? null,
                        $kayit['miktar'] ?? null,
                        $kayit['yapilma_tarihi'] ?? null,
                        trim((string) ($kayit['aciklama'] ?? '')),
                    ]));
                    if (isset($seen[$hash])) {
                        continue;
                    }
                    $seen[$hash] = true;
                    $unique[] = $kayit;
                }
                $kapsam['haftalik_kayitlar'] = $unique;
            }
            unset($kapsam);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param  list<int>  $fromIds
     */
    private static function repointForeignKeys(int $toId, array $fromIds): void
    {
        if ($fromIds === [] || $toId <= 0) {
            return;
        }

        if (Schema::hasTable('control_team_audit_notes')
            && Schema::hasColumn('control_team_audit_notes', 'aylik_faaliyet_id')) {
            ControlTeamAuditNote::query()
                ->whereIn('aylik_faaliyet_id', $fromIds)
                ->update(['aylik_faaliyet_id' => $toId]);
        }
    }

    public static function normalizeAllAyValues(): int
    {
        $updated = 0;
        AylikFaaliyet::query()->orderBy('id')->each(function (AylikFaaliyet $record) use (&$updated): void {
            $normalized = self::normalizeAy((string) ($record->ay ?? ''));
            if ($normalized !== (string) $record->ay) {
                $record->ay = $normalized;
                $record->saveQuietly();
                $updated++;
            }
        });

        return $updated;
    }
}
