<?php

namespace App\Filament\Widgets;

use App\Models\AylikFaaliyet;
use App\Models\User;
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
        $doneTotals = [];
        $remainingTotals = [];

        foreach ($directorates as $directorateUser) {
            $records = AylikFaaliyet::query()
                ->where('user_id', (int) $directorateUser->id)
                ->where('yil', $year)
                ->whereIn('ay', $monthVariants)
                ->get(['faaliyetler']);

            if ($records->isEmpty()) {
                $records = AylikFaaliyet::query()
                    ->where('user_id', (int) $directorateUser->id)
                    ->get(['yil', 'ay', 'faaliyetler'])
                    ->sortByDesc(fn (AylikFaaliyet $record): int => static::reportPeriodSortKey($record))
                    ->take(1)
                    ->values();
            }

            $planned = 0;
            $done = 0;

            foreach ($records as $record) {
                $rows = is_array($record->faaliyetler) ? $record->faaliyetler : [];
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $planned += static::plannedCountForRow($row);
                    $done += static::actualCountForRow($row);
                }
            }

            $remaining = max(0, $planned - $done);

            $labels[] = (string) $directorateUser->name;
            $doneTotals[] = $done;
            $remainingTotals[] = $remaining;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Yapılan İş',
                    'data' => $doneTotals,
                    'backgroundColor' => '#22c55e',
                    'borderColor' => '#16a34a',
                ],
                [
                    'label' => 'Bekleyen İş',
                    'data' => $remainingTotals,
                    'backgroundColor' => '#3b82f6',
                    'borderColor' => '#2563eb',
                ],
            ],
            'labels' => $labels,
        ];
    }

    private static function reportPeriodSortKey(AylikFaaliyet $record): int
    {
        $year = (int) ($record->yil ?? 0);
        $month = (int) (preg_replace('/\D/', '', (string) ($record->ay ?? '')) ?: 0);

        return $year * 100 + $month;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function plannedCountForRow(array $row): int
    {
        $plannedQuantity = static::plannedQuantityForRow($row);
        $actualQuantity = static::actualQuantityForRow($row);

        return ($plannedQuantity > 0 || $actualQuantity > 0) ? 1 : 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function actualCountForRow(array $row): int
    {
        $plannedQuantity = static::plannedQuantityForRow($row);
        $actualQuantity = static::actualQuantityForRow($row);

        if ($plannedQuantity <= 0) {
            return $actualQuantity > 0 ? 1 : 0;
        }

        return $actualQuantity >= $plannedQuantity ? 1 : 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function plannedQuantityForRow(array $row): float
    {
        $kapsam = $row['kapsam_verileri'] ?? null;
        if (is_array($kapsam) && $kapsam !== []) {
            $sum = 0.0;
            foreach ($kapsam as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $v = static::toNumber($line['ongorulen'] ?? $line['deger'] ?? null);
                if ($v !== null) {
                    $sum += $v;
                }
            }

            return $sum;
        }

        foreach (['hedef', 'ongorulen'] as $field) {
            $v = static::toNumber($row[$field] ?? null);
            if ($v !== null) {
                return $v;
            }
        }

        $gerceklesen = static::toNumber($row['gerceklesen'] ?? null) ?? 0.0;
        $bekleyen = static::toNumber($row['bekleyen_is'] ?? null) ?? 0.0;

        return max(0.0, $gerceklesen + $bekleyen);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function actualQuantityForRow(array $row): float
    {
        $kapsam = $row['kapsam_verileri'] ?? null;
        if (is_array($kapsam) && $kapsam !== []) {
            $sum = 0.0;
            foreach ($kapsam as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $v = static::toNumber($line['gerceklesen'] ?? null);
                if ($v !== null) {
                    $sum += $v;
                }
            }

            return $sum;
        }

        return static::toNumber($row['gerceklesen'] ?? null) ?? 0.0;
    }

    private static function toNumber(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $normalized = str_replace(' ', '', $raw);
        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized) ?? '';
        if ($normalized === '') {
            return null;
        }

        $parts = explode('.', $normalized);
        if (count($parts) > 2) {
            $decimal = array_pop($parts);
            $normalized = implode('', $parts).'.'.$decimal;
        }

        return is_numeric($normalized) ? (float) $normalized : null;
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
