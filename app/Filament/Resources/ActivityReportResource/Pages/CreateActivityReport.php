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
        $hafta = ReportPeriodWeeks::MONTHLY_VALUE;

        if ($userId > 0 && $yil > 0 && $ay !== ''
            && AylikFaaliyet::existsForUserPeriod($userId, $yil, $ay)) {
            $existing = AylikFaaliyet::query()
                ->where('user_id', $userId)
                ->where('yil', $yil)
                ->whereIn('ay', AylikFaaliyet::ayQueryVariants($ay))
                ->orderBy('id')
                ->first();

            if ($existing instanceof AylikFaaliyet) {
                $editUrl = ActivityReportResource::getUrl('edit', ['record' => $existing]);
                $label = ReportPeriodWeeks::monthPeriodLabel($yil, (int) $ay);

                Notification::make()
                    ->warning()
                    ->title('Bu dönem için rapor zaten var')
                    ->body("{$label} için yeni rapor açılamaz. Mevcut rapora yönlendiriliyorsunuz.")
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

        $data = AylikFaaliyetResource::syncFaaliyetlerWithCurrentCatalog(
            $data,
            auth()->user()?->name
        );

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
        $donemLabel = ReportPeriodWeeks::monthPeriodLabel($yil, (int) preg_replace('/\D/', '', (string) $ay));

        $admin = User::find(1);

        if ($admin) {
            $faaliyetler = $this->record->faaliyetler;
            $escalation = is_array($faaliyetler) && AylikFaaliyetEscalation::recordHasEscalation($faaliyetler);

            Notification::make()
                ->title('Yeni Faaliyet Raporu Girildi')
                ->body(
                    $escalation
                        ? "$mudurlukAdi, $donemLabel raporunda üst yönetim bilgilendirmesi gereken sapma veya gecikme satırları var."
                        : "$mudurlukAdi, $donemLabel faaliyet raporunu sisteme yükledi."
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
