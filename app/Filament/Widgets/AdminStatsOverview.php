<?php

namespace App\Filament\Widgets;

use App\Models\AylikFaaliyet;
use App\Models\Feedback;
use App\Models\User;
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

        foreach ($kayitlar as $kayit) {
            $isler = is_string($kayit->faaliyetler) ? json_decode($kayit->faaliyetler, true) : $kayit->faaliyetler;

            if (is_array($isler)) {
                foreach ($isler as $is) {
                    $toplamIs++;
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
            Stat::make('Bekleyen Geri Bildirimler', $bekleyenGeriBildirim)
                ->description('IT ekibinin incelemesini bekleyen kayıtlar')
                ->icon('heroicon-m-chat-bubble-left-right')
                ->color('warning'),
        ];
    }
}
