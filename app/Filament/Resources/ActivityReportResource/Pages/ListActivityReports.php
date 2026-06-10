<?php

namespace App\Filament\Resources\ActivityReportResource\Pages;

use App\Filament\Resources\ActivityReportResource;
use App\Models\ExtraordinarySituation;
use App\Models\User;
use App\Services\ActivityService;
use App\Support\CoordinationAccess;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListActivityReports extends ListRecords
{
    protected static string $resource = ActivityReportResource::class;

    public function mount(): void
    {
        parent::mount();
        $this->collapseAllGroupsInitially();
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
        return User::queryMudurlukReportingAccounts()
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

            Action::make('reportExtraordinarySituation')
                ->label('Olağanüstü Durum Bildir')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()?->isMudurlukReportingAccount() === true)
                ->form([
                    Select::make('yil')
                        ->label('Yıl')
                        ->options([
                            now()->year - 1 => (string) (now()->year - 1),
                            now()->year => (string) now()->year,
                            now()->year + 1 => (string) (now()->year + 1),
                        ])
                        ->default(now()->year)
                        ->required(),
                    Select::make('ay')
                        ->label('Ay')
                        ->options([
                            '01' => 'Ocak',
                            '02' => 'Şubat',
                            '03' => 'Mart',
                            '04' => 'Nisan',
                            '05' => 'Mayıs',
                            '06' => 'Haziran',
                            '07' => 'Temmuz',
                            '08' => 'Ağustos',
                            '09' => 'Eylül',
                            '10' => 'Ekim',
                            '11' => 'Kasım',
                            '12' => 'Aralık',
                        ])
                        ->default(now()->format('m'))
                        ->required(),
                    Textarea::make('message')
                        ->label('Olağanüstü Durum Açıklaması')
                        ->rows(4)
                        ->maxLength(2000)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $currentUserId = (int) (auth()->id() ?? 0);
                    if ($currentUserId <= 0) {
                        return;
                    }

                    ExtraordinarySituation::query()->create([
                        'reporter_user_id' => $currentUserId,
                        'target_user_id' => $currentUserId,
                        'yil' => (int) ($data['yil'] ?? now()->year),
                        'ay' => str_pad((string) ($data['ay'] ?? now()->format('m')), 2, '0', STR_PAD_LEFT),
                        'message' => trim((string) ($data['message'] ?? '')),
                    ]);

                    Notification::make()
                        ->title('Olağanüstü durum kaydedildi')
                        ->body('Bildirim yalnızca kendi müdürlüğünüz için kaydedildi.')
                        ->success()
                        ->send();
                }),

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
                    $baslik = trim((string) ($is['konu'] ?? ''));
                    if ($baslik === '') {
                        $baslik = trim((string) ($is['faaliyet_kodu'] ?? 'Faaliyet'));
                    }

                    $kapsamIcerigi = trim((string) ($is['kapsam_icerigi'] ?? ''));
                    $olcuBirimi = trim((string) ($is['olcu_birimi'] ?? ''));
                    $gerceklesen = $is['gerceklesen'] ?? '-';
                    $bekleyen = $is['bekleyen_is'] ?? '-';
                    $extraordinary = ExtraordinarySituation::query()
                        ->where('target_user_id', (int) ($record->user_id ?? 0))
                        ->where('yil', (int) ($record->yil ?? 0))
                        ->where('ay', str_pad((string) ($record->ay ?? ''), 2, '0', STR_PAD_LEFT))
                        ->latest('id')
                        ->first();
                    $extraordinaryText = null;
                    if ($extraordinary instanceof ExtraordinarySituation) {
                        $reporter = User::find((int) ($extraordinary->reporter_user_id ?? 0));
                        $reporterName = $reporter?->name ? trim((string) $reporter->name) : 'Sistem';
                        $message = trim((string) ($extraordinary->message ?? ''));
                        $extraordinaryText = $message === '' ? $reporterName : $reporterName.': '.$message;
                    }

                    $kapsamKalemleri = '';
                    $satirlar = $is['kapsam_verileri'] ?? [];
                    if (is_array($satirlar) && $satirlar !== []) {
                        $pairs = [];
                        foreach ($satirlar as $satir) {
                            if (! is_array($satir)) {
                                continue;
                            }
                            $kalem = trim((string) ($satir['kalem'] ?? ''));
                            if ($kalem === '') {
                                continue;
                            }
                            $ong = $satir['ongorulen'] ?? $satir['deger'] ?? null;
                            $ger = $satir['gerceklesen'] ?? null;
                            $acik = $satir['acikta_kalan'] ?? null;
                            $pairs[] = e($kalem).': yapılacak '.e(filled($ong) ? (string) $ong : '-').' / yapılan '.e(filled($ger) ? (string) $ger : '-').' / bekleyen '.e(filled($acik) ? (string) $acik : '-');
                        }
                        if ($pairs !== []) {
                            $kapsamKalemleri = '<br><b>Kapsam Kalemleri:</b> '.implode(' | ', $pairs);
                        }
                    }

                    $isDetaylari .= "<div style='margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 4px;'>
                                        <b>[".e($durum).']</b> '.e($baslik).'
                                        <br><b>Ay sonu gerçekleşen / Ay sonu bekleyen:</b> '.e((string) $gerceklesen).' / '.e((string) $bekleyen).'
                                        '.($olcuBirimi !== '' ? '<br><b>Ölçü birimi:</b> '.e($olcuBirimi) : '').'
                                        '.($kapsamIcerigi !== '' ? '<br><b>Kapsam:</b> '.e($kapsamIcerigi) : '').'
                                        '.(filled($extraordinaryText) ? '<br><b>Olağanüstü durum:</b> '.e((string) $extraordinaryText) : '').'
                                        '.$kapsamKalemleri.'
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

    private function collapseAllGroupsInitially(): void
    {
        if (! method_exists($this, 'js')) {
            return;
        }

        $this->js(<<<'JS'
            if (window.__activityReportsCollapseBooted) {
                window.__activityReportsCollapseNow?.();
                return;
            }

            const extractGroupTitles = (tableRoot) => {
                return Array.from(
                    tableRoot.querySelectorAll('.fi-ta-group-header[x-on\\:click*="toggleCollapseGroup"]')
                )
                    .map((header) => {
                        const expr = header.getAttribute('x-on:click') ?? '';
                        const match = expr.match(/toggleCollapseGroup\((.*)\)/);
                        if (!match || !match[1]) {
                            return '';
                        }

                        let raw = match[1].trim();
                        if ((raw.startsWith('"') && raw.endsWith('"')) || (raw.startsWith("'") && raw.endsWith("'"))) {
                            raw = raw.slice(1, -1);
                        }

                        return raw
                            .replace(/\\"/g, '"')
                            .replace(/\\'/g, "'");
                    })
                    .filter((title) => title.length > 0);
            };

            const collapseAllGroups = () => {
                document.querySelectorAll('.fi-ta[x-data="table"]').forEach((tableRoot) => {
                    if (!tableRoot.__x?.$data) {
                        return;
                    }

                    const titles = extractGroupTitles(tableRoot);
                    if (titles.length === 0) {
                        return;
                    }

                    tableRoot.__x.$data.collapsedGroups = [...new Set(titles)];

                    // Keep as hard fallback in case local state wasn't reflected yet.
                    tableRoot.querySelectorAll('.fi-ta-group-header').forEach((header) => {
                        const button = header.querySelector('[aria-expanded="true"]');
                        if (button) {
                            header.click();
                        }
                    });
                });
            };

            window.__activityReportsCollapseBooted = true;
            window.__activityReportsCollapseNow = collapseAllGroups;

            collapseAllGroups();
            setTimeout(collapseAllGroups, 150);
            setTimeout(collapseAllGroups, 500);
            setTimeout(collapseAllGroups, 1200);

            if (window.Livewire?.hook) {
                Livewire.hook('message.processed', () => {
                    collapseAllGroups();
                });
            }
        JS);
    }
}
