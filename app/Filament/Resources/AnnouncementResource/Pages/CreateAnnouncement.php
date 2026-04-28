<?php

namespace App\Filament\Resources\AnnouncementResource\Pages;

use App\Filament\Resources\AnnouncementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Duyuru başarıyla oluşturuldu.';
    }
}
