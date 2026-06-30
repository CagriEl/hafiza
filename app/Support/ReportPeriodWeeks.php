<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Aylık faaliyet raporlarında ayı sabit 4 haftalık takvim aralığına böler.
 */
final class ReportPeriodWeeks
{
    public const WEEK_COUNT = 4;

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

    /**
     * @return list<array{hafta: int, baslangic: Carbon, bitis: Carbon}>
     */
    public static function weeksForMonth(int $year, int $month): array
    {
        if ($year <= 0 || $month < 1 || $month > 12) {
            return [];
        }

        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $lastDay = (int) $monthStart->daysInMonth;

        $ranges = [
            [1, min(7, $lastDay)],
            [8, min(14, $lastDay)],
            [15, min(21, $lastDay)],
            [22, $lastDay],
        ];

        $weeks = [];
        foreach ($ranges as $index => [$startDay, $endDay]) {
            if ($startDay > $lastDay) {
                continue;
            }

            $weeks[] = [
                'hafta' => $index + 1,
                'baslangic' => $monthStart->copy()->day($startDay),
                'bitis' => $monthStart->copy()->day($endDay),
            ];
        }

        return $weeks;
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

    public static function weekShortLabel(int $year, int $month, int $week): string
    {
        $weekData = self::weekByNumber($year, $month, $week);
        if ($weekData === null) {
            return $week.'. Hafta';
        }

        return sprintf(
            '%d. Hafta (%s - %s)',
            $week,
            self::formatDate($weekData['baslangic']),
            self::formatDate($weekData['bitis'])
        );
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
            $parts[] = sprintf(
                '%d. Hafta: %s – %s',
                $week['hafta'],
                self::formatDate($week['baslangic']),
                self::formatDate($week['bitis'])
            );
        }

        return implode(' | ', $parts);
    }

    public static function weeksOverviewHtml(int $year, int $month): string
    {
        $parts = [];
        foreach (self::weeksForMonth($year, $month) as $week) {
            $parts[] = sprintf(
                '<strong>%d. Hafta:</strong> %s – %s',
                $week['hafta'],
                e(self::formatDate($week['baslangic'])),
                e(self::formatDate($week['bitis']))
            );
        }

        return implode(' &nbsp;|&nbsp; ', $parts);
    }

    public static function weekLabelForRecord(?int $year, mixed $month, mixed $week): ?string
    {
        $weekNumber = (int) $week;
        $yearNumber = (int) $year;
        $monthNumber = (int) preg_replace('/\D/', '', (string) $month);

        if ($weekNumber < 1 || $yearNumber <= 0 || $monthNumber < 1 || $monthNumber > 12) {
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

    public static function isWeeklyReportingFrequency(?string $frequency): bool
    {
        $normalized = mb_strtolower(trim((string) $frequency));

        return str_contains($normalized, 'haftalık') || str_contains($normalized, 'haftalik');
    }
}
