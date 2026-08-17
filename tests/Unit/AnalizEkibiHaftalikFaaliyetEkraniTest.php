<?php

namespace Tests\Unit;

use App\Models\ActivityCatalog;
use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\AnalizEkibiHaftalikFaaliyetEkrani;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnalizEkibiHaftalikFaaliyetEkraniTest extends TestCase
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
            $table->string('olcu_birimi')->nullable();
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

    public function test_assigned_analiz_sees_week_report_with_olcu_and_tavsiye(): void
    {
        [$analiz, $mudurluk] = $this->makeAssignedPair();

        ActivityCatalog::query()->create([
            'faaliyet_kodu' => 'SKM-02',
            'faaliyet_ailesi' => 'Şebeke arıza',
            'olcu_birimi' => 'arıza / müdahale',
            'mudurluk' => $mudurluk->name,
        ]);

        AylikFaaliyet::withoutEvents(function () use ($mudurluk): void {
            AylikFaaliyet::query()->create([
                'user_id' => $mudurluk->id,
                'yil' => 2026,
                'ay' => '07',
                'hafta' => '4',
                'faaliyetler' => [
                    [
                        'faaliyet_kodu' => 'SKM-02',
                        'olcu_birimi' => 'arıza / müdahale',
                        'kapsam_icerigi' => 'Şebeke arıza',
                        'kapsam_verileri' => [
                            [
                                'kalem' => 'Su arızası',
                                'ongorulen' => 21,
                                'gerceklesen' => 10,
                                'olcu_birimi' => 'arıza / müdahale',
                            ],
                            [
                                'kalem' => 'Pompa ve bakım',
                                'olcu_birimi' => 'adet',
                            ],
                        ],
                    ],
                ],
            ]);
        });

        $screen = AnalizEkibiHaftalikFaaliyetEkrani::build($analiz, (int) $mudurluk->id, 2026, '07', 4);

        $this->assertTrue($screen['rapor_var']);
        $this->assertSame(2, $screen['ozet']['kalem_sayisi']);
        $this->assertSame('arıza / müdahale', $screen['rows'][0]['olcu']);
        $this->assertSame('adet', $screen['rows'][1]['olcu']);
        $this->assertSame('danger', $screen['rows'][0]['tone']);
        $this->assertSame('warning', $screen['rows'][1]['tone']);
        $this->assertSame('yuksek', $screen['tavsiye']['seviye']);
        $this->assertStringContainsString('arıza / müdahale', $screen['tavsiye']['maddeler'][0]);
        $this->assertStringStartsWith('Sistem tavsiyesi:', $screen['tavsiye']['ozet']);
    }

    public function test_unassigned_analiz_cannot_see_report(): void
    {
        User::query()->create([
            'name' => 'Sistem',
            'email' => 'sistem-deny-hf@example.com',
            'password' => 'secret',
            'role' => null,
        ]);
        $analiz = User::query()->create([
            'name' => 'Analiz Üye',
            'email' => 'analiz-no@example.com',
            'password' => 'secret',
            'role' => User::ROLE_ANALIZ_EKIBI,
        ]);
        $mudurluk = User::query()->create([
            'name' => 'Fen İşleri',
            'email' => 'fen-no@example.com',
            'password' => 'secret',
            'role' => null,
        ]);

        AylikFaaliyet::withoutEvents(function () use ($mudurluk): void {
            AylikFaaliyet::query()->create([
                'user_id' => $mudurluk->id,
                'yil' => 2026,
                'ay' => '07',
                'hafta' => '4',
                'faaliyetler' => [
                    ['faaliyet_kodu' => 'FNM-01', 'hedef' => 4, 'gerceklesen' => 4],
                ],
            ]);
        });

        $screen = AnalizEkibiHaftalikFaaliyetEkrani::build($analiz, (int) $mudurluk->id, 2026, '07', 4);

        $this->assertFalse($screen['rapor_var']);
        $this->assertSame('Yetki yok', $screen['durum']);
        $this->assertSame([], $screen['rows']);
    }

    public function test_week_selection_does_not_mix_other_weeks(): void
    {
        [$analiz, $mudurluk] = $this->makeAssignedPair();

        AylikFaaliyet::withoutEvents(function () use ($mudurluk): void {
            AylikFaaliyet::query()->create([
                'user_id' => $mudurluk->id,
                'yil' => 2026,
                'ay' => '07',
                'hafta' => '2',
                'faaliyetler' => [
                    [
                        'faaliyet_kodu' => 'SKM-01',
                        'kapsam_verileri' => [
                            ['kalem' => 'Arıtma', 'ongorulen' => 5, 'gerceklesen' => 5, 'olcu_birimi' => 'numune'],
                        ],
                    ],
                ],
            ]);
            AylikFaaliyet::query()->create([
                'user_id' => $mudurluk->id,
                'yil' => 2026,
                'ay' => '07',
                'hafta' => '4',
                'faaliyetler' => [
                    [
                        'faaliyet_kodu' => 'SKM-04',
                        'kapsam_verileri' => [
                            ['kalem' => 'Sayaç', 'ongorulen' => 74, 'gerceklesen' => 74, 'olcu_birimi' => 'adet / tutanak'],
                        ],
                    ],
                ],
            ]);
        });

        $week2 = AnalizEkibiHaftalikFaaliyetEkrani::build($analiz, (int) $mudurluk->id, 2026, '07', 2);
        $week4 = AnalizEkibiHaftalikFaaliyetEkrani::build($analiz, (int) $mudurluk->id, 2026, '07', 4);

        $this->assertSame('SKM-01', $week2['rows'][0]['kod']);
        $this->assertSame('numune', $week2['rows'][0]['olcu']);
        $this->assertSame('SKM-04', $week4['rows'][0]['kod']);
        $this->assertSame('adet / tutanak', $week4['rows'][0]['olcu']);
        $this->assertSame('ok', $week4['tavsiye']['seviye']);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function makeAssignedPair(): array
    {
        User::query()->create([
            'name' => 'Sistem',
            'email' => 'sistem-hf@example.com',
            'password' => 'secret',
            'role' => null,
        ]);

        $analiz = User::query()->create([
            'name' => 'Analiz Üye',
            'email' => 'analiz-hf@example.com',
            'password' => 'secret',
            'role' => User::ROLE_ANALIZ_EKIBI,
        ]);
        $mudurluk = User::query()->create([
            'name' => 'Su ve Kanalizasyon',
            'email' => 'su-hf@example.com',
            'password' => 'secret',
            'role' => null,
        ]);
        $analiz->assignedDirectorates()->attach($mudurluk->id);

        return [$analiz, $mudurluk];
    }
}
