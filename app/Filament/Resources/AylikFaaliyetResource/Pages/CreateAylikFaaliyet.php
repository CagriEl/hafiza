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
        $ay = \App\Support\AylikFaaliyetPeriodMerge::normalizeAy((string) ($data['ay'] ?? ''));
        $hafta = \App\Support\ReportPeriodWeeks::normalizeReportHafta($data['hafta'] ?? null)
            ?? (string) \App\Support\ReportPeriodWeeks::resolveWeekForReportPeriod($yil, (int) $ay);

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
                $label = \App\Support\ReportPeriodWeeks::periodLabelForRecord($yil, (int) $ay, $hafta) ?? $hafta;

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

        $data = AylikFaaliyetRepeaterLock::clampNonNegativeNumericFaaliyetler($data);

        return AylikFaaliyetResource::prepareFaaliyetlerForSave(
            AylikFaaliyetRepeaterLock::stripAySonuFieldsFromPlanOnlySave($data),
            null,
            auth()->user() instanceof User ? auth()->user() : null
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
