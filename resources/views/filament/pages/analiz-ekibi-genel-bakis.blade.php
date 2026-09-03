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
                    <div class="text-sm font-medium text-gray-950 dark:text-white">{{ $report['donem_etiketi'] }} — Bağlı Müdürlük Raporu</div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Yalnızca size atanan müdürlüklerin seçilen aya ait faaliyet verileri gösterilir.
                    </div>
                </div>
                @if ($ozet['gecen_ay_fark'] !== null)
                    <div @class([
                        'rounded-lg px-3 py-1.5 text-sm font-medium',
                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $ozet['gecen_ay_fark'] >= 0,
                        'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $ozet['gecen_ay_fark'] < 0,
                    ])>
                        Geçen aya göre gerçekleşen:
                        {{ $ozet['gecen_ay_fark'] > 0 ? '+' : '' }}{{ $ozet['gecen_ay_fark'] }}
                    </div>
                @endif
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-8">
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Müdürlük</div>
                    <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $ozet['mudurluk_sayisi'] }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Rapor Giren</div>
                    <div class="mt-1 text-xl font-semibold text-emerald-700 dark:text-emerald-300">{{ $ozet['rapor_olan'] }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Rapor Yok</div>
                    <div class="mt-1 text-xl font-semibold text-amber-700 dark:text-amber-300">{{ $ozet['rapor_olmayan'] }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Hedef</div>
                    <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $ozet['hedef'] }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Gerçekleşen</div>
                    <div class="mt-1 text-xl font-semibold text-emerald-700 dark:text-emerald-300">{{ $ozet['gerceklesen'] }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Açıkta</div>
                    <div class="mt-1 text-xl font-semibold text-blue-700 dark:text-blue-300">{{ $ozet['kalan'] }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Tamamlanma</div>
                    <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">
                        {{ $ozet['tamamlanma_orani'] !== null ? $ozet['tamamlanma_orani'].'%' : '—' }}
                    </div>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Dikkat / Revize</div>
                    <div class="mt-1 text-xl font-semibold text-rose-700 dark:text-rose-300">
                        {{ $ozet['dikkat_sayisi'] }} / {{ $ozet['revize_karar'] }}
                    </div>
                </div>
            </div>
        </div>

        @if (empty($report['mudurlukler']))
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-400">
                Size atanmış müdürlük bulunmuyor. Yönetici hesabından Analiz Ekibi kaydınıza müdürlük bağlanmalıdır.
            </div>
        @else
            <div class="space-y-4">
                @foreach ($report['mudurlukler'] as $mudurluk)
                    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                            <div>
                                <div class="text-base font-semibold text-gray-950 dark:text-white">{{ $mudurluk['name'] }}</div>
                                <div class="mt-1 flex flex-wrap gap-2 text-xs">
                                    @if ($mudurluk['rapor_var'])
                                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">Rapor mevcut</span>
                                    @else
                                        <span class="rounded-full bg-amber-50 px-2 py-0.5 font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">Bu dönemde rapor yok</span>
                                    @endif
                                    @if (($mudurluk['ozet']['dikkat_sayisi'] ?? 0) > 0)
                                        <span class="rounded-full bg-rose-50 px-2 py-0.5 font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                                            {{ $mudurluk['ozet']['dikkat_sayisi'] }} dikkat gerektiren faaliyet
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if (! empty($mudurluk['rapor_url']))
                                    <x-filament::button
                                        tag="a"
                                        :href="$mudurluk['rapor_url']"
                                        color="gray"
                                        size="sm"
                                    >
                                        Raporu aç
                                    </x-filament::button>
                                @endif
                            </div>
                        </div>

                        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                            <div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">Hedef</div>
                                <div class="text-lg font-semibold text-gray-950 dark:text-white">{{ $mudurluk['ozet']['hedef'] }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">Gerçekleşen</div>
                                <div class="text-lg font-semibold text-emerald-700 dark:text-emerald-300">{{ $mudurluk['ozet']['gerceklesen'] }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">Açıkta</div>
                                <div class="text-lg font-semibold text-blue-700 dark:text-blue-300">{{ $mudurluk['ozet']['kalan'] }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">Tamamlanma</div>
                                <div class="text-lg font-semibold text-gray-950 dark:text-white">
                                    {{ $mudurluk['ozet']['tamamlanma_orani'] !== null ? $mudurluk['ozet']['tamamlanma_orani'].'%' : '—' }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">Revize / Karar</div>
                                <div class="text-lg font-semibold text-rose-700 dark:text-rose-300">{{ $mudurluk['ozet']['revize_karar'] }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">Geçen ay farkı</div>
                                <div @class([
                                    'text-lg font-semibold',
                                    'text-gray-950 dark:text-white' => $mudurluk['ozet']['gecen_ay_fark'] === null,
                                    'text-emerald-700 dark:text-emerald-300' => ($mudurluk['ozet']['gecen_ay_fark'] ?? 0) > 0,
                                    'text-rose-700 dark:text-rose-300' => ($mudurluk['ozet']['gecen_ay_fark'] ?? 0) < 0,
                                    'text-gray-700 dark:text-gray-300' => ($mudurluk['ozet']['gecen_ay_fark'] ?? null) === 0,
                                ])>
                                    @if ($mudurluk['ozet']['gecen_ay_fark'] === null)
                                        —
                                    @else
                                        {{ $mudurluk['ozet']['gecen_ay_fark'] > 0 ? '+' : '' }}{{ $mudurluk['ozet']['gecen_ay_fark'] }}
                                    @endif
                                </div>
                            </div>
                        </div>

                        @if (! empty($mudurluk['ozet']['kritik_kalem_notu']))
                            <div class="border-t border-amber-100 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                                {{ $mudurluk['ozet']['kritik_kalem_notu'] }}
                            </div>
                        @endif

                        @if ($mudurluk['rapor_var'] && ! empty($mudurluk['faaliyetler']))
                            <div class="overflow-x-auto border-t border-gray-100 dark:border-gray-800">
                                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-800/80">
                                        <tr>
                                            <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Faaliyet</th>
                                            <th class="px-4 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Hedef</th>
                                            <th class="px-4 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Gerçekleşen</th>
                                            <th class="px-4 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Açıkta</th>
                                            <th class="px-4 py-2 text-right font-medium text-gray-600 dark:text-gray-300">%</th>
                                            <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Durum</th>
                                            <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Not</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                        @foreach ($mudurluk['faaliyetler'] as $faaliyet)
                                            <tr @class([
                                                'bg-rose-50/60 dark:bg-rose-500/5' => $faaliyet['dikkat'],
                                            ])>
                                                <td class="px-4 py-2 text-gray-950 dark:text-white">
                                                    <div class="font-medium">{{ $faaliyet['etiket'] }}</div>
                                                    @if ($faaliyet['faaliyet_kodu'] !== '')
                                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $faaliyet['faaliyet_kodu'] }}</div>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ $faaliyet['hedef'] }}</td>
                                                <td class="px-4 py-2 text-right tabular-nums text-emerald-700 dark:text-emerald-300">{{ $faaliyet['gerceklesen'] }}</td>
                                                <td class="px-4 py-2 text-right tabular-nums text-blue-700 dark:text-blue-300">{{ $faaliyet['kalan'] }}</td>
                                                <td class="px-4 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">
                                                    {{ $faaliyet['tamamlanma_orani'] !== null ? $faaliyet['tamamlanma_orani'].'%' : '—' }}
                                                </td>
                                                <td class="px-4 py-2">
                                                    <span @class([
                                                        'rounded-full px-2 py-0.5 text-xs font-medium',
                                                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $faaliyet['durum'] === 'Tamamlandı',
                                                        'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $faaliyet['durum'] === 'Kısmi',
                                                        'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $faaliyet['durum'] === 'Riskli',
                                                        'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => ! in_array($faaliyet['durum'], ['Tamamlandı', 'Kısmi', 'Riskli'], true),
                                                    ])>
                                                        {{ $faaliyet['durum'] }}
                                                    </span>
                                                </td>
                                                <td class="max-w-xs px-4 py-2 text-xs text-gray-600 dark:text-gray-300">
                                                    @if ($faaliyet['gerekli_revize'])
                                                        <div>Revize gerekli</div>
                                                    @endif
                                                    @if ($faaliyet['karar_ihtiyaci'] !== '')
                                                        <div>Karar: {{ $faaliyet['karar_ihtiyaci'] }}</div>
                                                    @endif
                                                    @if ($faaliyet['sapma_nedeni'] !== '')
                                                        <div>Sapma: {{ $faaliyet['sapma_nedeni'] }}</div>
                                                    @endif
                                                    @if (! $faaliyet['gerekli_revize'] && $faaliyet['karar_ihtiyaci'] === '' && $faaliyet['sapma_nedeni'] === '')
                                                        —
                                                    @endif
                                                </td>
                                            </tr>
                                            @if (! empty($faaliyet['kalemler']))
                                                <tr class="bg-gray-50/80 dark:bg-gray-800/40">
                                                    <td colspan="7" class="px-4 py-2">
                                                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Kapsam kalemleri</div>
                                                        <div class="mt-1 flex flex-wrap gap-2">
                                                            @foreach ($faaliyet['kalemler'] as $kalem)
                                                                <span class="rounded-md border border-gray-200 bg-white px-2 py-1 text-xs text-gray-700 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                                                    {{ $kalem['kalem'] }}:
                                                                    {{ (int) $kalem['gerceklesen'] }}/{{ (int) $kalem['gerceklesen'] + (int) $kalem['acikta'] }}
                                                                    · {{ $kalem['durum'] }}
                                                                </span>
                                                            @endforeach
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endif
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @elseif ($mudurluk['rapor_var'])
                            <div class="border-t border-gray-100 px-4 py-3 text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                Rapor kaydı var ancak faaliyet satırı bulunamadı.
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
