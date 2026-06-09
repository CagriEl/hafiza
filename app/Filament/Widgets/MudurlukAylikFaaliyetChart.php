<?php

namespace App\Filament\Widgets;

use App\Models\AylikFaaliyet;
use App\Models\User;
use Filament\Widgets\ChartWidget;

class MudurlukAylikFaaliyetChart extends ChartWidget
{
    protected static ?string $heading = 'Aylık Faaliyet Durum Özeti';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public ?string $filter = null;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isMudurlukReportingAccount();
    }

    protected function getFilters(): ?array
    {
        $currentYear = (int) now()->year;

        return [
            (string) $currentYear => (string) $currentYear,
            (string) ($currentYear - 1) => (string) ($currentYear - 1),
            (string) ($currentYear - 2) => (string) ($currentYear - 2),
        ];
    }

    protected function getData(): array
    {
        $labels = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
        $doneCounts = array_fill(0, 12, 0);
        $pendingCounts = array_fill(0, 12, 0);

        $user = auth()->user();
        if (! $user instanceof User || ! $user->isMudurlukReportingAccount()) {
            return [
                'datasets' => [],
                'labels' => $labels,
            ];
        }

        $year = (int) ($this->filter ?: now()->year);

        $records = AylikFaaliyet::query()
            ->where('user_id', $user->id)
            ->where('yil', $year)
            ->get(['ay', 'faaliyetler']);

        foreach ($records as $record) {
            $monthIndex = max(1, min(12, (int) preg_replace('/\D/', '', (string) $record->ay))) - 1;

            $rows = is_array($record->faaliyetler) ? $record->faaliyetler : [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $planned = static::plannedValueForRow($row);
                $actual = static::actualValueForRow($row);
                if ($planned <= 0 && $actual <= 0) {
                    continue;
                }

                $isCompleted = ($planned > 0 && $actual >= $planned) || ($planned <= 0 && $actual > 0);

                if ($isCompleted) {
                    $doneCounts[$monthIndex]++;

                    continue;
                }

                $pendingCounts[$monthIndex]++;
            }
        }

        return [
            'datasets' => [
                [
                    'label' => 'Yapılan İş Sayısı',
                    'data' => $doneCounts,
                    'backgroundColor' => '#22c55e',
                    'borderColor' => '#16a34a',
                ],
                [
                    'label' => 'Bekleyen İşlem Sayısı',
                    'data' => $pendingCounts,
                    'backgroundColor' => '#3b82f6',
                    'borderColor' => '#2563eb',
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
