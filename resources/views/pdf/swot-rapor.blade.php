<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>SWOT Analiz Raporu</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #333; }
        
        .header { 
            text-align: center; 
            margin-bottom: 30px; 
            border-bottom: 2px solid #333; 
            padding-bottom: 10px; 
        }
        
        /* KUTU TASARIMI (ARTIK TAM GENİŞLİK) */
        .swot-section {
            width: 100%;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            border-radius: 5px;
            /* Sayfa sonuna gelirse kutuyu bölme, bir sonrakine at */
            page-break-inside: avoid; 
        }
        
        .section-header {
            padding: 10px;
            font-weight: bold;
            color: #fff;
            text-transform: uppercase;
            font-size: 12px;
            border-radius: 4px 4px 0 0;
            border-bottom: 1px solid #ddd;
        }
        
        .section-content {
            padding: 15px;
            line-height: 1.6;
            background-color: #fff;
            min-height: 80px; /* Boşsa bile biraz yer kaplasın */
        }

        /* RENKLER */
        .bg-green { background-color: #28a745; }  /* Güçlü */
        .bg-red { background-color: #dc3545; }    /* Zayıf */
        .bg-blue { background-color: #007bff; }   /* Fırsat */
        .bg-gray { background-color: #6c757d; }   /* Tehdit */

        .footer { 
            position: fixed; 
            bottom: 0; 
            width: 100%; 
            text-align: center; 
            font-size: 9px; 
            color: #999; 
            border-top: 1px solid #eee; 
            padding-top: 5px; 
        }
    </style>
</head>
<body>

    <div class="header">
        <h2 style="margin:0;">STRATEJİK PLANLAMA - SWOT ANALİZİ</h2>
        <h1 style="margin: 10px 0; font-size: 18px; color: #d72323;">
        {{ $record->baslik ?? 'Başlık Girilmemiş' }}
    </h1>
        <h3 style="margin:5px 0; color: #555;">{{ $record->user->name ?? 'Genel Rapor' }}</h3>
        <p style="margin:0; font-size: 10px;">Dönem: {{ $record->yil ?? now()->year }} | Rapor Tarihi: {{ now()->format('d.m.Y') }}</p>
    </div>

    <div class="swot-section">
        <div class="section-header bg-green">
            1. GÜÇLÜ YÖNLER (Strengths)
        </div>
        <div class="section-content">
            {!! $record->guclu_yonler ?? '<i>Veri girişi yapılmamış.</i>' !!}
        </div>
    </div>

    <div class="swot-section">
        <div class="section-header bg-red">
            2. ZAYIF YÖNLER (Weaknesses)
        </div>
        <div class="section-content">
            {!! $record->zayif_yonler ?? '<i>Veri girişi yapılmamış.</i>' !!}
        </div>
    </div>

    <div class="swot-section">
        <div class="section-header bg-blue">
            3. FIRSATLAR (Opportunities)
        </div>
        <div class="section-content">
            {!! $record->firsatlar ?? '<i>Veri girişi yapılmamış.</i>' !!}
        </div>
    </div>

    <div class="swot-section">
        <div class="section-header bg-gray">
            4. TEHDİTLER (Threats)
        </div>
        <div class="section-content">
            {!! $record->tehditler ?? '<i>Veri girişi yapılmamış.</i>' !!}
        </div>
    </div>

    <div class="footer">
        Kurumsal Hafıza Sistemi
    </div>

</body>
</html>