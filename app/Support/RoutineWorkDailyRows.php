<?php

namespace App\Support;

use App\Models\RoutineWorkWindow;
use Carbon\Carbon;

final class RoutineWorkDailyRows
{
    /**
     * Aktif penceredeki her gün için boş günlük satır şablonu.
     *
     * @return list<array{start_date: string, end_date: string, work_item: string, status: string}>
     */
    public static function emptyRowsForCurrentWindow(): array
    {
        $window = RoutineWorkWindow::current();
        if (! $window?->start_date || ! $window?->end_date) {
            return [];
        }

        $rows = [];
        $cursor = $window->start_date->copy()->startOfDay();
        $end = $window->end_date->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $day = $cursor->toDateString();
            $rows[] = [
                'start_date' => $day,
                'end_date' => $day,
                'work_item' => '',
                'status' => '',
            ];
            $cursor->addDay();
        }

        return $rows;
    }

    public static function datesWithinWindow(?string $startDate, ?string $endDate, RoutineWorkWindow $window): bool
    {
        if (! filled($startDate) || ! filled($endDate)) {
            return false;
        }

        try {
            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->startOfDay();
            $windowStart = $window->start_date->copy()->startOfDay();
            $windowEnd = $window->end_date->copy()->endOfDay();
        } catch (\Throwable) {
            return false;
        }

        return $start->lte($end)
            && $start->betweenIncluded($windowStart, $windowEnd)
            && $end->betweenIncluded($windowStart, $windowEnd);
    }
}
