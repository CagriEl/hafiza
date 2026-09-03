@php
    $ozet = $screen['ozet'] ?? [];
    $chart = $screen['chart'] ?? ['categories' => [], 'values' => [], 'planned' => [], 'max' => 0];
    $rows = $screen['rows'] ?? [];
    $uyarilar = $screen['uyarilar'] ?? [];
    $tavsiye = $screen['tavsiye'] ?? null;
    $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');
    $toneLabel = [
        'success' => 'Kapandı',
        'danger' => 'Açık',
        'warning' => 'Boş',
        'neutral' => 'İş yok',
    ];
    $groups = [];
    foreach ($rows as $row) {
        if (! is_array($row)) {
            continue;
        }
        $kod = (string) ($row['kod'] ?? '—');
        $groups[$kod]['aile'] = (string) ($row['aile'] ?? '');
        $groups[$kod]['rows'][] = $row;
    }
    $chartCats = $chart['categories'] ?? [];
    $chartN = count($chartCats);
    $svgW = 720;
    $svgH = 240;
    $padL = 8;
    $padR = 8;
    $padT = 28;
    $padB = 36;
    $plotW = $svgW - $padL - $padR;
    $plotH = $svgH - $padT - $padB;
    $slotW = $chartN > 0 ? $plotW / $chartN : $plotW;
@endphp

<style>
    .hf-ekran { --card:#fff; --text:#111827; --muted:#6b7280; --line:#e5e7eb; --ok:#16a34a; --warn:#d97706; --danger:#dc2626; --plan:#94a3b8; --real:#2563eb; color:var(--text); font-size:14px; line-height:1.45; }
    .hf-ekran .hf-head { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start; margin-bottom:16px; }
    .hf-ekran h2 { margin:0; font-size:20px; font-weight:700; letter-spacing:-0.02em; }
    .hf-ekran .hf-sub { color:var(--muted); font-size:13px; margin-top:4px; }
    .hf-ekran .hf-chip { border:1px solid var(--line); background:#fff; border-radius:999px; padding:5px 12px; font-size:12px; font-weight:600; }
    .hf-ekran .hf-ok { color:#166534; background:#dcfce7; border-color:#bbf7d0; }
    .hf-ekran .hf-warn { color:#92400e; background:#fef3c7; border-color:#fde68a; }
    .hf-ekran .hf-miss { color:#991b1b; background:#fee2e2; border-color:#fecaca; }
    .hf-ekran .hf-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
    .hf-ekran .hf-card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:14px 16px; }
    .hf-ekran .hf-kpi-l { color:var(--muted); font-size:12px; font-weight:500; margin-bottom:8px; }
    .hf-ekran .hf-kpi-v { font-size:28px; font-weight:700; line-height:1; letter-spacing:-0.03em; }
    .hf-ekran .hf-section { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:16px 18px; margin-bottom:14px; }
    .hf-ekran .hf-section h3 { margin:0 0 4px; font-size:15px; font-weight:700; }
    .hf-ekran .hf-hint { color:var(--muted); font-size:12px; margin-bottom:14px; }
    .hf-ekran .hf-legend { display:flex; gap:16px; flex-wrap:wrap; font-size:12px; color:var(--muted); margin-bottom:10px; }
    .hf-ekran .hf-legend b { display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:6px; vertical-align:middle; }
    .hf-ekran .hf-chart-wrap { overflow-x:auto; }
    .hf-ekran .hf-chart { width:100%; min-width:520px; height:240px; display:block; }
    .hf-ekran .hf-group { border:1px solid var(--line); border-radius:12px; margin-bottom:10px; overflow:hidden; }
    .hf-ekran .hf-group-h { display:flex; justify-content:space-between; gap:10px; align-items:baseline; flex-wrap:wrap; padding:10px 14px; background:#f8fafc; border-bottom:1px solid var(--line); }
    .hf-ekran .hf-group-h strong { font-size:14px; }
    .hf-ekran .hf-group-h span { color:var(--muted); font-size:12px; }
    .hf-ekran table { width:100%; border-collapse:collapse; }
    .hf-ekran th, .hf-ekran td { padding:10px 12px; font-size:13px; vertical-align:middle; }
    .hf-ekran th { text-align:left; color:#6b7280; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; background:#fff; border-bottom:1px solid var(--line); }
    .hf-ekran tbody tr + tr td { border-top:1px solid var(--line); }
    .hf-ekran tbody tr.t-success { background:#f0fdf4; }
    .hf-ekran tbody tr.t-danger { background:#fef2f2; }
    .hf-ekran tbody tr.t-warning { background:#fffbeb; }
    .hf-ekran tbody tr.t-neutral { background:#fff; }
    .hf-ekran .num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; font-weight:600; }
    .hf-ekran .hf-kalem { font-weight:600; display:block; }
    .hf-ekran .hf-olcu { color:var(--muted); font-size:12px; font-weight:500; }
    .hf-ekran .hf-badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; }
    .hf-ekran .b-success { background:#dcfce7; color:#166534; }
    .hf-ekran .b-danger { background:#fee2e2; color:#991b1b; }
    .hf-ekran .b-warning { background:#fef3c7; color:#92400e; }
    .hf-ekran .b-neutral { background:#f3f4f6; color:#4b5563; }
    .hf-ekran .hf-meter { width:92px; height:8px; border-radius:999px; background:#e5e7eb; overflow:hidden; display:inline-block; vertical-align:middle; }
    .hf-ekran .hf-meter > span { display:block; height:100%; background:#16a34a; border-radius:999px; }
    .hf-ekran .hf-meter.warn > span { background:#d97706; }
    .hf-ekran .hf-meter.bad > span { background:#dc2626; }
    .hf-ekran .hf-empty { color:var(--muted); padding:18px 4px; }
    .hf-ekran .hf-uyari { border-left:4px solid var(--warn); padding:10px 12px; background:#fffbeb; border-radius:8px; font-size:13px; margin-top:8px; }
    .hf-ekran .hf-tavsiye { border:1px solid var(--line); border-left-width:4px; border-radius:14px; padding:14px 16px; margin-bottom:14px; background:#fff; }
    .hf-ekran .hf-tavsiye.ok { border-left-color:var(--ok); }
    .hf-ekran .hf-tavsiye.orta { border-left-color:var(--warn); }
    .hf-ekran .hf-tavsiye.yuksek { border-left-color:var(--danger); }
    .hf-ekran .hf-tavsiye.info { border-left-color:#2563eb; }
    .hf-ekran .hf-tavsiye h3 { margin:0 0 6px; font-size:14px; }
    .hf-ekran .hf-tavsiye p { margin:0; font-size:13px; }
    .hf-ekran .hf-tavsiye ul { margin:8px 0 0; padding-left:18px; font-size:13px; }
    @media (max-width: 900px) { .hf-ekran .hf-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .dark .hf-ekran { --card:rgb(17 24 39); --text:#f3f4f6; --muted:#9ca3af; --line:#374151; --plan:#64748b; }
    .dark .hf-ekran .hf-chip, .dark .hf-ekran .hf-card, .dark .hf-ekran .hf-section, .dark .hf-ekran .hf-tavsiye, .dark .hf-ekran .hf-group { background:var(--card); color:var(--text); }
    .dark .hf-ekran .hf-group-h, .dark .hf-ekran th { background:rgb(31 41 55); color:#d1d5db; }
    .dark .hf-ekran tbody tr.t-success { background:rgba(22,163,74,.12); }
    .dark .hf-ekran tbody tr.t-danger { background:rgba(220,38,38,.12); }
    .dark .hf-ekran tbody tr.t-warning { background:rgba(217,119,6,.12); }
    .dark .hf-ekran .hf-meter { background:#374151; }
    .dark .hf-ekran .hf-uyari { background:rgb(66 32 6); }
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

        @if ($chartN > 0)
            <div class="hf-section">
                <h3>Faaliyet bazında öngörülen / gerçekleşen</h3>
                <div class="hf-hint">Her kod kendi ölçeğinde çizilir (adet ile tutanak karışmaz). Sütun yüksekliği o kodun planına göredir.</div>
                <div class="hf-legend">
                    <span><b style="background:var(--plan)"></b>Öngörülen</span>
                    <span><b style="background:var(--real)"></b>Gerçekleşen</span>
                </div>
                <div class="hf-chart-wrap">
                    <svg class="hf-chart" viewBox="0 0 {{ $svgW }} {{ $svgH }}" role="img" aria-label="Faaliyet bazında öngörülen ve gerçekleşen">
                        <line x1="{{ $padL }}" y1="{{ $padT + $plotH }}" x2="{{ $svgW - $padR }}" y2="{{ $padT + $plotH }}" stroke="#e5e7eb" stroke-width="1"/>
                        @foreach ($chartCats as $i => $kod)
                            @php
                                $plan = (float) ($chart['planned'][$i] ?? 0);
                                $real = (float) ($chart['values'][$i] ?? 0);
                                $localMax = max($plan, $real, 1);
                                $cx = $padL + ($i * $slotW) + ($slotW / 2);
                                $barW = min(22, $slotW * 0.28);
                                $gap = 4;
                                $planH = ($plan / $localMax) * $plotH;
                                $realH = ($real / $localMax) * $plotH;
                                $planX = $cx - $barW - ($gap / 2);
                                $realX = $cx + ($gap / 2);
                                $planY = $padT + $plotH - $planH;
                                $realY = $padT + $plotH - $realH;
                                $realColor = ($plan > 0 && $real + 0.0001 >= $plan) ? '#16a34a' : '#2563eb';
                            @endphp
                            <rect x="{{ $planX }}" y="{{ $planY }}" width="{{ $barW }}" height="{{ max(1, $planH) }}" rx="3" fill="#94a3b8"/>
                            <rect x="{{ $realX }}" y="{{ $realY }}" width="{{ $barW }}" height="{{ max(1, $realH) }}" rx="3" fill="{{ $realColor }}"/>
                            <text x="{{ $planX + $barW / 2 }}" y="{{ $planY - 4 }}" text-anchor="middle" font-size="9" fill="#64748b">{{ $fmt($plan) }}</text>
                            <text x="{{ $realX + $barW / 2 }}" y="{{ $realY - 4 }}" text-anchor="middle" font-size="9" fill="#1d4ed8" font-weight="700">{{ $fmt($real) }}</text>
                            <text x="{{ $cx }}" y="{{ $svgH - 14 }}" text-anchor="middle" font-size="11" font-weight="600" fill="#374151">{{ $kod }}</text>
                        @endforeach
                    </svg>
                </div>
            </div>
        @endif

        <div class="hf-section">
            <h3>Kapsam kalemleri</h3>
            <div class="hf-hint">Ölçü birimi kalemin altındadır (m², adet, tutanak, iş…). Yeşil kapandı, kırmızı açık, sarı henüz sayı yok.</div>

            @foreach ($groups as $kod => $group)
                <div class="hf-group">
                    <div class="hf-group-h">
                        <strong>{{ $kod }}</strong>
                        <span>{{ $group['aile'] !== '' ? $group['aile'] : '—' }} · {{ count($group['rows']) }} kalem</span>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Kalem</th>
                                <th>Ölçü</th>
                                <th class="num">Öngörülen</th>
                                <th class="num">Gerçekleşen</th>
                                <th class="num">Açıkta</th>
                                <th>Durum</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group['rows'] as $row)
                                @php
                                    $tone = (string) ($row['tone'] ?? 'neutral');
                                    $planN = $row['ongorulen_sayi'] ?? null;
                                    $doneN = $row['gerceklesen_sayi'] ?? null;
                                    $pct = (is_numeric($planN) && (float) $planN > 0 && is_numeric($doneN))
                                        ? (int) min(100, round(((float) $doneN / (float) $planN) * 100))
                                        : null;
                                    $meterClass = $pct === null ? '' : ($pct >= 100 ? '' : ($pct >= 50 ? 'warn' : 'bad'));
                                @endphp
                                <tr class="t-{{ $tone }}">
                                    <td>
                                        <span class="hf-kalem">{{ $row['kalem'] }}</span>
                                    </td>
                                    <td>
                                        <span class="hf-olcu">{{ ($row['olcu'] ?? '') !== '' ? $row['olcu'] : '—' }}</span>
                                    </td>
                                    <td class="num">{{ $row['ongorulen'] }}</td>
                                    <td class="num">
                                        {{ $row['gerceklesen'] }}
                                        @if ($pct !== null)
                                            <div class="hf-meter {{ $meterClass }}" title="%{{ $pct }}"><span style="width: {{ $pct }}%"></span></div>
                                        @endif
                                    </td>
                                    <td class="num">{{ $row['acikta'] }}</td>
                                    <td>
                                        <span class="hf-badge b-{{ $tone }}">{{ $toneLabel[$tone] ?? $tone }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach

            @foreach ($uyarilar as $uyari)
                <div class="hf-uyari">{{ $uyari }}</div>
            @endforeach
        </div>
    @endif
</div>
