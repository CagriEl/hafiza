<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\HaftalikRapor;
use Carbon\Carbon;

class AylikFaaliyetChart extends ChartWidget
{
    protected static ?string $heading = 'Aylık Faaliyet Yoğunluğu';
    
    // Grafiğin genişliği (Opsiyonel, full width yapar)
    protected int | string | array $columnSpan = 'full';
    
    // Sıralama (Stats widget'ın altında görünsün diye)
    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $user = auth()->user();
        $isAdmin = $user->id === 1;
        $yil = now()->year; // Sadece bu yılın verisi

        // 1. Verileri Çek
        $query = HaftalikRapor::query()->whereYear('baslangic_tarihi', $yil);

        if (!$isAdmin) {
            $query->where('user_id', $user->id);
        }

        $raporlar = $query->get();

        // 2. Aylara Göre Hazırlık (Ocak=0, ... Aralık=0)
        // Dizi indexleri 1'den 12'ye kadar olsun
        $aylikVeriler = array_fill(1, 12, 0);

        // 3. Döngüyle İçerik Sayma
        foreach ($raporlar as $rapor) {
            // Raporun ayı
            $ay = Carbon::parse($rapor->baslangic_tarihi)->month;

            // İçindeki işleri say
            $isler = is_string($rapor->faaliyetler) ? json_decode($rapor->faaliyetler, true) : $rapor->faaliyetler;
            
            if (is_array($isler)) {
                $sayi = count($isler);
                // İlgili aya ekle
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
                'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar'; // Sütun Grafik
    }
}