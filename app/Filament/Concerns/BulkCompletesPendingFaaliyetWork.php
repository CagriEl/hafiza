<?php

namespace App\Filament\Concerns;

use App\Filament\Resources\AylikFaaliyetResource;
use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\AylikFaaliyetWeeklyCarryover;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;

trait BulkCompletesPendingFaaliyetWork
{
    protected function bulkCompletePendingWorkAction(): Actions\Action
    {
        return Actions\Action::make('bulkCompletePendingWork')
            ->label('Açık işleri tamamla')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (): bool => $this->canBulkCompletePendingWork())
            ->modalHeading('Açık işleri tamamla')
            ->modalDescription(function (): string {
                $count = $this->countRecordPendingKapsamItems();

                return "Rapordaki {$count} açık kapsam kalemi, kalan miktar kadar tamamlanmış olarak kaydedilecek. Öngörülen değerler değiştirilmez.";
            })
            ->form([
                Forms\Components\Textarea::make('aciklama')
                    ->label('Açıklama')
                    ->placeholder('Toplu tamamlama gerekçesini yazınız (ör. dönem sonu gerçekleşme girişi)')
                    ->rows(3)
                    ->required()
                    ->maxLength(2000),
            ])
            ->requiresConfirmation()
            ->modalSubmitActionLabel('Tamamla ve kaydet')
            ->action(function (array $data): void {
                $record = $this->getRecord();
                if (! $record instanceof AylikFaaliyet) {
                    return;
                }

                $aciklama = trim((string) ($data['aciklama'] ?? ''));
                if ($aciklama === '') {
                    return;
                }

                $payload = [
                    'yil' => $record->yil,
                    'ay' => $record->ay,
                    'faaliyetler' => is_array($record->faaliyetler) ? $record->faaliyetler : [],
                ];

                $before = AylikFaaliyetWeeklyCarryover::countPendingKapsamItems($payload);
                $payload = AylikFaaliyetWeeklyCarryover::applyBulkPendingCompletion($payload, $aciklama);

                $user = auth()->user();
                $prepared = AylikFaaliyetResource::prepareFaaliyetlerForSave(
                    $payload,
                    $record,
                    $user instanceof User ? $user : null
                );

                $record->update([
                    'faaliyetler' => $prepared['faaliyetler'] ?? [],
                ]);

                $record->refresh();
                // Tam fillForm katalog senkronunu yeniden çalıştırıp formu şişiriyor; yalnızca veri alanını yenile.
                if (method_exists($this, 'refreshFormData')) {
                    $this->refreshFormData(['faaliyetler']);
                } else {
                    $this->fillForm();
                }

                $after = AylikFaaliyetWeeklyCarryover::countPendingKapsamItems([
                    'yil' => $record->yil,
                    'ay' => $record->ay,
                    'faaliyetler' => is_array($record->faaliyetler) ? $record->faaliyetler : [],
                ]);

                Notification::make()
                    ->title('Açık işler tamamlandı')
                    ->body($before - $after > 0
                        ? ($before - $after).' kalem tamamlanmış olarak kaydedildi.'
                        : 'Kayıt güncellendi.')
                    ->success()
                    ->send();
            });
    }

    protected function canBulkCompletePendingWork(): bool
    {
        $record = $this->getRecord();
        if (! $record instanceof AylikFaaliyet) {
            return false;
        }

        $resourceClass = static::getResource();
        if (! is_subclass_of($resourceClass, Resource::class) || ! $resourceClass::canEdit($record)) {
            return false;
        }

        return $this->countRecordPendingKapsamItems() > 0;
    }

    protected function countRecordPendingKapsamItems(): int
    {
        $record = $this->getRecord();
        if (! $record instanceof AylikFaaliyet) {
            return 0;
        }

        return AylikFaaliyetWeeklyCarryover::countPendingKapsamItems([
            'yil' => $record->yil,
            'ay' => $record->ay,
            'faaliyetler' => is_array($record->faaliyetler) ? $record->faaliyetler : [],
        ]);
    }
}
