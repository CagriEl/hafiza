<?php

namespace App\Filament\Resources\ActivityReportResource\Pages;

use App\Filament\Resources\ActivityReportResource;
use App\Models\User;
use App\Support\AylikFaaliyetPdfHtml;
use App\Support\CoordinationAccess;
use Barryvdh\DomPDF\Facade\Pdf;
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
        $this->collapseAllGroupsInitially();
        $legacy = ['my', 'own'];
        if (in_array(session('activity_report_active_tab'), $legacy, true)) {
            session(['activity_report_active_tab' => 'all']);
        }
        session(['activity_report_active_tab' => $this->activeTab ?? 'all']);
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

            Action::make('pdfIndir')
                ->label('Tüm Faaliyetleri PDF İndir')
                ->color('success')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => auth()->id() === 1)
                ->action(function () {
                    $records = $this->getFilteredTableQuery()
                        ->with('user')
                        ->get();

                    $html = AylikFaaliyetPdfHtml::buildMerged($records);
                    $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
                    $filename = 'tum_faaliyet_raporlari_'.now()->format('Y-m-d_His').'.pdf';

                    return response()->streamDownload(function () use ($pdf) {
                        echo $pdf->output();
                    }, $filename);
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
                return;
            }
            window.__activityReportsCollapseBooted = true;

            const collapseAllGroups = () => {
                document.querySelectorAll('.fi-ta-group-header').forEach((header) => {
                    const isOpen = header.querySelector('[aria-expanded="true"]');
                    if (isOpen) {
                        header.click();
                    }
                });
            };

            collapseAllGroups();
            setTimeout(collapseAllGroups, 200);
        JS);
    }
}
