@php
    $ozet = $screen['ozet'] ?? [];
    $chart = $screen['chart'] ?? ['categories' => [], 'values' => [], 'max' => 0];
    $rows = $screen['rows'] ?? [];
    $uyarilar = $screen['uyarilar'] ?? [];
    $tavsiye = $screen['tavsiye'] ?? null;
    $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');
    $toneDot = [
        'success' => '#16a34a',
        'danger' => '#dc2626',
        'warning' => '#d97706',
        'neutral' => '#9ca3af',
    ];
@endphp

<style>
    .hf-ekran { --card:#fff; --text:#1f2937; --muted:#6b7280; --line:#e5e7eb; --ok:#16a34a; --warn:#d97706; --danger:#dc2626; color:var(--text); }
    .hf-ekran .hf-head { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start; margin-bottom:14px; }
    .hf-ekran h2 { margin:0; font-size:18px; font-weight:700; }
    .hf-ekran .hf-sub { color:var(--muted); font-size:13px; margin-top:4px; }
    .hf-ekran .hf-chip { border:1px solid var(--line); background:#fff; border-radius:999px; padding:5px 10px; font-size:12px; font-weight:600; }
    .hf-ekran .hf-ok { color:#166534; background:#dcfce7; border-color:#bbf7d0; }
    .hf-ekran .hf-warn { color:#92400e; background:#fef3c7; border-color:#fde68a; }
    .hf-ekran .hf-miss { color:#991b1b; background:#fee2e2; border-color:#fecaca; }
    .hf-ekran .hf-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:14px; }
    .hf-ekran .hf-card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:12px; }
    .hf-ekran .hf-kpi-l { color:var(--muted); font-size:12px; margin-bottom:6px; }
    .hf-ekran .hf-kpi-v { font-size:26px; font-weight:700; line-height:1; }
    .hf-ekran .hf-section { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:14px; margin-bottom:12px; }
    .hf-ekran .hf-section h3 { margin:0 0 6px; font-size:14px; font-weight:700; }
    .hf-ekran .hf-hint { color:var(--muted); font-size:12px; margin-bottom:10px; }
    .hf-ekran .hf-bars { display:grid; gap:8px; }
    .hf-ekran .hf-bar-row { display:grid; grid-template-columns:72px 1fr 64px; gap:8px; align-items:center; font-size:12px; }
    .hf-ekran .hf-bar { height:10px; border-radius:999px; background:#e5e7eb; overflow:hidden; }
    .hf-ekran .hf-bar > span { display:block; height:100%; background:#2563eb; border-radius:999px; }
    .hf-ekran table { width:100%; border-collapse:collapse; }
    .hf-ekran th, .hf-ekran td { border-bottom:1px solid var(--line); padding:9px 8px; font-size:13px; vertical-align:top; }
    .hf-ekran th { text-align:left; color:#374151; background:#f9fafb; font-weight:600; }
    .hf-ekran .num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
    .hf-ekran .hf-kalem { font-weight:600; }
    .hf-ekran .hf-olcu { display:inline-block; margin-left:6px; padding:1px 8px; border-radius:999px; border:1px solid var(--line); background:#f3f4f6; color:#374151; font-size:11px; font-weight:600; white-space:nowrap; }
    .hf-ekran .hf-dot { display:inline-block; width:8px; height:8px; border-radius:999px; margin-right:6px; }
    .hf-ekran .hf-empty { color:var(--muted); padding:16px 4px; font-size:13px; }
    .hf-ekran .hf-uyari { border-left:4px solid var(--warn); padding:10px 12px; background:#fffbeb; border-radius:8px; font-size:13px; margin-top:8px; }
    .hf-ekran .hf-tavsiye { border:1px solid var(--line); border-left-width:4px; border-radius:12px; padding:12px 14px; margin-bottom:14px; background:#fff; }
    .hf-ekran .hf-tavsiye.ok { border-left-color:var(--ok); }
    .hf-ekran .hf-tavsiye.orta { border-left-color:var(--warn); }
    .hf-ekran .hf-tavsiye.yuksek { border-left-color:var(--danger); }
    .hf-ekran .hf-tavsiye.info { border-left-color:#2563eb; }
    .hf-ekran .hf-tavsiye h3 { margin:0 0 6px; font-size:14px; }
    .hf-ekran .hf-tavsiye p { margin:0; font-size:13px; line-height:1.45; }
    .hf-ekran .hf-tavsiye ul { margin:8px 0 0; padding-left:18px; font-size:13px; }
    @media (max-width: 900px) { .hf-ekran .hf-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .dark .hf-ekran { --card:rgb(17 24 39); --text:#f3f4f6; --muted:#9ca3af; --line:#374151; }
    .dark .hf-ekran .hf-chip, .dark .hf-ekran .hf-card, .dark .hf-ekran .hf-section { background:var(--card); color:var(--text); }
    .dark .hf-ekran th { background:rgb(31 41 55); color:#d1d5db; }
    .dark .hf-ekran .hf-olcu { background:rgb(31 41 55); color:#d1d5db; }
    .dark .hf-ekran .hf-bar { background:#374151; }
    .dark .hf-ekran .hf-uyari { background:rgb(66 32 6); }
    .dark .hf-ekran .hf-tavsiye { background:var(--card); }
</style>

<div class="hf-ekran">
    <div class="hf-head">
        <div>
            <h2>Haftalık faaliyet raporu</h2>
            <div class="hf-sub">
                {{ $screen['mudurluk_adi'] !== '' ? $screen['mudurluk_adi'] : 'Müdürlük seçin' }}
                @if (!empty($screen['donem_etiketi']))
                    · {{ $screen['donem_etiketi'] }}
                @endif
            </div>
        </div>
        <span class="hf-chip {{ ($screen['durum'] ?? '') === 'Tamamlandı' ? 'hf-ok' : (($screen['rapor_var'] ?? false) ? 'hf-warn' : 'hf-miss') }}">
            {{ $screen['durum'] ?? '—' }}
        </span>
    </div>

    @if (is_array($tavsiye) && (($tavsiye['ozet'] ?? '') !== ''))
        <div class="hf-tavsiye {{ $tavsiye['seviye'] ?? 'info' }}">
            <h3>{{ $tavsiye['baslik'] ?? 'Sistem tavsiyesi' }}</h3>
            <p>{{ $tavsiye['ozet'] }}</p>
            @if (($tavsiye['maddeler'] ?? []) !== [])
                <ul>
                    @foreach ($tavsiye['maddeler'] as $madde)
                        <li>{{ $madde }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if (!($screen['rapor_var'] ?? false))
        <div class="hf-section">
            <div class="hf-empty">
                @if (($screen['durum'] ?? '') === 'Yetki yok')
                    Bu müdürlük raporunu yalnızca size atanan birimler için görebilirsiniz.
                @else
                    Seçilen müdürlük, ay ve hafta için faaliyet raporu bulunamadı. Ay ve hafta (tarih aralığı) seçimini kontrol edin.
                @endif
            </div>
        </div>
    @else
        <div class="hf-kpis">
            <div class="hf-card">
                <div class="hf-kpi-l">Faaliyet kodu</div>
                <div class="hf-kpi-v">{{ $fmt($ozet['kod_sayisi'] ?? 0) }}</div>
            </div>
            <div class="hf-card">
                <div class="hf-kpi-l">Kapsam kalemi</div>
                <div class="hf-kpi-v">{{ $fmt($ozet['kalem_sayisi'] ?? 0) }}</div>
            </div>
            <div class="hf-card">
                <div class="hf-kpi-l">Toplam gerçekleşen</div>
                <div class="hf-kpi-v">{{ $fmt($ozet['toplam_gerceklesen'] ?? 0) }}</div>
            </div>
            <div class="hf-card">
                <div class="hf-kpi-l">Açıkta kalan kalem</div>
                <div class="hf-kpi-v" style="color: {{ ((int) ($ozet['acikta_kalem_sayisi'] ?? 0)) === 0 ? 'var(--ok)' : 'var(--danger)' }}">
                    {{ $fmt($ozet['acikta_kalem_sayisi'] ?? 0) }}
                </div>
            </div>
        </div>

        @if (($chart['categories'] ?? []) !== [])
            <div class="hf-section">
                <h3>Faaliyet bazında gerçekleşen iş</h3>
                <div class="hf-hint">Kalem gerçekleşenlerinin toplamı · birimler faaliyet içinde aynı, faaliyetler arası toplanmaz; karşılaştırma hacim içindir</div>
                <div class="hf-bars">
                    @foreach ($chart['categories'] as $i => $kod)
                        @php
                            $val = (float) ($chart['values'][$i] ?? 0);
                            $max = (float) ($chart['max'] ?? 0);
                            $pct = $max > 0 ? (int) round(($val / $max) * 100) : 0;
                        @endphp
                        <div class="hf-bar-row">
                            <div>{{ $kod }}</div>
                            <div class="hf-bar"><span style="width: {{ $pct }}%"></span></div>
                            <div class="num">{{ $fmt($val) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="hf-section">
            <h3>Kapsam kalemleri</h3>
            <div class="hf-hint">Her kalemin yanında katalog ölçü birimi (m², adet, tutanak, iş vb.) görünür. Yeşil: plan = gerçekleşen. Sarı: henüz sayı yok. Kırmızı: açıkta iş var.</div>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Kod</th>
                            <th>Faaliyet ailesi</th>
                            <th>Kalem</th>
                            <th>Ölçü</th>
                            <th class="num">Öngörülen</th>
                            <th class="num">Gerçekleşen</th>
                            <th class="num">Açıkta</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>
                                    <span class="hf-dot" style="background: {{ $toneDot[$row['tone'] ?? 'neutral'] ?? $toneDot['neutral'] }}"></span>
                                    {{ $row['kod'] }}
                                </td>
                                <td>{{ $row['aile'] !== '' ? $row['aile'] : '—' }}</td>
                                <td>
                                    <span class="hf-kalem">{{ $row['kalem'] }}</span>
                                    @if (($row['olcu'] ?? '') !== '')
                                        <span class="hf-olcu">{{ $row['olcu'] }}</span>
                                    @endif
                                </td>
                                <td>{{ $row['olcu'] !== '' ? $row['olcu'] : '—' }}</td>
                                <td class="num">{{ $row['ongorulen'] }}</td>
                                <td class="num">{{ $row['gerceklesen'] }}</td>
                                <td class="num">{{ $row['acikta'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @foreach ($uyarilar as $uyari)
                <div class="hf-uyari">{{ $uyari }}</div>
            @endforeach
        </div>
    @endif
</div>
