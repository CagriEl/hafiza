<?php

namespace App\Filament\Resources\FeedbackResource\Pages;

use App\Filament\Resources\FeedbackResource;
use App\Models\Feedback;
use Filament\Resources\Pages\CreateRecord;

class CreateFeedback extends CreateRecord
{
    protected static string $resource = FeedbackResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['directorate_id'] = FeedbackResource::resolveDirectorateIdForAuthUser();
        $data['status'] = Feedback::STATUS_NEW;

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Geri bildiriminiz başarıyla gönderildi.';
    }
}
