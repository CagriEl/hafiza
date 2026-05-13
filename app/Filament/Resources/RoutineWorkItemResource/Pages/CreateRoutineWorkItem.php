<?php

namespace App\Filament\Resources\RoutineWorkItemResource\Pages;

use App\Filament\Resources\RoutineWorkItemResource;
use App\Models\RoutineWorkItem;
use App\Models\RoutineWorkWindow;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateRoutineWorkItem extends CreateRecord
{
    protected static string $resource = RoutineWorkItemResource::class;

    /**
     * @var list<array{work_date:string, work_item:string, status:string}>
     */
    protected array $pendingRows = [];

    protected int $createdRowCount = 1;

    public function mount(): void
    {
        parent::mount();

        if (! static::isAdmin() && ! RoutineWorkWindow::isEntryOpenForDate()) {
            Notification::make()
                ->title(RoutineWorkItemResource::closedMessage())
                ->danger()
                ->send();

            $this->redirect($this->getResource()::getUrl('index'));
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return "{$this->createdRowCount} adet rutin iş kaydı oluşturuldu.";
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $rawRows = $data['bulk_items'] ?? [];
        if (! is_array($rawRows)) {
            $rawRows = [];
        }

        $rows = [];
        foreach ($rawRows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = trim((string) ($row['work_date'] ?? ''));
            $item = trim((string) ($row['work_item'] ?? ''));
            $status = trim((string) ($row['status'] ?? ''));

            if ($date === '' && $item === '' && $status === '') {
                continue;
            }

            if ($date === '' || $item === '' || $status === '') {
                throw ValidationException::withMessages([
                    'data.bulk_items' => 'Eksik satır var. Lütfen doldurduğunuz her satırda tarih, iş ve durum alanlarını tamamlayın. Hatalı satır: '.((int) $index + 1),
                ]);
            }

            $rows[] = [
                'work_date' => $date,
                'work_item' => $item,
                'status' => $status,
            ];
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'data.bulk_items' => 'En az bir rutin iş satırı girmelisiniz.',
            ]);
        }

        $this->pendingRows = array_slice($rows, 1);
        $this->createdRowCount = count($rows);
        $first = $rows[0];

        return [
            'work_date' => $first['work_date'],
            'work_item' => $first['work_item'],
            'status' => $first['status'],
            'user_id' => (int) (auth()->id() ?? 0),
        ];
    }

    protected function afterCreate(): void
    {
        if ($this->pendingRows === []) {
            return;
        }

        $userId = (int) ($this->record->user_id ?? auth()->id() ?? 0);

        foreach ($this->pendingRows as $row) {
            RoutineWorkItem::query()->create([
                'user_id' => $userId,
                'work_date' => $row['work_date'],
                'work_item' => $row['work_item'],
                'status' => $row['status'],
            ]);
        }
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
