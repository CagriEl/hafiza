<?php

namespace App\Filament\Widgets;

use App\Models\MaliHizmetlerRapor;
use App\Models\User;
use App\Support\MaliHizmetlerAccess;
use App\Support\ReportPeriodWeeks;
use Filament\Widgets\ChartWidget;

class MaliHizmetlerChart extends ChartWidget
{
    protected static ?string $heading = 'Ödeme Planı — Haftalık Özet';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public ?string $filter = null;

    public static function canView(): bool
    {
        return MaliHizmetlerAccess::userCanManageMaliRaporlar(auth()->user());
    }

    protected function getFilters(): ?array
    {
        $currentYear = (int) now()->year;

        return [
            (string) $currentYear => (string) $currentYear,
            (string) ($currentYear - 1) => (string) ($currentYear - 1),
        ];
    }

    protected function getData(): array
    {
        $labels = [];
        $odemeData = [];
        $talepData = [];

        $user = auth()->user();
        if (! $user instanceof User) {
            return ['datasets' => [], 'labels' => $labels];
        }

        $year = (int) ($this->filter ?: now()->year);

        $query = MaliHizmetlerRapor::query()->where('yil', $year)->orderBy('ay')->orderBy('hafta');

        if ($user->isMaliHizmetlerAccount() && ! $user->isReportingSuperAdmin()) {
            $query->where('user_id', $user->id);
        } elseif (! $user->isReportingSuperAdmin()) {
            $maliId = MaliHizmetlerAccess::maliHizmetlerUserId();
            if ($maliId === null) {
                return ['datasets' => [], 'labels' => []];
            }
            $query->where('user_id', $maliId);
        }

        $records = $query->get();

        foreach ($records as $record) {
            $ay = str_pad(trim((string) $record->ay), 2, '0', STR_PAD_LEFT);
            $weekLabel = ReportPeriodWeeks::weekShortLabel((int) $record->yil, (int) $ay, (int) $record->hafta);
            $monthName = ReportPeriodWeeks::turkishMonthName((int) $ay);

            $labels[] = $monthName.' · '.$weekLabel;
            $odemeData[] = (float) $record->haftalik_odeme_toplam;
            $talepData[] = $record->odemeTalepleriToplam();
        }

        if ($labels === []) {
            $labels = ['Veri yok'];
            $odemeData = [0];
            $talepData = [0];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Haftalık Ödeme (₺)',
                    'data' => $odemeData,
                    'backgroundColor' => 'rgba(59, 130, 246, 0.7)',
                    'borderColor' => '#2563eb',
                    'type' => 'bar',
                ],
                [
                    'label' => 'Ödeme Talepleri Toplamı (₺)',
                    'data' => $talepData,
                    'backgroundColor' => 'rgba(245, 158, 11, 0.7)',
                    'borderColor' => '#d97706',
                    'type' => 'bar',
                ],
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
            'responsive' => true,
            'maintainAspectRatio' => false,
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
            ],
        ];
    }
}
