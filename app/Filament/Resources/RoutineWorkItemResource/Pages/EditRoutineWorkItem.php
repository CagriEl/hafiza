<?php

namespace App\Filament\Resources\RoutineWorkItemResource\Pages;

use App\Filament\Resources\RoutineWorkItemResource;
use App\Models\RoutineWorkWindow;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditRoutineWorkItem extends EditRecord
{
    protected static string $resource = RoutineWorkItemResource::class;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        if (! static::isAdmin() && ! RoutineWorkWindow::isEntryOpenForDate()) {
            Notification::make()
                ->title(RoutineWorkItemResource::closedMessage())
                ->danger()
                ->send();

            $this->redirect($this->getResource()::getUrl('index'));
        }
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Rutin iş kaydı güncellendi.';
    }

    protected static function isAdmin(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        if ((int) ($user->id ?? 0) === 1) {
            return true;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('Admin')) {
            return true;
        }

        return mb_strtolower(trim((string) ($user->role ?? ''))) === 'admin';
    }
}
