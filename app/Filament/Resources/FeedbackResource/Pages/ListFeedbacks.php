<?php

namespace App\Filament\Resources\FeedbackResource\Pages;

use App\Filament\Resources\FeedbackResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFeedbacks extends ListRecords
{
    protected static string $resource = FeedbackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Yeni Geri Bildirim'),
        ];
    }
}
