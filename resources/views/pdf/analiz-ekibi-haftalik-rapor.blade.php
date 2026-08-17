<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Haftalık faaliyet raporu</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 14px 0 6px; }
        .muted { color: #4b5563; font-size: 10px; }
        .meta { width: 100%; border-collapse: collapse; margin: 8px 0 12px; }
        .meta td { border: 1px solid #d1d5db; padding: 5px 8px; }
        .meta .l { width: 22%; background: #f3f4f6; font-weight: bold; }
        .kpi { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .kpi td { border: 1px solid #d1d5db; padding: 8px; text-align: center; width: 25%; }
        .kpi .n { font-size: 9px; color: #6b7280; }
        .kpi .v { font-size: 16px; font-weight: bold; padding-top: 3px; }
        .legend { font-size: 9px; color: #4b5563; margin: 0 0 8px; }
        .swatch { display: inline-block; width: 9px; height: 9px; }
        .chart-wrap { width: 100%; margin: 0 0 14px; }
        table.bars { width: 100%; border-collapse: collapse; }
        table.bars td { text-align: center; vertical-align: bottom; padding: 0 3px; border: 0; }
        .bar-pair { margin: 0 auto; }
        .bar-col { display: inline-block; width: 18px; text-align: center; vertical-align: bottom; }
        .bar { width: 16px; margin: 0 auto; }
        .bar-plan { background-color: #94a3b8; }
        .bar-real { background-color: #2563eb; }
        .bar-ok { background-color: #16a34a; }
        .bar-label { font-size: 7px; color: #4b5563; padding-bottom: 2px; }
        .bar-kod { font-size: 8px; font-weight: bold; padding-top: 4px; }
        table.main th { background: #f3f4f6; font-size: 9px; text-align: left; padding: 5px 6px; border: 1px solid #d1d5db; }
        table.main td { padding: 5px 6px; border: 1px solid #e5e7eb; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .ghead td { background: #eef2ff; font-weight: bold; }
        .ok { background: #f0fdf4; }
        .danger { background: #fef2f2; }
        .warn { background: #fffbeb; }
        .box { border: 1px solid #d1d5db; border-left: 4px solid #2563eb; padding: 8px 10px; margin: 8px 0 12px; }
        .box.yuksek { border-left-color: #dc2626; }
        .box.ok { border-left-color: #16a34a; }
        .box.orta { border-left-color: #d97706; }
        ul { margin: 4px 0 0 16px; padding: 0; }
    </style>
</head>
<body>
@php
    $ozet = $screen['ozet'] ?? [];
    $rows = $screen['rows'] ?? [];
    $chart = $screen['chart'] ?? ['categories' => [], 'values' => [], 'planned' => []];
    $tavsiye = $screen['tavsiye'] ?? null;
    $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');
    $groups = [];
    foreach ($rows as $row) {
        if (! is_array($row)) {
            continue;
        }
        $kod = (string) ($row['kod'] ?? '—');
        $groups[$kod]['aile'] = (string) ($row['aile'] ?? '');
        $groups[$kod]['rows'][] = $row;
    }
    $toneClass = ['success' => 'ok', 'danger' => 'danger', 'warning' => 'warn', 'neutral' => ''];
    $toneLabel = ['success' => 'Kapandı', 'danger' => 'Açık', 'warning' => 'Boş', 'neutral' => 'İş yok'];
@endphp

<h1>Haftalık faaliyet raporu</h1>
<div class="muted">
    {{ $screen['mudurluk_adi'] ?? ($meta['mudurluk'] ?? '') }}
    @if (!empty($screen['donem_etiketi']))
        · {{ $screen['donem_etiketi'] }}
    @endif
    · {{ $screen['durum'] ?? '' }}
</div>

<table class="meta">
    <tr>
        <td class="l">Müdürlük</td>
        <td>{{ $meta['mudurluk'] ?? ($screen['mudurluk_adi'] ?? '—') }}</td>
        <td class="l">Analiz eden</td>
        <td>{{ $meta['analiz_eden'] ?? '—' }}</td>
    </tr>
    <tr>
        <td class="l">Dönem</td>
        <td>{{ $meta['donem'] ?? '—' }}</td>
        <td class="l">Hafta</td>
        <td>{{ $meta['rapor_haftasi'] ?? '—' }}</td>
    </tr>
    <tr>
        <td class="l">Analiz tarihi</td>
        <td colspan="3">{{ $meta['analiz_tarihi'] ?? '—' }}</td>
    </tr>
</table>

@if (is_array($tavsiye) && (($tavsiye['ozet'] ?? '') !== ''))
    <div class="box {{ $tavsiye['seviye'] ?? 'info' }}">
        <strong>{{ $tavsiye['baslik'] ?? 'Sistem tavsiyesi' }}</strong>
        <div>{{ $tavsiye['ozet'] }}</div>
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
    <p class="muted">
        @if (($screen['durum'] ?? '') === 'Yetki yok')
            Bu müdürlük raporunu yalnızca size atanan birimler için görebilirsiniz.
        @else
            Seçilen müdürlük, ay ve hafta için faaliyet raporu bulunamadı.
        @endif
    </p>
@else
    <table class="kpi">
        <tr>
            <td><div class="n">Faaliyet kodu</div><div class="v">{{ $fmt($ozet['kod_sayisi'] ?? 0) }}</div></td>
            <td><div class="n">Kapsam kalemi</div><div class="v">{{ $fmt($ozet['kalem_sayisi'] ?? 0) }}</div></td>
            <td><div class="n">Toplam gerçekleşen</div><div class="v">{{ $fmt($ozet['toplam_gerceklesen'] ?? 0) }}</div></td>
            <td><div class="n">Açıkta kalan kalem</div><div class="v">{{ $fmt($ozet['acikta_kalem_sayisi'] ?? 0) }}</div></td>
        </tr>
    </table>

    @if (($chart['categories'] ?? []) !== [])
        @php
            $chartCats = $chart['categories'];
            $barMaxH = 120;
        @endphp
        <h2>Faaliyet bazında öngörülen / gerçekleşen</h2>
        <div class="legend">
            <span style="background-color:#94a3b8;width:9px;height:9px;display:inline-block;"></span> Öngörülen
            &nbsp;&nbsp;
            <span style="background-color:#2563eb;width:9px;height:9px;display:inline-block;"></span> Gerçekleşen
            &nbsp;&nbsp;
            <span class="muted">Her kod kendi ölçeğinde çizilir.</span>
        </div>
        <table class="bars" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                @foreach ($chartCats as $i => $kod)
                    @php
                        $plan = (float) ($chart['planned'][$i] ?? 0);
                        $real = (float) ($chart['values'][$i] ?? 0);
                        $localMax = max($plan, $real, 1);
                        $planH = max(4, (int) round(($plan / $localMax) * $barMaxH));
                        $realH = max(4, (int) round(($real / $localMax) * $barMaxH));
                        $realColor = ($plan > 0 && $real + 0.0001 >= $plan) ? '#16a34a' : '#2563eb';
                    @endphp
                    <td align="center" valign="bottom" width="{{ (int) round(100 / max(1, count($chartCats))) }}%">
                        <table cellpadding="0" cellspacing="2" align="center" class="bar-pair">
                            <tr>
                                <td align="center" valign="bottom" width="20">
                                    <div class="bar-label">{{ $fmt($plan) }}</div>
                                    <table cellpadding="0" cellspacing="0"><tr>
                                        <td width="16" height="{{ $planH }}" bgcolor="#94a3b8" style="width:16px;height:{{ $planH }}px;background-color:#94a3b8;font-size:1px;line-height:{{ $planH }}px;">&nbsp;</td>
                                    </tr></table>
                                </td>
                                <td align="center" valign="bottom" width="20">
                                    <div class="bar-label">{{ $fmt($real) }}</div>
                                    <table cellpadding="0" cellspacing="0"><tr>
                                        <td width="16" height="{{ $realH }}" bgcolor="{{ $realColor }}" style="width:16px;height:{{ $realH }}px;background-color:{{ $realColor }};font-size:1px;line-height:{{ $realH }}px;">&nbsp;</td>
                                    </tr></table>
                                </td>
                            </tr>
                        </table>
                        <div class="bar-kod">{{ $kod }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    <h2>Kapsam kalemleri</h2>
    <table class="main">
        <thead>
            <tr>
                <th>Kod</th>
                <th>Kalem</th>
                <th>Ölçü</th>
                <th class="num">Öngörülen</th>
                <th class="num">Gerçekleşen</th>
                <th class="num">Açıkta</th>
                <th>Durum</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($groups as $kod => $group)
                <tr class="ghead">
                    <td colspan="7">{{ $kod }} — {{ $group['aile'] !== '' ? $group['aile'] : '—' }}</td>
                </tr>
                @foreach ($group['rows'] as $row)
                    <tr class="{{ $toneClass[$row['tone'] ?? 'neutral'] ?? '' }}">
                        <td>{{ $row['kod'] }}</td>
                        <td>{{ $row['kalem'] }}</td>
                        <td>{{ ($row['olcu'] ?? '') !== '' ? $row['olcu'] : '—' }}</td>
                        <td class="num">{{ $row['ongorulen'] }}</td>
                        <td class="num">{{ $row['gerceklesen'] }}</td>
                        <td class="num">{{ $row['acikta'] }}</td>
                        <td>{{ $toneLabel[$row['tone'] ?? 'neutral'] ?? '' }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
@endif

@if ($noteText !== '')
    <h2>Analiz notu ve bulgular</h2>
    <div class="box">{{ $noteText }}</div>
@endif

<div class="muted" style="margin-top:16px;">Kırklareli Belediyesi · Hafıza · {{ now()->format('d.m.Y H:i') }}</div>
</body>
</html>
