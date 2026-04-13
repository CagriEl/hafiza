<?php

namespace App\Filament\Pages;

use App\Support\UsageGuideData;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;

class UsageGuide extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static string $view = 'filament.pages.usage-guide';

    protected static ?string $navigationLabel = 'Kullanım Rehberi';

    protected static ?string $title = 'Kullanım Rehberi';

    protected static ?int $navigationSort = 99;

    protected static ?string $navigationGroup = 'Yardım';

    protected static ?string $slug = 'kullanim-rehberi';

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdfIndir')
                ->label('PDF indir')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function (): \Symfony\Component\HttpFoundation\StreamedResponse {
                    $guide = UsageGuideData::forFilamentPanel();
                    $pdf = Pdf::loadView('pdf.kullanim-rehberi', ['guide' => $guide])
                        ->setPaper('a4', 'portrait');

                    $filename = 'kullanim-rehberi_'.now()->format('Y-m-d').'.pdf';

                    return response()->streamDownload(function () use ($pdf): void {
                        echo $pdf->output();
                    }, $filename);
                }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getGuideData(): array
    {
        return UsageGuideData::forFilamentPanel();
    }
}
