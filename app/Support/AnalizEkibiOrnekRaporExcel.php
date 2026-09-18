<?php

namespace App\Support;

use App\Models\ControlTeamAuditNote;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analiz Ekibi örnek raporu — boş şablon ve doldurulmuş analiz notu Excel çıktısı.
 */
final class AnalizEkibiOrnekRaporExcel
{
    public const TEMPLATE_FILENAME = 'analiz-ekibi-ornek-rapor-sablonu.xlsx';

    public static function templateDownloadResponse(): StreamedResponse
    {
        return self::streamDownload(self::TEMPLATE_FILENAME, function (string $path): void {
            self::writeEmptyTemplateToFile($path);
        });
    }

    public static function downloadResponse(): StreamedResponse
    {
        return self::templateDownloadResponse();
    }

    public static function exportNoteDownloadResponse(ControlTeamAuditNote $note): StreamedResponse
    {
        $note->loadMissing(['directorate', 'user', 'activityCatalog']);
        $filename = self::filenameForNote($note);

        return self::streamDownload($filename, function (string $path) use ($note): void {
            self::writeNoteToFile($note, $path);
        });
    }

    public static function writeToOutput(): void
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'analiz_sablon_');
        if ($tmpBase === false) {
            throw new \RuntimeException('Geçici dosya oluşturulamadı.');
        }

        $path = $tmpBase.'.xlsx';
        @unlink($tmpBase);

        try {
            self::writeEmptyTemplateToFile($path);
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException('Şablon dosyası okunamadı.');
            }
            echo $contents;
        } finally {
            @unlink($path);
        }
    }

    public static function writeEmptyTemplateToFile(string $path): void
    {
        self::writeReportToFile(
            $path,
            [
                'donem' => '',
                'mudurluk' => '',
                'faaliyet_kodu' => '',
                'analiz_tarihi' => '',
                'analiz_eden' => '',
                'rapor_haftasi' => '',
            ],
            AnalizEkibiRaporVerileri::emptyStructure(),
            '',
            true
        );
    }

    public static function writeNoteToFile(ControlTeamAuditNote $note, string $path): void
    {
        $meta = AnalizEkibiRaporVerileri::metaFromNote($note);
        $data = AnalizEkibiRaporVerileri::normalize($note->rapor_verileri);

        self::writeReportToFile(
            $path,
            $meta,
            $data,
            trim((string) ($note->note ?? '')),
            false
        );
    }

    /**
     * @param  array<string, string>  $meta
     * @param  array<string, mixed>  $data
     */
    private static function writeReportToFile(
        string $path,
        array $meta,
        array $data,
        string $analizNotu,
        bool $isTemplate
    ): void {
        $titleStyle = (new Style())->setFontBold()->setFontSize(14);
        $sectionStyle = (new Style())
            ->setFontBold()
            ->setBackgroundColor(Color::rgb(243, 244, 246));
        $headerStyle = (new Style())
            ->setFontBold()
            ->setBackgroundColor(Color::rgb(249, 250, 251));
        $hintStyle = (new Style())->setFontItalic()->setFontColor(Color::rgb(107, 114, 128));

        $writer = new Writer();
        $writer->openToFile($path);

        $title = $isTemplate
            ? 'Analiz Ekibi Örnek Raporu — Boş Şablon'
            : 'Analiz Ekibi Raporu — '.$meta['mudurluk'];
        $writer->addRow(Row::fromValues([$title], $titleStyle));

        if ($isTemplate) {
            $writer->addRow(Row::fromValues([
                'Bu dosyayı indirip doldurun veya panelde Analiz Notları bölümünden doğrudan giriş yapıp Excel alın.',
            ], $hintStyle));
        }

        $writer->addRow(Row::fromValues(['']));

        $writer->addRow(Row::fromValues(['RAPOR BİLGİLERİ'], $sectionStyle));
        $writer->addRow(Row::fromValues(
            ['Dönem', $meta['donem'], 'Müdürlük', $meta['mudurluk'], 'Kapsam', 'Müdürlük geneli'],
            $headerStyle
        ));
        $writer->addRow(Row::fromValues(
            ['Analiz Tarihi', $meta['analiz_tarihi'], 'Analiz Eden', $meta['analiz_eden'], 'Rapor Haftası', $meta['rapor_haftasi']],
            $headerStyle
        ));
        $writer->addRow(Row::fromValues(['']));

        $ozet = is_array($data['ozet'] ?? null) ? $data['ozet'] : [];
        $writer->addRow(Row::fromValues(['ÖZET GÖSTERGELER'], $sectionStyle));
        $writer->addRow(Row::fromValues(
            ['Yapılan İş', 'Açıkta Bekleyen', 'Tamamlanma (%)', 'Revize+Karar', 'Geçen Ay Fark', 'Kritik Kalem Notu'],
            $headerStyle
        ));
        $writer->addRow(Row::fromValues([
            self::cell($ozet['yapilan_is'] ?? ''),
            self::cell($ozet['acikta_bekleyen'] ?? ''),
            self::cell($ozet['tamamlanma_orani'] ?? ''),
            self::cell($ozet['revize_karar'] ?? ''),
            self::cell($ozet['gecen_ay_fark'] ?? ''),
            (string) ($ozet['kritik_kalem_notu'] ?? ''),
        ]));
        $writer->addRow(Row::fromValues(['']));

        $writer->addRow(Row::fromValues(['KALEM KALEM ANALİZ'], $sectionStyle));
        $writer->addRow(Row::fromValues(
            ['Kalem', 'Gerçekleşen', 'Açıkta', 'Durum', 'Sapma / Not', 'Son Tarih'],
            $headerStyle
        ));
        $kalemler = is_array($data['kalem_analizi'] ?? null) ? $data['kalem_analizi'] : [];
        if ($kalemler === [] && $isTemplate) {
            foreach (range(1, 8) as $_) {
                $writer->addRow(Row::fromValues(['', '', '', '', '', '']));
            }
        } else {
            foreach ($kalemler as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $writer->addRow(Row::fromValues([
                    (string) ($row['kalem'] ?? ''),
                    self::cell($row['gerceklesen'] ?? ''),
                    self::cell($row['acikta'] ?? ''),
                    (string) ($row['durum'] ?? ''),
                    (string) ($row['sapma_not'] ?? ''),
                    (string) ($row['son_tarih'] ?? ''),
                ]));
            }
        }
        $writer->addRow(Row::fromValues(['']));

        $writer->addRow(Row::fromValues(['ÖNCELİKLİ AKSİYON LİSTESİ'], $sectionStyle));
        $writer->addRow(Row::fromValues(
            ['Öncelik', 'Aksiyon', 'İlgili Kalem', 'Sorumlu', 'Hedef Tarih', 'Durum'],
            $headerStyle
        ));
        $aksiyonlar = is_array($data['aksiyonlar'] ?? null) ? $data['aksiyonlar'] : [];
        if ($aksiyonlar === [] && $isTemplate) {
            foreach (range(1, 5) as $_) {
                $writer->addRow(Row::fromValues(['', '', '', '', '', '']));
            }
        } else {
            foreach ($aksiyonlar as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $writer->addRow(Row::fromValues([
                    (string) ($row['oncelik'] ?? ''),
                    (string) ($row['aksiyon'] ?? ''),
                    (string) ($row['kalem'] ?? ''),
                    (string) ($row['sorumlu'] ?? ''),
                    (string) ($row['hedef_tarih'] ?? ''),
                    (string) ($row['durum'] ?? ''),
                ]));
            }
        }
        $writer->addRow(Row::fromValues(['']));

        $writer->addRow(Row::fromValues(['OLGUNLUK GÖSTERGELERİ (%)'], $sectionStyle));
        $writer->addRow(Row::fromValues(['Gösterge', 'Değer (%)', 'Not'], $headerStyle));
        $olgunlukLabels = [
            'veri_kalitesi' => 'Veri Kalitesi',
            'zamaninda_kapanis' => 'Zamanında Kapanış',
            'risk_yonetimi' => 'Risk Yönetimi',
            'aksiyon_kapanis' => 'Aksiyon Kapanış Disiplini',
        ];
        $olgunluk = is_array($data['olgunluk'] ?? null) ? $data['olgunluk'] : [];
        foreach ($olgunlukLabels as $key => $label) {
            $item = is_array($olgunluk[$key] ?? null) ? $olgunluk[$key] : [];
            $writer->addRow(Row::fromValues([
                $label,
                self::cell($item['deger'] ?? ''),
                (string) ($item['not'] ?? ''),
            ]));
        }
        $writer->addRow(Row::fromValues(['']));

        $writer->addRow(Row::fromValues(['RİSK ISI HARİTASI'], $sectionStyle));
        $writer->addRow(Row::fromValues(['Kalem', 'Seviye', 'Açıklama'], $headerStyle));
        $riskler = is_array($data['risk_haritasi'] ?? null) ? $data['risk_haritasi'] : [];
        if ($riskler === [] && $isTemplate) {
            foreach (range(1, 4) as $_) {
                $writer->addRow(Row::fromValues(['', '', '']));
            }
        } else {
            foreach ($riskler as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $writer->addRow(Row::fromValues([
                    (string) ($row['kalem'] ?? ''),
                    (string) ($row['seviye'] ?? ''),
                    (string) ($row['aciklama'] ?? ''),
                ]));
            }
        }
        $writer->addRow(Row::fromValues(['']));

        $writer->addRow(Row::fromValues(['ANALİZ NOTU VE BULGULAR'], $sectionStyle));
        if ($analizNotu !== '') {
            foreach (preg_split("/\r\n|\n|\r/", $analizNotu) ?: [] as $line) {
                $writer->addRow(Row::fromValues([(string) $line]));
            }
        } elseif ($isTemplate) {
            foreach (range(1, 4) as $_) {
                $writer->addRow(Row::fromValues(['']));
            }
        }

        $writer->getCurrentSheet()->setName('Analiz Raporu');

        if ($isTemplate) {
            $writer->addNewSheetAndMakeItCurrent();
            $writer->getCurrentSheet()->setName('Analiz Rehberi');
            $writer->addRow(Row::fromValues(['İleri Analiz İmkanları (Referans)'], $sectionStyle));
            $writer->addRow(Row::fromValues(['Analiz Başlığı', 'Ne Ölçer?', 'Yönetim Faydası'], $headerStyle));
            foreach (self::guideRows() as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();
    }

    private static function streamDownload(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer): void {
            $tmpBase = tempnam(sys_get_temp_dir(), 'analiz_xlsx_');
            if ($tmpBase === false) {
                throw new \RuntimeException('Geçici dosya oluşturulamadı.');
            }
            $path = $tmpBase.'.xlsx';
            @unlink($tmpBase);
            try {
                $writer($path);
                $contents = file_get_contents($path);
                if ($contents === false) {
                    throw new \RuntimeException('Excel dosyası okunamadı.');
                }
                echo $contents;
            } finally {
                @unlink($path);
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private static function filenameForNote(ControlTeamAuditNote $note): string
    {
        $mudurluk = \Illuminate\Support\Str::slug($note->directorate?->name ?? 'mudurluk');
        $ay = str_pad(preg_replace('/\D/', '', (string) ($note->ay ?? '')) ?: '00', 2, '0', STR_PAD_LEFT);

        return 'analiz_raporu_'.$mudurluk.'_'.(int) ($note->yil ?? 0).'_'.$ay.'.xlsx';
    }

    private static function cell(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return (string) $value;
    }

    /**
     * @return list<list<string>>
     */
    private static function guideRows(): array
    {
        return [
            ['Veri Kalitesi Skoru', 'Eksik kalem, boş alan, sadece 0 giriş oranı', 'Düşük kaliteli raporu erken tespit eder'],
            ['Kök Neden Analizi', 'Sapma/revize nedenlerinin kategori dağılımı', 'Tekrarlayan sorunların ana kaynağını gösterir'],
            ['Erken Uyarı Sistemi', '2 dönem üst üste artan açıkta iş kalemleri', 'Kritikleşmeden önce müdahale sağlar'],
            ['Trend Karşılaştırma (3-6-12 Ay)', 'Kısa/orta/uzun dönem performans eğilimi', 'Tek ay yanılgısını azaltır, eğilimi görür'],
            ['Kapasite ve İş Yükü Analizi', 'İş adedi / ekip kapasitesi oranı', 'Personel veya kaynak ihtiyacını kanıtlar'],
            ['Benchmark (Müdürlük Kıyas)', 'Aynı faaliyet kodunun müdürlük bazlı karşılaştırması', 'İyi uygulamaları yaygınlaştırır'],
        ];
    }
}
