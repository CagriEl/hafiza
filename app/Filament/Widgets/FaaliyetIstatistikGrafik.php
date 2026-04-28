<?php

namespace App\Filament\Widgets;

use App\Models\AylikFaaliyet;
use App\Models\User;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Str;

class FaaliyetIstatistikGrafik extends ChartWidget
{
    protected static ?string $heading = 'Müdürlük Performans ve İş Yükü Analizi';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '800px';

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
                'short_name' => $this->shortDirectorateName((string) $mudurluk->name),
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

        $labels = array_column($rows, 'short_name');
        $fullLabels = array_column($rows, 'full_name');
        $tamamlananVerisi = array_column($rows, 'tamam');
        $gecikmeVerisi = array_column($rows, 'gecikme');
        $bekleyenVerisi = array_column($rows, 'bekleyen');

        static::$maxHeight = max(600, (count($rows) * 34) + 120).'px';

        return [
            'datasets' => [
                ['label' => 'Tamamlanan', 'data' => $tamamlananVerisi, 'backgroundColor' => '#22c55e', 'barThickness' => 14],
                ['label' => 'Bekleyen / Planlanan', 'data' => $bekleyenVerisi, 'backgroundColor' => '#3b82f6', 'barThickness' => 14],
                ['label' => 'Geciken', 'data' => $gecikmeVerisi, 'backgroundColor' => '#ef4444', 'barThickness' => 14],
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
                'x' => ['stacked' => true],
                'y' => [
                    'stacked' => true,
                    'ticks' => ['autoSkip' => false, 'font' => ['size' => 10]],
                ],
            ],
            'plugins' => [
                'legend' => ['position' => 'top'],
                'tooltip' => [
                    'callbacks' => [
                        'title' => RawJs::make('function (items) {
                            if (!items.length) return "";
                            const chart = items[0].chart;
                            const idx = items[0].dataIndex;
                            const full = chart?.data?.fullLabels?.[idx];
                            return full ?? items[0].label;
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
            'class' => 'max-h-[600px] overflow-y-auto',
        ];
    }

    private function shortDirectorateName(string $name): string
    {
        $normalized = trim(Str::of($name)->replace(' Müdürlüğü', '')->toString());

        if (Str::length($normalized) <= 24) {
            return $normalized;
        }

        return Str::of($normalized)->limit(24, '...')->toString();
    }
}
