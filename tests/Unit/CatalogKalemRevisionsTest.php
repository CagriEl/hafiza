<?php

namespace Tests\Unit;

use App\Models\ActivityCatalog;
use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\CatalogKalemRevisions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogKalemRevisionsTest extends TestCase
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

    public function test_ulm05_rename_preserves_numbers_and_ulasim_week3_pending_is_closed(): void
    {
        $ulasim = User::query()->create([
            'name' => 'Ulaşım Hizmetleri Müdürlüğü',
            'email' => 'ulm-rev@example.com',
            'password' => 'x',
            'role' => User::ROLE_MUDURLUK,
        ]);

        ActivityCatalog::query()->create([
            'mudurluk' => 'Ulaşım Hizmetleri Müdürlüğü',
            'faaliyet_kodu' => 'ULM-05',
            'faaliyet_ailesi' => 'Geçici Trafik Düzeni ve Şantiye Güvenliği',
            'kapsam' => 'Geçici trafik planı ve saha uygulama takibi',
            'olcu_birimi' => 'uygulama sayısı',
        ]);
        ActivityCatalog::query()->create([
            'mudurluk' => 'Ulaşım Hizmetleri Müdürlüğü',
            'faaliyet_kodu' => 'ULM-04',
            'faaliyet_ailesi' => 'Sinyalizasyon ve İşaretleme',
            'kapsam' => 'Arıza, bakım, yatay-dikey işaretleme',
            'olcu_birimi' => 'arıza / iş',
        ]);

        $week3 = AylikFaaliyet::query()->create([
            'user_id' => $ulasim->id,
            'yil' => 2026,
            'ay' => '07',
            'hafta' => 3,
            'faaliyetler' => [
                [
                    'faaliyet_kodu' => 'ULM-04',
                    'kapsam_icerigi' => 'Sinyalizasyon ve İşaretleme',
                    'olcu_birimi' => 'arıza / iş',
                    'kapsam_verileri' => [
                        ['kalem' => 'yatay-dikey işaretleme', 'ongorulen' => 7, 'gerceklesen' => 5, 'acikta_kalan' => 2],
                    ],
                    'gerceklesen' => 5,
                    'bekleyen_is' => 2,
                ],
                [
                    'faaliyet_kodu' => 'ULM-05',
                    'kapsam_icerigi' => 'Geçici trafik planı ve saha uygulama takibi',
                    'olcu_birimi' => 'uygulama sayısı',
                    'kapsam_verileri' => [
                        ['kalem' => 'Geçici trafik planı ve saha uygulama takibi', 'ongorulen' => 1, 'gerceklesen' => 1, 'acikta_kalan' => 0],
                    ],
                ],
            ],
        ]);

        CatalogKalemRevisions::apply(false);

        $this->assertSame(
            'Geçici trafik planı',
            ActivityCatalog::query()->where('faaliyet_kodu', 'ULM-05')->value('kapsam')
        );

        $week3->refresh();
        $ulm05 = collect($week3->faaliyetler)->first(fn ($r) => ($r['faaliyet_kodu'] ?? '') === 'ULM-05');
        $this->assertSame('Geçici trafik planı', $ulm05['kapsam_verileri'][0]['kalem']);
        $this->assertSame(1, $ulm05['kapsam_verileri'][0]['ongorulen']);
        $this->assertSame(1, $ulm05['kapsam_verileri'][0]['gerceklesen']);

        $ulm04 = collect($week3->faaliyetler)->first(fn ($r) => ($r['faaliyet_kodu'] ?? '') === 'ULM-04');
        $this->assertSame(7, $ulm04['kapsam_verileri'][0]['gerceklesen']);
        $this->assertSame(0.0, (float) $ulm04['kapsam_verileri'][0]['acikta_kalan']);
        $this->assertSame(0.0, (float) $ulm04['bekleyen_is']);
    }

    public function test_skm_and_mkm_patches_preserve_existing_kalem_numbers(): void
    {
        $skm = User::query()->create([
            'name' => 'Su ve Kanalizasyon Müdürlüğü',
            'email' => 'skm-rev@example.com',
            'password' => 'x',
            'role' => User::ROLE_MUDURLUK,
        ]);
        $mkm = User::query()->create([
            'name' => 'Makine İkmal Bakım ve Onarım Müdürlüğü',
            'email' => 'mkm-rev@example.com',
            'password' => 'x',
            'role' => User::ROLE_MUDURLUK,
        ]);

        foreach ([
            ['SKM-02', 'Su ve Kanalizasyon Müdürlüğü', 'Şebeke Arıza, Bakım ve Onarım', 'Su arızası, isale hattı, pompa ve bakım işleri, Arıza kontrol', 'arıza / müdahale'],
            ['SKM-03', 'Su ve Kanalizasyon Müdürlüğü', 'Abonelik ve Tahakkuk İşlemleri', 'Abonelik açma-kapama, değişiklik, bildirim', 'işlem sayısı'],
            ['SKM-04', 'Su ve Kanalizasyon Müdürlüğü', 'Sayaç, Endeks ve Kaçak Su', 'Sayaç kontrol, endeks, kaçak tespiti', 'işlem / tutanak'],
            ['MKM-01', 'Makine İkmal Bakım ve Onarım Müdürlüğü', 'Planlı Bakım', 'Periyodik bakım, muayene, kalibrasyon hazırlığı', 'araç / ekipman'],
            ['MKM-03', 'Makine İkmal Bakım ve Onarım Müdürlüğü', 'Garaj ve Saha Operasyonları', 'Görev sevk, dönüş kontrolü, şoför ve nöbet planı, Engelli taşıma aracı, Hasta taşıma aracı', 'sevk / araç'],
        ] as [$code, $mud, $aile, $kapsam, $olcu]) {
            ActivityCatalog::query()->create([
                'mudurluk' => $mud,
                'faaliyet_kodu' => $code,
                'faaliyet_ailesi' => $aile,
                'kapsam' => $kapsam,
                'olcu_birimi' => $olcu,
            ]);
        }

        $skmReport = AylikFaaliyet::query()->create([
            'user_id' => $skm->id,
            'yil' => 2026,
            'ay' => '07',
            'hafta' => 1,
            'faaliyetler' => [
                [
                    'faaliyet_kodu' => 'SKM-03',
                    'kapsam_verileri' => [
                        ['kalem' => 'Abonelik açma-kapama', 'ongorulen' => 141, 'gerceklesen' => 277],
                        ['kalem' => 'değişiklik', 'ongorulen' => 91, 'gerceklesen' => 179],
                        ['kalem' => 'bildirim', 'ongorulen' => 0, 'gerceklesen' => 0],
                    ],
                ],
                [
                    'faaliyet_kodu' => 'SKM-04',
                    'olcu_birimi' => 'işlem / tutanak',
                    'kapsam_verileri' => [
                        ['kalem' => 'Sayaç kontrol', 'ongorulen' => 10, 'gerceklesen' => 10],
                        ['kalem' => 'kaçak tespiti', 'ongorulen' => 1, 'gerceklesen' => 1],
                    ],
                ],
            ],
        ]);

        $mkmReport = AylikFaaliyet::query()->create([
            'user_id' => $mkm->id,
            'yil' => 2026,
            'ay' => '07',
            'hafta' => 1,
            'faaliyetler' => [
                [
                    'faaliyet_kodu' => 'MKM-01',
                    'kapsam_verileri' => [
                        ['kalem' => 'Periyodik bakım', 'ongorulen' => 2, 'gerceklesen' => 2],
                        ['kalem' => 'muayene', 'ongorulen' => 4, 'gerceklesen' => 7],
                        ['kalem' => 'kalibrasyon hazırlığı', 'ongorulen' => 0, 'gerceklesen' => 0],
                    ],
                ],
                [
                    'faaliyet_kodu' => 'MKM-03',
                    'olcu_birimi' => 'sevk / araç',
                    'kapsam_verileri' => [
                        ['kalem' => 'Görev sevk', 'ongorulen' => 8, 'gerceklesen' => 8],
                        ['kalem' => 'Engelli taşıma aracı', 'ongorulen' => 1, 'gerceklesen' => 1],
                        ['kalem' => 'Hasta taşıma aracı', 'ongorulen' => 2, 'gerceklesen' => 3],
                    ],
                ],
            ],
        ]);

        CatalogKalemRevisions::apply(false);

        $this->assertSame('adet / tutanak', ActivityCatalog::query()->where('faaliyet_kodu', 'SKM-04')->value('olcu_birimi'));
        $this->assertSame('sevk', ActivityCatalog::query()->where('faaliyet_kodu', 'MKM-03')->value('olcu_birimi'));
        $this->assertStringContainsString('Arıza kontrol', (string) ActivityCatalog::query()->where('faaliyet_kodu', 'SKM-03')->value('kapsam'));
        $this->assertStringNotContainsString('bildirim', (string) ActivityCatalog::query()->where('faaliyet_kodu', 'SKM-03')->value('kapsam'));

        $skmReport->refresh();
        $skm03 = collect($skmReport->faaliyetler)->first(fn ($r) => ($r['faaliyet_kodu'] ?? '') === 'SKM-03');
        $kalemler = array_column($skm03['kapsam_verileri'], 'kalem');
        $this->assertNotContains('bildirim', $kalemler);
        $this->assertContains('Arıza kontrol', $kalemler);
        $this->assertSame(141, $skm03['kapsam_verileri'][0]['ongorulen']);
        $this->assertSame(277, $skm03['kapsam_verileri'][0]['gerceklesen']);

        $skm04 = collect($skmReport->faaliyetler)->first(fn ($r) => ($r['faaliyet_kodu'] ?? '') === 'SKM-04');
        $this->assertSame('adet / tutanak', $skm04['olcu_birimi']);
        $this->assertSame(1, collect($skm04['kapsam_verileri'])->firstWhere('kalem', 'kaçak tespiti')['gerceklesen']);

        $mkmReport->refresh();
        $mkm01 = collect($mkmReport->faaliyetler)->first(fn ($r) => ($r['faaliyet_kodu'] ?? '') === 'MKM-01');
        $this->assertSame(['Periyodik bakım', 'muayene'], array_column($mkm01['kapsam_verileri'], 'kalem'));
        $this->assertSame(7, $mkm01['kapsam_verileri'][1]['gerceklesen']);

        $mkm03 = collect($mkmReport->faaliyetler)->first(fn ($r) => ($r['faaliyet_kodu'] ?? '') === 'MKM-03');
        $this->assertSame('sevk', $mkm03['olcu_birimi']);
        $merged = collect($mkm03['kapsam_verileri'])->firstWhere('kalem', 'Engelli ve hasta taşıma aracı');
        $this->assertNotNull($merged);
        $this->assertSame(3.0, (float) $merged['ongorulen']);
        $this->assertSame(4.0, (float) $merged['gerceklesen']);
        $this->assertContains('Araç talepleri', array_column($mkm03['kapsam_verileri'], 'kalem'));
    }

    public function test_mkm04_drops_verimlilik_analizi_from_catalog_and_form_rows(): void
    {
        $mkm = User::query()->create([
            'name' => 'Makine İkmal Bakım ve Onarım Müdürlüğü',
            'email' => 'mkm04-rev@example.com',
            'password' => 'x',
            'role' => User::ROLE_MUDURLUK,
        ]);

        ActivityCatalog::query()->create([
            'mudurluk' => 'Makine İkmal Bakım ve Onarım Müdürlüğü',
            'faaliyet_kodu' => 'MKM-04',
            'faaliyet_ailesi' => 'Yakıt ve Telematik Verimliliği',
            'kapsam' => 'Yakıt tüketimi, verimlilik analizi',
            'olcu_birimi' => 'lt',
        ]);

        $report = AylikFaaliyet::query()->create([
            'user_id' => $mkm->id,
            'yil' => 2026,
            'ay' => '08',
            'hafta' => 1,
            'faaliyetler' => [
                [
                    'faaliyet_kodu' => 'MKM-04',
                    'kapsam_verileri' => [
                        ['kalem' => 'Yakıt tüketimi', 'ongorulen' => 10, 'gerceklesen' => 8],
                        ['kalem' => 'verimlilik analizi', 'ongorulen' => 1, 'gerceklesen' => 0],
                        ['kalem' => 'rölanti', 'ongorulen' => 3, 'gerceklesen' => 3],
                    ],
                ],
                [
                    'faaliyet_kodu' => 'MKM-01',
                    'kapsam_verileri' => [
                        ['kalem' => 'Periyodik bakım', 'ongorulen' => 2, 'gerceklesen' => 2],
                        ['kalem' => 'muayene', 'ongorulen' => 4, 'gerceklesen' => 4],
                    ],
                ],
            ],
        ]);

        CatalogKalemRevisions::resetEnsureState();
        CatalogKalemRevisions::ensureApplied();

        $this->assertSame(
            'Yakıt tüketimi',
            ActivityCatalog::query()->where('faaliyet_kodu', 'MKM-04')->value('kapsam')
        );

        $report->refresh();
        $mkm04 = collect($report->faaliyetler)->first(fn ($r) => ($r['faaliyet_kodu'] ?? '') === 'MKM-04');
        $kalemler = array_column($mkm04['kapsam_verileri'], 'kalem');
        $this->assertSame(['Yakıt tüketimi', 'rölanti'], $kalemler);
        $this->assertSame(10, $mkm04['kapsam_verileri'][0]['ongorulen']);
        $this->assertSame(8, $mkm04['kapsam_verileri'][0]['gerceklesen']);
        $this->assertSame(3, collect($mkm04['kapsam_verileri'])->firstWhere('kalem', 'rölanti')['gerceklesen']);

        $mkm01 = collect($report->faaliyetler)->first(fn ($r) => ($r['faaliyet_kodu'] ?? '') === 'MKM-01');
        $this->assertSame(['Periyodik bakım', 'muayene'], array_column($mkm01['kapsam_verileri'], 'kalem'));
        $this->assertSame(4, $mkm01['kapsam_verileri'][1]['gerceklesen']);
    }

    public function test_ensure_applied_upgrades_legacy_catalog_for_new_reports(): void
    {
        CatalogKalemRevisions::resetEnsureState();

        $mkm = User::query()->create([
            'name' => 'Makine İkmal Bakım ve Onarım Müdürlüğü',
            'email' => 'mkm-ensure@example.com',
            'password' => 'x',
            'role' => User::ROLE_MUDURLUK,
        ]);

        ActivityCatalog::query()->create([
            'mudurluk' => 'Makine İkmal Bakım ve Onarım Müdürlüğü',
            'faaliyet_kodu' => 'MKM-01',
            'faaliyet_ailesi' => 'Planlı Bakım',
            'kapsam' => 'Periyodik bakım, muayene, kalibrasyon hazırlığı',
            'olcu_birimi' => 'araç / ekipman',
        ]);

        app(\App\Services\ActivityService::class)->forgetCache();
        $options = app(\App\Services\ActivityService::class)
            ->resolveCatalogOptionsForMudurluk('Makine İkmal Bakım ve Onarım Müdürlüğü')['options'];

        $this->assertNotEmpty($options);
        $this->assertSame(
            'Periyodik bakım, muayene',
            ActivityCatalog::query()->where('faaliyet_kodu', 'MKM-01')->value('kapsam')
        );

        $data = \App\Filament\Resources\AylikFaaliyetResource::syncFaaliyetlerWithCurrentCatalog(
            [
                'faaliyetler' => [
                    [
                        'faaliyet_kodu' => 'MKM-01',
                        'kapsam_verileri' => [
                            ['kalem' => 'Periyodik bakım', 'ongorulen' => 2, 'gerceklesen' => 2],
                            ['kalem' => 'muayene', 'ongorulen' => 4, 'gerceklesen' => 4],
                            ['kalem' => 'kalibrasyon hazırlığı', 'ongorulen' => 0, 'gerceklesen' => 0],
                        ],
                    ],
                ],
            ],
            $mkm->name
        );

        $kalemler = array_column($data['faaliyetler'][0]['kapsam_verileri'], 'kalem');
        $this->assertSame(['Periyodik bakım', 'muayene'], $kalemler);
        $this->assertSame(2, $data['faaliyetler'][0]['kapsam_verileri'][0]['ongorulen']);
    }
}
