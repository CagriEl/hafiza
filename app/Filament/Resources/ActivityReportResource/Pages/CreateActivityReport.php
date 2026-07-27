<?php

namespace App\Filament\Resources\ActivityReportResource\Pages;

use App\Filament\Concerns\WarnsIfActivityCatalogEmpty;
use App\Filament\Resources\ActivityReportResource;
use App\Filament\Resources\AylikFaaliyetResource;
use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\AylikFaaliyetEscalation;
use App\Support\AylikFaaliyetPeriodMerge;
use App\Support\AylikFaaliyetRepeaterLock;
use App\Support\ReportPeriodWeeks;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateActivityReport extends CreateRecord
{
    use WarnsIfActivityCatalogEmpty;

    protected static string $resource = ActivityReportResource::class;

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
        $ay = AylikFaaliyetPeriodMerge::normalizeAy((string) ($data['ay'] ?? ''));
        $hafta = ReportPeriodWeeks::normalizeReportHafta($data['hafta'] ?? null)
            ?? (string) ReportPeriodWeeks::resolveWeekForReportPeriod($yil, (int) $ay);

        if ($userId > 0 && $yil > 0 && $ay !== ''
            && AylikFaaliyet::existsForUserPeriodWeek($userId, $yil, $ay, $hafta)) {
            $existing = AylikFaaliyet::query()
                ->where('user_id', $userId)
                ->where('yil', $yil)
                ->whereIn('ay', AylikFaaliyet::ayQueryVariants($ay))
                ->where('hafta', $hafta)
                ->orderBy('id')
                ->first();

            if ($existing instanceof AylikFaaliyet) {
                $editUrl = ActivityReportResource::getUrl('edit', ['record' => $existing]);
                $label = ReportPeriodWeeks::periodLabelForRecord($yil, (int) $ay, $hafta) ?? $hafta;

                Notification::make()
                    ->warning()
                    ->title('Bu hafta için rapor zaten var')
                    ->body("{$yil}-{$ay} · {$label} için yeni rapor açılamaz. Mevcut hafta raporuna yönlendiriliyorsunuz.")
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

        $data['ay'] = $ay;
        $data['hafta'] = $hafta;

        return AylikFaaliyetResource::prepareFaaliyetlerForSave(
            AylikFaaliyetRepeaterLock::stripAySonuFieldsFromPlanOnlySave($data),
            null,
            auth()->user() instanceof User ? auth()->user() : null
        );
    }

    protected function afterCreate(): void
    {
        $mudurlukAdi = auth()->user()->name;
        $ay = $this->record->ay;
        $yil = $this->record->yil;
        $haftaLabel = $this->record->raporHaftasiLabel();

        $admin = User::find(1);

        if ($admin) {
            $faaliyetler = $this->record->faaliyetler;
            $escalation = is_array($faaliyetler) && AylikFaaliyetEscalation::recordHasEscalation($faaliyetler);

            Notification::make()
                ->title('Yeni Faaliyet Raporu Girildi')
                ->body(
                    $escalation
                        ? "$mudurlukAdi, $yil - $ay ($haftaLabel) raporunda üst yönetim bilgilendirmesi gereken sapma veya gecikme satırları var."
                        : "$mudurlukAdi, $yil - $ay ($haftaLabel) faaliyet raporunu sisteme yükledi."
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
