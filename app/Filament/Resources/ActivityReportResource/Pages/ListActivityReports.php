<?php

namespace App\Filament\Resources\ActivityReportResource\Pages;

use App\Filament\Resources\ActivityReportResource;
use App\Models\ExtraordinarySituation;
use App\Models\User;
use App\Services\ActivityService;
use App\Support\AylikFaaliyetPdfHtml;
use App\Support\CoordinationAccess;
use Barryvdh\DomPDF\Facade\Pdf;
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
                    $records = $this->getFilteredTableQuery()
                        ->with('user')
                        ->get();

                    $pdf = Pdf::loadHTML(AylikFaaliyetPdfHtml::render($records))
                        ->setPaper('a4', 'landscape')
                        ->setWarnings(false);

                    return response()->streamDownload(function () use ($pdf) {
                        echo $pdf->output();
                    }, 'aylik_faaliyet_raporu_'.now()->format('d_m_Y').'.pdf');
                }),
        ];
    }

    private function collapseAllGroupsInitially(): void
    {
        if (! method_exists($this, 'js')) {
            return;
        }

        $this->js(<<<'JS'
            if (window.__activityReportsCollapseBooted) {
                window.__activityReportsRunCollapseBurst?.();
                return;
            }

            const collapseAllGroups = () => {
                document.querySelectorAll('.fi-ta-group-header').forEach((header) => {
                    const isOpen = header.querySelector('[aria-expanded="true"]');
                    if (isOpen) {
                        header.click();
                    }
                });
            };

            const runCollapseBurst = () => {
                let tries = 0;
                collapseAllGroups();
                const timer = setInterval(() => {
                    tries += 1;
                    collapseAllGroups();
                    if (tries >= 10) {
                        clearInterval(timer);
                    }
                }, 150);
            };

            window.__activityReportsCollapseBooted = true;
            window.__activityReportsRunCollapseBurst = runCollapseBurst;

            runCollapseBurst();

            if (window.Livewire?.hook) {
                Livewire.hook('message.processed', () => {
                    runCollapseBurst();
                });
            }
        JS);
    }
}
