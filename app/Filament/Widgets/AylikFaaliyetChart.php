<?php

namespace App\Filament\Widgets;

use App\Models\AylikFaaliyet;
use App\Support\ReportDirectorateScope;
use Filament\Widgets\ChartWidget;

class AylikFaaliyetChart extends ChartWidget
{
    protected static ?string $heading = 'Aylık Faaliyet Yoğunluğu';

    // Grafiğin genişliği (Opsiyonel, full width yapar)
    protected int|string|array $columnSpan = 'full';

    // Sıralama (Stats widget'ın altında görünsün diye)
    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $yil = now()->year;

        $query = ReportDirectorateScope::constrain(
            AylikFaaliyet::query()->where('yil', $yil)
        );

        $raporlar = $query->get();

        // 2. Aylara Göre Hazırlık (Ocak=0, ... Aralık=0)
        // Dizi indexleri 1'den 12'ye kadar olsun
        $aylikVeriler = array_fill(1, 12, 0);

        foreach ($raporlar as $rapor) {
            $ay = (int) ($rapor->ay ?? 0);
            if ($ay < 1 || $ay > 12) {
                continue;
            }
            $isler = is_string($rapor->faaliyetler) ? json_decode($rapor->faaliyetler, true) : $rapor->faaliyetler;

            if (is_array($isler)) {
                $sayi = count($isler);
                $aylikVeriler[$ay] += $sayi;
            }
        }

        // 4. Grafiğe Gönder
        return [
            'datasets' => [
                [
                    'label' => 'Yapılan İş Adedi (Satır Sayısı)',
                    'data' => array_values($aylikVeriler), // [5, 12, 0, 8...]
                    'backgroundColor' => '#36A2EB',
                    'borderColor' => '#36A2EB',
                ],
            ],
            'labels' => [
                'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
                'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık',
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar'; // Sütun Grafik
    }
}
