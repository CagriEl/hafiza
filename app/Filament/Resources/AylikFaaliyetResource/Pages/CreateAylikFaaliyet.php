<?php

namespace App\Filament\Resources\AylikFaaliyetResource\Pages;

use App\Filament\Resources\AylikFaaliyetResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification; // Bildirim sınıfı
use Filament\Notifications\Actions\Action; // Bildirim içi buton
use App\Models\User; // Kullanıcı modeli

class CreateAylikFaaliyet extends CreateRecord
{
    protected static string $resource = AylikFaaliyetResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    // --- BU FONKSİYONU EKLEYİN ---
    protected function afterCreate(): void
    {
        // 1. Raporu giren müdürlüğün adını ve ayı alalım
        $mudurlukAdi = auth()->user()->name;
        $ay = $this->record->ay;
        $yil = $this->record->yil;

        // 2. Admin kullanıcısını bul (Genelde ID'si 1'dir)
        $admin = User::find(1);

        if ($admin) {
            Notification::make()
                ->title('Yeni Faaliyet Raporu Girildi')
                ->body("$mudurlukAdi, $yil - $ay ayı faaliyet planını sisteme yükledi.")
                ->success() // Yeşil renkli başarı ikonu
                ->actions([
                    // Bildirimin içinde "Görüntüle" butonu olsun
                    Action::make('goruntule')
                        ->label('Raporu Gör')
                        ->url(AylikFaaliyetResource::getUrl('edit', ['record' => $this->record])),
                ])
                ->sendToDatabase($admin); // Sadece Admin'in paneline düşür
        }
    }
}