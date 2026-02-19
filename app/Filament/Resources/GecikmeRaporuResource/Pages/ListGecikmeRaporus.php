<?php

namespace App\Filament\Resources\GecikmeRaporuResource\Pages;

use App\Filament\Resources\GecikmeRaporuResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Barryvdh\DomPDF\Facade\Pdf;

class ListGecikmeRaporus extends ListRecords
{
    protected static string $resource = GecikmeRaporuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdfIndir')
                ->label('PDF Raporu Al')
                ->color('success')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function () {
                    // Filtrelenmiş veriyi çek
                    $data = $this->getFilteredTableQuery()->get();

                    // PDF oluştur
                    $pdf = Pdf::loadHTML($this->generatePdfHtml($data))
                        ->setPaper('a4', 'portrait')
                        ->setWarnings(false);
                    
                    return response()->streamDownload(function () use ($pdf) {
                        echo $pdf->output();
                    }, 'gecikme_raporu_' . now()->format('d_m_Y') . '.pdf');
                }),
        ];
    }

    protected function generatePdfHtml($data)
    {
        // Türkçe karakterler için 'DejaVu Sans' fontu en güvenli yöntemdir.
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
            <style>
                body { 
                    font-family: "DejaVu Sans", sans-serif; 
                    font-size: 11px; 
                    color: #333; 
                }
                table { 
                    width: 100%; 
                    border-collapse: collapse; 
                    margin-top: 20px; 
                }
                th, td { 
                    padding: 8px; 
                    border: 1px solid #ccc; 
                    text-align: left; 
                }
                th { 
                    background-color: #f2f2f2; 
                    font-weight: bold; 
                }
                .header { 
                    text-align: center; 
                    margin-bottom: 30px; 
                }
                .footer { 
                    margin-top: 20px; 
                    text-align: right; 
                    font-style: italic; 
                    font-size: 9px; 
                }
                .gecikme-item { 
                    margin-bottom: 5px; 
                    padding-bottom: 5px; 
                    border-bottom: 1px dashed #eee; 
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>MÜDÜRLÜK BAZLI GECİKME RAPORU</h2>
                <p>Rapor Tarihi: ' . now()->format('d.m.Y H:i') . '</p>
            </div>

            <table>
                <thead>
                    <tr>
                        <th width="25%">Müdürlük</th>
                        <th width="15%">Dönem</th>
                        <th width="60%">Geciken İşler ve Gerekçeler</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($data as $record) {
            $isler = is_string($record->faaliyetler) ? json_decode($record->faaliyetler, true) : $record->faaliyetler;
            $gecikenlerHtml = "";
            
            if (is_array($isler)) {
                foreach ($isler as $is) {
                    if (isset($is['son_tarih']) && \Carbon\Carbon::parse($is['son_tarih'])->isPast() && ($is['durum'] ?? '') !== 'tamam') {
                        $gerekce = !empty($is['gecikme_gerekcesi']) ? $is['gecikme_gerekcesi'] : 'Gerekçe belirtilmemiş!';
                        $gecikenlerHtml .= "<div class='gecikme-item'>
                                                <b>İş:</b> " . e($is['konu']) . "<br>
                                                <b>Gerekçe:</b> " . e($gerekce) . "
                                            </div>";
                    }
                }
            }
            
            if (!empty($gecikenlerHtml)) {
                $html .= "<tr>
                            <td>" . e($record->user->name) . "</td>
                            <td>" . $record->yil . " / " . $record->ay . "</td>
                            <td>{$gecikenlerHtml}</td>
                          </tr>";
            }
        }

        $html .= '
                </tbody>
            </table>
            <div class="footer">
                Bu rapor sistem üzerinden otomatik olarak oluşturulmuştur.
            </div>
        </body>
        </html>';

        return $html;
    }

    public function getTitle(): string 
    {
        return 'Müdürlük Bazlı Gecikme Raporu';
    }
}