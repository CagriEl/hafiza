<?php

namespace App\Filament\Widgets;

use App\Models\AylikFaaliyet;
use App\Filament\Resources\AylikFaaliyetResource;
use Filament\Widgets\Widget;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Carbon\Carbon;

class GecikenIslerUyari extends Widget implements HasForms, HasActions
{
    use InteractsWithActions, InteractsWithForms;

    protected static string $view = 'filament.widgets.geciken-isler-uyari';

    protected static ?int $sort = -1;

    public static function canView(): bool
    {
        return auth()->id() !== 1;
    }

    public function getGecikenIsler()
    {
        $kayitlar = AylikFaaliyet::where('user_id', auth()->id())->get();
        $gecikenler = [];

        foreach ($kayitlar as $kayit) {
            $isler = is_string($kayit->faaliyetler) ? json_decode($kayit->faaliyetler, true) : $kayit->faaliyetler;
            
            if (is_array($isler)) {
                foreach ($isler as $index => $is) {
                    if (
                        isset($is['son_tarih']) && 
                        Carbon::parse($is['son_tarih'])->isPast() && 
                        ($is['durum'] ?? '') !== 'tamam' && 
                        empty($is['gecikme_gerekcesi'])
                    ) {
                        $gecikenler[] = [
                            'kayit_id' => $kayit->id,
                            'konu' => $is['konu'],
                            'tarih' => $is['son_tarih'],
                        ];
                    }
                }
            }
        }

        return $gecikenler;
    }

    // Modal yerine doğrudan Edit sayfasına yönlendiren buton
    public function gerekceGirAction(): Action
    {
        return Action::make('gerekceGir')
            ->label('Düzenle ve Gerekçe Yaz')
            ->color('danger')
            ->icon('heroicon-m-pencil-square')
            ->url(fn (array $arguments): string => 
                AylikFaaliyetResource::getUrl('edit', ['record' => $arguments['kayit_id']])
            );
    }
}