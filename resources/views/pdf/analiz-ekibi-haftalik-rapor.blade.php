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
        table.main { width: 100%; border-collapse: collapse; }
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
        <h2>Faaliyet bazında öngörülen / gerçekleşen</h2>
        <table class="main">
            <thead>
                <tr>
                    <th>Kod</th>
                    <th class="num">Öngörülen</th>
                    <th class="num">Gerçekleşen</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($chart['categories'] as $i => $kod)
                    <tr>
                        <td>{{ $kod }}</td>
                        <td class="num">{{ $fmt($chart['planned'][$i] ?? 0) }}</td>
                        <td class="num">{{ $fmt($chart['values'][$i] ?? 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
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
