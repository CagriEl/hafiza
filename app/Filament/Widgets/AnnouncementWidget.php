<?php

namespace App\Filament\Widgets;

use App\Models\Announcement;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class AnnouncementWidget extends Widget
{
    protected static string $view = 'filament.widgets.announcement-widget';

    protected static ?int $sort = -2;

    /**
     * @return Collection<int, Announcement>
     */
    public function getAnnouncements(): Collection
    {
        return Announcement::queryActiveAnnouncements()
            ->where('is_popup', false)
            ->get();
    }

    public function getPopupAnnouncement(): ?Announcement
    {
        return Announcement::latestActivePopup();
    }
}
