<x-filament-panels::page>
    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-700 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <div class="font-medium text-gray-950 dark:text-white">Rapor dönemi</div>
        <div>{{ $this->getDonemLabel() }}</div>
        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Yıl, ay ve hafta seçerek ödeme planını ilgili hafta için kaydediniz.
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" size="lg">
            Kaydet
        </x-filament::button>
    </form>
</x-filament-panels::page>
