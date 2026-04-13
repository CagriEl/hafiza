<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Kullanım Rehberi</title>
    <style>
        @page { margin: 22pt 28pt; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5pt; color: #292524; line-height: 1.5; }
        .doc-title { font-size: 20pt; font-weight: bold; color: #1c1917; margin: 0 0 4pt 0; letter-spacing: -0.02em; }
        .doc-sub { font-size: 9.5pt; color: #78716c; margin: 0 0 18pt 0; padding-bottom: 12pt; border-bottom: 2pt solid #f59e0b; }
        .intro { background: #fffbeb; border: 1pt solid #fcd34d; border-radius: 6pt; padding: 12pt 14pt; margin-bottom: 20pt; }
        .intro strong { font-size: 11pt; color: #92400e; }
        .intro p { margin: 8pt 0 0 0; color: #44403c; }
        h2 { font-size: 14pt; color: #1c1917; margin: 22pt 0 10pt 0; padding-bottom: 6pt; border-bottom: 1pt solid #e7e5e4; }
        h2 .num { display: inline-block; background: #f59e0b; color: #fff; font-size: 10pt; padding: 2pt 8pt; border-radius: 4pt; margin-right: 8pt; vertical-align: middle; }
        h2.swot .num { background: #7c3aed; }
        .badge { font-size: 8pt; background: #f5f5f4; color: #57534e; padding: 2pt 7pt; border-radius: 3pt; margin-left: 6pt; vertical-align: middle; }
        .lead { margin: 0 0 10pt 0; color: #44403c; }
        h3 { font-size: 10.5pt; color: #57534e; text-transform: uppercase; letter-spacing: 0.04em; margin: 14pt 0 6pt 0; }
        .box { background: #fafaf9; border: 1pt solid #e7e5e4; border-radius: 5pt; padding: 10pt 12pt; margin-bottom: 10pt; }
        .steps { margin: 0; padding-left: 16pt; }
        .steps li { margin-bottom: 7pt; padding-left: 4pt; }
        .notes { margin-top: 12pt; padding: 10pt 12pt; background: #f0f9ff; border-left: 4pt solid #0ea5e9; border-radius: 0 5pt 5pt 0; font-size: 9.5pt; color: #0c4a6e; }
        .notes strong { color: #0369a1; }
        .notes ul { margin: 6pt 0 0 0; padding-left: 14pt; }
        .notes li { margin-bottom: 4pt; }
        .block { page-break-inside: avoid; margin-bottom: 8pt; }
    </style>
</head>
<body>
    <h1 class="doc-title">Kullanım Rehberi</h1>
    <p class="doc-sub">{{ config('app.name', 'Uygulama') }} — {{ now()->format('d.m.Y H:i') }}</p>

    <div class="intro block">
        <strong>{{ $guide['intro']['title'] }}</strong>
        <p>{{ $guide['intro']['text'] }}</p>
    </div>

    @foreach ($guide['sections'] as $index => $section)
        <div class="block">
            <h2 class="{{ ($section['key'] ?? '') === 'swot' ? 'swot' : '' }}">
                <span class="num">{{ $index + 1 }}</span>{{ $section['title'] }}
                <span class="badge">{{ $section['badge'] }}</span>
            </h2>
            <p class="lead">{{ $section['lead'] }}</p>
            <h3>Nereden başlarım?</h3>
            <div class="box">{{ $section['menu'] }}</div>
            <h3>Adımlar</h3>
            <ol class="steps">
                @foreach ($section['steps'] as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
            @if (! empty($section['notes']))
                <div class="notes">
                    <strong>İpuçları</strong>
                    <ul>
                        @foreach ($section['notes'] as $note)
                            <li>{{ $note }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endforeach
</body>
</html>
