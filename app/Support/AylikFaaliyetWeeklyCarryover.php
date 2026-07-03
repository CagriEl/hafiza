<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Açıkta kalan işlerin sonraki haftalarda yalnızca devam girişi ile takibi.
 */
final class AylikFaaliyetWeeklyCarryover
{
    /**
     * @param  array<string, mixed>  $kapsamRow
     */
    public static function kapsamPendingAmount(array $kapsamRow): float
    {
        if (array_key_exists('acikta_kalan', $kapsamRow) && is_numeric($kapsamRow['acikta_kalan'])) {
            return max(0.0, (float) $kapsamRow['acikta_kalan']);
        }

        $plan = (float) ($kapsamRow['ongorulen'] ?? $kapsamRow['deger'] ?? 0);
        $done = (float) ($kapsamRow['gerceklesen'] ?? 0);

        return max(0.0, $plan - $done);
    }

    public static function currentWeekForReportData(array $data): int
    {
        $yil = (int) ($data['yil'] ?? 0);
        $ay = (int) preg_replace('/\D/', '', (string) ($data['ay'] ?? ''));

        if ($yil <= 0 || $ay < 1 || $ay > 12) {
            return 1;
        }

        return ReportPeriodWeeks::resolveWeekForReportPeriod($yil, $ay);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $data
     */
    public static function resolveWeekForFaaliyetRow(array $row, array $data): int
    {
        if (filled($row['hafta'] ?? null) && is_numeric($row['hafta'])) {
            $week = (int) $row['hafta'];
            if ($week >= 1 && $week <= ReportPeriodWeeks::WEEK_COUNT) {
                return $week;
            }
        }

        return self::currentWeekForReportData($data);
    }

    /**
     * Sonraki haftalarda yalnızca açıkta kalan iş için devam girişi.
     *
     * @param  array<string, mixed>  $kapsamRow
     */
    public static function kapsamShowsFollowUpOnly(array $kapsamRow, int $currentWeek): bool
    {
        return $currentWeek > 1 && self::kapsamPendingAmount($kapsamRow) > 0.0;
    }

    /**
     * @param  array<string, mixed>  $kapsamRow
     */
    public static function kapsamVisibleInCurrentWeek(array $kapsamRow, int $currentWeek): bool
    {
        if ($currentWeek <= 1) {
            return true;
        }

        return self::kapsamPendingAmount($kapsamRow) > 0.0;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyWeeklyEntries(array $data): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        $today = now()->toDateString();

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row) || (bool) ($row['ay_sonu_performans_kilitli'] ?? false)) {
                continue;
            }

            $currentWeek = self::resolveWeekForFaaliyetRow($row, $data);

            $kv = $row['kapsam_verileri'] ?? null;
            if (! is_array($kv) || $kv === []) {
                continue;
            }

            foreach (array_keys($kv) as $j) {
                if (! is_array($data['faaliyetler'][$i]['kapsam_verileri'][$j] ?? null)) {
                    continue;
                }

                $kapsamRow = &$data['faaliyetler'][$i]['kapsam_verileri'][$j];
                $buHafta = self::toFloat($kapsamRow['bu_hafta_tamamlanan'] ?? null);
                $aciklama = trim((string) ($kapsamRow['bu_hafta_aciklama'] ?? ''));

                if ($buHafta > 0.0 && $aciklama === '') {
                    continue;
                }

                if ($buHafta > 0.0) {
                    $pending = self::kapsamPendingAmount($kapsamRow);
                    $amount = $pending > 0.0 ? min($buHafta, $pending) : $buHafta;

                    $kayitlar = is_array($kapsamRow['haftalik_kayitlar'] ?? null)
                        ? array_values($kapsamRow['haftalik_kayitlar'])
                        : [];

                    $kayitlar[] = [
                        'hafta' => $currentWeek,
                        'miktar' => $amount,
                        'aciklama' => $aciklama,
                        'yapilma_tarihi' => $today,
                    ];

                    $kapsamRow['haftalik_kayitlar'] = $kayitlar;
                    $kapsamRow['gerceklesen'] = self::toFloat($kapsamRow['gerceklesen'] ?? 0) + $amount;
                    $kapsamRow['son_yapilma_tarihi'] = $today;
                }

                unset($kapsamRow['bu_hafta_tamamlanan'], $kapsamRow['bu_hafta_aciklama']);
                $kapsamRow['acikta_kalan'] = AylikFaaliyetRepeaterLock::kapsamSatirAciktaKalan($kapsamRow);
            }
            unset($kapsamRow);
        }

        return AylikFaaliyetRepeaterLock::syncRowAySonuTotalsFromKapsamVerileri($data);
    }

    /**
     * Aynı ay raporunda aynı katalog faaliyeti tek satırda birleştirilir.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function consolidateFaaliyetRowsByCatalog(array $data): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        $merged = [];

        foreach ($data['faaliyetler'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $catalogId = (int) ($row['activity_catalog_id'] ?? 0);
            $code = trim((string) ($row['faaliyet_kodu'] ?? ''));
            $week = (int) ($row['hafta'] ?? 0);
            $weekKey = $week >= 1 && $week <= ReportPeriodWeeks::WEEK_COUNT ? 'w:'.$week : 'w:auto';
            $key = $catalogId > 0
                ? 'id:'.$catalogId.':'.$weekKey
                : ($code !== '' ? 'code:'.$code.':'.$weekKey : 'row:'.md5(json_encode($row)));

            if (! isset($merged[$key])) {
                $merged[$key] = $row;

                continue;
            }

            $merged[$key] = self::mergeFaaliyetRows($merged[$key], $row);
        }

        $data['faaliyetler'] = array_values($merged);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private static function mergeFaaliyetRows(array $base, array $incoming): array
    {
        $baseKv = is_array($base['kapsam_verileri'] ?? null) ? $base['kapsam_verileri'] : [];
        $incomingKv = is_array($incoming['kapsam_verileri'] ?? null) ? $incoming['kapsam_verileri'] : [];

        if ($baseKv !== [] && $incomingKv !== []) {
            foreach ($incomingKv as $idx => $incomingLine) {
                if (! is_array($incomingLine)) {
                    continue;
                }
                $kalem = trim((string) ($incomingLine['kalem'] ?? ''));
                $matched = false;
                foreach (array_keys($baseKv) as $baseIdx) {
                    if (! is_array($baseKv[$baseIdx] ?? null)) {
                        continue;
                    }
                    if (trim((string) ($baseKv[$baseIdx]['kalem'] ?? '')) !== $kalem) {
                        continue;
                    }
                    $baseKv[$baseIdx] = self::mergeKapsamRows($baseKv[$baseIdx], $incomingLine);
                    $matched = true;
                    break;
                }
                if (! $matched) {
                    $baseKv[] = $incomingLine;
                }
            }
            $base['kapsam_verileri'] = array_values($baseKv);
        }

        foreach (['gerceklesen', 'bekleyen_is'] as $numericKey) {
            if (isset($incoming[$numericKey]) && is_numeric($incoming[$numericKey])) {
                $base[$numericKey] = self::toFloat($base[$numericKey] ?? 0) + self::toFloat($incoming[$numericKey]);
            }
        }

        if (trim((string) ($incoming['sapma_nedeni'] ?? '')) !== '') {
            $base['sapma_nedeni'] = $incoming['sapma_nedeni'];
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private static function mergeKapsamRows(array $base, array $incoming): array
    {
        $baseKayitlar = is_array($base['haftalik_kayitlar'] ?? null) ? $base['haftalik_kayitlar'] : [];
        $incomingKayitlar = is_array($incoming['haftalik_kayitlar'] ?? null) ? $incoming['haftalik_kayitlar'] : [];
        if ($incomingKayitlar !== []) {
            $base['haftalik_kayitlar'] = array_values(array_merge($baseKayitlar, $incomingKayitlar));
        }

        $basePlan = self::toFloat($base['ongorulen'] ?? $base['deger'] ?? 0);
        $incomingPlan = self::toFloat($incoming['ongorulen'] ?? $incoming['deger'] ?? 0);
        if ($incomingPlan > $basePlan) {
            $base['ongorulen'] = $incomingPlan;
        }

        $base['gerceklesen'] = self::toFloat($base['gerceklesen'] ?? 0) + self::toFloat($incoming['gerceklesen'] ?? 0);
        $base['acikta_kalan'] = AylikFaaliyetRepeaterLock::kapsamSatirAciktaKalan($base);

        $incomingDate = trim((string) ($incoming['son_yapilma_tarihi'] ?? ''));
        if ($incomingDate !== '') {
            $base['son_yapilma_tarihi'] = $incomingDate;
        }

        return $base;
    }

    private static function toFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return 0.0;
        }

        $normalized = trim(str_replace(["\xc2\xa0", ' '], '', $value));
        if ($normalized === '' || ! is_numeric($normalized)) {
            return 0.0;
        }

        return (float) $normalized;
    }

    public static function formatDisplayDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->format('d.m.Y');
        } catch (\Throwable) {
            return $date;
        }
    }
}
