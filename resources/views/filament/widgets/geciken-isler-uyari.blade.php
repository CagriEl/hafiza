<x-filament-widgets::widget>
    @php $isler = $this->getGecikenIsler(); @endphp

    @if(count($isler) > 0)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
            <x-slot name="heading">
                <span class="text-danger-600 font-bold">⚠️ Geciken İşleriniz Mevcut!</span>
            </x-slot>

            <div class="space-y-3">
                @foreach($isler as $is)
                    <div class="flex items-center justify-between p-4 bg-white border border-danger-100 rounded-xl shadow-sm">
                        <div class="flex-1">
                            <h4 class="font-semibold text-gray-900">{{ $is['konu'] }}</h4>
                            <p class="text-sm text-danger-500">Son Tarih: {{ \Carbon\Carbon::parse($is['tarih'])->format('d.m.Y') }}</p>
                        </div>
                        
                        <div>
                            {{ ($this->gerekceGirAction)(['kayit_id' => $is['kayit_id']]) }}
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>