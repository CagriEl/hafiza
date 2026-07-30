<?php

namespace Tests\Unit;

use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\AnalizEkibiYoneticiRapor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnalizEkibiYoneticiRaporTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('aylik_faaliyets');
        Schema::dropIfExists('users');
        Schema::dropIfExists('control_team_user_directorate');
        Schema::dropIfExists('activity_catalogs');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Schema::create('control_team_user_directorate', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('directorate_id');
        });

        Schema::create('activity_catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('faaliyet_kodu')->nullable();
            $table->string('faaliyet_ailesi')->nullable();
            $table->string('mudurluk')->nullable();
            $table->timestamps();
        });

        Schema::create('aylik_faaliyets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedSmallInteger('yil')->nullable();
            $table->string('ay')->nullable();
            $table->string('hafta')->nullable();
            $table->json('faaliyetler')->nullable();
            $table->timestamps();
        });
    }

    public function test_lists_zero_entered_codes_and_pending_items_for_week(): void
    {
        $analiz = User::query()->create([
            'name' => 'Analiz Üye',
            'email' => 'analiz-yon@example.com',
            'password' => 'secret',
            'role' => User::ROLE_ANALIZ_EKIBI,
        ]);

        $mudurluk = User::query()->create([
            'name' => 'Fen İşleri',
            'email' => 'fen-yon@example.com',
            'password' => 'secret',
            'role' => null,
        ]);

        $analiz->assignedDirectorates()->attach($mudurluk->id);

        AylikFaaliyet::withoutEvents(function () use ($mudurluk): void {
            AylikFaaliyet::query()->create([
                'user_id' => $mudurluk->id,
                'yil' => 2026,
                'ay' => '07',
                'hafta' => '2',
                'faaliyetler' => [
                    [
                        'faaliyet_kodu' => 'FNM-01',
                        'kapsam_verileri' => [
                            [
                                'kalem' => 'Temizlik',
                                'ongorulen' => 10,
                                'gerceklesen' => 0,
                                'acikta_kalan' => 10,
                            ],
                        ],
                    ],
                    [
                        'faaliyet_kodu' => 'FNM-02',
                        'kapsam_verileri' => [
                            [
                                'kalem' => 'Bakım',
                                'ongorulen' => 8,
                                'gerceklesen' => 5,
                                'acikta_kalan' => 3,
                            ],
                        ],
                    ],
                    [
                        'faaliyet_kodu' => 'FNM-03',
                        'hedef' => 4,
                        'gerceklesen' => 4,
                        'bekleyen_is' => 0,
                    ],
                ],
            ]);
        });

        $report = AnalizEkibiYoneticiRapor::buildForUser($analiz, (int) $mudurluk->id, 2026, 7, 2);

        $this->assertTrue($report['rapor_var']);
        $this->assertSame(3, $report['ozet']['toplam_kod']);
        $this->assertSame(1, $report['ozet']['sifir_kod_sayisi']);
        $this->assertSame(2, $report['ozet']['acikta_kalem_sayisi']);
        $this->assertSame(13.0, $report['ozet']['acikta_toplam']);
        $this->assertSame('FNM-01', $report['sifir_girilen_kodlar'][0]['faaliyet_kodu']);
        $this->assertSame('FNM-01', $report['acikta_kalan_isler'][0]['faaliyet_kodu']);
        $this->assertSame('Temizlik', $report['acikta_kalan_isler'][0]['kalem']);
        $this->assertSame('FNM-02', $report['acikta_kalan_isler'][1]['faaliyet_kodu']);
    }

    public function test_denies_unassigned_mudurluk(): void
    {
        $analiz = User::query()->create([
            'name' => 'Analiz Üye',
            'email' => 'analiz-deny@example.com',
            'password' => 'secret',
            'role' => User::ROLE_ANALIZ_EKIBI,
        ]);

        $mudurluk = User::query()->create([
            'name' => 'Başka Md',
            'email' => 'baska@example.com',
            'password' => 'secret',
            'role' => null,
        ]);

        $report = AnalizEkibiYoneticiRapor::buildForUser($analiz, (int) $mudurluk->id, 2026, 7, 1);

        $this->assertFalse($report['rapor_var']);
        $this->assertSame(0, $report['ozet']['toplam_kod']);
    }

    public function test_weekly_overview_covers_all_assigned_mudurluks(): void
    {
        // id=1 süper admin sayıldığı için analiz hesabını 1 yapma.
        User::query()->create([
            'name' => 'Sistem',
            'email' => 'sistem@example.com',
            'password' => 'secret',
            'role' => null,
        ]);

        $analiz = User::query()->create([
            'name' => 'Analiz',
            'email' => 'analiz-all@example.com',
            'password' => 'secret',
            'role' => User::ROLE_ANALIZ_EKIBI,
        ]);

        $fen = User::query()->create([
            'name' => 'Fen İşleri',
            'email' => 'fen-all@example.com',
            'password' => 'secret',
            'role' => null,
        ]);
        $kultur = User::query()->create([
            'name' => 'Kültür',
            'email' => 'kultur-all@example.com',
            'password' => 'secret',
            'role' => null,
        ]);
        $analiz->assignedDirectorates()->attach([$fen->id, $kultur->id]);

        AylikFaaliyet::withoutEvents(function () use ($fen, $kultur): void {
            AylikFaaliyet::query()->create([
                'user_id' => $fen->id,
                'yil' => 2026,
                'ay' => '07',
                'hafta' => '1',
                'faaliyetler' => [
                    [
                        'faaliyet_kodu' => 'FNM-01',
                        'hedef' => 10,
                        'gerceklesen' => 0,
                        'bekleyen_is' => 10,
                    ],
                ],
            ]);
            AylikFaaliyet::query()->create([
                'user_id' => $kultur->id,
                'yil' => 2026,
                'ay' => '07',
                'hafta' => '1',
                'faaliyetler' => [
                    [
                        'faaliyet_kodu' => 'KLT-01',
                        'hedef' => 5,
                        'gerceklesen' => 5,
                        'bekleyen_is' => 0,
                    ],
                ],
            ]);
        });

        $report = AnalizEkibiYoneticiRapor::buildWeeklyOverview($analiz, 2026, 7, 1);

        $this->assertSame(2, $report['ozet']['mudurluk_sayisi']);
        $this->assertSame(2, $report['ozet']['rapor_olan']);
        $this->assertSame(1, $report['ozet']['sifir_kod_sayisi']);
        $this->assertSame(5.0, $report['ozet']['yapilan']);
        $this->assertSame(10.0, $report['ozet']['acikta']);
        $this->assertCount(2, $report['mudurlukler']);
        $this->assertNotEmpty($report['risk_haritasi']);
        $this->assertNotEmpty($report['aksiyonlar']);
    }
}
