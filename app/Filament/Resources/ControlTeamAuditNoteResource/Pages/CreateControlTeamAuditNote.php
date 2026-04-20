<?php

namespace App\Filament\Resources\ControlTeamAuditNoteResource\Pages;

use App\Filament\Resources\ControlTeamAuditNoteResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CreateControlTeamAuditNote extends CreateRecord
{
    protected static string $resource = ControlTeamAuditNoteResource::class;

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        if (Schema::hasColumn('control_team_audit_notes', 'aylik_faaliyet_id')) {
            $aylikId = $this->resolveAylikFaaliyetId($data);
            if ($aylikId <= 0) {
                throw ValidationException::withMessages([
                    'directorate_user_id' => 'Seçilen müdürlük ve dönem için bağlı aylık rapor bulunamadı.',
                ]);
            }
            $data['aylik_faaliyet_id'] = $aylikId;
        }
        if (! Schema::hasColumn('control_team_audit_notes', 'yil')) {
            unset($data['yil']);
        }
        if (! Schema::hasColumn('control_team_audit_notes', 'ay')) {
            unset($data['ay']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveAylikFaaliyetId(array $data): int
    {
        $directorateUserId = (int) ($data['directorate_user_id'] ?? 0);
        $yil = (int) ($data['yil'] ?? 0);
        $ayRaw = trim((string) ($data['ay'] ?? ''));

        $rapor = ControlTeamAuditNoteResource::resolveAylikFaaliyetForDirectoratePeriod(
            $directorateUserId,
            $yil,
            $ayRaw
        );

        return $rapor ? (int) $rapor->id : 0;
    }
}
