<x-filament-panels::page>
    @include('filament.forms.analiz-ekibi-aylik-karsilastirma', [
        'screen' => $this->getScreen(),
        'hideTitle' => true,
        'kod' => $this->kod,
    ])
</x-filament-panels::page>
