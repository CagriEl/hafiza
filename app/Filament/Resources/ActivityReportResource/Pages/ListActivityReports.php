<?php

namespace App\Filament\Resources\ActivityReportResource\Pages;

use App\Filament\Resources\ActivityReportResource;
use App\Models\User;
use App\Models\ViceMayor;
use App\Services\ActivityService;
use App\Support\CoordinationAccess;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListActivityReports extends ListRecords
{
    protected static string $resource = ActivityReportResource::class;

    public function mount(): void
    {
        parent::mount();
        $legacy = ['my', 'own'];
        if (in_array(session('activity_report_active_tab'), $legacy, true)) {
            session(['activity_report_active_tab' => 'all']);
        }
        session(['activity_report_active_tab' => $this->activeTab ?? 'all']);

        $user = auth()->user();
        if (! $user instanceof User || $user->isReportingSuperAdmin()) {
            return;
        }

        $q = ActivityReportResource::getEloquentQuery();
        if ($q instanceof Builder && $q->count() === 0) {
            $bundle = app(ActivityService::class)->resolveCatalogOptionsForMudurluk(trim($user->name ?? ''));
            $payload = [
                'message' => 'Faaliyet raporu listesi boş — olası nedenler',
                'mudurluk_kullanici_adi' => $user->name,
                'erisim_kapsaminda_rapor_sayisi' => 0,
                'katalog_cozumleme' => $bundle['debug'],
                'not' => 'Sekme veya filtre listeyi daraltıyor olabilir. Katalog boşsa müdürlük adı eşleşmesi veya php artisan activity-catalog:sync gerekebilir.',
            ];
            if (method_exists($this, 'js')) {
                $this->js('console.warn('.json_encode($payload, JSON_UNESCAPED_UNICODE).')');
            }
        }
    }

    public function updatedActiveTab(): void
    {
        parent::updatedActiveTab();
        session(['activity_report_active_tab' => $this->activeTab ?? 'all']);
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return [];
        }

        if ($user->isControlTeam()) {
            $dirIds = $user->assignedDirectorates()
                ->pluck('users.id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if ($dirIds === []) {
                return [];
            }
            $incomingIds = CoordinationAccess::incomingAylikFaaliyetIdsForUserIds($dirIds);

            return [
                'all' => Tab::make('Tüm Raporlarım')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->query(fn (Builder $query) => $query->whereIn('user_id', $dirIds)),
                'outgoing' => Tab::make('Talep Ettiklerim')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->query(fn (Builder $query) => $query
                        ->whereIn('user_id', $dirIds)
                        ->whereHasCoordinationLine()),
                'incoming' => Tab::make('Gelen Koordinasyonlar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->badge(fn () => (string) count($incomingIds))
                    ->query(fn (Builder $query) => $incomingIds === []
                        ? $query->whereRaw('0 = 1')
                        : $query->whereIn('id', $incomingIds)),
            ];
        }

        if ($user->isReportingSuperAdmin()) {
            $mudurlukIds = $this->mudurlukCandidateUserIds();
            $incomingIds = CoordinationAccess::incomingAylikFaaliyetIdsForUserIds($mudurlukIds);

            return [
                'all' => Tab::make('Tüm Raporlarım')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->query(fn (Builder $query) => $query),
                'outgoing' => Tab::make('Talep Ettiklerim')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->query(fn (Builder $query) => $query->whereHasCoordinationLine()),
                'incoming' => Tab::make('Gelen Koordinasyonlar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->badge(fn () => (string) count($incomingIds))
                    ->query(fn (Builder $query) => $incomingIds === []
                        ? $query->whereRaw('0 = 1')
                        : $query->whereIn('id', $incomingIds)),
            ];
        }

        if ($user->isViceMayorAccount()) {
            $audience = $user->reportAudienceUserIds() ?? [];
            if ($audience === []) {
                return [];
            }
            $incomingIds = CoordinationAccess::incomingAylikFaaliyetIdsForUserIds($audience);

            return [
                'all' => Tab::make('Tüm Raporlarım')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->query(fn (Builder $query) => $query->whereIn('user_id', $audience)),
                'outgoing' => Tab::make('Talep Ettiklerim')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->query(fn (Builder $query) => $query
                        ->whereIn('user_id', $audience)
                        ->whereHasCoordinationLine()),
                'incoming' => Tab::make('Gelen Koordinasyonlar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->badge(fn () => (string) count($incomingIds))
                    ->query(fn (Builder $query) => $incomingIds === []
                        ? $query->whereRaw('0 = 1')
                        : $query->whereIn('id', $incomingIds)),
            ];
        }

        if ($user->isMudurlukReportingAccount()) {
            $audience = $user->reportAudienceUserIds() ?? [];
            if ($audience === []) {
                return [];
            }
            $uid = (int) $user->id;
            $incomingIds = CoordinationAccess::incomingAylikFaaliyetIdsForUser($uid);

            return [
                'all' => Tab::make('Tüm Raporlarım')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->query(fn (Builder $query) => $query->whereIn('user_id', $audience)),
                'outgoing' => Tab::make('Talep Ettiklerim')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->query(fn (Builder $query) => $query
                        ->whereIn('user_id', $audience)
                        ->whereHasCoordinationLine()),
                'incoming' => Tab::make('Gelen Koordinasyonlar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->badge(fn () => (string) count($incomingIds))
                    ->query(fn (Builder $query) => $incomingIds === []
                        ? $query->whereRaw('0 = 1')
                        : $query->whereIn('id', $incomingIds)),
            ];
        }

        return [];
    }

    /**
     * @return list<int>
     */
    private function mudurlukCandidateUserIds(): array
    {
        return User::query()
            ->where('id', '!=', 1)
            ->whereNotIn('id', ViceMayor::query()->pluck('user_id'))
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Yeni Faaliyet Raporu Oluştur')
                ->visible(fn () => ActivityReportResource::canCreate()),

            Action::make('pdfIndir')
                ->label('Tüm Faaliyetleri PDF İndir')
                ->color('success')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => auth()->id() === 1)
                ->action(function () {
                    $records = $this->getFilteredTableQuery()->get();

                    $pdf = Pdf::loadHTML($this->generateAylikFaaliyetHtml($records))
                        ->setPaper('a4', 'landscape')
                        ->setWarnings(false);

                    return response()->streamDownload(function () use ($pdf) {
                        echo $pdf->output();
                    }, 'aylik_faaliyet_raporu_'.now()->format('d_m_Y').'.pdf');
                }),
        ];
    }

    protected function generateAylikFaaliyetHtml($records)
    {
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
            <style>
                body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                th, td { padding: 6px; border: 1px solid #999; text-align: left; }
                th { background-color: #f2f2f2; font-weight: bold; }
                .title { text-align: center; font-size: 14px; font-weight: bold; margin-bottom: 10px; }
            </style>
        </head>
        <body>
            <div class="title">AYLIK FAALİYET VE PLANLAMA GENEL RAPORU</div>
            <p style="text-align:right">Rapor Tarihi: '.now()->format('d.m.Y').'</p>

            <table>
                <thead>
                    <tr>
                        <th width="15%">Müdürlük</th>
                        <th width="10%">Dönem</th>
                        <th width="75%">Faaliyet Detayları (Konu - Durum - Son Tarih)</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($records as $record) {
            $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
            $isDetaylari = '';

            if (is_array($isler)) {
                foreach ($isler as $is) {
                    $durum = match ($is['durum'] ?? '') {
                        'tamam' => 'Tamamlandı',
                        'devam' => 'Devam Ediyor',
                        'bekliyor' => 'Planlandı',
                        default => $is['durum']
                    };

                    $sonTarih = isset($is['son_tarih']) ? Carbon::parse($is['son_tarih'])->format('d.m.Y') : '-';
                    $isDetaylari .= "<div style='margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 4px;'>
                                        <b>[".e($durum).']</b> '.e($is['konu']).' 
                                        <br><b>Bitiş:</b> '.$sonTarih.'
                                     </div>';
                }
            }

            $html .= '<tr>
                        <td>'.e($record->user->name ?? 'Belirtilmemiş').'</td>
                        <td>'.$record->yil.' / '.$record->ay.'</td>
                        <td>'.($isDetaylari ?: 'Kayıtlı faaliyet yok.').'</td>
                      </tr>';
        }

        $html .= '</tbody></table></body></html>';

        return $html;
    }
}
