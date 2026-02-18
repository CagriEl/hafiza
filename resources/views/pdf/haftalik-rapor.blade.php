<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Haftalık Faaliyet Raporu</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #333; }
        .header-table { width: 100%; border-bottom: 2px solid #444; padding-bottom: 10px; margin-bottom: 20px; }
        h1 { font-size: 16px; margin: 0; text-transform: uppercase; text-align: center; }
        h2 { font-size: 14px; margin: 5px 0 0 0; font-weight: normal; text-align: center; }
        h3 { margin-top: 15px; margin-bottom: 5px; background-color: #eee; padding: 6px; border-left: 4px solid #333; font-size: 12px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #999; padding: 6px; text-align: left; vertical-align: top; }
        th { background-color: #f0f0f0; font-weight: bold; font-size: 10px; text-align: center; }
        .center { text-align: center; }
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 9px; color: #888; border-top: 1px solid #ddd; padding-top: 5px; }
    </style>
</head>
<body>

    <table class="header-table" style="border: none;">
        <tr>
            <td style="border: none; width: 25%;">
                {{-- Buraya Logo Gelebilir --}}
            </td>
            <td style="border: none; text-align: center; width: 50%;">
                <h1>T.C.</h1>
                <h1>BELEDİYE BAŞKANLIĞI</h1>
                <h2>Haftalık Faaliyet Raporu</h2>
            </td>
            <td style="border: none; width: 25%; text-align: right; font-size: 10px;">
                <strong>Müdürlük:</strong> {{ $record->user->name ?? 'Belirtilmemiş' }}<br>
                <strong>Tarih:</strong> {{ now()->format('d.m.Y') }}
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin-bottom: 20px; font-weight: bold;">
        RAPOR DÖNEMİ: 
        {{ $record->baslangic_tarihi ? \Carbon\Carbon::parse($record->baslangic_tarihi)->format('d.m.Y') : '...' }} 
        - 
        {{ $record->bitis_tarihi ? \Carbon\Carbon::parse($record->bitis_tarihi)->format('d.m.Y') : '...' }}
    </div>

    <h3>1. Personel Durumu</h3>
    <table>
        <thead>
            <tr>
                <th width="20%">Memur</th>
                <th width="20%">Sözleşmeli</th>
                <th width="20%">İşçi</th>
                <th width="20%">Şirket Personeli</th>
                <th width="20%">GENEL TOPLAM</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="center">{{ $record->memur_sayisi }}</td>
                <td class="center">{{ $record->sozlesmeli_memur_sayisi }}</td>
                <td class="center">{{ $record->kadrolu_isci_sayisi }}</td>
                <td class="center">{{ $record->sirket_personeli_sayisi }}</td>
                <td class="center" style="background-color: #f9f9f9; font-weight: bold;">
                    {{ (int)$record->memur_sayisi + (int)$record->sozlesmeli_memur_sayisi + (int)$record->kadrolu_isci_sayisi + (int)$record->sirket_personeli_sayisi }}
                </td>
            </tr>
        </tbody>
    </table>

    <h3>2. Talep ve Şikayetler</h3>
    <table>
        <thead>
            <tr>
                <th>CİMER</th>
                <th>AÇIKKAPI</th>
                <th>BELEDİYE</th>
                <th>TOPLAM</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="center">{{ $record->cimer_sayisi }}</td>
                <td class="center">{{ $record->acikkapi_sayisi }}</td>
                <td class="center">{{ $record->belediye_sayisi }}</td>
                <td class="center" style="background-color: #f9f9f9; font-weight: bold;">
                    {{ $record->toplam_sikayet }}
                </td>
            </tr>
        </tbody>
    </table>

    <h3>3. Yapılan Faaliyetler</h3>
    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="50%">Konu / Yapılan İş</th>
                <th width="15%">Başlama</th>
                <th width="15%">Bitiş</th>
                <th width="15%">Durum</th>
            </tr>
        </thead>
        <tbody>
            {{-- VERİYİ ZORLA DİZİYE ÇEVİRME (GÜVENLİK) --}}
            @php
                $faaliyetler = $record->faaliyetler;
                if (is_string($faaliyetler)) $faaliyetler = json_decode($faaliyetler, true);
                if (!is_array($faaliyetler)) $faaliyetler = [];
            @endphp

            @forelse($faaliyetler as $item)
            <tr>
                <td class="center">{{ $item['sira_no'] ?? '-' }}</td>
                <td>{{ $item['konusu'] ?? '' }}</td>
                <td class="center">{{ isset($item['baslama_tarihi']) ? \Carbon\Carbon::parse($item['baslama_tarihi'])->format('d.m.Y') : '-' }}</td>
                <td class="center">{{ isset($item['bitis_tarihi']) ? \Carbon\Carbon::parse($item['bitis_tarihi'])->format('d.m.Y') : '-' }}</td>
                <td class="center">{{ $item['durum'] ?? '' }}</td>
            </tr>
            @empty
            <tr><td colspan="5" class="center">Kayıt Yok</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3>4. Planlanan Faaliyetler</h3>
    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="50%">Konu</th>
                <th width="15%">Başlama</th>
                <th width="15%">Tahmini Bitiş</th>
                <th width="15%">Durum</th>
            </tr>
        </thead>
        <tbody>
            {{-- VERİYİ ZORLA DİZİYE ÇEVİRME --}}
            @php
                $planlar = $record->detayli_planlanan_faaliyetler;
                if (is_string($planlar)) $planlar = json_decode($planlar, true);
                if (!is_array($planlar)) $planlar = [];
            @endphp

            @forelse($planlar as $item)
            <tr>
                <td class="center">{{ $item['sira_no'] ?? '-' }}</td>
                <td>{{ $item['konusu'] ?? '' }}</td>
                <td class="center">{{ isset($item['baslama_tarihi']) ? \Carbon\Carbon::parse($item['baslama_tarihi'])->format('d.m.Y') : '-' }}</td>
                <td class="center">{{ isset($item['bitis_tarihi']) ? \Carbon\Carbon::parse($item['bitis_tarihi'])->format('d.m.Y') : '-' }}</td>
                <td class="center">{{ $item['durum'] ?? '' }}</td>
            </tr>
            @empty
            <tr><td colspan="5" class="center">Kayıt Yok</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3>5. İhaleler ve Doğrudan Teminler</h3>
    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="35%">İşin Adı</th>
                <th width="15%">İhale T.</th>
                <th width="15%">Sözleşme T.</th>
                <th width="15%">Tutar</th>
                <th width="15%">Bitiş T.</th>
            </tr>
        </thead>
        <tbody>
            {{-- VERİYİ ZORLA DİZİYE ÇEVİRME --}}
            @php
                $ihaleler = $record->ihaleler;
                if (is_string($ihaleler)) $ihaleler = json_decode($ihaleler, true);
                if (!is_array($ihaleler)) $ihaleler = [];
            @endphp

            @forelse($ihaleler as $item)
            <tr>
                <td class="center">{{ $item['sira_no'] ?? '-' }}</td>
                <td>{{ $item['isin_adi'] ?? '' }}</td>
                <td class="center">{{ isset($item['ihale_tarihi']) ? \Carbon\Carbon::parse($item['ihale_tarihi'])->format('d.m.Y') : '-' }}</td>
                <td class="center">{{ isset($item['sozlesme_tarihi']) ? \Carbon\Carbon::parse($item['sozlesme_tarihi'])->format('d.m.Y') : '-' }}</td>
                <td class="center">{{ $item['sozlesme_tutari'] ?? '0' }} ₺</td>
                <td class="center">{{ isset($item['bitis_tarihi']) ? \Carbon\Carbon::parse($item['bitis_tarihi'])->format('d.m.Y') : '-' }}</td>
            </tr>
            @empty
            <tr><td colspan="6" class="center">Kayıt Yok</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Bu belge Kurumsal Hafıza Sistemi tarafından {{ now()->format('d.m.Y H:i') }} tarihinde oluşturulmuştur.
    </div>

</body>
</html>