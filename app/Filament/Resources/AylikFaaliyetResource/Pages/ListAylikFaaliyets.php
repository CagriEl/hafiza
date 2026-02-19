<?php

namespace App\Filament\Resources\AylikFaaliyetResource\Pages;

use App\Filament\Resources\AylikFaaliyetResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class ListAylikFaaliyets extends ListRecords
{
    protected static string $resource = AylikFaaliyetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Sadece Admin (ID: 1) için PDF butonu
            Action::make('pdfIndir')
                ->label('Tüm Faaliyetleri PDF İndir')
                ->color('success')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => auth()->id() === 1) // Sadece admin görür
                ->action(function () {
                    // Tablodaki filtreli verileri çek
                    $records = $this->getFilteredTableQuery()->get();

                    $pdf = Pdf::loadHTML($this->generateAylikFaaliyetHtml($records))
                        ->setPaper('a4', 'landscape') // Yan sayfa daha çok iş alır
                        ->setWarnings(false);
                    
                    return response()->streamDownload(function () use ($pdf) {
                        echo $pdf->output();
                    }, 'aylik_faaliyet_raporu_' . now()->format('d_m_Y') . '.pdf');
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
                .title { text-align: center; font-size: 14px; font-bold; margin-bottom: 10px; }
                .badge { padding: 2px 4px; border-radius: 4px; font-size: 8px; }
                .status-tamam { background: #dcfce7; color: #166534; }
                .status-devam { background: #fef9c3; color: #854d0e; }
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
                        <th width="75%">Faaliyet Detayları (Konu - Durum - Son Tarih - Gerekçe)</th>
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
                    $gerekce = !empty($is['gecikme_gerekcesi']) ? "<br><b>Gecikme Nedeni:</b> " . e($is['gecikme_gerekcesi']) : "";

                    $isDetaylari .= "<div style='margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 4px;'>
                                        <b>[" . e($durum) . "]</b> " . e($is['konu']) . " 
                                        <br><b>Bitiş:</b> " . $sonTarih . $gerekce . "
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