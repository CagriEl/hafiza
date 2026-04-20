<?php

namespace App\Filament\Resources\AylikFaaliyetResource\Pages;

use App\Filament\Resources\AylikFaaliyetResource;
use App\Models\User;
use App\Services\ActivityService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
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
                    // Tablodaki o an filtreli olan tüm kayıtları çek
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

    /**
     * PDF İçeriği için HTML Şablonu (Türkçe Karakter Destekli)
     */
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
                    $hedef = $is['hedef'] ?? '-';
                    $gerceklesen = $is['gerceklesen'] ?? '-';
                    $bekleyen = $is['bekleyen_is'] ?? '-';
                    $miktar = $is['miktar'] ?? '-';

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
                            $deger = $satir['deger'] ?? null;
                            $pairs[] = e($kalem).': '.e(filled($deger) ? (string) $deger : '-');
                        }
                        if ($pairs !== []) {
                            $kapsamKalemleri = '<br><b>Kapsam Kalemleri:</b> '.implode(' | ', $pairs);
                        }
                    }

                    $isDetaylari .= "<div style='margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 4px;'>
                                        <b>[".e($durum).']</b> '.e($baslik).'
                                        <br><b>Hedef/Gerçekleşen/Bekleyen:</b> '.e((string) $hedef).' / '.e((string) $gerceklesen).' / '.e((string) $bekleyen).'
                                        <br><b>Miktar:</b> '.e((string) $miktar).($olcuBirimi !== '' ? ' '.e($olcuBirimi) : '').'
                                        '.($kapsamIcerigi !== '' ? '<br><b>Kapsam:</b> '.e($kapsamIcerigi) : '').'
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
}
