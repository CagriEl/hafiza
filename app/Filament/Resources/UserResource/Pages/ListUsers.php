<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Imports\UserImporter;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            \Filament\Actions\ImportAction::make()
                ->label('İçe Aktar')
                ->importer(UserImporter::class),
            Actions\Action::make('download_users_csv')
                ->label('Dışa Aktar')
                ->icon('heroicon-m-arrow-down-tray')
                ->action(function () {
                    $fileName = 'kullanicilar-'.now()->format('Y-m-d_His').'.csv';

                    return response()->streamDownload(function (): void {
                        $handle = fopen('php://output', 'w');

                        // Import tarafındaki alan adlarıyla birebir uyumlu kolonlar.
                        fputcsv($handle, ['name', 'email', 'directorate_id']);

                        User::query()
                            ->orderBy('id')
                            ->chunk(500, function ($users) use ($handle): void {
                                foreach ($users as $user) {
                                    fputcsv($handle, [
                                        $user->name,
                                        $user->email,
                                        $user->directorate_id,
                                    ]);
                                }
                            });

                        fclose($handle);
                    }, $fileName, [
                        'Content-Type' => 'text/csv; charset=UTF-8',
                    ]);
                }),
        ];
    }
}
