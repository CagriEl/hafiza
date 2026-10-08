<x-filament-panels::page>
    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-700 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <div class="font-medium text-gray-950 dark:text-white">Haftalık rapor dönemleri</div>
        <div class="mt-1">
            Seçtiğiniz yıl/ay için 1–4. haftaların tarih aralıklarını belirleyin; 5. hafta opsiyoneldir.
            Kayıtlı tanımlar faaliyet raporlarındaki hafta seçiminde kullanılır; tanım yoksa sistem otomatik takvimi uygular.
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit" size="lg">
                Kaydet
            </x-filament::button>
        </div>
    </form>

    @if ($this->definedPeriods->isNotEmpty())
        <div class="mt-10">
            <h2 class="mb-3 text-base font-semibold text-gray-950 dark:text-white">Kayıtlı dönemler</h2>
            <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Dönem</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Hafta</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Aralık</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-600 dark:text-gray-300"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                        @foreach ($this->definedPeriods as $period)
                            @php
                                $ayNo = (int) $period->ay;
                                $ayAdi = \App\Support\ReportPeriodWeeks::turkishMonthName($ayNo);
                                $baslangic = \Carbon\Carbon::parse($period->baslangic)->format('d.m.Y');
                                $bitis = \Carbon\Carbon::parse($period->bitis)->format('d.m.Y');
                            @endphp
                            <tr>
                                <td class="px-4 py-3 text-gray-950 dark:text-white">{{ $ayAdi }} {{ $period->yil }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $period->hafta_sayisi }} hafta</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $baslangic }} – {{ $bitis }}</td>
                                <td class="px-4 py-3 text-right">
                                    <x-filament::button
                                        color="gray"
                                        size="sm"
                                        wire:click="loadSavedPeriod({{ (int) $period->yil }}, '{{ $period->ay }}')"
                                    >
                                        Düzenle
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
