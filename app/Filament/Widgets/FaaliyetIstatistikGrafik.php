<?php

namespace App\Filament\Widgets;

use App\Models\AylikFaaliyet;
use App\Models\User;
use Carbon\Carbon;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class FaaliyetIstatistikGrafik extends ChartWidget
{
    protected static string $view = 'filament.widgets.faaliyet-istatistik-grafik';

    protected static ?string $heading = 'Müdürlük İş Yükü Dağılımı';

    protected string|int|array $columnSpan = 'full';

    protected static ?string $maxHeight = '500px';

    public ?string $filter = 'all';

    protected function getFilters(): ?array
    {
        return [
            'all' => 'Tüm Müdürlükler',
            'top_5' => 'En Yüksek Performanslı 5 Müdürlük',
            'bottom_5' => 'En Düşük Performanslı 5 Müdürlük',
        ];
    }

    public static function canView(): bool
    {
        return auth()->id() === 1;
    }

    protected function getData(): array
    {
        $activeFilter = $this->filter ?? 'all';

        $yil = now()->year;
        $ayRaw = now()->format('m');
        $ayNorm = str_pad(preg_replace('/\D/', '', (string) $ayRaw) ?: '1', 2, '0', STR_PAD_LEFT);
        $ayVariants = array_values(array_unique([$ayNorm, (string) (int) $ayNorm]));

        $mudurlukler = User::queryPerformanceChartDirectorates()->get();

        $rows = [];

        foreach ($mudurlukler as $mudurluk) {
            $kayitlar = AylikFaaliyet::query()
                ->where('user_id', $mudurluk->id)
                ->where('yil', $yil)
                ->whereIn('ay', $ayVariants)
                ->get();

            $tamam = 0;
            $gecikme = 0;
            $bekleyen = 0;

            foreach ($kayitlar as $kayit) {
                $isler = is_string($kayit->faaliyetler) ? json_decode($kayit->faaliyetler, true) : $kayit->faaliyetler;
                if (is_array($isler)) {
                    foreach ($isler as $is) {
                        $isTamam = ($is['durum'] ?? '') === 'tamam';
                        $sonTarih = isset($is['son_tarih']) ? Carbon::parse($is['son_tarih']) : null;

                        if ($isTamam) {
                            $tamam++;
                        } elseif ($sonTarih && $sonTarih->isPast()) {
                            $gecikme++;
                        } else {
                            $bekleyen++;
                        }
                    }
                }
            }

            $toplam = $tamam + $gecikme + $bekleyen;
            $performansOrani = $toplam > 0 ? round(($tamam / $toplam) * 100, 2) : 0.0;

            $rows[] = [
                'full_name' => (string) $mudurluk->name,
                'tamam' => $tamam,
                'gecikme' => $gecikme,
                'bekleyen' => $bekleyen,
                'performans' => $performansOrani,
            ];
        }

        if ($activeFilter === 'top_5') {
            usort($rows, fn (array $a, array $b): int => $b['performans'] <=> $a['performans']);
            $rows = array_slice($rows, 0, 5);
        } elseif ($activeFilter === 'bottom_5') {
            usort($rows, fn (array $a, array $b): int => $a['performans'] <=> $b['performans']);
            $rows = array_slice($rows, 0, 5);
        } else {
            usort($rows, fn (array $a, array $b): int => strcasecmp($a['full_name'], $b['full_name']));
        }

        $labels = array_column($rows, 'full_name');
        $fullLabels = array_column($rows, 'full_name');
        $performansVerisi = array_column($rows, 'performans');

        return [
            'datasets' => [
                [
                    'label' => 'İş Yükü Skoru (%)',
                    'data' => $performansVerisi,
                    'backgroundColor' => '#60a5fa',
                    'borderColor' => '#3b82f6',
                    'borderWidth' => 1,
                    'barThickness' => 5,
                    'barPercentage' => 1,
                    'categoryPercentage' => 1,
                ],
            ],
            'labels' => $labels,
            'fullLabels' => $fullLabels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'responsive' => true,
            'scales' => [
                'x' => [
                    'beginAtZero' => true,
                    'max' => 100,
                ],
                'y' => [
                    'ticks' => ['autoSkip' => false, 'font' => ['size' => 10]],
                ],
            ],
            'plugins' => [
                'legend' => ['display' => false],
                'tooltip' => [
                    'callbacks' => [
                        'title' => RawJs::make('function (items) {
                            if (!items.length) return "";
                            const chart = items[0].chart;
                            const idx = items[0].dataIndex;
                            const full = chart?.data?.fullLabels?.[idx];
                            return full ?? items[0].label;
                        }'),
                        'label' => RawJs::make('function (item) {
                            return "Performans Skoru: " + item.formattedValue + "%";
                        }'),
                    ],
                ],
            ],
            'maintainAspectRatio' => false,
        ];
    }

    protected function getExtraAttributes(): array
    {
        return [
            'class' => 'overflow-y-auto',
            'style' => 'height: 500px; min-height: 500px;',
        ];
    }
}
