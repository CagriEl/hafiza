<?php

namespace App\Filament\Resources\ActivityReportResource\Pages;

use App\Filament\Concerns\WarnsIfActivityCatalogEmpty;
use App\Filament\Resources\ActivityReportResource;
use App\Filament\Resources\AylikFaaliyetResource;
use App\Models\User;
use App\Support\AylikFaaliyetEscalation;
use App\Support\AylikFaaliyetRepeaterLock;
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
        if (ActivityReportResource::isReportPeriodClosed($this->getRecord())) {
            $this->redirect(
                ActivityReportResource::getUrl('view', [
                    'record' => $this->getRecord(),
                    'tab' => (string) request()->query('tab', 'all'),
                ])
            );

            return;
        }

        $mudurluk = $this->getRecord()->user?->name ?? auth()->user()?->name ?? '';
        $this->warnIfActivityCatalogEmpty($mudurluk);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->getRecord()->loadMissing('user');
        $data = AylikFaaliyetRepeaterLock::stampOrigIndexes($data);
        $data = AylikFaaliyetRepeaterLock::migrateLegacyKapsamVerileriKeys($data);
        $data = AylikFaaliyetRepeaterLock::hydrateAySonuPerformansKilitFromLegacy($data);
        $data = AylikFaaliyetRepeaterLock::clampNonNegativeNumericFaaliyetler($data);
        $data = AylikFaaliyetRepeaterLock::syncRowAySonuTotalsFromKapsamVerileri($data);

        return AylikFaaliyetResource::applyAutoHaftaToFaaliyetler(
            AylikFaaliyetResource::syncFaaliyetlerWithCurrentCatalog(
                $data,
                $this->getRecord()->user?->name
            )
        );
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
        if ($user instanceof User) {
            $data = AylikFaaliyetRepeaterLock::stripAySonuFieldsFromUnpersistedMudurlukRows($this->record, $user, $data);
        }

        $data = AylikFaaliyetRepeaterLock::clampNonNegativeNumericFaaliyetler($data);
        $data = AylikFaaliyetRepeaterLock::syncRowAySonuTotalsFromKapsamVerileri($data);

        if ($user instanceof User) {
            $data = AylikFaaliyetRepeaterLock::enforceMudurlukLocks($this->record, $user, $data);
            $data = AylikFaaliyetRepeaterLock::applyAySonuPerformansKilitAfterMudurlukSave($this->record, $user, $data);
        }

        if (! $user instanceof User) {
            return AylikFaaliyetResource::applyAutoHaftaToFaaliyetler(
                AylikFaaliyetRepeaterLock::stripInternalKeysFromFaaliyetler($data)
            );
        }

        if ($user->isMudurlukReportingAccount()
            && AylikFaaliyetRepeaterLock::actorOwnsAylikFaaliyetRecord($this->record, $user)) {
            return AylikFaaliyetResource::applyAutoHaftaToFaaliyetler(
                AylikFaaliyetRepeaterLock::stripInternalKeysFromFaaliyetler($data)
            );
        }

        $original = is_array($this->record->faaliyetler) ? $this->record->faaliyetler : [];
        $rows = $data['faaliyetler'] ?? null;
        if (! is_array($rows)) {
            return $data;
        }

        $keysToPreserve = [
            'faaliyet_turu',
            'isbirligi_hedef_mudurluk_user_ids',
            'isbirligi_talepleri',
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

        return AylikFaaliyetResource::applyAutoHaftaToFaaliyetler(
            AylikFaaliyetRepeaterLock::stripInternalKeysFromFaaliyetler($data)
        );
    }

    protected function afterSave(): void
    {
        $this->sendCoordinationPartnerNotifications();

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

    private function sendCoordinationPartnerNotifications(): void
    {
        $faaliyetler = $this->record->faaliyetler;
        if (! is_array($faaliyetler)) {
            return;
        }

        $validMudurlukIds = User::queryMudurlukReportingAccounts()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if ($validMudurlukIds === []) {
            return;
        }

        $messagesByUserId = [];
        foreach ($faaliyetler as $row) {
            if (! is_array($row) || ($row['faaliyet_turu'] ?? null) !== 'Koordinasyon') {
                continue;
            }

            $talepler = $row['isbirligi_talepleri'] ?? [];
            if (! is_array($talepler)) {
                continue;
            }

            $faaliyetKod = trim((string) ($row['faaliyet_kodu'] ?? 'Koordinasyon'));
            foreach ($talepler as $talep) {
                if (! is_array($talep)) {
                    continue;
                }
                $uid = (int) ($talep['mudurluk_user_id'] ?? 0);
                if ($uid <= 0 || $uid === (int) auth()->id()) {
                    continue;
                }
                if (! in_array($uid, $validMudurlukIds, true)) {
                    continue;
                }

                $ihtiyac = trim((string) ($talep['ihtiyac'] ?? ''));
                $hedefTarih = trim((string) ($talep['hedef_tarih'] ?? ''));
                $bitisSuresi = trim((string) ($talep['bitis_suresi'] ?? ''));

                $parts = [];
                if ($ihtiyac !== '') {
                    $parts[] = "ihtiyaç: {$ihtiyac}";
                }
                if ($hedefTarih !== '') {
                    $parts[] = "hedef tarih: {$hedefTarih}";
                }
                if ($bitisSuresi !== '') {
                    $parts[] = "bitiş süresi: {$bitisSuresi}";
                }
                if ($parts === []) {
                    continue;
                }

                $messagesByUserId[$uid] ??= [];
                $messagesByUserId[$uid][] = "{$faaliyetKod} -> ".implode(', ', $parts);
            }
        }

        if ($messagesByUserId === []) {
            return;
        }

        $sender = $this->record->user?->name ?? auth()->user()?->name ?? 'Müdürlük';
        foreach ($messagesByUserId as $uid => $lines) {
            $target = User::find($uid);
            if (! $target) {
                continue;
            }

            Notification::make()
                ->title('Koordinasyon talebi güncellendi')
                ->body($sender.' raporunda size atanan talepler: '.implode(' | ', array_unique($lines)))
                ->warning()
                ->actions([
                    NotificationAction::make('goruntule')
                        ->label('Raporu Aç')
                        ->url(ActivityReportResource::getUrl('edit', ['record' => $this->record])),
                ])
                ->sendToDatabase($target);
        }
    }
}
