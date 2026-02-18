<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Aylık Faaliyet İstatistikleri</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #333; }
        .header { text-align: center; border-bottom: 2px solid #333; margin-bottom: 20px; padding-bottom: 10px; }
        h1 { font-size: 16px; margin: 0; }
        h2 { font-size: 14px; margin: 5px 0; color: #555; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #d72323; color: white; text-align: center; font-weight: bold; }
        td.center { text-align: center; }
        
        /* Satırlar arasına zebra efekti */
        tr:nth-child(even) { background-color: #f9f9f9; }
        
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 9px; color: #999; border-top: 1px solid #eee; padding-top: 5px; }
        .total-row { background-color: #333 !important; color: #fff; font-weight: bold; }
    </style>
</head>
<body>
    
    <div class="header">
        <h1>T.C. BELEDİYE BAŞKANLIĞI</h1>
        <h2>{{ $donem }} DÖNEMİ FAALİYET ÖZETİ</h2>
        <div style="font-size: 10px; margin-top: 5px;">Rapor Tarihi: {{ now()->format('d.m.Y H:i') }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th width="5%">Sıra</th>
                <th width="55%">Müdürlük Adı</th>
                <th width="20%">Rapor Sayısı</th>
                <th width="20%">Toplam Yapılan İş</th>
            </tr>
        </thead>
        <tbody>
            @php 
                $genelToplamIs = 0; 
                $sira = 1;
            @endphp

            @foreach($gruplanmisRaporlar as $userId => $raporlar)
                @php
                    $mudurlukAdi = $raporlar->first()->user->name ?? 'Tanımsız Birim';
                    $raporSayisi = $raporlar->count(); // O ay kaç tane rapor girmiş (örn: 4 hafta)
                    
                    // O müdürlüğün faaliyet sayılarını topla
                    $mudurlukToplamIs = 0;
                    foreach($raporlar as $r) {
                        $isler = is_string($r->faaliyetler) ? json_decode($r->faaliyetler, true) : $r->faaliyetler;
                        if(is_array($isler)) {
                            $mudurlukToplamIs += count($isler);
                        }
                    }

                    // Genel toplama ekle
                    $genelToplamIs += $mudurlukToplamIs;
                @endphp

                <tr>
                    <td class="center">{{ $sira++ }}</td>
                    <td>{{ $mudurlukAdi }}</td>
                    <td class="center">{{ $raporSayisi }} Hafta</td>
                    <td class="center" style="font-weight: bold; font-size: 12px;">{{ $mudurlukToplamIs }}</td>
                </tr>
            @endforeach

            <tr class="total-row">
                <td colspan="3" style="text-align: right; padding-right: 10px;">GENEL TOPLAM:</td>
                <td class="center" style="font-size: 14px;">{{ $genelToplamIs }} ADET</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        Kurumsal Hafıza Sistemi - Yönetici Özeti
    </div>

</body>
</html>