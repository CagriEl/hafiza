<?php

namespace App\Filament\Widgets;

use App\Models\AylikFaaliyet;
use App\Models\User;
use Carbon\Carbon;
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
        $completed = array_fill(0, 12, 0);
        $inProgress = array_fill(0, 12, 0);
        $delayed = array_fill(0, 12, 0);

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

                $durum = mb_strtolower(trim((string) ($row['durum'] ?? '')));
                $isCompleted = in_array($durum, ['tamam', 'tamamlandı', 'tamamlandi'], true);

                if ($isCompleted) {
                    $completed[$monthIndex]++;

                    continue;
                }

                $sonTarih = trim((string) ($row['son_tarih'] ?? ''));
                if ($sonTarih !== '') {
                    try {
                        if (Carbon::parse($sonTarih)->isPast()) {
                            $delayed[$monthIndex]++;

                            continue;
                        }
                    } catch (\Throwable) {
                        // Geçersiz tarih değeri varsa "devam eden" kabul edilir.
                    }
                }

                $inProgress[$monthIndex]++;
            }
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
                [
                    'label' => 'Geciken',
                    'data' => $delayed,
                    'backgroundColor' => '#ef4444',
                    'borderColor' => '#dc2626',
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
