<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AylikFaaliyetResource;
use App\Models\AylikFaaliyet;
use App\Support\AylikFaaliyetEscalation;
use App\Support\ReportDirectorateScope;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Widgets\Widget;

class GecikenIslerUyari extends Widget implements HasActions, HasForms
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
        $kayitlar = ReportDirectorateScope::constrain(AylikFaaliyet::query())->get();
        $gecikenler = [];

        foreach ($kayitlar as $kayit) {
            $isler = is_string($kayit->faaliyetler) ? json_decode($kayit->faaliyetler, true) : $kayit->faaliyetler;

            if (is_array($isler)) {
                foreach ($isler as $index => $is) {
                    $legacyUyari = isset($is['son_tarih'])
                        && Carbon::parse($is['son_tarih'])->isPast()
                        && ($is['durum'] ?? '') !== 'tamam'
                        && empty($is['gecikme_gerekcesi']);
                    $hedefAltiSapmasiz = AylikFaaliyetEscalation::kpiUnderTarget($is)
                        && ! AylikFaaliyetEscalation::sapmaNedeniFilled($is);

                    if ($legacyUyari || $hedefAltiSapmasiz) {
                        $baslik = $is['konu'] ?? ($is['faaliyet_kodu'] ?? 'Faaliyet');
                        $gecikenler[] = [
                            'kayit_id' => $kayit->id,
                            'konu' => $baslik.($hedefAltiSapmasiz && ! $legacyUyari ? ' (hedef altı — sapma nedeni giriniz)' : ''),
                            'tarih' => $is['son_tarih'] ?? null,
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
            ->url(fn (array $arguments): string => AylikFaaliyetResource::getUrl('edit', ['record' => $arguments['kayit_id']])
            );
    }
}
