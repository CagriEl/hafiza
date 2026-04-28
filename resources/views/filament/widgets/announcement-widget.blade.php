<x-filament-widgets::widget>
    @php
        $announcements = $this->getAnnouncements();
        $popupAnnouncement = $this->getPopupAnnouncement();
    @endphp

    @if ($announcements->isNotEmpty())
        <div class="mb-4 space-y-3">
            @foreach ($announcements as $announcement)
                @php
                    $typeStyles = match ($announcement->type) {
                        \App\Models\Announcement::TYPE_CRITICAL => 'border-danger-200 bg-danger-50 text-danger-900',
                        \App\Models\Announcement::TYPE_WARNING => 'border-warning-200 bg-warning-50 text-warning-900',
                        default => 'border-info-200 bg-info-50 text-info-900',
                    };

                    $typeLabel = match ($announcement->type) {
                        \App\Models\Announcement::TYPE_CRITICAL => 'Kritik',
                        \App\Models\Announcement::TYPE_WARNING => 'Uyarı',
                        default => 'Bilgi',
                    };
                @endphp

                <div class="rounded-xl border p-4 shadow-sm {{ $typeStyles }}">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-sm font-semibold uppercase tracking-wide">
                            {{ $typeLabel }}
                        </div>
                        @if ($announcement->published_at)
                            <div class="text-xs opacity-75">
                                {{ $announcement->published_at->format('d.m.Y H:i') }}
                            </div>
                        @endif
                    </div>

                    <h3 class="mt-2 text-base font-bold">
                        {{ $announcement->title }}
                    </h3>

                    <div class="prose prose-sm mt-2 max-w-none">
                        {!! $announcement->content !!}
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($popupAnnouncement)
        @php
            $popupTypeLabel = match ($popupAnnouncement->type) {
                \App\Models\Announcement::TYPE_CRITICAL => 'Kritik Duyuru',
                \App\Models\Announcement::TYPE_WARNING => 'Uyarı Duyurusu',
                default => 'Bilgi Duyurusu',
            };
        @endphp

        <div
            x-data="{
                acik: false,
                kalanSure: 10,
                sayac: null,
                kullaniciId: @js(auth()->id()),
                duyuruId: @js($popupAnnouncement->id),
                baslat() {
                    const storageKey = `duyuru-popup-kapandi-${this.kullaniciId}-${this.duyuruId}`;
                    if (localStorage.getItem(storageKey) === '1') {
                        this.acik = false;
                        return;
                    }

                    this.acik = true;
                    this.sayac = setInterval(() => {
                        if (this.kalanSure <= 1) {
                            this.kalanSure = 0;
                            clearInterval(this.sayac);
                            return;
                        }
                        this.kalanSure--;
                    }, 1000);
                },
                kapat() {
                    if (this.kalanSure > 0) {
                        return;
                    }
                    const storageKey = `duyuru-popup-kapandi-${this.kullaniciId}-${this.duyuruId}`;
                    localStorage.setItem(storageKey, '1');
                    this.acik = false;
                },
            }"
            x-init="baslat()"
            x-cloak
        >
            <div
                x-show="acik"
                class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-900/70 p-4"
            >
                <div class="w-full max-w-2xl rounded-2xl bg-white shadow-2xl">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-primary-600">
                                    {{ $popupTypeLabel }}
                                </p>
                                <h2 class="mt-1 text-lg font-bold text-gray-900">
                                    {{ $popupAnnouncement->title }}
                                </h2>
                            </div>

                            <button
                                type="button"
                                class="rounded-lg p-2 text-gray-400"
                                x-show="kalanSure === 0"
                                @click="kapat()"
                                aria-label="Kapat"
                            >
                                <x-heroicon-o-x-mark class="h-5 w-5" />
                            </button>
                        </div>
                    </div>

                    <div class="max-h-[55vh] overflow-y-auto px-6 py-5">
                        <div class="prose prose-sm max-w-none text-gray-700">
                            {!! $popupAnnouncement->content !!}
                        </div>
                    </div>

                    <div class="border-t border-gray-200 px-6 py-4">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm text-gray-600" x-show="kalanSure > 0">
                                Kapatmak için <span class="font-semibold" x-text="kalanSure"></span> saniye bekleyin.
                            </p>
                            <p class="text-sm font-medium text-success-600" x-show="kalanSure === 0">
                                Süre doldu. Duyuruyu kapatabilirsiniz.
                            </p>

                            <x-filament::button
                                color="danger"
                                x-bind:disabled="kalanSure > 0"
                                @click="kapat()"
                            >
                                Kapat
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-filament-widgets::widget>
