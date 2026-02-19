<?php

namespace App\Filament\Resources\AylikFaaliyetResource\Pages;

use App\Filament\Resources\AylikFaaliyetResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class ListAylikFaaliyets extends ListRecords
{
    protected static string $resource = AylikFaaliyetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 1. OLUŞTURMA BUTONU: Admin (ID: 1) haricindeki herkes görür
            CreateAction::make()
                ->label('Yeni Faaliyet Raporu Oluştur')
                ->visible(fn () => auth()->id() !== 1),

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
                    }, 'aylik_faaliyet_raporu_' . now()->format('d_m_Y') . '.pdf');
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
            <p style="text-align:right">Rapor Tarihi: ' . now()->format('d.m.Y') . '</p>

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
            $isDetaylari = "";

            if (is_array($isler)) {
                foreach ($isler as $is) {
                    $durum = match($is['durum'] ?? '') {
                        'tamam' => 'Tamamlandı',
                        'devam' => 'Devam Ediyor',
                        'bekliyor' => 'Planlandı',
                        default => $is['durum']
                    };

                    $sonTarih = isset($is['son_tarih']) ? Carbon::parse($is['son_tarih'])->format('d.m.Y') : '-';
                    $isDetaylari .= "<div style='margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 4px;'>
                                        <b>[" . e($durum) . "]</b> " . e($is['konu']) . " 
                                        <br><b>Bitiş:</b> " . $sonTarih . "
                                     </div>";
                }
            }

            $html .= "<tr>
                        <td>" . e($record->user->name ?? 'Belirtilmemiş') . "</td>
                        <td>" . $record->yil . " / " . $record->ay . "</td>
                        <td>" . ($isDetaylari ?: 'Kayıtlı faaliyet yok.') . "</td>
                      </tr>";
        }

        $html .= '</tbody></table></body></html>';

        return $html;
    }
}