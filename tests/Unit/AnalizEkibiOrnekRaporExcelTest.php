<?php

namespace Tests\Unit;

use App\Models\ControlTeamAuditNote;
use App\Support\AnalizEkibiOrnekRaporExcel;
use App\Support\AnalizEkibiRaporVerileri;
use Tests\TestCase;
use ZipArchive;

class AnalizEkibiOrnekRaporExcelTest extends TestCase
{
    public function test_writes_valid_xlsx_template(): void
    {
        $path = sys_get_temp_dir().'/analiz_sablon_test_'.uniqid('', true).'.xlsx';

        try {
            AnalizEkibiOrnekRaporExcel::writeEmptyTemplateToFile($path);

            $this->assertFileExists($path);
            $this->assertGreaterThan(1000, filesize($path));

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($path));
            $this->assertNotFalse($zip->locateName('xl/workbook.xml'));
            $zip->close();
        } finally {
            @unlink($path);
        }
    }

    public function test_writes_filled_note_export(): void
    {
        $note = new ControlTeamAuditNote([
            'yil' => 2026,
            'ay' => '06',
            'note' => "Genel değerlendirme satırı.\nİkinci satır.",
            'rapor_verileri' => AnalizEkibiRaporVerileri::normalize([
                'ozet' => [
                    'yapilan_is' => 12,
                    'acikta_bekleyen' => 5,
                    'tamamlanma_orani' => 71,
                ],
                'kalem_analizi' => [
                    ['kalem' => 'Stok planı', 'gerceklesen' => 1, 'acikta' => 3, 'durum' => 'Riskli'],
                ],
            ]),
        ]);

        $path = sys_get_temp_dir().'/analiz_note_test_'.uniqid('', true).'.xlsx';

        try {
            AnalizEkibiOrnekRaporExcel::writeNoteToFile($note, $path);
            $this->assertFileExists($path);
            $this->assertGreaterThan(1000, filesize($path));
        } finally {
            @unlink($path);
        }
    }
}
