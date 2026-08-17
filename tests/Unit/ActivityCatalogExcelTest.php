<?php

namespace Tests\Unit;

use App\Models\ActivityCatalog;
use App\Support\ActivityCatalogExcel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use ZipArchive;

class ActivityCatalogExcelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('activity_catalogs');
        Schema::create('activity_catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('mudurluk')->nullable();
            $table->string('faaliyet_kodu')->nullable();
            $table->string('faaliyet_ailesi')->nullable();
            $table->string('kategori')->nullable();
            $table->text('kapsam')->nullable();
            $table->string('olcu_birimi')->nullable();
            $table->string('kpi_sla')->nullable();
            $table->string('raporlama_sikligi')->nullable();
            $table->string('baskanlik_bilgilendirme_seviyesi')->nullable();
            $table->timestamps();
        });
    }

    public function test_splits_kapsam_into_alt_kalem_rows_per_mudurluk(): void
    {
        ActivityCatalog::query()->create([
            'mudurluk' => 'Su ve Kanalizasyon Müdürlüğü',
            'faaliyet_kodu' => 'SKM-02',
            'faaliyet_ailesi' => 'Şebeke arıza',
            'kapsam' => 'Su arızası, isale hattı, pompa ve bakım işleri',
            'olcu_birimi' => 'arıza / müdahale',
            'kpi_sla' => 'zamanında gerçekleşme oranı',
            'raporlama_sikligi' => 'Haftalık',
            'kategori' => 'Operasyon',
        ]);
        ActivityCatalog::query()->create([
            'mudurluk' => 'Makine İkmal Müdürlüğü',
            'faaliyet_kodu' => 'MKM-01',
            'faaliyet_ailesi' => 'Planlı bakım',
            'kapsam' => 'Periyodik bakım, muayene',
            'olcu_birimi' => 'adet',
            'kpi_sla' => '',
            'raporlama_sikligi' => 'Haftalık',
            'kategori' => '',
        ]);

        $detail = ActivityCatalogExcel::detailRows(ActivityCatalog::query()->orderBy('mudurluk')->orderBy('faaliyet_kodu')->get());
        $this->assertCount(5, $detail);
        $this->assertSame('Makine İkmal Müdürlüğü', $detail[0]['mudurluk']);
        $this->assertSame('Periyodik bakım', $detail[0]['kalem']);
        $this->assertSame('muayene', $detail[1]['kalem']);
        $this->assertSame('Su arızası', $detail[2]['kalem']);

        $ozet = ActivityCatalogExcel::summaryRows($detail);
        $this->assertCount(2, $ozet);
        $mkm = collect($ozet)->firstWhere('mudurluk', 'Makine İkmal Müdürlüğü');
        $this->assertSame(1, $mkm['kod_sayisi']);
        $this->assertSame(2, $mkm['kalem_sayisi']);
    }

    public function test_writes_xlsx_with_katalog_and_ozet_sheets(): void
    {
        ActivityCatalog::query()->create([
            'mudurluk' => 'Su ve Kanalizasyon Müdürlüğü',
            'faaliyet_kodu' => 'SKM-01',
            'faaliyet_ailesi' => 'Arıtma',
            'kapsam' => 'Arıtma, Dezenfeksiyon',
            'olcu_birimi' => 'numune',
        ]);

        $path = sys_get_temp_dir().'/katalog_excel_'.uniqid('', true).'.xlsx';
        try {
            ActivityCatalogExcel::writeToFile($path);
            $this->assertFileExists($path);
            $this->assertGreaterThan(1000, filesize($path));

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $this->assertNotFalse($zip->locateName('xl/workbook.xml'));
            $workbook = (string) $zip->getFromName('xl/workbook.xml');
            $this->assertStringContainsString('Katalog', $workbook);
            $this->assertStringContainsString('Özet', $workbook);
            $zip->close();
        } finally {
            @unlink($path);
        }
    }
}
