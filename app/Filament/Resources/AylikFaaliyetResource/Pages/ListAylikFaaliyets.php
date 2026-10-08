<?php

namespace App\Filament\Resources\AylikFaaliyetResource\Pages;

use App\Filament\Resources\AylikFaaliyetResource;
use App\Models\User;
use App\Services\ActivityService;
use App\Support\AylikFaaliyetPdfHtml;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAylikFaaliyets extends ListRecords
{
    protected static string $resource = AylikFaaliyetResource::class;

    public function mount(): void
    {
        parent::mount();

        $user = auth()->user();
        if (! $user instanceof User || $user->isReportingSuperAdmin()) {
            return;
        }

        $q = AylikFaaliyetResource::getEloquentQuery();
        if ($q instanceof Builder && $q->count() === 0) {
            $bundle = app(ActivityService::class)->resolveCatalogOptionsForMudurluk(trim($user->name ?? ''));
            $payload = [
                'message' => 'Aylık faaliyet listesi boş — olası nedenler',
                'mudurluk_kullanici_adi' => $user->name,
                'erisim_kapsaminda_rapor_sayisi' => 0,
                'katalog_cozumleme' => $bundle['debug'],
                'not' => 'Aktif sekme veya tablo filtreleri listeyi boşaltıyor olabilir; "Raporlarım" sekmesi ve yıl filtresini kontrol edin. Katalog boşsa müdürlük adı eşleşmesi veya php artisan activity-catalog:sync gerekir.',
            ];
            if (method_exists($this, 'js')) {
                $this->js('console.warn('.json_encode($payload, JSON_UNESCAPED_UNICODE).')');
            }
        }
    }

    public function getTabs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            // 1. OLUŞTURMA BUTONU: Admin (ID: 1) haricindeki herkes görür
            CreateAction::make()
                ->label('Yeni Faaliyet Raporu Oluştur')
                ->visible(fn () => AylikFaaliyetResource::canCreate()),

            // 2. PDF İNDİRME BUTONU: Sadece Admin (ID: 1) görür
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
}
