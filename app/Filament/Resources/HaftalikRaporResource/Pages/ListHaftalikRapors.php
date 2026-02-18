<?php

namespace App\Filament\Resources\HaftalikRaporResource\Pages;

use App\Filament\Resources\HaftalikRaporResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\Action; // Özel Buton için gerekli
use App\Models\HaftalikRapor;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Str; // <-- EKSİK OLAN KISIM BURASIYDI

class ListHaftalikRapors extends ListRecords
{
    protected static string $resource = HaftalikRaporResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Yeni Rapor Oluştur'),

            // --- AYLIK BÜLTEN OLUŞTURMA BUTONU ---
            Action::make('aylik_bulten')
                ->label('Aylık Bülten İndir')
                ->icon('heroicon-o-printer')
                ->color('warning') // Sarı Buton
                ->form([
                    \Filament\Forms\Components\Select::make('yil')
                        ->label('Yıl')
                        ->options([
                            2025 => '2025',
                            2026 => '2026',
                            2027 => '2027',
                        ])
                        ->default(now()->year)
                        ->required(),
                    
                    \Filament\Forms\Components\Select::make('ay')
                        ->label('Ay')
                        ->options([
                            1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
                            5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
                            9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık',
                        ])
                        ->default(now()->month)
                        ->required(),
                ])
                ->action(function (array $data) {
                    
                    // 1. O ayın raporlarını çek ve Müdürlüğe göre grupla
                    // whereYear ve whereMonth kullanarak veritabanından süzüyoruz
                    $raporlar = HaftalikRapor::with('user')
                        ->whereYear('baslangic_tarihi', $data['yil'])
                        ->whereMonth('baslangic_tarihi', $data['ay'])
                        ->orderBy('baslangic_tarihi') // Tarihe göre sırala
                        ->get()
                        ->groupBy('user_id'); // Her müdürlüğü bir grup yap

                    // Eğer hiç kayıt yoksa uyarı ver
                    if ($raporlar->isEmpty()) {
                        \Filament\Notifications\Notification::make()
                            ->title('Kayıt Bulunamadı')
                            ->body('Seçilen ayda kayıtlı rapor bulunamadı.')
                            ->danger()
                            ->send();
                        return;
                    }

                    // Ay ismini al (Dosya adı ve başlık için)
                    $ayIsmi = Carbon::createFromDate($data['yil'], $data['ay'], 1)->translatedFormat('F Y');
                    
                    // Dosya adını oluştur (Str sınıfı burada kullanılıyor)
                    $dosyaAdi = Str::slug("{$ayIsmi}-Faaliyet-Bulteni") . ".pdf";

                    // PDF'i oluştur ve indir
                    return response()->streamDownload(function () use ($raporlar, $ayIsmi) {
                        echo Pdf::loadView('pdf.genel-bulten', [
                            'gruplanmisRaporlar' => $raporlar,
                            'donem' => $ayIsmi
                        ])->output();
                    }, $dosyaAdi);
                })
        ];
    }
}