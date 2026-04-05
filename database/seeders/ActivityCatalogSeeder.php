<?php

namespace Database\Seeders;

use App\Models\ActivityCatalog;
use Illuminate\Database\Seeder;

class ActivityCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            // ÖZEL KALEM MÜDÜRLÜĞÜ
            [
                'mudurluk' => 'Özel Kalem Müdürlüğü',
                'faaliyet_kodu' => 'OKM-01',
                'faaliyet_ailesi' => 'Protokol ve Başkanlık Takvimi',
                'kategori' => 'İletişim / Memnuniyet / Paydaş',
                'kapsam' => 'Randevu, kabul, ziyaret, tören ve protokol koordinasyonu',
                'olcu_birimi' => 'adet / hafta',
                'kpi_sla' => 'zamanında gerçekleşme oranı',
            ],
            [
                'mudurluk' => 'Özel Kalem Müdürlüğü',
                'faaliyet_kodu' => 'OKM-02',
                'faaliyet_ailesi' => 'Vatandaş Deneyimi ve Geri Bildirim',
                'kategori' => 'İletişim / Memnuniyet / Paydaş',
                'kapsam' => 'Çağrı-çözüm, başvuru izleme, memnuniyet analizi',
                'olcu_birimi' => 'başvuru / memnuniyet %',
                'kpi_sla' => 'kapanış oranı, memnuniyet',
            ],

            // BİLGİ İŞLEM MÜDÜRLÜĞÜ
            [
                'mudurluk' => 'Bilgi İşlem Müdürlüğü',
                'faaliyet_kodu' => 'BLM-01',
                'faaliyet_ailesi' => 'Son Kullanıcı Destek',
                'kategori' => 'Destek / İç İşleyiş',
                'kapsam' => 'Yardım masası talepleri, donanım/yazılım arıza giderimi',
                'olcu_birimi' => 'talep',
                'kpi_sla' => 'SLA süresinde çözüm oranı',
            ],
            [
                'mudurluk' => 'Bilgi İşlem Müdürlüğü',
                'faaliyet_kodu' => 'BLM-02',
                'faaliyet_ailesi' => 'Sistem ve Network Yönetimi',
                'kategori' => 'Destek / İç İşleyiş',
                'kapsam' => 'Sunucu, ağ güvenliği, yedekleme ve siber güvenlik süreçleri',
                'olcu_birimi' => 'operasyon / uptime %',
                'kpi_sla' => 'sistem sürekliliği (uptime)',
            ],

            // FEN İŞLERİ MÜDÜRLÜĞÜ
            [
                'mudurluk' => 'Fen İşleri Müdürlüğü',
                'faaliyet_kodu' => 'FNM-01',
                'faaliyet_ailesi' => 'Yol, Kaldırım ve Üstyapı İşleri',
                'kategori' => 'Operasyonel Hizmet',
                'kapsam' => 'Asfalt yama, kaldırım onarım, yeni yol açılması',
                'olcu_birimi' => 'm2 / metre',
                'kpi_sla' => 'planlanan metraj gerçekleşme oranı',
            ],

            // MALİ HİZMETLER MÜDÜRLÜĞÜ
            [
                'mudurluk' => 'Mali Hizmetler Müdürlüğü',
                'faaliyet_kodu' => 'MHM-05',
                'faaliyet_ailesi' => 'Teminat, Emanet ve Ön Ödeme',
                'kategori' => 'Destek / İç İşleyiş',
                'kapsam' => 'Teminat, emanet, avans-kredi ve mahsup süreçleri',
                'olcu_birimi' => 'işlem sayısı',
                'kpi_sla' => 'zamanında mahsup oranı',
            ],
            [
                'mudurluk' => 'Mali Hizmetler Müdürlüğü',
                'faaliyet_kodu' => 'MHM-06',
                'faaliyet_ailesi' => 'Mali Tablolar ve Kesin Hesap',
                'kategori' => 'Destek / İç İşleyiş',
                'kapsam' => 'Mizan, bilanço, faaliyet sonuçları, kesin hesap',
                'olcu_birimi' => 'rapor / tablo',
                'kpi_sla' => 'zamanında rapor üretimi',
            ],

            // DESTEK HİZMETLERİ MÜDÜRLÜĞÜ
            [
                'mudurluk' => 'Destek Hizmetleri Müdürlüğü',
                'faaliyet_kodu' => 'DHM-01',
                'faaliyet_ailesi' => 'İhale ve Satın Alma',
                'kategori' => 'Proje / Yatırım / Sözleşme',
                'kapsam' => 'İhale dosyası, ilan, teklif, karar ve sözleşme',
                'olcu_birimi' => 'dosya / ihale',
                'kpi_sla' => 'tamamlanan ihale süresi',
            ],
            [
                'mudurluk' => 'Destek Hizmetleri Müdürlüğü',
                'faaliyet_kodu' => 'DHM-04',
                'faaliyet_ailesi' => 'Depo, Lojistik ve Stok',
                'kategori' => 'Destek / İç İşleyiş',
                'kapsam' => 'Mal kabul, stok, dağıtım, minimum stok, sayım',
                'olcu_birimi' => 'stok kalemi / dağıtım',
                'kpi_sla' => 'kritik stok seviyesi uyumu',
            ],
        ];

        foreach ($data as $item) {
            ActivityCatalog::updateOrCreate(
                ['faaliyet_kodu' => $item['faaliyet_kodu']], // Aynı kod varsa güncelle
                $item
            );
        }
    }
}