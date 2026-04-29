<?php

namespace App\Filament\Imports;

use App\Models\User;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Facades\Hash;

class UserImporter extends Importer
{
    protected static ?string $model = User::class;

    /**
     * @var list<int>|null
     */
    private ?array $pendingAssignedDirectorateIds = null;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->label('Ad')
                ->requiredMapping()
                ->rules(['required', 'max:255']),
            ImportColumn::make('email')
                ->label('E-posta')
                ->requiredMapping()
                ->rules(['required', 'email', 'max:255']),
            ImportColumn::make('role')
                ->label('Rol')
                ->ignoreBlankState()
                ->rules(['nullable', 'in:'.User::ROLE_MUDURLUK.','.User::ROLE_ANALIZ_EKIBI]),
            ImportColumn::make('directorate_id')
                ->label('Müdürlük ID')
                ->numeric()
                ->ignoreBlankState()
                ->rules(['nullable', 'integer']),
            ImportColumn::make('assigned_directorate_ids')
                ->label('Bağlı Müdürlük ID Listesi')
                ->helperText('Analiz Ekibi için: 12;34;56 gibi ID listesini girin.')
                ->fillRecordUsing(function (mixed $state, UserImporter $importer): void {
                    $importer->pendingAssignedDirectorateIds = $importer->normalizeAssignedDirectorateIds($state);
                })
                ->rules(['nullable']),
        ];
    }

    public function resolveRecord(): ?User
    {
        $email = (string) ($this->data['email'] ?? '');
        $record = User::query()->firstOrNew([
            'email' => $email,
        ]);

        if (blank($record->password)) {
            $record->password = Hash::make('Hafiza2026!');
        }

        if (! $record->exists) {
            $record->role = User::ROLE_MUDURLUK;
        }

        return $record;
    }

    protected function afterSave(): void
    {
        $isControlTeam = trim((string) $this->record->role) === User::ROLE_ANALIZ_EKIBI;

        if ($this->hasMappedColumn('assigned_directorate_ids')) {
            $this->syncAssignedDirectorates($isControlTeam ? ($this->pendingAssignedDirectorateIds ?? []) : []);
        }

        if (! method_exists($this->record, 'assignRole')) {
            return;
        }

        try {
            $this->record->assignRole($isControlTeam ? User::ROLE_ANALIZ_EKIBI : User::ROLE_MUDURLUK);
        } catch (\Throwable) {
            // Spatie Permission kurulu değilse role sütunu yeterlidir.
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your user import has completed and ' . number_format($import->successful_rows) . ' ' . str('row')->plural($import->successful_rows) . ' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to import.';
        }

        return $body;
    }

    public function getJobConnection(): ?string
    {
        // Sunucuda queue worker olmasa bile kullanıcı importu anında işlenir.
        return 'sync';
    }

    private function hasMappedColumn(string $column): bool
    {
        return filled($this->columnMap[$column] ?? null);
    }

    /**
     * @return list<int>
     */
    private function normalizeAssignedDirectorateIds(mixed $state): array
    {
        if (is_array($state)) {
            $parts = $state;
        } else {
            $parts = preg_split('/[;,|]/', (string) $state) ?: [];
        }

        return collect($parts)
            ->map(static fn (mixed $value): int => (int) trim((string) $value))
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $ids
     */
    private function syncAssignedDirectorates(array $ids): void
    {
        $validDirectorateIds = User::query()
            ->onlyMudurlukReportingAccounts()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->record->assignedDirectorates()->sync($validDirectorateIds);
    }
}
