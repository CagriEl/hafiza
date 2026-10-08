<?php

namespace App\Filament\Widgets;

use App\Models\MaliHizmetlerRapor;
use App\Support\MaliHizmetlerPeriod;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

class MaliHizmetlerDonutChart extends ChartWidget
{
    protected static ?string $heading = 'Ödeme Planı Dağılımı';

    protected static ?string $description = 'Seçili haftanın kayıtlı ödeme planı';

    protected static ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public ?int $chartYil = null;

    public ?string $chartAy = null;

    public ?int $chartHafta = null;

    /**
     * @var array<string, string>
     */
    protected $listeners = [
        'mali-donut-period-changed' => 'setPeriod',
    ];

    public static function canView(): bool
    {
        return false;
    }

    public function mount(): void
    {
        parent::mount();

        if ($this->chartYil === null || $this->chartAy === null || $this->chartHafta === null) {
            $period = MaliHizmetlerPeriod::currentWeekAttributes();
            $this->chartYil ??= $period['yil'];
            $this->chartAy ??= $period['ay'];
            $this->chartHafta ??= $period['hafta'];
        }
    }

    public function setPeriod(int $yil, string $ay, int $hafta): void
    {
        $this->chartYil = $yil;
        $this->chartAy = $ay;
        $this->chartHafta = $hafta;
        $this->cachedData = null;
        $this->updateChartData();
    }

    public function getHeading(): string|Htmlable|null
    {
        $record = $this->resolveRecord();

        if ($record === null) {
            return static::$heading;
        }

        return static::$heading.' — '.$record->donemLabel();
    }

    public function getDescription(): string|Htmlable|null
    {
        $record = $this->resolveRecord();

        if ($record === null) {
            return 'Seçili hafta için kayıtlı ödeme planı bulunamadı.';
        }

        return 'Kayıtlı ödeme planı: haftalık ödeme ve ödeme talepleri.';
    }

    protected function getData(): array
    {
        $record = $this->resolveRecord();

        if ($record === null) {
            return $this->emptyChart('Kayıtlı plan yok');
        }

        $haftalikOdeme = (float) $record->haftalik_odeme_toplam;
        $talepToplam = $record->odemeTalepleriToplam();

        if ($haftalikOdeme <= 0 && $talepToplam <= 0) {
            return $this->emptyChart('Kayıtlı plan boş');
        }

        return [
            'labels' => [
                'Haftalık Ödeme',
                'Ödeme Talepleri',
            ],
            'datasets' => [
                [
                    'data' => [$haftalikOdeme, $talepToplam],
                    'backgroundColor' => [
                        'rgba(59, 130, 246, 0.85)',
                        'rgba(245, 158, 11, 0.85)',
                    ],
                    'borderColor' => [
                        '#2563eb',
                        '#d97706',
                    ],
                    'borderWidth' => 2,
                    'hoverOffset' => 8,
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => true,
            'cutout' => '62%',
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
        ];
    }

    private function resolveRecord(): ?MaliHizmetlerRapor
    {
        return MaliHizmetlerPeriod::resolveReportingRecordForPeriod([
            'yil' => $this->chartYil,
            'ay' => $this->chartAy,
            'hafta' => $this->chartHafta,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyChart(string $label = 'Veri yok'): array
    {
        return [
            'labels' => [$label],
            'datasets' => [
                [
                    'data' => [1],
                    'backgroundColor' => ['rgba(148, 163, 184, 0.5)'],
                    'borderColor' => ['#94a3b8'],
                    'borderWidth' => 1,
                ],
            ],
        ];
    }
}
