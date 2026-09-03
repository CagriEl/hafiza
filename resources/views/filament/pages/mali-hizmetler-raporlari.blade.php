<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-700 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <div class="font-medium text-gray-950 dark:text-white">Ödeme planı özeti</div>
        <div>{{ $this->getDonemLabel() }}</div>
        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Haftalık ödeme ve talep verilerinin grafiksel özeti. Veri girişi için Mali Veri Girişi ekranını kullanınız.
        </div>
    </div>

    <div class="mt-6">
        {{ $this->form }}
    </div>

    @php
        $period = $this->getSelectedPeriod();
    @endphp

    <div class="mt-6" wire:key="mali-donut-wrap-{{ $period['yil'] }}-{{ $period['ay'] }}-{{ $period['hafta'] }}">
        @livewire(
            \App\Filament\Widgets\MaliHizmetlerDonutChart::class,
            [
                'chartYil' => $period['yil'],
                'chartAy' => $period['ay'],
                'chartHafta' => $period['hafta'],
            ],
            key('mali-donut-chart-' . $period['yil'] . '-' . $period['ay'] . '-' . $period['hafta'])
        )
    </div>
</x-filament-panels::page>
