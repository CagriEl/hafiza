@php
    $ozet = $screen['ozet'] ?? [];
    $chart = $screen['chart'] ?? ['categories' => [], 'values' => [], 'planned' => [], 'acikta' => []];
    $haftalar = $screen['haftalar'] ?? [];
    $rows = $screen['rows'] ?? [];
    $kodlar = $screen['kodlar'] ?? [];
    $ayIci = $screen['ay_ici'] ?? [];
    $tavsiye = $screen['tavsiye'] ?? null;
    $kod = (string) ($kod ?? 'all');
    $selected = null;
    foreach ($kodlar as $k) {
        if (is_array($k) && (string) ($k['value'] ?? '') === $kod) {
            $selected = $k;
            break;
        }
    }
    $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');
    $chip = (string) ($screen['durum_chip'] ?? $screen['durum'] ?? '—');
    $zayifHafta = (int) ($ozet['en_zayif']['hafta'] ?? 0);
    $gucluHafta = (int) ($ozet['en_guclu']['hafta'] ?? 0);
    $chartCats = $chart['categories'] ?? [];
    $chartN = count($chartCats);
    $svgW = 720;
    $svgH = 220;
    $padL = 28;
    $padR = 16;
    $padT = 24;
    $padB = 32;
    $plotW = $svgW - $padL - $padR;
    $plotH = $svgH - $padT - $padB;
    $yMax = 110;
    $slotW = $chartN > 0 ? $plotW / $chartN : $plotW;
    $yOf = fn (float $v) => $padT + $plotH * (1 - max(0, min($yMax, $v)) / $yMax);
    $hideTitle = (bool) ($hideTitle ?? false);
@endphp

<style>
    .hf-ekran { --card:#fff; --text:#111827; --muted:#6b7280; --line:#e5e7eb; --ok:#16a34a; --warn:#d97706; --danger:#dc2626; --plan:#94a3b8; --real:#2563eb; color:var(--text); font-size:14px; line-height:1.45; }
    .hf-ekran .hf-head { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start; margin-bottom:8px; }
    .hf-ekran h2 { margin:0; font-size:22px; font-weight:700; letter-spacing:-0.02em; }
    .hf-ekran .hf-sub { color:var(--muted); font-size:13px; margin-top:4px; }
    .hf-ekran .hf-chip { border:1px solid var(--line); background:#fff; border-radius:999px; padding:5px 12px; font-size:12px; font-weight:600; }
    .hf-ekran .hf-ok { color:#166534; background:#dcfce7; border-color:#bbf7d0; }
    .hf-ekran .hf-warn { color:#92400e; background:#fef3c7; border-color:#fde68a; }
    .hf-ekran .hf-miss { color:#991b1b; background:#fee2e2; border-color:#fecaca; }
    .hf-ekran .hf-kpis { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px; margin:16px 0; }
    .hf-ekran .hf-card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:14px 16px; }
    .hf-ekran .hf-kpi-l { color:var(--muted); font-size:12px; font-weight:500; margin-top:8px; }
    .hf-ekran .hf-kpi-v { font-size:26px; font-weight:700; line-height:1; letter-spacing:-0.03em; }
    .hf-ekran .hf-section { margin-bottom:20px; }
    .hf-ekran .hf-section h3 { margin:0 0 4px; font-size:16px; font-weight:700; }
    .hf-ekran .hf-hint { color:var(--muted); font-size:12px; margin-bottom:12px; }
    .hf-ekran .hf-legend { display:flex; gap:16px; flex-wrap:wrap; font-size:12px; color:var(--muted); margin-bottom:10px; }
    .hf-ekran .hf-legend b { display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:6px; vertical-align:middle; }
    .hf-ekran .hf-chart-wrap { overflow-x:auto; }
    .hf-ekran .hf-chart { width:100%; min-width:480px; height:220px; display:block; }
    .hf-ekran table { width:100%; border-collapse:collapse; }
    .hf-ekran th, .hf-ekran td { padding:9px 10px; font-size:13px; vertical-align:middle; }
    .hf-ekran th { text-align:left; color:#6b7280; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; background:#fff; border-bottom:1px solid var(--line); }
    .hf-ekran tbody tr + tr td { border-top:1px solid var(--line); }
    .hf-ekran tbody tr.t-success { background:#f0fdf4; }
    .hf-ekran tbody tr.t-danger { background:#fef2f2; }
    .hf-ekran tbody tr.t-warning { background:#fffbeb; }
    .hf-ekran .num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; font-weight:600; }
    .hf-ekran .hf-badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; }
    .hf-ekran .b-success { background:#dcfce7; color:#166534; }
    .hf-ekran .b-danger { background:#fee2e2; color:#991b1b; }
    .hf-ekran .b-warning { background:#fef3c7; color:#92400e; }
    .hf-ekran .b-neutral { background:#f3f4f6; color:#4b5563; }
    .hf-ekran .hf-empty { color:var(--muted); padding:18px 4px; }
    .hf-ekran .hf-tavsiye { border:1px solid var(--line); border-left-width:4px; border-radius:14px; padding:14px 16px; margin-top:8px; background:#fff; }
    .hf-ekran .hf-tavsiye.ok { border-left-color:var(--ok); }
    .hf-ekran .hf-tavsiye.orta { border-left-color:var(--warn); }
    .hf-ekran .hf-tavsiye.yuksek { border-left-color:var(--danger); }
    .hf-ekran .hf-tavsiye.info { border-left-color:#2563eb; }
    .hf-ekran .hf-tavsiye h3 { margin:0 0 6px; font-size:14px; }
    .hf-ekran .hf-tavsiye p { margin:0; font-size:13px; }
    .hf-ekran .hf-tavsiye ul { margin:8px 0 0; padding-left:18px; font-size:13px; }
    .hf-ekran .hf-filter { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin:16px 0; }
    .hf-ekran .hf-filter label { font-weight:600; }
    .hf-ekran .hf-filter select { border:1px solid var(--line); border-radius:8px; padding:6px 10px; background:var(--card); color:var(--text); min-width:240px; }
    .hf-ekran .hf-split { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }
    .hf-ekran .hf-durum { display:flex; flex-direction:column; gap:10px; }
    .hf-ekran .hf-durum-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .hf-ekran .hf-hr { border:0; border-top:1px solid var(--line); margin:8px 0 20px; }
    .hf-ekran .hf-table-wrap { overflow-x:auto; }
    .hf-ekran .hf-kpi { font-size:12px; color:var(--muted); }
    @media (max-width: 1100px) { .hf-ekran .hf-kpis { grid-template-columns:repeat(3,minmax(0,1fr)); } .hf-ekran .hf-split { grid-template-columns:1fr; } }
    @media (max-width: 700px) { .hf-ekran .hf-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .dark .hf-ekran { --card:rgb(17 24 39); --text:#f3f4f6; --muted:#9ca3af; --line:#374151; --plan:#64748b; }
    .dark .hf-ekran .hf-chip, .dark .hf-ekran .hf-card, .dark .hf-ekran .hf-tavsiye, .dark .hf-ekran .hf-filter select { background:var(--card); color:var(--text); }
    .dark .hf-ekran th { background:rgb(31 41 55); color:#d1d5db; }
    .dark .hf-ekran tbody tr.t-success { background:rgba(22,163,74,.12); }
    .dark .hf-ekran tbody tr.t-danger { background:rgba(220,38,38,.12); }
    .dark .hf-ekran tbody tr.t-warning { background:rgba(217,119,6,.12); }
</style>

<div class="hf-ekran">
    @unless ($hideTitle)
        <div class="hf-head">
            <div>
                <h2>{{ $screen['baslik'] !== '' ? $screen['baslik'] : 'Aylık karşılaştırma' }}</h2>
                <div class="hf-sub">Aylık karşılaştırma · Analiz Notları içindeki bağlantıdan açılır</div>
            </div>
            <span class="hf-chip {{ str_contains($chip, 'Tamamlandı') ? 'hf-ok' : (($screen['rapor_var'] ?? false) ? 'hf-warn' : 'hf-miss') }}">{{ $chip }}</span>
        </div>
    @else
        <div class="hf-head">
            <div class="hf-sub">Aylık karşılaştırma · Analiz Notları içindeki bağlantıdan açılır</div>
            <span class="hf-chip {{ str_contains($chip, 'Tamamlandı') ? 'hf-ok' : (($screen['rapor_var'] ?? false) ? 'hf-warn' : 'hf-miss') }}">{{ $chip }}</span>
        </div>
    @endunless

    @if (($screen['durum'] ?? '') === 'Yetki yok' || !($screen['rapor_var'] ?? false))
        @if (is_array($tavsiye) && (($tavsiye['ozet'] ?? '') !== ''))
            <div class="hf-tavsiye {{ $tavsiye['seviye'] ?? 'info' }}">
                <h3>{{ $tavsiye['baslik'] ?? 'Sistem tavsiyesi' }}</h3>
                <p>{{ $tavsiye['ozet'] }}</p>
            </div>
        @endif
        <div class="hf-empty">
            @if (($screen['durum'] ?? '') === 'Yetki yok')
                Bu müdürlük raporunu yalnızca size atanan birimler için görebilirsiniz.
            @else
                Seçilen müdürlük ve ay için haftalık faaliyet raporu bulunamadı.
            @endif
        </div>
    @else
        <div class="hf-filter">
            <label for="hf-kod">Faaliyet</label>
            <select id="hf-kod" wire:model.live="kod">
                <option value="all">Tüm kodlar — SLA özeti</option>
                @foreach ($kodlar as $k)
                    <option value="{{ $k['value'] }}">{{ $k['label'] }}</option>
                @endforeach
            </select>
        </div>

        <div class="hf-kpis">
            <div class="hf-card">
                <div class="hf-kpi-v">{{ $fmt($ozet['raporlanan_hafta'] ?? 0) }} / {{ $fmt($ozet['beklenen_hafta'] ?? 0) }}</div>
                <div class="hf-kpi-l">Raporlanan hafta</div>
            </div>
            <div class="hf-card">
                <div class="hf-kpi-v">{{ ($ozet['sla_orani'] ?? null) !== null ? '%'.$ozet['sla_orani'] : '—' }}</div>
                <div class="hf-kpi-l">Ay ortalama SLA</div>
            </div>
            <div class="hf-card">
                <div class="hf-kpi-v" style="color:var(--danger)">{{ isset($ozet['en_zayif']['oran']) ? '%'.$ozet['en_zayif']['oran'] : '—' }}</div>
                <div class="hf-kpi-l">En zayıf hafta{{ $zayifHafta > 0 ? ' ('.$zayifHafta.'.)' : '' }}</div>
            </div>
            <div class="hf-card">
                <div class="hf-kpi-v" style="color:var(--ok)">{{ isset($ozet['en_guclu']['oran']) ? '%'.$ozet['en_guclu']['oran'] : '—' }}</div>
                <div class="hf-kpi-l">En güçlü hafta{{ $gucluHafta > 0 ? ' ('.$gucluHafta.'.)' : '' }}</div>
            </div>
            <div class="hf-card">
                <div class="hf-kpi-v" style="color:var(--danger)">{{ isset($ozet['en_zayif']['acikta']) ? $fmt($ozet['en_zayif']['acikta']) : $fmt($ozet['acikta_kalem_sayisi'] ?? 0) }}</div>
                <div class="hf-kpi-l">{{ $zayifHafta > 0 ? $zayifHafta.'. haftadaki açık kalem' : 'Açık kalem' }}</div>
            </div>
        </div>

        @if ($chartN > 0)
            <div class="hf-section">
                <h3>Haftalık SLA gerçekleşme oranı (%)</h3>
                <div class="hf-hint">SLA = planı olan kalemlerde gerçekleşen / öngörülen. Eşik %100 (hedef kapanışı). Birimler karıştırılmaz.</div>
                <div class="hf-legend">
                    <span><b style="background:#16a34a"></b>SLA eşiği %100</span>
                    <span><b style="background:#2563eb"></b>SLA gerçekleşme</span>
                </div>
                <div class="hf-chart-wrap">
                    <svg class="hf-chart" viewBox="0 0 {{ $svgW }} {{ $svgH }}" role="img" aria-label="Haftalık SLA gerçekleşme oranı">
                        <line x1="{{ $padL }}" y1="{{ $padT + $plotH }}" x2="{{ $svgW - $padR }}" y2="{{ $padT + $plotH }}" stroke="#e5e7eb" stroke-width="1"/>
                        @php $y100 = $yOf(100); @endphp
                        <line x1="{{ $padL }}" y1="{{ $y100 }}" x2="{{ $svgW - $padR }}" y2="{{ $y100 }}" stroke="#16a34a" stroke-width="1" stroke-dasharray="5 4"/>
                        <text x="{{ $svgW - $padR }}" y="{{ $y100 - 4 }}" text-anchor="end" font-size="10" fill="#16a34a">SLA eşiği %100</text>
                        @php
                            $pts = [];
                            foreach ($chartCats as $i => $etiket) {
                                $x = $padL + ($i * $slotW) + ($slotW / 2);
                                $v = (float) ($chart['values'][$i] ?? 0);
                                $pts[] = $x.','.$yOf($v);
                            }
                        @endphp
                        <polyline points="{{ implode(' ', $pts) }}" fill="none" stroke="#2563eb" stroke-width="2.5"/>
                        @foreach ($chartCats as $i => $etiket)
                            @php
                                $x = $padL + ($i * $slotW) + ($slotW / 2);
                                $v = (float) ($chart['values'][$i] ?? 0);
                                $y = $yOf($v);
                                $var = (bool) ($haftalar[$i]['rapor_var'] ?? false);
                            @endphp
                            <circle cx="{{ $x }}" cy="{{ $y }}" r="4" fill="#2563eb"/>
                            <text x="{{ $x }}" y="{{ $y - 8 }}" text-anchor="middle" font-size="11" font-weight="700" fill="#1d4ed8">{{ $var ? '%'.$fmt($v) : '—' }}</text>
                            <text x="{{ $x }}" y="{{ $svgH - 10 }}" text-anchor="middle" font-size="11" font-weight="600" fill="#374151">{{ $etiket }}</text>
                        @endforeach
                    </svg>
                </div>
            </div>
        @endif

        <div class="hf-split">
            <div>
                <h3>{{ $selected ? ($selected['value'].' öngörülen / gerçekleşen'.(($selected['olcu'] ?? '') !== '' ? ' ('.$selected['olcu'].')' : '')) : 'Haftaya göre açık kalem' }}</h3>
                <div class="hf-hint">
                    @if ($selected)
                        KPI / SLA: {{ ($selected['kpi'] ?? '') !== '' ? $selected['kpi'] : 'Gerçekleşen ≥ öngörülen' }}.
                    @else
                        Açık kalem = öngörülen tamamlanmamış veya sayı girilmemiş satır. Düşük sayı iyidir.
                    @endif
                </div>
                @php
                    $barSeries = $selected
                        ? [['name' => 'Öngörülen', 'data' => $selected['plan'] ?? [], 'color' => '#94a3b8'], ['name' => 'Gerçekleşen', 'data' => $selected['gercek'] ?? [], 'color' => '#2563eb']]
                        : [['name' => 'Açık kalem (adet)', 'data' => $chart['acikta'] ?? [], 'color' => '#dc2626']];
                    $barH = 180;
                    $bPadT = 24;
                    $bPadB = 32;
                    $bPlotH = $barH - $bPadT - $bPadB;
                    $allVals = [];
                    foreach ($barSeries as $s) {
                        foreach ($s['data'] as $v) {
                            $allVals[] = (float) $v;
                        }
                    }
                    $barMax = max(1, ...($allVals !== [] ? $allVals : [1]));
                    $bN = max(1, $chartN);
                    $bSlot = $plotW / $bN;
                @endphp
                <div class="hf-legend">
                    @foreach ($barSeries as $s)
                        <span><b style="background:{{ $s['color'] }}"></b>{{ $s['name'] }}</span>
                    @endforeach
                </div>
                <div class="hf-chart-wrap">
                    <svg class="hf-chart" viewBox="0 0 {{ $svgW }} {{ $barH }}" role="img">
                        <line x1="{{ $padL }}" y1="{{ $bPadT + $bPlotH }}" x2="{{ $svgW - $padR }}" y2="{{ $bPadT + $bPlotH }}" stroke="#e5e7eb" stroke-width="1"/>
                        @for ($i = 0; $i < $bN; $i++)
                            @php $cx = $padL + ($i * $bSlot) + ($bSlot / 2); $seriesN = count($barSeries); $barW = min(22, $bSlot * 0.28); $gap = 4; @endphp
                            @foreach ($barSeries as $si => $s)
                                @php
                                    $v = (float) ($s['data'][$i] ?? 0);
                                    $h = ($v / $barMax) * $bPlotH;
                                    $x = $seriesN === 1 ? ($cx - $barW / 2) : ($cx - ($barW + $gap / 2) + ($si * ($barW + $gap)));
                                    $y = $bPadT + $bPlotH - $h;
                                @endphp
                                <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barW }}" height="{{ max(1, $h) }}" rx="3" fill="{{ $s['color'] }}"/>
                                <text x="{{ $x + $barW / 2 }}" y="{{ $y - 4 }}" text-anchor="middle" font-size="9" fill="#64748b">{{ $fmt($v) }}</text>
                            @endforeach
                            <text x="{{ $cx }}" y="{{ $barH - 10 }}" text-anchor="middle" font-size="11" font-weight="600" fill="#374151">{{ $chartCats[$i] ?? '' }}</text>
                        @endfor
                    </svg>
                </div>
            </div>
            <div>
                <h3>Ay içi durum</h3>
                <div class="hf-hint">Raporlanan haftaların SLA dağılımı. Eksik hafta özetin dışında tutulur.</div>
                <div class="hf-durum">
                    @foreach ($ayIci as $item)
                        <div class="hf-durum-row">
                            <span class="hf-badge b-{{ $item['tone'] ?? 'neutral' }}">{{ $item['baslik'] }}</span>
                            <span class="hf-kpi">{{ $item['aciklama'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <hr class="hf-hr">

        <div class="hf-section">
            <h3>Hafta özeti</h3>
            <div class="hf-hint">Her satır bir haftalık faaliyet raporu. Ölçü karışmasın diye burada plan toplamı yok; SLA ve açık kalem karşılaştırılır.</div>
            <table>
                <thead>
                    <tr>
                        <th>Hafta</th>
                        <th>Tarih aralığı</th>
                        <th>Rapor</th>
                        <th class="num">SLA</th>
                        <th class="num">Açık kalem</th>
                        <th>Durum</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($haftalar as $hafta)
                        @php
                            $slaTone = ! ($hafta['rapor_var'] ?? false)
                                ? 'neutral'
                                : (((int) ($hafta['sla'] ?? 0) >= 100) ? 'success' : (((int) ($hafta['sla'] ?? 0) < 70) ? 'danger' : 'warning'));
                        @endphp
                        <tr class="t-{{ $slaTone }}">
                            <td>{{ $hafta['kisa'] }}</td>
                            <td>{{ $hafta['range'] !== '' ? $hafta['range'] : '—' }}</td>
                            <td>{{ ($hafta['rapor_var'] ?? false) ? 'Var' : 'Yok' }}</td>
                            <td class="num">{{ ($hafta['sla'] ?? null) !== null ? '%'.$hafta['sla'] : '—' }}</td>
                            <td class="num">{{ $hafta['acikta'] !== null ? $fmt($hafta['acikta']) : '—' }}</td>
                            <td><span class="hf-badge b-{{ $slaTone }}">{{ $hafta['durum'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="hf-section">
            <h3>Kalem bazında KPI / SLA</h3>
            <div class="hf-hint">Hücreler gerçekleşen/öngörülen. Ay sütunu girilen haftaları toplar. Katalogdaki “KPI / SLA hedefi” metni kalemin yanına yazılır; sayısal eşik gerçekleşen ≥ öngörülen kabul edilir.</div>
            <div class="hf-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Kod</th>
                            <th>Kalem</th>
                            <th>Ölçü</th>
                            <th>KPI / SLA</th>
                            @foreach ($haftalar as $hafta)
                                <th class="num">H{{ $hafta['hafta'] }}</th>
                            @endforeach
                            <th class="num">Ay</th>
                            <th class="num">Oran</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="t-{{ $row['tone'] ?? 'neutral' }}">
                                <td>{{ $row['kod'] }}</td>
                                <td>{{ $row['kalem'] }}</td>
                                <td>{{ ($row['olcu'] ?? '') !== '' ? $row['olcu'] : '—' }}</td>
                                <td>{{ $row['kpi'] }}</td>
                                @foreach (($row['cells'] ?? []) as $cell)
                                    <td class="num">{{ $cell }}</td>
                                @endforeach
                                <td class="num">{{ $row['ay'] }}</td>
                                <td class="num"><span class="hf-badge b-{{ $row['tone'] ?? 'neutral' }}">{{ $row['sla'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if (is_array($tavsiye) && (($tavsiye['ozet'] ?? '') !== ''))
            <div class="hf-section">
                <h3>Önerilen sistem tavsiyesi</h3>
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
            </div>
        @endif
    @endif
</div>
