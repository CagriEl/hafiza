<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->form }}

        @php
            $report = $this->getReport();
            $ozet = $report['ozet'];
        @endphp

        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <div class="text-sm font-medium text-gray-950 dark:text-white">
                        {{ $report['mudurluk_adi'] !== '' ? $report['mudurluk_adi'] : 'Müdürlük seçin' }}
                        @if ($report['donem_etiketi'] !== '')
                            — {{ $report['donem_etiketi'] }}
                        @endif
                    </div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Size bağlı müdürlükte seçilen haftanın 0 girilen kodları ve açıkta kalan işleri.
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($report['rapor_var'])
                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                            Haftalık rapor mevcut
                        </span>
                        @if (! empty($report['rapor_url']))
                            <x-filament::button tag="a" :href="$report['rapor_url']" color="gray" size="sm">
                                Rapora git
                            </x-filament::button>
                        @endif
                    @elseif ((int) ($report['mudurluk_id'] ?? 0) > 0)
                        <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                            Bu hafta için rapor yok
                        </span>
                    @endif
                </div>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Toplam Kod</div>
                    <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $ozet['toplam_kod'] }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
                    <div class="text-xs text-gray-500 dark:text-gray-400">0 Girilen Kod</div>
                    <div class="mt-1 text-xl font-semibold text-rose-700 dark:text-rose-300">{{ $ozet['sifir_kod_sayisi'] }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Açıkta Kalan Kalem</div>
                    <div class="mt-1 text-xl font-semibold text-amber-700 dark:text-amber-300">{{ $ozet['acikta_kalem_sayisi'] }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Açıkta Toplam</div>
                    <div class="mt-1 text-xl font-semibold text-blue-700 dark:text-blue-300">
                        {{ number_format((float) $ozet['acikta_toplam'], 0, ',', '.') }}
                    </div>
                </div>
            </div>
        </div>

        @if ((int) ($report['mudurluk_id'] ?? 0) <= 0)
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-400">
                Size atanmış müdürlük bulunmuyor veya henüz seçim yapılmadı. Analiz Ekibi kaydınıza müdürlük bağlanmalıdır.
            </div>
        @elseif (! $report['rapor_var'])
            <div class="rounded-xl border border-dashed border-amber-300 bg-amber-50/50 p-8 text-center text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-500/10 dark:text-amber-200">
                Seçilen müdürlük ve hafta için faaliyet raporu bulunamadı.
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <div class="text-base font-semibold text-gray-950 dark:text-white">0 Girilen Kodlar</div>
                    <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        Gerçekleşen değeri 0 olan faaliyet kodları.
                    </div>
                </div>
                @if (empty($report['sifir_girilen_kodlar']))
                    <div class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">
                        Bu haftada 0 girilmiş kod yok.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-800/80">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Kod</th>
                                    <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Faaliyet</th>
                                    <th class="px-4 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Hedef</th>
                                    <th class="px-4 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Gerçekleşen</th>
                                    <th class="px-4 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Açıkta</th>
                                    <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Durum</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($report['sifir_girilen_kodlar'] as $kod)
                                    <tr>
                                        <td class="px-4 py-2 font-medium text-gray-950 dark:text-white">{{ $kod['faaliyet_kodu'] !== '' ? $kod['faaliyet_kodu'] : '—' }}</td>
                                        <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $kod['etiket'] }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format((float) $kod['hedef'], 0, ',', '.') }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums text-rose-700 dark:text-rose-300">{{ number_format((float) $kod['gerceklesen'], 0, ',', '.') }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format((float) $kod['kalan'], 0, ',', '.') }}</td>
                                        <td class="px-4 py-2">
                                            <span @class([
                                                'rounded-full px-2 py-0.5 text-xs font-medium',
                                                'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => in_array($kod['durum'], ['Riskli', 'Veri Eksik'], true),
                                                'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $kod['durum'] === 'Kısmi',
                                                'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => ! in_array($kod['durum'], ['Riskli', 'Veri Eksik', 'Kısmi'], true),
                                            ])>{{ $kod['durum'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <div class="text-base font-semibold text-gray-950 dark:text-white">Açıkta Kalan İşler</div>
                    <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        Kapanmamış kapsam kalemleri ve bekleyen miktarlar.
                    </div>
                </div>
                @if (empty($report['acikta_kalan_isler']))
                    <div class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">
                        Bu haftada açıkta kalan iş yok.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-800/80">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Kod</th>
                                    <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Faaliyet</th>
                                    <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Alt Kalem</th>
                                    <th class="px-4 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Öngörülen</th>
                                    <th class="px-4 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Yapılan</th>
                                    <th class="px-4 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Açıkta</th>
                                    <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Durum</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($report['acikta_kalan_isler'] as $is)
                                    <tr>
                                        <td class="px-4 py-2 font-medium text-gray-950 dark:text-white">{{ $is['faaliyet_kodu'] !== '' ? $is['faaliyet_kodu'] : '—' }}</td>
                                        <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $is['etiket'] }}</td>
                                        <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $is['kalem'] }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format((float) $is['ongorulen'], 0, ',', '.') }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format((float) $is['gerceklesen'], 0, ',', '.') }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums font-semibold text-blue-700 dark:text-blue-300">{{ number_format((float) $is['acikta'], 0, ',', '.') }}</td>
                                        <td class="px-4 py-2">
                                            <span @class([
                                                'rounded-full px-2 py-0.5 text-xs font-medium',
                                                'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $is['durum'] === 'Riskli',
                                                'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $is['durum'] === 'Kısmi',
                                                'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => ! in_array($is['durum'], ['Riskli', 'Kısmi'], true),
                                            ])>{{ $is['durum'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
