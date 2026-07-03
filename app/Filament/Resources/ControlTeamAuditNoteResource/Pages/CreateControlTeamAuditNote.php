<?php

namespace App\Filament\Resources\ControlTeamAuditNoteResource\Pages;

use App\Filament\Concerns\ProvidesAnalizEkibiOrnekRaporSablonDownload;
use App\Filament\Resources\ControlTeamAuditNoteResource;
use App\Support\AnalizEkibiRaporVerileri;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CreateControlTeamAuditNote extends CreateRecord
{
    use ProvidesAnalizEkibiOrnekRaporSablonDownload;

    protected static string $resource = ControlTeamAuditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->analizEkibiOrnekRaporSablonDownloadAction(),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['rapor_verileri'] = AnalizEkibiRaporVerileri::normalize($data['rapor_verileri'] ?? null);
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

    public function mount(): void
    {
        parent::mount();

        $this->form->fill(array_merge($this->form->getState(), [
            'rapor_verileri' => AnalizEkibiRaporVerileri::emptyStructure(),
        ]));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Analiz raporu kaydedildi. Excel olarak indirmek için görüntüleme ekranındaki butonu kullanın.';
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
