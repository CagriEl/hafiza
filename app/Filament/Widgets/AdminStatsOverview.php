<?php

namespace App\Filament\Widgets;

use App\Models\AylikFaaliyet;
use App\Models\Feedback;
use App\Models\User;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminStatsOverview extends BaseWidget
{
    // Sadece Admin görsün
    public static function canView(): bool
    {
        return auth()->id() === 1;
    }

    protected function getStats(): array
    {
        $ownerIds = User::queryPerformanceChartDirectorates()->pluck('id');
        $kayitlar = AylikFaaliyet::query()
            ->whereIn('user_id', $ownerIds)
            ->get();

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

        $bekleyenGeriBildirim = Feedback::query()
            ->where('status', Feedback::STATUS_NEW)
            ->count();

        return [
            Stat::make('Sistemdeki Toplam İş Sayısı', $toplamIs)
                ->description('Tüm müdürlüklerin girdiği toplam faaliyet')
                ->icon('heroicon-m-clipboard-document-list')
                ->color('info'),

            Stat::make('Toplam Geciken İş Sayısı', $gecikenIs)
                ->description('Süresi dolmuş ama tamamlanmamış işler')
                ->icon('heroicon-m-exclamation-circle')
                ->color('danger'),
            Stat::make('Bekleyen Geri Bildirimler', $bekleyenGeriBildirim)
                ->description('IT ekibinin incelemesini bekleyen kayıtlar')
                ->icon('heroicon-m-chat-bubble-left-right')
                ->color('warning'),
        ];
    }
}
