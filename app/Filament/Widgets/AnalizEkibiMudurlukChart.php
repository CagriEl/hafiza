<?php

namespace App\Filament\Widgets;

use App\Models\AylikFaaliyet;
use App\Models\User;
use Filament\Widgets\ChartWidget;

class AnalizEkibiMudurlukChart extends ChartWidget
{
    protected static ?string $heading = 'Bağlı Müdürlüklerde Aylık Durum';

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
        $completed = [];
        $inProgress = [];

        foreach ($directorates as $directorateUser) {
            $records = AylikFaaliyet::query()
                ->where('user_id', (int) $directorateUser->id)
                ->where('yil', $year)
                ->whereIn('ay', $monthVariants)
                ->get(['faaliyetler']);

            $doneCount = 0;
            $progressCount = 0;

            foreach ($records as $record) {
                $rows = is_array($record->faaliyetler) ? $record->faaliyetler : [];

                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $status = mb_strtolower(trim((string) ($row['durum'] ?? '')));
                    $isDone = in_array($status, ['tamam', 'tamamlandı', 'tamamlandi'], true);

                    if ($isDone) {
                        $doneCount++;

                        continue;
                    }

                    $progressCount++;
                }
            }

            $labels[] = (string) $directorateUser->name;
            $completed[] = $doneCount;
            $inProgress[] = $progressCount;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Tamamlanan',
                    'data' => $completed,
                    'backgroundColor' => '#22c55e',
                    'borderColor' => '#16a34a',
                ],
                [
                    'label' => 'Devam Eden',
                    'data' => $inProgress,
                    'backgroundColor' => '#3b82f6',
                    'borderColor' => '#2563eb',
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
