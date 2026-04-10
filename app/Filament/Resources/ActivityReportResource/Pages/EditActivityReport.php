<?php

namespace App\Filament\Resources\ActivityReportResource\Pages;

use App\Filament\Concerns\WarnsIfActivityCatalogEmpty;
use App\Filament\Resources\ActivityReportResource;
use App\Models\User;
use App\Support\AylikFaaliyetEscalation;
use Filament\Actions;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditActivityReport extends EditRecord
{
    use WarnsIfActivityCatalogEmpty;

    protected static string $resource = ActivityReportResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->getRecord()->loadMissing('user');
        $mudurluk = $this->getRecord()->user?->name ?? auth()->user()?->name ?? '';
        $this->warnIfActivityCatalogEmpty($mudurluk);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => ActivityReportResource::canDelete($this->getRecord())),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return $data;
        }

        if ((int) $this->record->user_id === (int) $user->id && $user->isMudurlukReportingAccount()) {
            return $data;
        }

        $original = is_array($this->record->faaliyetler) ? $this->record->faaliyetler : [];
        $rows = $data['faaliyetler'] ?? null;
        if (! is_array($rows)) {
            return $data;
        }

        $keysToPreserve = [
            'faaliyet_turu',
            'isbirligi_hedef_mudurluk_user_ids',
            'isbirligi_hangi_ihtiyac',
            'isbirligi_hedef_tarih',
            'isbirligi_bitis_suresi',
        ];

        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $orig = $original[$i] ?? [];
            if (! is_array($orig)) {
                continue;
            }
            foreach ($keysToPreserve as $k) {
                if (array_key_exists($k, $orig)) {
                    $data['faaliyetler'][$i][$k] = $orig[$k];
                }
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if (auth()->id() === 1) {
            return;
        }

        $faaliyetler = $this->record->faaliyetler;
        if (! is_array($faaliyetler) || ! AylikFaaliyetEscalation::recordHasEscalation($faaliyetler)) {
            return;
        }

        $admin = User::find(1);
        if (! $admin) {
            return;
        }

        $mudurlukAdi = $this->record->user?->name ?? 'Müdürlük';

        Notification::make()
            ->title('Üst yönetim: sapma veya gecikme')
            ->body("{$mudurlukAdi} — {$this->record->yil}/{$this->record->ay} raporunda bildirim gerektiren satırlar güncellendi.")
            ->warning()
            ->actions([
                NotificationAction::make('goruntule')
                    ->label('Raporu Aç')
                    ->url(ActivityReportResource::getUrl('edit', ['record' => $this->record])),
            ])
            ->sendToDatabase($admin);
    }
}
