<?php

namespace App\Filament\Widgets;

use App\Models\AylikFaaliyet;
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
        $monthVariants = [(string) $month, str_pad((string) $month, 2, '0', STR_PAD_LEFT)];

        $directorates = $user->assignedDirectorates()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);

        $labels = [];
        $plannedTotals = [];
        $actualTotals = [];
        $remainingTotals = [];
        $remainingColors = [];

        foreach ($directorates as $directorateUser) {
            $records = AylikFaaliyet::query()
                ->where('user_id', (int) $directorateUser->id)
                ->where('yil', $year)
                ->whereIn('ay', $monthVariants)
                ->get(['faaliyetler']);

            $planned = 0;
            $actual = 0;

            foreach ($records as $record) {
                $rows = is_array($record->faaliyetler) ? $record->faaliyetler : [];
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $planned += static::plannedValueForRow($row);
                    $actual += static::actualValueForRow($row);
                }
            }

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

    /**
     * @param  array<string, mixed>  $row
     */
    private static function plannedValueForRow(array $row): int
    {
        $kapsam = $row['kapsam_verileri'] ?? null;
        if (is_array($kapsam) && $kapsam !== []) {
            $sum = 0;
            foreach ($kapsam as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $v = $line['ongorulen'] ?? $line['deger'] ?? null;
                if (is_numeric($v)) {
                    $sum += (int) $v;
                }
            }

            return $sum;
        }

        if (is_numeric($row['hedef'] ?? null)) {
            return (int) $row['hedef'];
        }

        if (is_numeric($row['ongorulen'] ?? null)) {
            return (int) $row['ongorulen'];
        }

        $gerceklesen = is_numeric($row['gerceklesen'] ?? null) ? (int) $row['gerceklesen'] : 0;
        $bekleyen = is_numeric($row['bekleyen_is'] ?? null) ? (int) $row['bekleyen_is'] : 0;
        if ($gerceklesen > 0 || $bekleyen > 0) {
            return $gerceklesen + $bekleyen;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function actualValueForRow(array $row): int
    {
        $kapsam = $row['kapsam_verileri'] ?? null;
        if (is_array($kapsam) && $kapsam !== []) {
            $sum = 0;
            foreach ($kapsam as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $v = $line['gerceklesen'] ?? null;
                if (is_numeric($v)) {
                    $sum += (int) $v;
                }
            }

            return $sum;
        }

        return is_numeric($row['gerceklesen'] ?? null) ? (int) $row['gerceklesen'] : 0;
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
