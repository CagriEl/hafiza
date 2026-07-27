<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Aylık faaliyet raporlarında ayı sabit 4 haftalık takvim aralığına böler.
 * Her hafta Pazartesi–Pazar (7 gün) tam takvim haftasıdır; 1. hafta ayın 1’ini
 * içeren haftanın Pazartesinden başlar (önceki aya taşabilir).
 */
final class ReportPeriodWeeks
{
    public const WEEK_COUNT = 4;

  /** Aylık raporlama dönemi (hafta numarası yerine). */
    public const MONTHLY_VALUE = 'aylik';

    /**
     * @return array<int, string>
     */
    public static function turkishMonthNames(): array
    {
        return [
            1 => 'Ocak',
            2 => 'Şubat',
            3 => 'Mart',
            4 => 'Nisan',
            5 => 'Mayıs',
            6 => 'Haziran',
            7 => 'Temmuz',
            8 => 'Ağustos',
            9 => 'Eylül',
            10 => 'Ekim',
            11 => 'Kasım',
            12 => 'Aralık',
        ];
    }

    public static function turkishMonthName(int $month): string
    {
        return self::turkishMonthNames()[$month] ?? (string) $month;
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    public static function monthBounds(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();

        return ['start' => $start, 'end' => $end];
    }

    public static function isWeekend(Carbon $date): bool
    {
        return $date->isWeekend();
    }

    /**
     * Hafta sonu günlerinde raporlama için son iş günü (Cuma); diğer günlerde aynı tarih.
     */
    public static function reportingReferenceDate(?Carbon $date = null): Carbon
    {
        $date = ($date ?? now())->copy()->startOfDay();

        if ($date->isSaturday()) {
            return $date->copy()->subDay();
        }

        if ($date->isSunday()) {
            return $date->copy()->subDays(2);
        }

        return $date;
    }

    /**
     * Sisteme kayıt anındaki gerçek takvim tarihi (hafta sonu kaydırması yok).
     */
    public static function systemRecordDate(?Carbon $date = null): Carbon
    {
        return ($date ?? now())->copy()->startOfDay();
    }

    public static function systemRecordDateString(?Carbon $date = null): string
    {
        return self::systemRecordDate($date)->toDateString();
    }

    /**
     * Ayı 4 rapor haftasına böler: her hafta tam takvim haftası (Pzt–Paz, 7 gün).
     * 1. hafta ayın 1’ini içeren haftanın Pazartesinden o Pazar’a;
     * 2–3. haftalar sonraki tam Pzt–Paz aralıkları;
     * 4. hafta kalan günler (ay sonuna kadar).
     *
     * @return list<array{hafta: int, baslangic: Carbon, bitis: Carbon}>
     */
    public static function weeksForMonth(int $year, int $month): array
    {
        if ($year <= 0 || $month < 1 || $month > 12) {
            return [];
        }

        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        // Ayın 1’ini içeren ISO haftanın Pazartesi’si (önceki aya taşabilir).
        $cursor = $monthStart->copy()->startOfWeek(Carbon::MONDAY);

        $weeks = [];

        for ($weekNum = 1; $weekNum <= 3; $weekNum++) {
            $baslangic = $cursor->copy();
            $bitis = $baslangic->copy()->addDays(6);

            if ($baslangic->gt($monthEnd)) {
                break;
            }

            $weeks[] = [
                'hafta' => $weekNum,
                'baslangic' => $baslangic,
                'bitis' => $bitis,
            ];

            $cursor = $bitis->copy()->addDay();
        }

        if ($cursor->lte($monthEnd)) {
            $weeks[] = [
                'hafta' => 4,
                'baslangic' => $cursor->copy(),
                'bitis' => $monthEnd->copy(),
            ];
        }

        return $weeks;
    }

    /**
     * @return array{start: Carbon, end: Carbon}|null
     */
    public static function weekdayBoundsInRange(Carbon $rangeStart, Carbon $rangeEnd): ?array
    {
        $start = self::firstWeekdayOnOrAfter($rangeStart, $rangeEnd);
        $end = self::lastWeekdayOnOrBefore($rangeEnd, $rangeStart);

        if ($start === null || $end === null || $start->gt($end)) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return array{hafta: int, baslangic: Carbon, bitis: Carbon}|null
     */
    public static function weekByNumber(int $year, int $month, int $week): ?array
    {
        foreach (self::weeksForMonth($year, $month) as $row) {
            if ((int) $row['hafta'] === $week) {
                return $row;
            }
        }

        return null;
    }

    public static function formatDate(Carbon $date): string
    {
        return $date->format('d.m.Y');
    }

    /**
     * Rapor dönemi ve bugünün tarihine göre otomatik hafta numarası (1–4).
     * Hafta sonları, önceki Cuma'nın haftasına göre değerlendirilir.
     */
    public static function resolveWeekForReportPeriod(int $year, int $month, ?Carbon $date = null): int
    {
        $weeks = self::weeksForMonth($year, $month);
        if ($weeks === []) {
            return 1;
        }

        $date = ($date ?? now())->copy()->startOfDay();
        $effective = self::reportingReferenceDate($date);
        $bounds = self::monthBounds($year, $month);

        if ($effective->lt($bounds['start'])) {
            return 1;
        }

        if ($effective->gt($bounds['end'])) {
            return (int) $weeks[array_key_last($weeks)]['hafta'];
        }

        foreach ($weeks as $week) {
            if ($effective->greaterThanOrEqualTo($week['baslangic']) && $effective->lessThanOrEqualTo($week['bitis'])) {
                return (int) $week['hafta'];
            }
        }

        return 1;
    }

    public static function weekShortLabel(int $year, int $month, int $week): string
    {
        if ($week < 1 || $week > self::WEEK_COUNT) {
            return $week.'. Hafta';
        }

        // Tarih aralığı gösterilmez; yalnızca sıra numarası.
        return $week.'. Hafta';
    }

    public static function monthPeriodLabel(int $year, int $month): string
    {
        $bounds = self::monthBounds($year, $month);

        return sprintf(
            '%s %d (%s - %s)',
            self::turkishMonthName($month),
            $year,
            self::formatDate($bounds['start']),
            self::formatDate($bounds['end'])
        );
    }

    /**
     * @return array<int, string>
     */
    public static function selectOptions(int $year, int $month): array
    {
        $options = [];
        foreach (self::weeksForMonth($year, $month) as $week) {
            $options[(int) $week['hafta']] = self::weekShortLabel($year, $month, (int) $week['hafta']);
        }

        return $options;
    }

    public static function weeksOverviewText(int $year, int $month): string
    {
        $parts = [];
        foreach (self::weeksForMonth($year, $month) as $week) {
            $parts[] = ((int) $week['hafta']).'. Hafta';
        }

        return implode(' · ', $parts);
    }

    public static function weeksOverviewHtml(int $year, int $month): string
    {
        $parts = [];
        foreach (self::weeksForMonth($year, $month) as $week) {
            $parts[] = '<strong>'.((int) $week['hafta']).'. Hafta</strong>';
        }

        return implode(' &nbsp;·&nbsp; ', $parts);
    }

    public static function weekLabelForRecord(?int $year, mixed $month, mixed $week): ?string
    {
        $weekNumber = (int) $week;
        $yearNumber = (int) $year;
        $monthNumber = (int) preg_replace('/\D/', '', (string) $month);

        if ($yearNumber <= 0 || $monthNumber < 1 || $monthNumber > 12) {
            return null;
        }

        if (self::isMonthlyPeriod($week)) {
            return self::monthlyPeriodLabel($yearNumber, $monthNumber);
        }

        if ($weekNumber < 1) {
            return null;
        }

        return self::weekShortLabel($yearNumber, $monthNumber, $weekNumber);
    }

    public static function recordPeriodLabel(?int $year, mixed $month): ?string
    {
        $yearNumber = (int) $year;
        $monthNumber = (int) preg_replace('/\D/', '', (string) $month);

        if ($yearNumber <= 0 || $monthNumber < 1 || $monthNumber > 12) {
            return null;
        }

        return self::monthPeriodLabel($yearNumber, $monthNumber);
    }

    public static function isMonthlyPeriod(mixed $period): bool
    {
        if ($period === self::MONTHLY_VALUE || $period === 0 || $period === '0') {
            return true;
        }

        return false;
    }

    public static function isMonthlyReportingFrequency(?string $frequency): bool
    {
        $normalized = mb_strtolower(trim((string) $frequency));

        return str_contains($normalized, 'aylık') || str_contains($normalized, 'aylik');
    }

    public static function isWeeklyReportingFrequency(?string $frequency): bool
    {
        $normalized = mb_strtolower(trim((string) $frequency));

        return str_contains($normalized, 'haftalık') || str_contains($normalized, 'haftalik');
    }

    public static function monthlyPeriodLabel(int $year, int $month): string
    {
        return 'Aylık';
    }

    /**
     * Raporlama sıklığına göre hafta (1–4) ve/veya aylık seçenekleri.
     *
     * @return array<int|string, string>
     */
    public static function periodSelectOptions(int $year, int $month, ?string $raporlamaSikligi): array
    {
        $weekly = self::isWeeklyReportingFrequency($raporlamaSikligi);
        $monthly = self::isMonthlyReportingFrequency($raporlamaSikligi);
        $showAll = ! $weekly && ! $monthly;

        $options = [];

        if ($weekly || $showAll) {
            foreach (self::weeksForMonth($year, $month) as $week) {
                $options[(int) $week['hafta']] = self::weekShortLabel($year, $month, (int) $week['hafta']);
            }
        }

        if ($monthly || $showAll) {
            $options[self::MONTHLY_VALUE] = self::monthlyPeriodLabel($year, $month);
        }

        return $options;
    }

    public static function defaultPeriodForReportingFrequency(int $year, int $month, ?string $raporlamaSikligi): int|string
    {
        $weekly = self::isWeeklyReportingFrequency($raporlamaSikligi);
        $monthly = self::isMonthlyReportingFrequency($raporlamaSikligi);

        if ($monthly && ! $weekly) {
            return self::MONTHLY_VALUE;
        }

        return self::resolveWeekForReportPeriod($year, $month);
    }

    public static function periodLabelForRecord(?int $year, mixed $month, mixed $period): ?string
    {
        $yearNumber = (int) $year;
        $monthNumber = (int) preg_replace('/\D/', '', (string) $month);

        if ($yearNumber <= 0 || $monthNumber < 1 || $monthNumber > 12) {
            return null;
        }

        if (self::isMonthlyPeriod($period)) {
            return self::monthlyPeriodLabel($yearNumber, $monthNumber);
        }

        $weekNumber = (int) $period;
        if ($weekNumber < 1) {
            return null;
        }

        return self::weekShortLabel($yearNumber, $monthNumber, $weekNumber);
    }


    private static function firstFridayOnOrAfter(Carbon $from, Carbon $max): ?Carbon
    {
        $cursor = $from->copy()->startOfDay();
        while ($cursor->lte($max)) {
            if ($cursor->isFriday()) {
                return $cursor;
            }
            $cursor->addDay();
        }

        return self::lastWeekdayOnOrBefore($max, $from);
    }

    private static function nextMondayOnOrAfter(Carbon $from, Carbon $max): ?Carbon
    {
        $cursor = $from->copy()->startOfDay();
        while ($cursor->lte($max) && $cursor->isWeekend()) {
            $cursor->addDay();
        }

        if ($cursor->gt($max)) {
            return null;
        }

        if (! $cursor->isMonday()) {
            $cursor = $cursor->copy()->next(Carbon::MONDAY);
        }

        return $cursor->lte($max) ? $cursor : null;
    }

    private static function fridayOfWeekStarting(Carbon $weekStart, Carbon $monthEnd): ?Carbon
    {
        $cursor = $weekStart->copy()->startOfDay();
        while ($cursor->lte($monthEnd) && ! $cursor->isFriday()) {
            $cursor->addDay();
        }

        if ($cursor->gt($monthEnd)) {
            return self::lastWeekdayOnOrBefore($monthEnd, $weekStart);
        }

        return $cursor;
    }

    private static function firstWeekdayOnOrAfter(Carbon $from, Carbon $max): ?Carbon
    {
        $cursor = $from->copy()->startOfDay();
        while ($cursor->lte($max)) {
            if (! $cursor->isWeekend()) {
                return $cursor;
            }
            $cursor->addDay();
        }

        return null;
    }

    private static function lastWeekdayOnOrBefore(Carbon $from, Carbon $min): ?Carbon
    {
        $cursor = $from->copy()->startOfDay();
        while ($cursor->gte($min)) {
            if (! $cursor->isWeekend()) {
                return $cursor;
            }
            $cursor->subDay();
        }

        return null;
    }
}
