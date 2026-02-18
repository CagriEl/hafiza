<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Aylık Faaliyet Raporu</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; }
        h2 { text-align: center; border-bottom: 2px solid #333; padding-bottom: 10px; }
        .bilgi-kutusu { background-color: #f0f0f0; padding: 10px; margin-bottom: 20px; border-radius: 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #999; padding: 8px; text-align: left; }
        th { background-color: #e2e2e2; }
        .badge { padding: 3px 6px; border-radius: 4px; color: white; font-size: 10px; }
        .bekliyor { background-color: #f59e0b; } /* Turuncu */
        .tamam { background-color: #10b981; }    /* Yeşil */
        .devam { background-color: #3b82f6; }    /* Mavi */
        .iptal { background-color: #ef4444; }    /* Kırmızı */
    </style>
</head>
<body>
    <h2>{{ $rapor->yil }} / {{ $rapor->ay }}. Ay Faaliyet Raporu</h2>
    
    <div class="bilgi-kutusu">
        <strong>Birim:</strong> {{ $rapor->user->name }} <br>
        <strong>Oluşturulma Tarihi:</strong> {{ $rapor->created_at->format('d.m.Y') }}
    </div>

    <h3>1. Personel Durumu</h3>
    <table>
        <thead>
            <tr>
                <th>Memur</th>
                <th>Sözleşmeli</th>
                <th>İşçi (Kadrolu)</th>
                <th>Şirket Personeli</th>
                <th>Geçici İşçi</th>
                <th>TOPLAM</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $rapor->memur }}</td>
                <td>{{ $rapor->sozlesmeli_memur }}</td>
                <td>{{ $rapor->kadrolu_isci }}</td>
                <td>{{ $rapor->sirket_personeli }}</td>
                <td>{{ $rapor->gecici_isci }}</td>
                <td>
                    {{ $rapor->memur + $rapor->sozlesmeli_memur + $rapor->kadrolu_isci + $rapor->sirket_personeli + $rapor->gecici_isci }}
                </td>
            </tr>
        </tbody>
    </table>

    <h3>2. Faaliyet Planı ve Gerçekleşmeler</h3>
    <table>
        <thead>
            <tr>
                <th style="width: 10%;">Hafta</th>
                <th style="width: 40%;">Konu / Yapılacak İş</th>
                <th style="width: 15%;">Durum</th>
                <th style="width: 35%;">Gerçekleşme Sonucu / Açıklama</th>
            </tr>
        </thead>
        <tbody>
            @if(is_array($rapor->faaliyetler))
                @foreach($rapor->faaliyetler as $is)
                    <tr>
                        <td style="text-align: center;">{{ $is['hafta'] }}. Hafta</td>
                        <td>{{ $is['konu'] }}</td>
                        <td style="text-align: center;">
                            @php
                                $durum = $is['durum'] ?? 'bekliyor';
                                $renk = match($durum) {
                                    'tamam' => 'tamam',
                                    'devam' => 'devam',
                                    'iptal' => 'iptal',
                                    default => 'bekliyor'
                                };
                                $etiket = match($durum) {
                                    'tamam' => 'Tamamlandı',
                                    'devam' => 'Devam Ediyor',
                                    'iptal' => 'İptal',
                                    default => 'Bekliyor'
                                };
                            @endphp
                            <span class="badge {{ $renk }}">{{ $etiket }}</span>
                        </td>
                        <td>{{ $is['aciklama'] ?? '-' }}</td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="4" style="text-align: center;">Henüz veri girilmemiş.</td>
                </tr>
            @endif
        </tbody>
    </table>

    <br><br><br>
    <div style="text-align: right; padding-right: 50px;">
        <p><strong>{{ $rapor->user->name }}</strong></p>
        <p>İmza</p>
    </div>
</body>
</html>