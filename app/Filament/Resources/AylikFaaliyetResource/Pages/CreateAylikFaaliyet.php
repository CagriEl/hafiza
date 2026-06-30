<?php

namespace App\Filament\Resources\AylikFaaliyetResource\Pages;

use App\Filament\Concerns\WarnsIfActivityCatalogEmpty;
use App\Filament\Resources\ActivityReportResource;
use App\Filament\Resources\AylikFaaliyetResource;
use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\AylikFaaliyetEscalation;
use App\Support\AylikFaaliyetRepeaterLock;
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $userId = (int) (auth()->id() ?? 0);
        $yil = (int) ($data['yil'] ?? 0);
        $ay = str_pad(trim((string) ($data['ay'] ?? '')), 2, '0', STR_PAD_LEFT);

        if ($userId > 0 && $yil > 0 && $ay !== '') {
            $existing = AylikFaaliyet::query()
                ->where('user_id', $userId)
                ->where('yil', $yil)
                ->where('ay', $ay)
                ->first();

            if ($existing instanceof AylikFaaliyet) {
                $editUrl = ActivityReportResource::getUrl('edit', ['record' => $existing]);

                Notification::make()
                    ->warning()
                    ->title('Bu ay için rapor zaten var')
                    ->body("{$yil}-{$ay} dönemi için yeni rapor açılamaz. Mevcut rapora yönlendiriliyorsunuz; İş Listesi alanına ekleme yapabilirsiniz.")
                    ->actions([
                        Action::make('raporaGit')
                            ->label('Mevcut Raporu Aç')
                            ->url($editUrl),
                    ])
                    ->send();

                $this->redirect($editUrl);
                $this->halt();
            }
        }

        $data = AylikFaaliyetRepeaterLock::clampNonNegativeNumericFaaliyetler($data);

        return AylikFaaliyetResource::applyAutoHaftaToFaaliyetler(
            AylikFaaliyetRepeaterLock::stripAySonuFieldsFromPlanOnlySave($data)
        );
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
                        ->url(ActivityReportResource::getUrl('edit', ['record' => $this->record])),
                ])
                ->sendToDatabase($admin);
        }
    }
}
