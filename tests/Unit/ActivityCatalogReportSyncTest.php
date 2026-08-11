<?php

namespace Tests\Unit;

use App\Models\ActivityCatalog;
use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\ActivityCatalogReportSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ActivityCatalogReportSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('aylik_faaliyets');
        Schema::dropIfExists('activity_catalogs');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->timestamps();
        });

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

        Schema::create('aylik_faaliyets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->unsignedSmallInteger('yil')->nullable();
            $table->string('ay')->nullable();
            $table->unsignedTinyInteger('hafta')->nullable();
            $table->json('faaliyetler')->nullable();
            $table->timestamps();
        });
    }

    public function test_preview_and_apply_updates_family_and_kapsam_kalemleri(): void
    {
        $catalog = ActivityCatalog::query()->create([
            'mudurluk' => 'Test Sync Müdürlüğü',
            'faaliyet_kodu' => 'TST-99',
            'faaliyet_ailesi' => 'Yeni Aile Adı',
            'kapsam' => 'Kalem A, Kalem B, Kalem C',
            'olcu_birimi' => 'adet',
            'raporlama_sikligi' => 'Haftalık',
            'baskanlik_bilgilendirme_seviyesi' => 'Bilgi amaçlı',
        ]);

        $user = User::query()->create([
            'name' => 'Test Sync Müdürlüğü',
            'email' => 'sync-test@example.com',
            'password' => 'x',
            'role' => User::ROLE_MUDURLUK,
        ]);

        $report = AylikFaaliyet::query()->create([
            'user_id' => $user->id,
            'yil' => 2026,
            'ay' => '07',
            'hafta' => 2,
            'faaliyetler' => [
                [
                    'activity_catalog_id' => $catalog->id,
                    'faaliyet_kodu' => 'TST-99',
                    'kapsam_icerigi' => 'Eski Aile Adı',
                    'olcu_birimi' => 'eski-birim',
                    'raporlama_sikligi' => 'Aylık',
                    'baskanlik_bilgilendirme_seviyesi' => 'Takip edilecek',
                    'kapsam_verileri' => [
                        ['kalem' => 'Kalem A', 'ongorulen' => 10, 'gerceklesen' => 4],
                        ['kalem' => 'Kalem B', 'ongorulen' => 5, 'gerceklesen' => 1],
                    ],
                ],
            ],
        ]);

        $preview = ActivityCatalogReportSync::previewForCatalog($catalog);

        $this->assertSame(1, $preview['summary']['reports']);
        $this->assertGreaterThan(0, $preview['summary']['change_fields']);
        $this->assertSame('TST-99', $preview['items'][0]['faaliyet_kodu']);

        $fieldLabels = array_column($preview['items'][0]['changes'], 'field_label');
        $this->assertContains('Faaliyet ailesi', $fieldLabels);
        $this->assertContains('Kapsam kalemleri', $fieldLabels);

        $stats = ActivityCatalogReportSync::applyForCatalog($catalog);
        $this->assertSame(1, $stats['reports']);

        $report->refresh();
        $row = $report->faaliyetler[0];
        $this->assertSame('Yeni Aile Adı', $row['kapsam_icerigi']);
        $this->assertSame('adet', $row['olcu_birimi']);
        $kalemler = array_map(fn ($l) => $l['kalem'], $row['kapsam_verileri']);
        $this->assertSame(['Kalem A', 'Kalem B', 'Kalem C'], $kalemler);
        $this->assertSame(10, $row['kapsam_verileri'][0]['ongorulen']);
        $this->assertSame(4, $row['kapsam_verileri'][0]['gerceklesen']);

        $after = ActivityCatalogReportSync::previewForCatalog($catalog);
        $this->assertSame(0, $after['summary']['reports']);
    }

    public function test_preview_detects_removed_kapsam_kalem_and_apply_drops_it(): void
    {
        $catalog = ActivityCatalog::query()->create([
            'mudurluk' => 'Test Sync Müdürlüğü',
            'faaliyet_kodu' => 'TST-88',
            'faaliyet_ailesi' => 'Planlı Bakım',
            'kapsam' => 'Periyodik bakım, muayene',
            'olcu_birimi' => 'adet',
            'raporlama_sikligi' => 'Haftalık',
            'baskanlik_bilgilendirme_seviyesi' => 'Bilgi amaçlı',
        ]);

        $user = User::query()->create([
            'name' => 'Test Sync Müdürlüğü',
            'email' => 'sync-remove@example.com',
            'password' => 'x',
            'role' => User::ROLE_MUDURLUK,
        ]);

        $report = AylikFaaliyet::query()->create([
            'user_id' => $user->id,
            'yil' => 2026,
            'ay' => '07',
            'hafta' => 1,
            'faaliyetler' => [
                [
                    'activity_catalog_id' => $catalog->id,
                    'faaliyet_kodu' => 'TST-88',
                    'kapsam_icerigi' => 'Planlı Bakım',
                    'olcu_birimi' => 'adet',
                    'raporlama_sikligi' => 'Haftalık',
                    'baskanlik_bilgilendirme_seviyesi' => 'Bilgi amaçlı',
                    'kapsam_verileri' => [
                        ['kalem' => 'Periyodik bakım', 'ongorulen' => 3, 'gerceklesen' => 1],
                        ['kalem' => 'muayene', 'ongorulen' => 2, 'gerceklesen' => 0],
                        ['kalem' => 'kalibrasyon hazırlığı', 'ongorulen' => 1, 'gerceklesen' => 0],
                    ],
                ],
            ],
        ]);

        $preview = ActivityCatalogReportSync::previewForCatalog($catalog);
        $this->assertSame(1, $preview['summary']['reports']);
        $fieldLabels = array_column($preview['items'][0]['changes'], 'field_label');
        $this->assertContains('Kapsam kalemleri', $fieldLabels);

        ActivityCatalogReportSync::applyForCatalog($catalog);
        $report->refresh();
        $kalemler = array_map(fn ($l) => $l['kalem'], $report->faaliyetler[0]['kapsam_verileri']);
        $this->assertSame(['Periyodik bakım', 'muayene'], $kalemler);
    }

    public function test_create_missing_from_snapshot_does_not_overwrite_admin_kapsam(): void
    {
        $catalog = ActivityCatalog::query()->create([
            'mudurluk' => 'Makine İkmal Bakım ve Onarım Müdürlüğü',
            'faaliyet_kodu' => 'MKM-01',
            'faaliyet_ailesi' => 'Planlı Bakım',
            'kapsam' => 'Admin yeni kapsam',
            'olcu_birimi' => 'araç / ekipman',
            'raporlama_sikligi' => 'Haftalık / Aylık',
            'baskanlik_bilgilendirme_seviyesi' => '',
        ]);

        // Eski davranış: options resolve → snapshot updateExisting → admin kapsam geri yazılıyordu.
        app(\App\Services\ActivityService::class)->forgetCache();
        app(\App\Services\ActivityService::class)
            ->resolveCatalogOptionsForMudurluk('Makine İkmal Bakım ve Onarım Müdürlüğü');

        $this->assertSame('Admin yeni kapsam', $catalog->fresh()->kapsam);

        $stats = app(\App\Services\ActivityCatalogSqlImportService::class)->createMissingFromRows([
            [
                'faaliyet_kodu' => 'MKM-01',
                'mudurluk' => 'Makine İkmal Bakım ve Onarım Müdürlüğü',
                'faaliyet_ailesi' => 'Planlı Bakım',
                'kategori' => 'Destek',
                'kapsam' => 'Periyodik bakım, muayene, kalibrasyon hazırlığı',
                'olcu_birimi' => 'araç / ekipman',
                'kpi_sla' => '',
                'raporlama_sikligi' => 'Haftalık / Aylık',
                'baskanlik_bilgilendirme_seviyesi' => '',
            ],
            [
                'faaliyet_kodu' => 'MKM-NEW-TEST',
                'mudurluk' => 'Makine İkmal Bakım ve Onarım Müdürlüğü',
                'faaliyet_ailesi' => 'Yeni',
                'kategori' => 'Destek',
                'kapsam' => 'Yeni kalem',
                'olcu_birimi' => 'adet',
                'kpi_sla' => '',
                'raporlama_sikligi' => 'Aylık',
                'baskanlik_bilgilendirme_seviyesi' => '',
            ],
        ]);

        $this->assertSame(1, $stats['created']);
        $this->assertSame(1, $stats['skipped_existing']);
        $this->assertSame('Admin yeni kapsam', $catalog->fresh()->kapsam);
        $this->assertTrue(ActivityCatalog::query()->where('faaliyet_kodu', 'MKM-NEW-TEST')->exists());
    }

    public function test_preview_html_handles_empty(): void
    {
        $html = ActivityCatalogReportSync::previewToHtml([
            'summary' => ['reports' => 0, 'rows' => 0, 'change_fields' => 0],
            'items' => [],
            'truncated' => false,
        ]);

        $this->assertStringContainsString('güncellenecek rapor satırı bulunamadı', $html);
    }
}
