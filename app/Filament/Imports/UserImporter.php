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
            ImportColumn::make('directorate_id')
                ->label('Müdürlük ID')
                ->numeric()
                ->rules(['nullable', 'integer']),
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

        $record->role = User::ROLE_MUDURLUK;

        return $record;
    }

    protected function afterSave(): void
    {
        if (! method_exists($this->record, 'assignRole')) {
            return;
        }

        try {
            $this->record->assignRole(User::ROLE_MUDURLUK);
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
}
