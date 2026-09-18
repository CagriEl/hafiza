<?php

namespace App\Filament\Resources\RoutineWorkItemResource\Pages;

use App\Filament\Resources\RoutineWorkItemResource;
use App\Models\RoutineWorkItem;
use App\Models\RoutineWorkWindow;
use App\Support\RoutineWorkDailyRows;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateRoutineWorkItem extends CreateRecord
{
    protected static string $resource = RoutineWorkItemResource::class;

    /**
     * @var list<array{start_date:string, end_date:string, work_item:string, status:string}>
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

        $dailyRows = RoutineWorkDailyRows::emptyRowsForCurrentWindow();
        if ($dailyRows !== []) {
            $this->form->fill([
                'bulk_items' => $dailyRows,
            ]);
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

        $window = RoutineWorkWindow::current();
        $rows = [];
        foreach ($rawRows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $startDate = trim((string) ($row['start_date'] ?? ''));
            $endDate = trim((string) ($row['end_date'] ?? ''));
            $item = trim((string) ($row['work_item'] ?? ''));
            $status = trim((string) ($row['status'] ?? ''));

            if ($startDate === '' && $endDate === '' && $item === '' && $status === '') {
                continue;
            }

            if ($startDate === '' || $endDate === '' || $item === '' || $status === '') {
                throw ValidationException::withMessages([
                    'data.bulk_items' => 'Eksik satır var. Doldurduğunuz her satırda başlangıç tarihi, bitiş tarihi, iş ve durum alanlarını tamamlayın. Hatalı satır: '.((int) $index + 1),
                ]);
            }

            if ($window instanceof RoutineWorkWindow
                && ! static::isAdmin()
                && ! RoutineWorkDailyRows::datesWithinWindow($startDate, $endDate, $window)) {
                throw ValidationException::withMessages([
                    'data.bulk_items' => 'Tarihler aktif ölçüm penceresi içinde olmalıdır. Hatalı satır: '.((int) $index + 1),
                ]);
            }

            $rows[] = [
                'start_date' => $startDate,
                'end_date' => $endDate,
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
            'start_date' => $first['start_date'],
            'end_date' => $first['end_date'],
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
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
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
