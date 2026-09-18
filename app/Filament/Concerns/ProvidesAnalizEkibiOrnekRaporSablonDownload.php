<?php

namespace App\Filament\Concerns;

use App\Models\User;
use App\Support\AnalizEkibiOrnekRaporExcel;
use Filament\Actions\Action;

trait ProvidesAnalizEkibiOrnekRaporSablonDownload
{
    protected function analizEkibiOrnekRaporSablonDownloadAction(): Action
    {
        return Action::make('ornekRaporSablonIndir')
            ->label('Boş Örnek Rapor (Excel)')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => static::canDownloadAnalizEkibiOrnekRaporSablon())
            ->action(fn () => AnalizEkibiOrnekRaporExcel::downloadResponse());
    }

    protected static function canDownloadAnalizEkibiOrnekRaporSablon(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ($user->isReportingSuperAdmin() || $user->isControlTeam());
    }
}
