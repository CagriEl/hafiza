<x-filament-panels::page>
    @php
        $portfolio = $this->getPortfolio();
        $playbook = $this->getPlaybook();
        $kpi = $this->getKpi();
        $maddeler = $this->getMaddeler();
        $yoneticiUrl = \App\Filament\Pages\AnalizEkibiYoneticiRaporu::getUrl();
    @endphp

    <style>
        .koord {
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
        .koord .title {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }
        .koord .title h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
        }
        .koord .subtitle { color: var(--muted); font-size: 13px; margin-top: 4px; }
        .koord .link-chip {
            display: inline-flex;
            align-items: center;
            border: 1px solid var(--line);
            background: #fff;
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 12px;
            color: #374151;
            text-decoration: none;
        }
        .koord .link-chip:hover { border-color: #cbd5e1; }
        .koord .grid-kpi {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }
        .koord .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
        }
        .koord .kpi-label { color: var(--muted); font-size: 12px; margin-bottom: 6px; }
        .koord .kpi-value { font-size: 28px; font-weight: 700; line-height: 1; }
        .koord .ok { color: var(--ok); }
        .koord .warn { color: var(--warn); }
        .koord .danger { color: var(--danger); }
        .koord .info { color: var(--info); }
        .koord .section {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 12px;
        }
        .koord .section h2 {
            margin: 0 0 10px;
            font-size: 16px;
            font-weight: 700;
        }
        .koord .section h3 {
            margin: 12px 0 6px;
            font-size: 13px;
            font-weight: 700;
            color: #374151;
        }
        .koord .portfolio {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 10px;
        }
        .koord .portfolio-card {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px;
            background: #fff;
        }
        .koord .portfolio-card strong { display: block; margin-bottom: 4px; }
        .koord .portfolio-card ul {
            margin: 8px 0 0;
            padding-left: 16px;
            font-size: 12px;
            color: #374151;
        }
        .koord .muted { color: var(--muted); font-size: 12px; }
        .koord table { width: 100%; border-collapse: collapse; }
        .koord th, .koord td {
            border-bottom: 1px solid var(--line);
            padding: 10px 8px;
            text-align: left;
            vertical-align: top;
            font-size: 13px;
        }
        .koord th { color: #374151; background: #f9fafb; }
        .koord .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .koord .badge-ok { background: #dcfce7; color: #166534; }
        .koord .badge-warn { background: #fef3c7; color: #92400e; }
        .koord .badge-danger { background: #fee2e2; color: #991b1b; }
        .koord .actions-row { display: flex; flex-wrap: wrap; gap: 6px; }
        .koord .btn-mini {
            border: 1px solid var(--line);
            background: #fff;
            border-radius: 8px;
            padding: 4px 8px;
            font-size: 11px;
            cursor: pointer;
            color: #374151;
        }
        .koord .btn-mini:hover { background: #f9fafb; }
        .koord .btn-danger { color: #991b1b; border-color: #fecaca; }
        .koord .two-col {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 12px;
        }
        .koord .play-list { margin: 0; padding-left: 18px; font-size: 13px; color: #374151; }
        .koord .play-list li { margin-bottom: 4px; }
        .koord .form-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        @media (max-width: 900px) {
            .koord .grid-kpi { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .koord .two-col { grid-template-columns: 1fr; }
        }
    </style>

    <div class="koord">
        <div class="title">
            <div>
                <h1>Koordinasyon Takibi</h1>
                <div class="subtitle">
                    Portföy sahipliği, haftalık ritim ve saha teyit maddelerini tek ekrandan izleyin.
                </div>
            </div>
            <a class="link-chip" href="{{ $yoneticiUrl }}">Haftalık Yönetici Raporu →</a>
        </div>

        <div class="grid-kpi">
            <div class="card">
                <div class="kpi-label">Toplam madde</div>
                <div class="kpi-value info">{{ $kpi['toplam'] }}</div>
            </div>
            <div class="card">
                <div class="kpi-label">Yeşil — Teyit</div>
                <div class="kpi-value ok">{{ $kpi['teyit'] }}</div>
            </div>
            <div class="card">
                <div class="kpi-label">Sarı — Şüphe</div>
                <div class="kpi-value warn">{{ $kpi['suphe'] }}</div>
            </div>
            <div class="card">
                <div class="kpi-label">Kırmızı — Düzeltme</div>
                <div class="kpi-value danger">{{ $kpi['duzeltme'] }}</div>
            </div>
            <div class="card">
                <div class="kpi-label">Saha / kaynak teyidi</div>
                <div class="kpi-value">{{ $kpi['saha'] }}</div>
            </div>
        </div>

        <div class="section">
            <h2>Canlı portföy (Analiz Ekibi)</h2>
            <div class="portfolio">
                @forelse ($portfolio as $uye)
                    <div class="portfolio-card">
                        <strong>{{ $uye['name'] }}</strong>
                        <div class="muted">{{ $uye['mudurluk_sayisi'] }} müdürlük</div>
                        <ul>
                            @forelse ($uye['mudurlukler'] as $m)
                                <li>{{ $m['name'] }}</li>
                            @empty
                                <li class="muted">Atama yok</li>
                            @endforelse
                        </ul>
                    </div>
                @empty
                    <div class="muted">Analiz ekibi kullanıcısı bulunamadı.</div>
                @endforelse
            </div>
        </div>

        <div class="section">
            <h2>Haftalık işletim</h2>
            {{ $this->form }}
            <div class="form-actions">
                <x-filament::button wire:click="saveHaftaOzeti" color="primary">
                    Haftalık özeti kaydet
                </x-filament::button>
            </div>
        </div>

        <div class="two-col">
            <div class="section" style="margin-bottom: 0;">
                <h2>Takip maddeleri</h2>
                <p class="muted" style="margin-top:0;">
                    Şüpheli / düzeltilmesi gereken kalemleri buraya ekleyin. Kırmızı sahipsiz kalmamalı.
                </p>
                {{ $this->maddeForm }}
                <div class="form-actions">
                    <x-filament::button wire:click="saveMadde" color="warning">
                        {{ $this->editingMaddeId ? 'Maddeyi güncelle' : 'Madde ekle' }}
                    </x-filament::button>
                    @if ($this->editingMaddeId)
                        <x-filament::button wire:click="cancelEditMadde" color="gray">
                            Vazgeç
                        </x-filament::button>
                    @endif
                </div>

                <div style="margin-top: 16px; overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Konu</th>
                                <th>Sorumlu</th>
                                <th>Müdürlük</th>
                                <th>Durum</th>
                                <th>Saha</th>
                                <th>İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($maddeler as $madde)
                                @php
                                    $badge = match ($madde->durum) {
                                        'teyit' => 'badge-ok',
                                        'duzeltme' => 'badge-danger',
                                        default => 'badge-warn',
                                    };
                                    $etiket = \App\Models\KoordinasyonTakipMadde::durumOptions()[$madde->durum] ?? $madde->durum;
                                @endphp
                                <tr wire:key="madde-{{ $madde->id }}">
                                    <td>
                                        <strong>{{ $madde->baslik }}</strong>
                                        @if ($madde->notlar)
                                            <div class="muted">{{ $madde->notlar }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $madde->analizUser?->name ?? '—' }}</td>
                                    <td>{{ $madde->directorate?->name ?? '—' }}</td>
                                    <td><span class="badge {{ $badge }}">{{ $etiket }}</span></td>
                                    <td>{{ $madde->saha_kontrolu ? 'Evet' : 'Hayır' }}</td>
                                    <td>
                                        <div class="actions-row">
                                            <button type="button" class="btn-mini" wire:click="editMadde({{ $madde->id }})">Düzenle</button>
                                            <button type="button" class="btn-mini" wire:click="markMaddeDurum({{ $madde->id }}, 'teyit')">Yeşil</button>
                                            <button type="button" class="btn-mini" wire:click="markMaddeDurum({{ $madde->id }}, 'suphe')">Sarı</button>
                                            <button type="button" class="btn-mini" wire:click="markMaddeDurum({{ $madde->id }}, 'duzeltme')">Kırmızı</button>
                                            <button type="button" class="btn-mini btn-danger" wire:click="deleteMadde({{ $madde->id }})" wire:confirm="Bu madde silinsin mi?">Sil</button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="muted">Bu hafta henüz madde yok. Şüpheli bir kalem gördüğünüzde ekleyin.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="section" style="margin-bottom: 0;">
                <h2>Operasyon rehberi</h2>

                <h3>Roller</h3>
                <ul class="play-list">
                    @foreach ($playbook['roller'] as $r)
                        <li>{{ $r }}</li>
                    @endforeach
                </ul>

                <h3>Haftalık ritim</h3>
                <ul class="play-list">
                    @foreach ($playbook['ritim'] as $r)
                        <li><strong>{{ $r['gun'] }}:</strong> {{ $r['is'] }}</li>
                    @endforeach
                </ul>

                <h3>Saha doğrulama adımları</h3>
                <ol class="play-list">
                    @foreach ($playbook['saha_adimlari'] as $a)
                        <li>{{ $a }}</li>
                    @endforeach
                </ol>

                <h3>Ne zaman sahaya?</h3>
                <ul class="play-list">
                    @foreach ($playbook['saha_tetikleyicileri'] as $t)
                        <li>{{ $t }}</li>
                    @endforeach
                </ul>

                <h3>Toplantı gündemi (45–60 dk)</h3>
                <ol class="play-list">
                    @foreach ($playbook['toplanti_gundemi'] as $g)
                        <li>{{ $g }}</li>
                    @endforeach
                </ol>

                <h3>Basit KPI</h3>
                <ul class="play-list">
                    @foreach ($playbook['kpi'] as $k)
                        <li>{{ $k }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</x-filament-panels::page>
