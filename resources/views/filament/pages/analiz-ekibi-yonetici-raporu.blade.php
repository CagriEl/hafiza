<x-filament-panels::page>
    <style>
        .yon-rapor {
            --bg: #f6f8fb;
            --card: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --line: #e5e7eb;
            --ok: #16a34a;
            --warn: #d97706;
            --danger: #dc2626;
            --info: #2563eb;
            color: var(--text);
        }
        .yon-rapor .title {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
            gap: 12px;
            flex-wrap: wrap;
        }
        .yon-rapor .title h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            color: var(--text);
        }
        .yon-rapor .subtitle {
            color: var(--muted);
            font-size: 13px;
            margin-top: 4px;
        }
        .yon-rapor .filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .yon-rapor .chip {
            border: 1px solid var(--line);
            background: #fff;
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 12px;
            color: #374151;
        }
        .yon-rapor .grid-kpi {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }
        .yon-rapor .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
        }
        .yon-rapor .kpi-label {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 6px;
        }
        .yon-rapor .kpi-value {
            font-size: 28px;
            font-weight: 700;
            line-height: 1;
        }
        .yon-rapor .kpi-note {
            margin-top: 8px;
            font-size: 12px;
            color: #374151;
        }
        .yon-rapor .ok { color: var(--ok); }
        .yon-rapor .warn { color: var(--warn); }
        .yon-rapor .danger { color: var(--danger); }
        .yon-rapor .info { color: var(--info); }
        .yon-rapor .section {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 12px;
        }
        .yon-rapor .section h2 {
            margin: 0 0 10px;
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
        }
        .yon-rapor table {
            width: 100%;
            border-collapse: collapse;
        }
        .yon-rapor th, .yon-rapor td {
            border-bottom: 1px solid var(--line);
            padding: 10px 8px;
            text-align: left;
            vertical-align: top;
            font-size: 13px;
        }
        .yon-rapor th { color: #374151; background: #f9fafb; }
        .yon-rapor .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .yon-rapor .badge-ok { background: #dcfce7; color: #166534; }
        .yon-rapor .badge-warn { background: #fef3c7; color: #92400e; }
        .yon-rapor .badge-danger { background: #fee2e2; color: #991b1b; }
        .yon-rapor .badge-muted { background: #f3f4f6; color: #374151; }
        .yon-rapor .actions {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .yon-rapor .actions li {
            border: 1px dashed var(--line);
            border-left: 4px solid var(--danger);
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 8px;
            background: #fff;
            font-size: 13px;
        }
        .yon-rapor .muted { color: var(--muted); }
        .yon-rapor .row-flex {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }
        .yon-rapor .progress-wrap { margin-top: 8px; }
        .yon-rapor .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #374151;
            margin-bottom: 4px;
        }
        .yon-rapor .progress {
            width: 100%;
            height: 10px;
            border-radius: 999px;
            background: #e5e7eb;
            overflow: hidden;
        }
        .yon-rapor .progress > span {
            display: block;
            height: 100%;
            border-radius: 999px;
        }
        .yon-rapor .heat-list { display: grid; gap: 8px; }
        .yon-rapor .heat-item {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px;
            background: #fff;
            font-size: 13px;
        }
        .yon-rapor .heat-item strong { display: block; margin-bottom: 4px; }
        .yon-rapor .heat-low { border-left: 4px solid var(--ok); }
        .yon-rapor .heat-mid { border-left: 4px solid var(--warn); }
        .yon-rapor .heat-high { border-left: 4px solid var(--danger); }
        .yon-rapor .mudurluk-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        .yon-rapor .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        @media (max-width: 900px) {
            .yon-rapor .grid-kpi { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .yon-rapor .row-flex { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .yon-rapor .grid-kpi { grid-template-columns: 1fr; }
        }
        .dark .yon-rapor {
            --bg: transparent;
            --card: rgb(17 24 39);
            --text: #f3f4f6;
            --muted: #9ca3af;
            --line: #374151;
        }
        .dark .yon-rapor .chip,
        .dark .yon-rapor .card,
        .dark .yon-rapor .section,
        .dark .yon-rapor .heat-item,
        .dark .yon-rapor .actions li {
            background: rgb(17 24 39);
            color: var(--text);
        }
        .dark .yon-rapor th { background: rgb(31 41 55); color: #d1d5db; }
    </style>

    <div class="space-y-6">
        {{ $this->form }}

        @php
            $report = $this->getReport();
            $ozet = $report['ozet'];
            $olgunluk = $report['olgunluk'] ?? [];
            $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');
            $badgeClass = function (string $durum): string {
                return match ($durum) {
                    'Tamamlandı' => 'badge-ok',
                    'Kısmi' => 'badge-warn',
                    'Riskli', 'Veri Eksik' => 'badge-danger',
                    default => 'badge-muted',
                };
            };
            $barColor = function (int $pct): string {
                if ($pct >= 75) return '#22c55e';
                if ($pct >= 50) return '#3b82f6';
                if ($pct >= 30) return '#f59e0b';
                return '#ef4444';
            };
        @endphp

        <div class="yon-rapor">
            <div class="title">
                <div>
                    <h1>Haftalık Yönetici Raporu</h1>
                    <div class="subtitle">Tüm müdürlükler — performans özeti, 0 girilen kodlar ve açıkta kalan işler</div>
                </div>
                <div class="filters">
                    <span class="chip">Dönem: {{ $report['donem_etiketi'] }}</span>
                    <span class="chip">Müdürlük: {{ $ozet['mudurluk_sayisi'] }}</span>
                    <span class="chip">Rapor giren: {{ $ozet['rapor_olan'] }} / {{ $ozet['mudurluk_sayisi'] }}</span>
                    <a class="chip" href="{{ \App\Filament\Pages\AnalizEkibiKoordinasyonTakibi::getUrl() }}" style="text-decoration:none;color:#1d4ed8;font-weight:600;">Koordinasyon Takibi →</a>
                </div>
            </div>

            <div class="grid-kpi">
                <div class="card">
                    <div class="kpi-label">Yapılan İş</div>
                    <div class="kpi-value ok">{{ $fmt($ozet['yapilan']) }}</div>
                    <div class="kpi-note">Hedef: {{ $fmt($ozet['hedef']) }}</div>
                </div>
                <div class="card">
                    <div class="kpi-label">Açıkta Bekleyen İş</div>
                    <div class="kpi-value danger">{{ $fmt($ozet['acikta']) }}</div>
                    <div class="kpi-note">{{ $ozet['acikta_kalem_sayisi'] }} kalem · {{ $ozet['sifir_kod_sayisi'] }} kodda 0 giriş</div>
                </div>
                <div class="card">
                    <div class="kpi-label">Tamamlanma Oranı</div>
                    <div class="kpi-value info">
                        {{ $ozet['tamamlanma_orani'] !== null ? '%'.$ozet['tamamlanma_orani'] : '—' }}
                    </div>
                    <div class="kpi-note">{{ $fmt($ozet['yapilan']) }} / {{ $fmt(($ozet['hedef'] > 0 ? $ozet['hedef'] : ($ozet['yapilan'] + $ozet['acikta']))) }} toplam iş</div>
                </div>
                <div class="card">
                    <div class="kpi-label">Rapor Durumu</div>
                    <div class="kpi-value warn">{{ $ozet['rapor_olmayan'] }}</div>
                    <div class="kpi-note">{{ $ozet['rapor_olan'] }} müdürlük raporladı · {{ $ozet['rapor_olmayan'] }} eksik</div>
                </div>
            </div>

            <div class="section">
                <h2>Öncelikli Aksiyon Listesi</h2>
                @if (empty($report['aksiyonlar']))
                    <div class="muted">Bu hafta için öncelikli aksiyon önerisi yok.</div>
                @else
                    <ul class="actions">
                        @foreach ($report['aksiyonlar'] as $aksiyon)
                            <li>{{ $aksiyon }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="row-flex">
                <div class="section">
                    <h2>Olgunluk Göstergesi</h2>
                    @foreach ([
                        'veri_kalitesi' => 'Veri Kalitesi',
                        'zamaninda_kapanis' => 'Zamanında Kapanış',
                        'risk_yonetimi' => 'Risk Yönetimi',
                        'aksiyon_kapanis' => 'Aksiyon Kapanış Disiplini',
                    ] as $key => $label)
                        @php $pct = (int) ($olgunluk[$key] ?? 0); @endphp
                        <div class="progress-wrap">
                            <div class="progress-label"><span>{{ $label }}</span><strong>%{{ $pct }}</strong></div>
                            <div class="progress"><span style="width: {{ $pct }}%; background: {{ $barColor($pct) }};"></span></div>
                        </div>
                    @endforeach
                </div>

                <div class="section">
                    <h2>Risk Isı Haritası</h2>
                    <div class="heat-list">
                        @forelse ($report['risk_haritasi'] as $risk)
                            <div @class([
                                'heat-item',
                                'heat-high' => $risk['seviye'] === 'high',
                                'heat-mid' => $risk['seviye'] === 'mid',
                                'heat-low' => $risk['seviye'] === 'low',
                            ])>
                                <strong>
                                    {{ $risk['mudurluk_adi'] }}
                                    @if (! empty($risk['rapor_url']))
                                        <a href="{{ $risk['rapor_url'] }}" class="muted" style="font-weight:500;margin-left:6px;text-decoration:underline;">Rapora git</a>
                                    @endif
                                </strong>
                                {{ $risk['aciklama'] }}
                            </div>
                        @empty
                            <div class="muted">Müdürlük bulunamadı.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="section">
                <h2>0 Girilen Kodlar</h2>
                @if (empty($report['sifir_girilen_kodlar']))
                    <div class="muted">Bu haftada 0 girilmiş kod yok.</div>
                @else
                    <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Müdürlük</th>
                                    <th>Kod</th>
                                    <th>Faaliyet</th>
                                    <th class="num">Hedef</th>
                                    <th class="num">Gerçekleşen</th>
                                    <th class="num">Açıkta</th>
                                    <th>Durum</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['sifir_girilen_kodlar'] as $kod)
                                    <tr>
                                        <td>{{ $kod['mudurluk_adi'] ?? '—' }}</td>
                                        <td>{{ $kod['faaliyet_kodu'] !== '' ? $kod['faaliyet_kodu'] : '—' }}</td>
                                        <td>{{ $kod['etiket'] }}</td>
                                        <td class="num">{{ $fmt($kod['hedef']) }}</td>
                                        <td class="num danger">{{ $fmt($kod['gerceklesen']) }}</td>
                                        <td class="num">{{ $fmt($kod['kalan']) }}</td>
                                        <td><span class="badge {{ $badgeClass((string) $kod['durum']) }}">{{ $kod['durum'] }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="section">
                <h2>Açıkta Kalan İşler (Kalem Kalem)</h2>
                @if (empty($report['acikta_kalan_isler']))
                    <div class="muted">Bu haftada açıkta kalan iş yok.</div>
                @else
                    <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Müdürlük</th>
                                    <th>Kod</th>
                                    <th>Faaliyet</th>
                                    <th>Alt Kalem</th>
                                    <th class="num">Öngörülen</th>
                                    <th class="num">Yapılan</th>
                                    <th class="num">Açıkta</th>
                                    <th>Durum</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['acikta_kalan_isler'] as $is)
                                    <tr>
                                        <td>{{ $is['mudurluk_adi'] ?? '—' }}</td>
                                        <td>{{ $is['faaliyet_kodu'] !== '' ? $is['faaliyet_kodu'] : '—' }}</td>
                                        <td>{{ $is['etiket'] }}</td>
                                        <td>{{ $is['kalem'] }}</td>
                                        <td class="num">{{ $fmt($is['ongorulen']) }}</td>
                                        <td class="num">{{ $fmt($is['gerceklesen']) }}</td>
                                        <td class="num danger">{{ $fmt($is['acikta']) }}</td>
                                        <td><span class="badge {{ $badgeClass((string) $is['durum']) }}">{{ $is['durum'] }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="section">
                <h2>Müdürlük Bazlı Özet</h2>
                @forelse ($report['mudurlukler'] as $m)
                    <div style="border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:10px;">
                        <div class="mudurluk-head">
                            <div>
                                <strong style="font-size:14px;">{{ $m['mudurluk_adi'] }}</strong>
                                <div class="subtitle" style="margin-top:2px;">
                                    @if ($m['rapor_var'])
                                        Rapor mevcut · {{ $m['ozet']['sifir_kod_sayisi'] }} sıfır kod · {{ $m['ozet']['acikta_kalem_sayisi'] }} açık kalem
                                    @else
                                        Bu hafta rapor yok
                                    @endif
                                </div>
                            </div>
                            <div class="filters">
                                <span class="chip">Yapılan: {{ $fmt($m['ozet']['gerceklesen'] ?? 0) }}</span>
                                <span class="chip">Açıkta: {{ $fmt($m['ozet']['acikta_toplam']) }}</span>
                                <span class="chip">
                                    %{{ $m['ozet']['tamamlanma_orani'] !== null ? $m['ozet']['tamamlanma_orani'] : '—' }}
                                </span>
                                @if (! empty($m['rapor_url']))
                                    <a class="chip" href="{{ $m['rapor_url'] }}" style="text-decoration:none;color:#1d4ed8;font-weight:600;">Rapora git</a>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="muted">Listelenecek müdürlük yok.</div>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>
