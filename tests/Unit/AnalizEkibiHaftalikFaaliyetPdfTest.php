<?php

namespace Tests\Unit;

use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\AnalizEkibiHaftalikFaaliyetPdf;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnalizEkibiHaftalikFaaliyetPdfTest extends TestCase
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

    public function test_pdf_html_includes_kalem_olcu_and_note(): void
    {
        User::query()->create([
            'name' => 'Sistem',
            'email' => 'sistem-pdf@example.com',
            'password' => 'secret',
            'role' => null,
        ]);
        $analiz = User::query()->create([
            'name' => 'Analiz Üye',
            'email' => 'analiz-pdf@example.com',
            'password' => 'secret',
            'role' => User::ROLE_ANALIZ_EKIBI,
        ]);
        $mudurluk = User::query()->create([
            'name' => 'Su ve Kanalizasyon',
            'email' => 'su-pdf@example.com',
            'password' => 'secret',
            'role' => null,
        ]);
        $analiz->assignedDirectorates()->attach($mudurluk->id);

        AylikFaaliyet::withoutEvents(function () use ($mudurluk): void {
            AylikFaaliyet::query()->create([
                'user_id' => $mudurluk->id,
                'yil' => 2026,
                'ay' => '07',
                'hafta' => '4',
                'faaliyetler' => [
                    [
                        'faaliyet_kodu' => 'SKM-04',
                        'kapsam_verileri' => [
                            [
                                'kalem' => 'Sayaç kontrol',
                                'ongorulen' => 74,
                                'gerceklesen' => 74,
                                'olcu_birimi' => 'adet / tutanak',
                            ],
                        ],
                    ],
                ],
            ]);
        });

        $html = AnalizEkibiHaftalikFaaliyetPdf::htmlFromFormState([
            'directorate_user_id' => $mudurluk->id,
            'yil' => 2026,
            'ay' => '07',
            'hafta' => '4',
            'note' => 'Sayaç okuma bu hafta kapandı.',
        ], $analiz);

        $this->assertStringContainsString('Faaliyet bazında öngörülen / gerçekleşen', $html);
        $this->assertStringContainsString('bgcolor="#94a3b8"', $html);
        $this->assertTrue(
            str_contains($html, 'bgcolor="#2563eb"') || str_contains($html, 'bgcolor="#16a34a"')
        );
        $this->assertStringContainsString('Su ve Kanalizasyon', $html);
        $this->assertStringContainsString('Sayaç kontrol', $html);
        $this->assertStringContainsString('adet / tutanak', $html);
        $this->assertStringContainsString('Sayaç okuma bu hafta kapandı.', $html);
        $this->assertStringContainsString('analiz_raporu_su-ve-kanalizasyon_2026_07_h4.pdf', AnalizEkibiHaftalikFaaliyetPdf::filenameFromScreen(
            AnalizEkibiHaftalikFaaliyetPdf::screenFromFormState([
                'directorate_user_id' => $mudurluk->id,
                'yil' => 2026,
                'ay' => '07',
                'hafta' => '4',
            ], $analiz)
        ));
    }
}
