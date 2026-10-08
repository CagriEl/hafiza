<x-filament-panels::page>
    <div class="space-y-4">
        {{ $this->form }}

        <div class="flex flex-wrap gap-2">
            <x-filament::button wire:click="runPreview" color="gray" icon="heroicon-o-eye">
                Önizle
            </x-filament::button>
            <x-filament::button
                wire:click="runApply"
                color="warning"
                icon="heroicon-o-check"
                wire:confirm="Önizlenen katalog değişiklikleri ilgili TÜM raporlara kalıcı yazılacak. Her raporu tek tek açmanız gerekmez. Mevcut sayısal veriler korunur. Devam edilsin mi?"
            >
                Kalıcı uygula
            </x-filament::button>
        </div>

        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="fi-section-header flex items-center gap-3 px-6 py-4">
                <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    Önizleme
                </h3>
            </div>
            <div class="fi-section-content-ctn border-t border-gray-200 dark:border-white/10">
                <div class="fi-section-content p-6">
                    {{ $this->getPreviewHtml() }}
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
