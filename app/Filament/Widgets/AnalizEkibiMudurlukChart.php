<?php

namespace App\Filament\Widgets;

use App\Models\RoutineWorkItem;
use App\Models\User;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class AnalizEkibiMudurlukChart extends ChartWidget
{
    protected static ?string $heading = 'Bağlı Müdürlüklerde Aylık İş Yoğunluğu';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public ?string $filter = null;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isControlTeam();
    }

    protected function getFilters(): ?array
    {
        $now = now();
        $filters = [];

        for ($i = 0; $i < 6; $i++) {
            $period = $now->copy()->subMonths($i);
            $key = $period->format('Y-m');
            $filters[$key] = $period->translatedFormat('F Y');
        }

        return $filters;
    }

    protected function getData(): array
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $user->isControlTeam()) {
            return [
                'datasets' => [],
                'labels' => [],
            ];
        }

        $period = $this->filter ?: now()->format('Y-m');
        [$yearRaw, $monthRaw] = array_pad(explode('-', $period), 2, '');
        $year = (int) $yearRaw;
        $month = max(1, min(12, (int) $monthRaw));
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $directorates = $user->assignedDirectorates()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);

        $directorateIds = $directorates
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $aggregates = RoutineWorkItem::query()
            ->selectRaw('user_id, COUNT(*) as planned_total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as actual_total', [RoutineWorkItem::STATUS_DONE])
            ->whereIn('user_id', $directorateIds)
            ->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $labels = [];
        $plannedTotals = [];
        $actualTotals = [];
        $remainingTotals = [];
        $remainingColors = [];

        foreach ($directorates as $directorateUser) {
            $summary = $aggregates->get((int) $directorateUser->id);
            $planned = (int) ($summary->planned_total ?? 0);
            $actual = (int) ($summary->actual_total ?? 0);
            $remaining = max(0, $planned - $actual);

            $labels[] = (string) $directorateUser->name;
            $plannedTotals[] = $planned;
            $actualTotals[] = $actual;
            $remainingTotals[] = $remaining;

            if ($remaining <= 0) {
                $remainingColors[] = '#22c55e';
            } elseif ($actual <= 0) {
                $remainingColors[] = '#ef4444';
            } else {
                $remainingColors[] = '#a855f7';
            }
        }

        return [
            'datasets' => [
                [
                    'label' => 'Öngörülen',
                    'data' => $plannedTotals,
                    'backgroundColor' => '#60a5fa',
                    'borderColor' => '#3b82f6',
                ],
                [
                    'label' => 'Gerçekleşen',
                    'data' => $actualTotals,
                    'backgroundColor' => '#22c55e',
                    'borderColor' => '#16a34a',
                ],
                [
                    'label' => 'Açıkta Kalan',
                    'data' => $remainingTotals,
                    'backgroundColor' => $remainingColors,
                    'borderColor' => $remainingColors,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
            'scales' => [
                'x' => [
                    'stacked' => true,
                ],
                'y' => [
                    'beginAtZero' => true,
                    'stacked' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }
}
