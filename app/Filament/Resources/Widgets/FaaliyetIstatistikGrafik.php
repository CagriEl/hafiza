<?php

namespace App\Filament\Widgets;

use App\Models\AylikFaaliyet;
use App\Models\User;
use Filament\Widgets\ChartWidget;
use Carbon\Carbon;

class FaaliyetIstatistikGrafik extends ChartWidget
{
    protected static ?string $heading = 'Müdürlük Performans ve İş Yükü Analizi';
    
    protected int | string | array $columnSpan = 'full';

    // Başlangıçta mevcut ayı (2026-02) otomatik seçer
    public ?string $filter = null;

    public function __construct()
    {
        $this->filter = now()->format('Y-m');
    }

    protected function getFilters(): ?array
    {
        $filters = [];
        $year = now()->year;

        // Tüm yılı (12 ayı) listeye ekliyoruz
        for ($m = 1; $m <= 12; $m++) {
            $date = Carbon::create($year, $m, 1);
            $key = $date->format('Y-m');
            $label = $date->translatedFormat('F Y');
            $filters[$key] = $label;
        }

        return array_reverse($filters); 
    }

    public static function canView(): bool
    {
        return auth()->id() === 1;
    }

    protected function getData(): array
    {
        $activeFilter = $this->filter ?? now()->format('Y-m');
        
        $filterParts = explode('-', $activeFilter);
        $yil = $filterParts[0];
        $ay = $filterParts[1];

        $mudurlukler = User::where('id', '!=', 1)->get();
        
        $labels = [];
        $tamamlananVerisi = [];
        $gecikmeVerisi = [];
        $bekleyenVerisi = [];

        foreach ($mudurlukler as $mudurluk) {
            $labels[] = $mudurluk->name;
            
            $kayitlar = AylikFaaliyet::where('user_id', $mudurluk->id)
                ->where('yil', $yil)
                ->where('ay', $ay)
                ->get();

            $tamam = 0; $gecikme = 0; $bekleyen = 0;

            foreach ($kayitlar as $kayit) {
                $isler = is_string($kayit->faaliyetler) ? json_decode($kayit->faaliyetler, true) : $kayit->faaliyetler;
                if (is_array($isler)) {
                    foreach ($isler as $is) {
                        $isTamam = ($is['durum'] ?? '') === 'tamam';
                        $sonTarih = isset($is['son_tarih']) ? Carbon::parse($is['son_tarih']) : null;

                        if ($isTamam) $tamam++;
                        elseif ($sonTarih && $sonTarih->isPast()) $gecikme++;
                        else $bekleyen++;
                    }
                }
            }

            $tamamlananVerisi[] = $tamam;
            $gecikmeVerisi[] = $gecikme;
            $bekleyenVerisi[] = $bekleyen;
        }

        return [
            'datasets' => [
                ['label' => 'Tamamlanan', 'data' => $tamamlananVerisi, 'backgroundColor' => '#22c55e'],
                ['label' => 'Bekleyen / Planlanan', 'data' => $bekleyenVerisi, 'backgroundColor' => '#3b82f6'],
                ['label' => 'Geciken', 'data' => $gecikmeVerisi, 'backgroundColor' => '#ef4444'],
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
            'indexAxis' => 'y',
            'scales' => [
                'x' => ['stacked' => true],
                'y' => [
                    'stacked' => true,
                    'ticks' => ['autoSkip' => false, 'font' => ['size' => 10]],
                ],
            ],
            'plugins' => [
                'legend' => ['position' => 'top'],
            ],
            'maintainAspectRatio' => false,
        ];
    }
}