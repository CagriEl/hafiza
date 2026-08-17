<?php

namespace App\Support;

use App\Models\ActivityCatalog;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Faaliyet kataloğunun müdürlük / aile / alt kalem Excel çıktısı.
 */
final class ActivityCatalogExcel
{
    public static function downloadResponse(): StreamedResponse
    {
        $filename = 'faaliyet_katalogu_'.now()->format('Y_m_d').'.xlsx';

        return self::streamDownload($filename, function (string $path): void {
            self::writeToFile($path);
        });
    }

    public static function writeToFile(string $path, ?Collection $catalogs = null): void
    {
        $catalogs ??= ActivityCatalog::query()
            ->orderBy('mudurluk')
            ->orderBy('faaliyet_kodu')
            ->get();

        $detail = self::detailRows($catalogs);
        $ozet = self::summaryRows($detail);

        $titleStyle = (new Style)->setFontBold()->setFontSize(14);
        $sectionStyle = (new Style)
            ->setFontBold()
            ->setBackgroundColor(Color::rgb(243, 244, 246));
        $headerStyle = (new Style)
            ->setFontBold()
            ->setBackgroundColor(Color::rgb(249, 250, 251));

        $writer = new Writer;
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName('Katalog');
        $writer->addRow(Row::fromValues(['Faaliyet kataloğu — müdürlük / aile / alt kalem'], $titleStyle));
        $writer->addRow(Row::fromValues(['Kaynak: activity_catalogs · '.now()->format('d.m.Y')]));
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues([
            'Müdürlük',
            'Kod',
            'Faaliyet Ailesi',
            'Alt Kalem',
            'Ölçü Birimi',
            'KPI / SLA',
            'Raporlama Sıklığı',
            'Kategori',
        ], $headerStyle));

        foreach ($detail as $row) {
            $writer->addRow(Row::fromValues([
                $row['mudurluk'],
                $row['kod'],
                $row['aile'],
                $row['kalem'],
                $row['olcu'],
                $row['kpi'],
                $row['sikligi'],
                $row['kategori'],
            ]));
        }

        $writer->addNewSheetAndMakeItCurrent();
        $writer->getCurrentSheet()->setName('Özet');
        $writer->addRow(Row::fromValues(['Müdürlük bazında özet'], $sectionStyle));
        $writer->addRow(Row::fromValues([
            'Müdürlük',
            'Faaliyet kodu',
            'Faaliyet ailesi',
            'Alt kalem',
        ], $headerStyle));
        foreach ($ozet as $row) {
            $writer->addRow(Row::fromValues([
                $row['mudurluk'],
                $row['kod_sayisi'],
                $row['aile_sayisi'],
                $row['kalem_sayisi'],
            ]));
        }

        $writer->close();
    }

    /**
     * @return list<array{mudurluk: string, kod: string, aile: string, kalem: string, olcu: string, kpi: string, sikligi: string, kategori: string}>
     */
    public static function detailRows(Collection $catalogs): array
    {
        $out = [];
        foreach ($catalogs as $catalog) {
            if (! $catalog instanceof ActivityCatalog) {
                continue;
            }
            $mudurluk = trim((string) ($catalog->mudurluk ?? ''));
            $kod = trim((string) ($catalog->faaliyet_kodu ?? ''));
            $aile = trim((string) ($catalog->faaliyet_ailesi ?? ''));
            $olcu = trim((string) ($catalog->olcu_birimi ?? ''));
            $kpi = trim((string) ($catalog->kpi_sla ?? ''));
            $sikligi = trim((string) ($catalog->raporlama_sikligi ?? ''));
            $kategori = trim((string) ($catalog->kategori ?? ''));
            $kalemler = self::parseKapsamKalemleri((string) ($catalog->kapsam ?? ''));
            if ($kalemler === []) {
                $kalemler = ['—'];
            }
            foreach ($kalemler as $kalem) {
                $out[] = [
                    'mudurluk' => $mudurluk !== '' ? $mudurluk : '—',
                    'kod' => $kod !== '' ? $kod : '—',
                    'aile' => $aile !== '' ? $aile : '—',
                    'kalem' => $kalem,
                    'olcu' => $olcu,
                    'kpi' => $kpi,
                    'sikligi' => $sikligi,
                    'kategori' => $kategori,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{mudurluk: string, kod: string, aile: string, kalem: string, olcu: string, kpi: string, sikligi: string, kategori: string}>  $detail
     * @return list<array{mudurluk: string, kod_sayisi: int, aile_sayisi: int, kalem_sayisi: int}>
     */
    public static function summaryRows(array $detail): array
    {
        $byMudurluk = [];
        foreach ($detail as $row) {
            $key = $row['mudurluk'];
            if (! isset($byMudurluk[$key])) {
                $byMudurluk[$key] = [
                    'mudurluk' => $key,
                    'kodlar' => [],
                    'aileler' => [],
                    'kalem_sayisi' => 0,
                ];
            }
            if ($row['kod'] !== '—' && $row['kod'] !== '') {
                $byMudurluk[$key]['kodlar'][$row['kod']] = true;
            }
            if ($row['aile'] !== '—' && $row['aile'] !== '') {
                $byMudurluk[$key]['aileler'][$row['aile']] = true;
            }
            $byMudurluk[$key]['kalem_sayisi']++;
        }

        $out = [];
        foreach ($byMudurluk as $bucket) {
            $out[] = [
                'mudurluk' => $bucket['mudurluk'],
                'kod_sayisi' => count($bucket['kodlar']),
                'aile_sayisi' => count($bucket['aileler']),
                'kalem_sayisi' => $bucket['kalem_sayisi'],
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function parseKapsamKalemleri(string $kapsam): array
    {
        $kapsam = trim($kapsam);
        if ($kapsam === '') {
            return [];
        }

        return collect(explode(',', $kapsam))
            ->map(fn (string $parca): string => trim($parca))
            ->filter(fn (string $parca): bool => $parca !== '')
            ->values()
            ->all();
    }

    private static function streamDownload(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer): void {
            $tmpBase = tempnam(sys_get_temp_dir(), 'katalog_xlsx_');
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
}
