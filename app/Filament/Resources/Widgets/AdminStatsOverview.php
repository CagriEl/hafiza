<?php

namespace App\Filament\Widgets;

use App\Models\AylikFaaliyet;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Carbon\Carbon;

class AdminStatsOverview extends BaseWidget
{
    // Sadece Admin görsün
    public static function canView(): bool
    {
        return auth()->id() === 1;
    }

    protected function getStats(): array
    {
        $kayitlar = AylikFaaliyet::all();
        
        $toplamIs = 0;
        $gecikenIs = 0;

        foreach ($kayitlar as $kayit) {
            $isler = is_string($kayit->faaliyetler) ? json_decode($kayit->faaliyetler, true) : $kayit->faaliyetler;
            
            if (is_array($isler)) {
                foreach ($isler as $is) {
                    $toplamIs++;
                    
                    // Gecikme kontrolü: Tarih geçmiş ve tamamlanmamış
                    if (
                        isset($is['son_tarih']) && 
                        Carbon::parse($is['son_tarih'])->isPast() && 
                        ($is['durum'] ?? '') !== 'tamam'
                    ) {
                        $gecikenIs++;
                    }
                }
            }
        }

        return [
            Stat::make('Sistemdeki Toplam İş Sayısı', $toplamIs)
                ->description('Tüm müdürlüklerin girdiği toplam faaliyet')
                ->icon('heroicon-m-clipboard-document-list')
                ->color('info'),

            Stat::make('Toplam Geciken İş Sayısı', $gecikenIs)
                ->description('Süresi dolmuş ama tamamlanmamış işler')
                ->icon('heroicon-m-exclamation-circle')
                ->color('danger'),
        ];
    }
}