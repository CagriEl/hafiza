<?php

namespace App\Filament\Resources\FeedbackResource\Pages;

use App\Filament\Resources\FeedbackResource;
use App\Models\Feedback;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditFeedback extends EditRecord
{
    protected static string $resource = FeedbackResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();
        if ($record instanceof Feedback) {
            $status = trim((string) ($record->status ?? ''));
            if (in_array($status, [
                Feedback::STATUS_CLOSED,
                Feedback::STATUS_RESOLVED,
                Feedback::STATUS_REJECTED,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Bu geri bildirim kapalı olduğu için artık güncellenemez.',
                ]);
            }
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            return $data;
        }

        $isAdmin = ((int) ($user->id ?? 0) === 1)
            || (method_exists($user, 'hasRole') && $user->hasRole('Admin'))
            || mb_strtolower(trim((string) ($user->role ?? ''))) === 'admin';
        $isControlTeam = trim((string) ($user->role ?? '')) === User::ROLE_ANALIZ_EKIBI;

        if (! $isAdmin && ! $isControlTeam) {
            // Geri bildirimi açan kullanıcı sadece "Kapatıldı" durumuna çekebilir.
            $data['status'] = Feedback::STATUS_CLOSED;
            unset($data['admin_note']);
            unset($data['new_reply']);
        } else {
            $newReply = trim((string) ($data['new_reply'] ?? ''));
            unset($data['new_reply']);

            $existingNote = trim((string) ($record?->admin_note ?? ''));
            if ($newReply !== '') {
                $author = trim((string) ($user->name ?? 'Analiz Ekibi'));
                $stamp = now()->format('d.m.Y H:i');
                $entry = '['.$stamp.' - '.$author.'] '.$newReply;
                $data['admin_note'] = $existingNote === ''
                    ? $entry
                    : ($existingNote."\n\n".$entry);
            } else {
                // Mevcut yanıta dokunma.
                unset($data['admin_note']);
            }
        }

        return $data;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Geri bildirim kaydı güncellendi.';
    }
}
