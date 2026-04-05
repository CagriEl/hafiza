<?php

namespace App\Filament\Widgets;

use App\Models\HaftalikRapor;
use App\Models\SwotAnaliz;
use App\Support\ReportDirectorateScope;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class GenelIstatistikWidget extends BaseWidget
{
    // Widget'ın ne kadar hızlı yenileneceği (Opsiyonel)
    protected static ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $swotQuery = ReportDirectorateScope::constrain(SwotAnaliz::query());
        $raporQuery = ReportDirectorateScope::constrain(HaftalikRapor::query());

        if (! $swotQuery instanceof Builder || ! $raporQuery instanceof Builder) {
            return [
                Stat::make('Toplam SWOT Analizi', '—')->color('gray'),
                Stat::make('Haftalik Rapor Sayısı', '—')->color('gray'),
                Stat::make('Toplam Tamamlanan Faaliyet', '—')->color('gray'),
            ];
        }

        // --- RAPORLARDAKİ TOPLAM İŞ (SATIR) SAYISINI HESAPLA ---
        // Bu biraz işlem gerektirir, tüm raporları çekip içindeki JSON'ı sayacağız.
        $tumRaporlar = $raporQuery->get();
        $toplamIsSayisi = 0;

        foreach ($tumRaporlar as $rapor) {
            $isler = is_string($rapor->faaliyetler) ? json_decode($rapor->faaliyetler, true) : $rapor->faaliyetler;
            if (is_array($isler)) {
                $toplamIsSayisi += count($isler);
            }
        }

        return [
            Stat::make('Toplam SWOT Analizi', $swotQuery->count())
                ->description('Sisteme girilen stratejik planlar')
                ->descriptionIcon('heroicon-m-presentation-chart-line')
                ->color('success'),

            Stat::make('Haftalik Rapor Sayısı', $raporQuery->count())
                ->description('Girilen rapor adedi')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('warning'),

            Stat::make('Toplam Tamamlanan Faaliyet', $toplamIsSayisi)
                ->description('Raporlardaki toplam iş satırı')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('primary'),
        ];
    }
}
