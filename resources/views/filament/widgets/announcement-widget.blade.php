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

                <div class="overflow-hidden rounded-xl border p-3 shadow-sm sm:p-4 {{ $typeStyles }}">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                        <div class="text-xs font-semibold uppercase tracking-wide sm:text-sm">
                            {{ $typeLabel }}
                        </div>
                        <div class="text-xs opacity-75">
                            @if ($announcement->published_at)
                                <div>Yayın: {{ $announcement->published_at->format('d.m.Y H:i') }}</div>
                            @endif
                            @if ($announcement->expires_at)
                                <div>Bitiş: {{ $announcement->expires_at->format('d.m.Y H:i') }}</div>
                            @endif
                        </div>
                    </div>

                    <h3 class="mt-2 break-words text-sm font-bold sm:text-base">
                        {{ $announcement->title }}
                    </h3>

                    <div class="prose prose-sm mt-2 max-w-none break-words">
                        <div class="[&_*]:max-w-full [&_img]:h-auto [&_img]:max-w-full [&_table]:block [&_table]:w-full [&_table]:overflow-x-auto">
                            {!! $announcement->content !!}
                        </div>
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
                            this.kapat(true);
                            return;
                        }
                        this.kalanSure--;
                    }, 1000);
                },
                kapat(otomatik = false) {
                    if (!otomatik && this.kalanSure > 0) {
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
                class="fixed inset-0 z-[60] flex items-end justify-center bg-gray-900/70 p-2 sm:items-center sm:p-4"
            >
                <div class="w-full max-w-2xl rounded-2xl bg-white shadow-2xl">
                    <div class="border-b border-gray-200 px-4 py-3 sm:px-6 sm:py-4">
                        <div class="flex items-start justify-between gap-3 sm:gap-4">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wide text-primary-600">
                                    {{ $popupTypeLabel }}
                                </p>
                                <h2 class="mt-1 break-words text-base font-bold text-gray-900 sm:text-lg">
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

                    <div class="max-h-[60vh] overflow-y-auto px-4 py-4 sm:max-h-[55vh] sm:px-6 sm:py-5">
                        <div class="prose prose-sm max-w-none break-words text-gray-700">
                            <div class="[&_*]:max-w-full [&_img]:h-auto [&_img]:max-w-full [&_table]:block [&_table]:w-full [&_table]:overflow-x-auto">
                                {!! $popupAnnouncement->content !!}
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-200 px-4 py-3 sm:px-6 sm:py-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-gray-600" x-show="kalanSure > 0">
                                Duyuru <span class="font-semibold" x-text="kalanSure"></span> saniye sonra otomatik kapanır.
                            </p>
                            <p class="text-sm font-medium text-success-600" x-show="kalanSure === 0">
                                Duyuru otomatik kapatıldı.
                            </p>

                            <x-filament::button
                                color="danger"
                                @click="kapat()"
                            >
                                Hemen Kapat
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-filament-widgets::widget>
