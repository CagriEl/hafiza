<?php

namespace Tests\Unit;

use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\AnalizEkibiMudurlukRapor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnalizEkibiMudurlukRaporTest extends TestCase
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
            $table->string('olcu_birimi')->nullable();
            $table->string('kpi_sla')->nullable();
            $table->string('raporlama_sikligi')->nullable();
            $table->string('baskanlik_bilgilendirme_seviyesi')->nullable();
            $table->timestamps();
        });

        Schema::create('aylik_faaliyets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedSmallInteger('yil')->nullable();
            $table->string('ay')->nullable();
            $table->json('faaliyetler')->nullable();
            $table->timestamps();
        });
    }

    public function test_build_for_user_aggregates_assigned_directorates(): void
    {
        $analiz = User::query()->create([
            'name' => 'Analiz Üye',
            'email' => 'analiz@example.com',
            'password' => 'secret',
            'role' => User::ROLE_ANALIZ_EKIBI,
        ]);

        $mudurluk = User::query()->create([
            'name' => 'Fen İşleri',
            'email' => 'fen@example.com',
            'password' => 'secret',
            'role' => null,
        ]);

        $analiz->assignedDirectorates()->attach($mudurluk->id);

        AylikFaaliyet::withoutEvents(function () use ($mudurluk): void {
            AylikFaaliyet::query()->create([
                'user_id' => $mudurluk->id,
                'yil' => 2026,
                'ay' => '07',
                'faaliyetler' => [
                    [
                        'faaliyet_kodu' => 'FNM-01',
                        'hedef' => 10,
                        'gerceklesen' => 7,
                        'bekleyen_is' => 3,
                        'gerekli_revize' => true,
                        'karar_ihtiyaci' => '',
                        'sapma_nedeni' => 'Malzeme gecikmesi',
                    ],
                ],
            ]);
        });

        $report = AnalizEkibiMudurlukRapor::buildForUser($analiz, 2026, 7);

        $this->assertSame('Temmuz 2026', $report['donem_etiketi']);
        $this->assertSame(1, $report['ozet']['mudurluk_sayisi']);
        $this->assertSame(1, $report['ozet']['rapor_olan']);
        $this->assertSame(10, $report['ozet']['hedef']);
        $this->assertSame(7, $report['ozet']['gerceklesen']);
        $this->assertSame(3, $report['ozet']['kalan']);
        $this->assertSame(70, $report['ozet']['tamamlanma_orani']);
        $this->assertCount(1, $report['mudurlukler']);
        $this->assertTrue($report['mudurlukler'][0]['rapor_var']);
        $this->assertSame('Fen İşleri', $report['mudurlukler'][0]['name']);
        $this->assertCount(1, $report['mudurlukler'][0]['faaliyetler']);
        $this->assertTrue($report['mudurlukler'][0]['faaliyetler'][0]['dikkat']);
        $this->assertSame('Kısmi', $report['mudurlukler'][0]['faaliyetler'][0]['durum']);
    }

    public function test_missing_period_report_is_marked_empty(): void
    {
        $detail = AnalizEkibiMudurlukRapor::buildDirectorateDetail(99, 'Yok', 2026, '07');

        $this->assertFalse($detail['rapor_var']);
        $this->assertSame(0, $detail['ozet']['hedef']);
        $this->assertSame([], $detail['faaliyetler']);
    }
}
