<?php

namespace App\Filament\Resources\AylikFaaliyetResource\Pages;

use App\Filament\Concerns\WarnsIfActivityCatalogEmpty;
use App\Filament\Resources\AylikFaaliyetResource;
use App\Models\User;
use App\Support\AylikFaaliyetEscalation;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateAylikFaaliyet extends CreateRecord
{
    use WarnsIfActivityCatalogEmpty;

    protected static string $resource = AylikFaaliyetResource::class;

    public function mount(): void
    {
        parent::mount();
        $this->warnIfActivityCatalogEmpty(auth()->user()?->name ?? '');
    }

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
            $faaliyetler = $this->record->faaliyetler;
            $escalation = is_array($faaliyetler) && AylikFaaliyetEscalation::recordHasEscalation($faaliyetler);

            Notification::make()
                ->title('Yeni Faaliyet Raporu Girildi')
                ->body(
                    $escalation
                        ? "$mudurlukAdi, $yil - $ay ayı raporunda üst yönetim bilgilendirmesi gereken sapma veya gecikme satırları var."
                        : "$mudurlukAdi, $yil - $ay ayı faaliyet planını sisteme yükledi."
                )
                ->success()
                ->actions([
                    Action::make('goruntule')
                        ->label('Raporu Gör')
                        ->url(AylikFaaliyetResource::getUrl('edit', ['record' => $this->record])),
                ])
                ->sendToDatabase($admin);
        }
    }
}
