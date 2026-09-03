<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Haftalık rapor kayıtları (her rapor ayrı hafta) için ilerleme / birleştirme.
 * Bir rapor yalnızca kendi haftasına aittir; haftalar arası tek kayıt birikimi yoktur.
 */
final class AylikFaaliyetWeeklyCarryover
{
    /**
     * Not ile kapatılan (gerçekleşmeyen) açık iş toplamı.
     *
     * @param  array<string, mixed>  $kapsamRow
     */
    public static function notIleKapatilanToplam(array $kapsamRow): float
    {
        if (array_key_exists('not_ile_kapatilan', $kapsamRow) && is_numeric($kapsamRow['not_ile_kapatilan'])) {
            return max(0.0, (float) $kapsamRow['not_ile_kapatilan']);
        }

        $sum = 0.0;
        $kayitlar = $kapsamRow['haftalik_kayitlar'] ?? null;
        if (! is_array($kayitlar)) {
            return 0.0;
        }

        foreach ($kayitlar as $kayit) {
            if (! is_array($kayit)) {
                continue;
            }
            if (($kayit['tip'] ?? null) !== 'kapatma') {
                continue;
            }
            if (! is_numeric($kayit['kapatilan_acikta'] ?? null)) {
                continue;
            }
            $sum += (float) $kayit['kapatilan_acikta'];
        }

        return max(0.0, $sum);
    }

    /**
     * @param  array<string, mixed>  $kapsamRow
     */
    public static function kapsamPendingAmount(array $kapsamRow): float
    {
        // Tüm açık iş not ile kapatıldıysa bekleyen yoktur.
        if ((bool) ($kapsamRow['acikta_kapatildi'] ?? false)) {
            return 0.0;
        }

        $ong = $kapsamRow['ongorulen'] ?? $kapsamRow['deger'] ?? null;
        if (is_numeric($ong)) {
            $plan = (float) $ong;
            $done = is_numeric($kapsamRow['gerceklesen'] ?? null) ? (float) $kapsamRow['gerceklesen'] : 0.0;
            $notClosed = self::notIleKapatilanToplam($kapsamRow);

            return max(0.0, $plan - $done - $notClosed);
        }

        if (array_key_exists('acikta_kalan', $kapsamRow) && is_numeric($kapsamRow['acikta_kalan'])) {
            return max(0.0, (float) $kapsamRow['acikta_kalan']);
        }

        return 0.0;
    }

    public static function currentWeekForReportData(array $data): int
    {
        $reportHafta = ReportPeriodWeeks::normalizeReportHafta($data['hafta'] ?? null);
        if ($reportHafta !== null && ! ReportPeriodWeeks::isMonthlyPeriod($reportHafta)) {
            return (int) $reportHafta;
        }

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
        // Rapor düzeyindeki hafta egemendir: 2. hafta raporuna 1. hafta yazılmaz.
        $reportHafta = ReportPeriodWeeks::normalizeReportHafta($data['hafta'] ?? null);
        if ($reportHafta !== null) {
            if (ReportPeriodWeeks::isMonthlyPeriod($reportHafta)) {
                return 0;
            }

            return (int) $reportHafta;
        }

        if (ReportPeriodWeeks::isMonthlyPeriod($row['hafta'] ?? null)) {
            return 0;
        }

        if (filled($row['hafta'] ?? null) && is_numeric($row['hafta'])) {
            $week = (int) $row['hafta'];
            if ($week >= 1 && $week <= ReportPeriodWeeks::WEEK_COUNT) {
                return $week;
            }
        }

        return self::currentWeekForReportData($data);
    }

    /**
     * Eski tek-rapor modeli; artık her hafta ayrı rapor olduğu için kullanılmaz.
     *
     * @param  array<string, mixed>  $kapsamRow
     */
    public static function kapsamShowsFollowUpOnly(array $kapsamRow, int $currentWeek): bool
    {
        return false;
    }

    /**
     * Her haftalık rapor kendi kalemlerini tam gösterir.
     *
     * @param  array<string, mixed>  $kapsamRow
     */
    public static function kapsamVisibleInCurrentWeek(array $kapsamRow, int $currentWeek): bool
    {
        return true;
    }

    /**
     * Faaliyet satırlarını ve haftalık kayıtları rapor haftasına hizalar.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function restrictFaaliyetlerToReportHafta(array $data): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        $reportHafta = ReportPeriodWeeks::normalizeReportHafta($data['hafta'] ?? null);
        if ($reportHafta === null) {
            return $data;
        }

        $isMonthlyReport = ReportPeriodWeeks::isMonthlyPeriod($reportHafta);
        $isDailyReport = ReportPeriodWeeks::isDailyPeriod($reportHafta);
        $reportWeek = ($isMonthlyReport || $isDailyReport) ? null : (int) $reportHafta;
        $kept = [];

        foreach ($data['faaliyetler'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowHafta = $row['hafta'] ?? null;
            if ($isMonthlyReport || $isDailyReport) {
                $row['hafta'] = $reportHafta;
            } else {
                // Yabancı hafta satırlarını bu rapora alma.
                if (filled($rowHafta)
                    && ! ReportPeriodWeeks::isMonthlyPeriod($rowHafta)
                    && is_numeric($rowHafta)
                    && (int) $rowHafta !== $reportWeek) {
                    continue;
                }
                $row['hafta'] = $reportWeek;
            }

            $kv = $row['kapsam_verileri'] ?? null;
            if (is_array($kv)) {
                foreach ($kv as $ki => $kapsamRow) {
                    if (! is_array($kapsamRow)) {
                        continue;
                    }
                    $kayitlar = $kapsamRow['haftalik_kayitlar'] ?? null;
                    if (! is_array($kayitlar)) {
                        continue;
                    }
                    $filtered = [];
                    foreach ($kayitlar as $kayit) {
                        if (! is_array($kayit)) {
                            continue;
                        }
                        $kh = $kayit['hafta'] ?? null;
                        if ($isMonthlyReport || $isDailyReport) {
                            if (ReportPeriodWeeks::isMonthlyPeriod($kh)
                                || ReportPeriodWeeks::isDailyPeriod($kh)
                                || $kh === null
                                || $kh === '') {
                                $filtered[] = $kayit;
                            }
                            continue;
                        }
                        if ($kh === null || $kh === '' || (is_numeric($kh) && (int) $kh === $reportWeek)) {
                            $kayit['hafta'] = $reportWeek;
                            $filtered[] = $kayit;
                        }
                    }
                    $kv[$ki]['haftalik_kayitlar'] = array_values($filtered);
                }
                $row['kapsam_verileri'] = array_values($kv);
            }

            $kept[] = $row;
        }

        $data['faaliyetler'] = $kept;

        return $data;
    }

    /**
     * Açıkta kalan işi gerekçe notu ile kapat veya kalemi tamamlandı say.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyAciktaKapatma(array $data): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        $today = ReportPeriodWeeks::systemRecordDateString();

        foreach ($data['faaliyetler'] as $i => $row) {
            // Performans kilidi açıkta kapatmayı engellemez; aksi halde kısmi tamamlanan
            // sonrası satır kilitlenince bekleyen iş kapanamaz kalır.
            if (! is_array($row)) {
                continue;
            }

            if (ReportPeriodWeeks::isMonthlyPeriod($row['hafta'] ?? null)) {
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

                if ((bool) ($kapsamRow['acikta_kapatildi'] ?? false)) {
                    $kapsamRow['acikta_kalan'] = 0;
                    unset(
                        $kapsamRow['acikta_is_kapatiliyor'],
                        $kapsamRow['kalan_acik_tamamla'],
                        $kapsamRow['acikta_kapanis_miktar']
                    );

                    continue;
                }

                $weekForEntry = $currentWeek >= 1 ? $currentWeek : self::currentWeekForReportData($data);

                // Kapanışta girilen miktar tamamlanana eklenir; kalan 0 olursa bekleyen kapanır.
                $kapanisMiktar = self::toFloat($kapsamRow['acikta_kapanis_miktar'] ?? null);
                if ($kapanisMiktar > 0.0) {
                    $pendingForAmount = self::kapsamPendingAmount($kapsamRow);
                    $amount = $pendingForAmount > 0.0 ? min($kapanisMiktar, $pendingForAmount) : $kapanisMiktar;
                    if ($amount > 0.0) {
                        $kayitlar = is_array($kapsamRow['haftalik_kayitlar'] ?? null)
                            ? array_values($kapsamRow['haftalik_kayitlar'])
                            : [];
                        $kayitlar[] = [
                            'hafta' => $weekForEntry,
                            'miktar' => $amount,
                            'aciklama' => 'Açık iş kapanışında tamamlanan miktar.',
                            'yapilma_tarihi' => $today,
                            'tip' => 'kapanis_miktar',
                        ];
                        $kapsamRow['haftalik_kayitlar'] = $kayitlar;
                        $kapsamRow['gerceklesen'] = self::toFloat($kapsamRow['gerceklesen'] ?? 0) + $amount;
                        $kapsamRow['son_yapilma_tarihi'] = $today;
                        $kapsamRow['acikta_kalan'] = self::kapsamPendingAmount($kapsamRow);
                    }
                    unset($kapsamRow['acikta_kapanis_miktar']);
                }

                // Kalem bazında "kalanı tamamla" (gerçekleşene ekler).
                if ((bool) ($kapsamRow['kalan_acik_tamamla'] ?? false)) {
                    $pendingComplete = self::kapsamPendingAmount($kapsamRow);
                    if ($pendingComplete > 0.0) {
                        $kayitlar = is_array($kapsamRow['haftalik_kayitlar'] ?? null)
                            ? array_values($kapsamRow['haftalik_kayitlar'])
                            : [];
                        $kayitlar[] = [
                            'hafta' => $weekForEntry,
                            'miktar' => $pendingComplete,
                            'aciklama' => 'Kalan açık iş tamamlandı sayıldı.',
                            'yapilma_tarihi' => $today,
                            'tip' => 'kalan_tamamlama',
                        ];
                        $kapsamRow['haftalik_kayitlar'] = $kayitlar;
                        $kapsamRow['gerceklesen'] = self::toFloat($kapsamRow['gerceklesen'] ?? 0) + $pendingComplete;
                        $kapsamRow['son_yapilma_tarihi'] = $today;
                        $kapsamRow['acikta_kalan'] = 0;
                    }
                    unset($kapsamRow['kalan_acik_tamamla'], $kapsamRow['acikta_is_kapatiliyor']);

                    continue;
                }

                if (self::kapsamPendingAmount($kapsamRow) <= 0.0) {
                    $kapsamRow['acikta_kalan'] = 0;
                    unset($kapsamRow['acikta_is_kapatiliyor']);

                    continue;
                }

                if (! (bool) ($kapsamRow['acikta_is_kapatiliyor'] ?? false)) {
                    continue;
                }

                $note = trim((string) ($kapsamRow['acikta_kapatma_notu'] ?? ''));
                if ($note === '') {
                    continue;
                }

                $pending = self::kapsamPendingAmount($kapsamRow);
                if ($pending <= 0.0) {
                    continue;
                }

                // Kısmi kapatma: örn. 3 açıktan 2'si bu hafta, 1'i sonraki haftaya.
                $requested = self::toFloat($kapsamRow['acikta_not_kapat_miktar'] ?? null);
                $closeAmount = $requested > 0.0 ? min($requested, $pending) : $pending;
                if ($closeAmount <= 0.0) {
                    continue;
                }

                $oncekiNotKapatilan = self::notIleKapatilanToplam($kapsamRow);
                $kayitlar = is_array($kapsamRow['haftalik_kayitlar'] ?? null)
                    ? array_values($kapsamRow['haftalik_kayitlar'])
                    : [];

                $kayitlar[] = [
                    'hafta' => $weekForEntry,
                    'miktar' => 0,
                    'aciklama' => 'Açık iş kapanışı: '.$note,
                    'yapilma_tarihi' => $today,
                    'tip' => 'kapatma',
                    'kapatilan_acikta' => $closeAmount,
                ];

                $kapsamRow['haftalik_kayitlar'] = $kayitlar;
                $kapsamRow['not_ile_kapatilan'] = $oncekiNotKapatilan + $closeAmount;
                $remaining = max(0.0, $pending - $closeAmount);
                $kapsamRow['acikta_kalan'] = $remaining;
                $kapsamRow['acikta_revize_notu'] = $note;
                if (! filled($kapsamRow['acikta_revize_tarihi'] ?? null)) {
                    $kapsamRow['acikta_revize_tarihi'] = $today;
                }
                $kapsamRow['acikta_kapatma_notu'] = $note;
                // Yalnızca kalanın tamamı kapandıysa kalem tamamen kapatılmış sayılır.
                $kapsamRow['acikta_kapatildi'] = $remaining <= 0.0;
                unset(
                    $kapsamRow['acikta_is_kapatiliyor'],
                    $kapsamRow['bu_hafta_tamamlanan'],
                    $kapsamRow['bu_hafta_aciklama'],
                    $kapsamRow['kalan_acik_tamamla'],
                    $kapsamRow['acikta_kapanis_miktar'],
                    $kapsamRow['acikta_not_kapat_miktar']
                );
            }
            unset($kapsamRow);
        }

        return $data;
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

        $today = ReportPeriodWeeks::systemRecordDateString();

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row) || (bool) ($row['ay_sonu_performans_kilitli'] ?? false)) {
                continue;
            }

            if (ReportPeriodWeeks::isMonthlyPeriod($row['hafta'] ?? null)) {
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
                $yapilmaTarihi = self::normalizeYapilmaTarihi(
                    $kapsamRow['bu_hafta_yapilma_tarihi'] ?? null,
                    $today
                );

                if ((bool) ($kapsamRow['acikta_kapatildi'] ?? false)) {
                    unset(
                        $kapsamRow['bu_hafta_tamamlanan'],
                        $kapsamRow['bu_hafta_aciklama'],
                        $kapsamRow['bu_hafta_yapilma_tarihi'],
                        $kapsamRow['acikta_is_kapatiliyor'],
                        $kapsamRow['acikta_kapanis_miktar']
                    );
                    $kapsamRow['acikta_kalan'] = 0;

                    continue;
                }

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
                        'yapilma_tarihi' => $yapilmaTarihi,
                    ];

                    $kapsamRow['haftalik_kayitlar'] = $kayitlar;
                    $kapsamRow['gerceklesen'] = self::toFloat($kapsamRow['gerceklesen'] ?? 0) + $amount;
                    $kapsamRow['son_yapilma_tarihi'] = $yapilmaTarihi;
                }

                unset(
                    $kapsamRow['bu_hafta_tamamlanan'],
                    $kapsamRow['bu_hafta_aciklama'],
                    $kapsamRow['bu_hafta_yapilma_tarihi']
                );
                $kapsamRow['acikta_kalan'] = AylikFaaliyetRepeaterLock::kapsamSatirAciktaKalan($kapsamRow);
            }
            unset($kapsamRow);
        }

        return AylikFaaliyetRepeaterLock::syncRowAySonuTotalsFromKapsamVerileri($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function countPendingKapsamItems(array $data): int
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return 0;
        }

        $count = 0;

        foreach ($data['faaliyetler'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (ReportPeriodWeeks::isMonthlyPeriod($row['hafta'] ?? null)) {
                continue;
            }

            $kv = $row['kapsam_verileri'] ?? null;
            if (! is_array($kv)) {
                continue;
            }

            foreach ($kv as $kapsamRow) {
                if (! is_array($kapsamRow) || (bool) ($kapsamRow['acikta_kapatildi'] ?? false)) {
                    continue;
                }

                if (self::kapsamPendingAmount($kapsamRow) > 0.0) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Tüm açık kapsam kalemlerini kalan miktar kadar tamamlanmış olarak kaydeder.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyBulkPendingCompletion(array $data, string $aciklama): array
    {
        $aciklama = trim($aciklama);
        if ($aciklama === '' || ! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return $data;
        }

        $today = ReportPeriodWeeks::systemRecordDateString();

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row) || (bool) ($row['ay_sonu_performans_kilitli'] ?? false)) {
                continue;
            }

            if (ReportPeriodWeeks::isMonthlyPeriod($row['hafta'] ?? null)) {
                continue;
            }

            $currentWeek = self::resolveWeekForFaaliyetRow($row, $data);
            if ($currentWeek < 1) {
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

                $kapsamRow = &$data['faaliyetler'][$i]['kapsam_verileri'][$j];

                if ((bool) ($kapsamRow['acikta_kapatildi'] ?? false)) {
                    continue;
                }

                $pending = self::kapsamPendingAmount($kapsamRow);
                if ($pending <= 0.0) {
                    continue;
                }

                $kayitlar = is_array($kapsamRow['haftalik_kayitlar'] ?? null)
                    ? array_values($kapsamRow['haftalik_kayitlar'])
                    : [];

                $kayitlar[] = [
                    'hafta' => $currentWeek,
                    'miktar' => $pending,
                    'aciklama' => $aciklama,
                    'yapilma_tarihi' => $today,
                    'tip' => 'toplu_tamamlama',
                ];

                $kapsamRow['haftalik_kayitlar'] = $kayitlar;
                $kapsamRow['gerceklesen'] = self::toFloat($kapsamRow['gerceklesen'] ?? 0) + $pending;
                $kapsamRow['son_yapilma_tarihi'] = $today;
                $kapsamRow['acikta_kalan'] = AylikFaaliyetRepeaterLock::kapsamSatirAciktaKalan($kapsamRow);

                unset(
                    $kapsamRow['bu_hafta_tamamlanan'],
                    $kapsamRow['bu_hafta_aciklama']
                );
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
            $week = $row['hafta'] ?? null;
            $weekKey = ReportPeriodWeeks::isMonthlyPeriod($week)
                ? 'w:monthly'
                : ((is_numeric($week) && (int) $week >= 1 && (int) $week <= ReportPeriodWeeks::WEEK_COUNT)
                    ? 'w:'.(int) $week
                    : 'w:auto');
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

        foreach (['baslangic_tarihi', 'bitis_tarihi'] as $dateKey) {
            $incomingValue = trim((string) ($incoming[$dateKey] ?? ''));
            if ($incomingValue !== '') {
                $base[$dateKey] = $incomingValue;
            }
        }

        $incomingTur = KapsamIslemTuru::normalizeStored($incoming['islem_turu'] ?? null);
        if ($incomingTur !== null) {
            $base['islem_turu'] = $incomingTur;
        }

        if (trim((string) ($incoming['acikta_revize_notu'] ?? '')) !== '') {
            $base['acikta_revize_notu'] = $incoming['acikta_revize_notu'];
        }
        if (trim((string) ($incoming['acikta_revize_tarihi'] ?? '')) !== '') {
            $base['acikta_revize_tarihi'] = $incoming['acikta_revize_tarihi'];
        }

        return $base;
    }

    /**
     * Tamamlanan boş veya 0 kalan kalemlerde gerceklesen = ongorulen yapar.
     * Kısmi ilerleme (0 < gerceklesen < ongorulen) ve not ile kapatılmış kalemler dokunulmaz.
     * Mevcut miktar/geçmiş silinmez; yalnızca tamamlanan doldurulur ve denetim kaydı eklenir.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<array{kod: string, kalem: string, onceki: mixed, yeni: float, pending: float}>}
     */
    public static function backfillEmptyGerceklesen(array $data): array
    {
        if (! isset($data['faaliyetler']) || ! is_array($data['faaliyetler'])) {
            return [$data, []];
        }

        $today = ReportPeriodWeeks::systemRecordDateString();
        $changes = [];

        foreach ($data['faaliyetler'] as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $kv = $row['kapsam_verileri'] ?? null;
            if (! is_array($kv) || $kv === []) {
                continue;
            }

            $currentWeek = self::resolveWeekForFaaliyetRow($row, $data);
            $kod = trim((string) ($row['faaliyet_kodu'] ?? $row['kod'] ?? ''));

            foreach (array_keys($kv) as $j) {
                if (! is_array($data['faaliyetler'][$i]['kapsam_verileri'][$j] ?? null)) {
                    continue;
                }

                $kapsamRow = &$data['faaliyetler'][$i]['kapsam_verileri'][$j];

                if ((bool) ($kapsamRow['acikta_kapatildi'] ?? false)) {
                    continue;
                }

                $ongRaw = $kapsamRow['ongorulen'] ?? $kapsamRow['deger'] ?? null;
                if (! is_numeric($ongRaw) || (float) $ongRaw <= 0.0) {
                    continue;
                }

                $gerRaw = $kapsamRow['gerceklesen'] ?? null;
                $gerEmpty = $gerRaw === null || $gerRaw === '';
                $gerZero = is_numeric($gerRaw) && (float) $gerRaw == 0.0;
                if (! $gerEmpty && ! $gerZero) {
                    continue;
                }

                $pending = self::kapsamPendingAmount($kapsamRow);
                if ($pending <= 0.0) {
                    continue;
                }

                $weekForEntry = $currentWeek >= 1 ? $currentWeek : self::currentWeekForReportData($data);
                $kayitlar = is_array($kapsamRow['haftalik_kayitlar'] ?? null)
                    ? array_values($kapsamRow['haftalik_kayitlar'])
                    : [];
                $kayitlar[] = [
                    'hafta' => $weekForEntry,
                    'miktar' => $pending,
                    'aciklama' => 'Sistem: boş/0 tamamlanan, yapılan iş kadar tamamlandı sayıldı.',
                    'yapilma_tarihi' => $today,
                    'tip' => 'kalan_tamamlama',
                ];

                $onceki = $gerRaw;
                $kapsamRow['haftalik_kayitlar'] = $kayitlar;
                $kapsamRow['gerceklesen'] = self::toFloat($kapsamRow['ongorulen'] ?? $kapsamRow['deger'] ?? 0);
                $kapsamRow['son_yapilma_tarihi'] = $today;
                $kapsamRow['acikta_kalan'] = 0;

                $changes[] = [
                    'kod' => $kod,
                    'kalem' => trim((string) ($kapsamRow['kalem'] ?? $kapsamRow['baslik'] ?? '')),
                    'onceki' => $onceki,
                    'yeni' => (float) $kapsamRow['gerceklesen'],
                    'pending' => $pending,
                ];
            }
            unset($kapsamRow);
        }

        if ($changes !== []) {
            $data = AylikFaaliyetRepeaterLock::syncRowAySonuTotalsFromKapsamVerileri($data);
        }

        return [$data, $changes];
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

    private static function normalizeYapilmaTarihi(mixed $value, string $fallback): string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value))
                ->startOfDay()
                ->toDateString();
        }

        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return $fallback;
        }

        try {
            return Carbon::parse($raw)->startOfDay()->toDateString();
        } catch (\Throwable) {
            return $fallback;
        }
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
