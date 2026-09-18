<?php

namespace Tests\Unit;

use App\Models\KoordinasyonHafta;
use App\Models\KoordinasyonTakipMadde;
use App\Models\User;
use App\Support\AnalizEkibiKoordinasyonPlan;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnalizEkibiKoordinasyonPlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('koordinasyon_takip_maddeleri');
        Schema::dropIfExists('koordinasyon_haftalar');
        Schema::dropIfExists('control_team_user_directorate');
        Schema::dropIfExists('users');

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

        Schema::create('koordinasyon_haftalar', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('yil');
            $table->unsignedTinyInteger('ay');
            $table->unsignedTinyInteger('hafta');
            $table->json('checklist')->nullable();
            $table->text('ozet_not')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['yil', 'ay', 'hafta']);
        });

        Schema::create('koordinasyon_takip_maddeleri', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('yil');
            $table->unsignedTinyInteger('ay');
            $table->unsignedTinyInteger('hafta');
            $table->unsignedBigInteger('analiz_user_id')->nullable();
            $table->unsignedBigInteger('directorate_user_id')->nullable();
            $table->string('baslik');
            $table->string('durum', 20)->default('suphe');
            $table->boolean('saha_kontrolu')->default(false);
            $table->text('notlar')->nullable();
            $table->timestamp('kapanis_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function test_playbook_has_core_sections(): void
    {
        $playbook = AnalizEkibiKoordinasyonPlan::playbook();

        $this->assertArrayHasKey('ritim', $playbook);
        $this->assertArrayHasKey('saha_adimlari', $playbook);
        $this->assertArrayHasKey('toplanti_gundemi', $playbook);
        $this->assertNotEmpty($playbook['saha_adimlari']);
    }

    public function test_portfolio_lists_analiz_users_with_directorates(): void
    {
        $analiz = User::query()->create([
            'name' => 'Test Analiz',
            'email' => 'analiz@example.com',
            'password' => 'x',
            'role' => User::ROLE_ANALIZ_EKIBI,
        ]);
        $mudurluk = User::query()->create([
            'name' => 'Test Mudurluk',
            'email' => 'mud@example.com',
            'password' => 'x',
            'role' => User::ROLE_MUDURLUK,
        ]);
        $analiz->assignedDirectorates()->attach($mudurluk->id);

        $portfolio = AnalizEkibiKoordinasyonPlan::portfolio();
        $row = collect($portfolio)->firstWhere('id', $analiz->id);

        $this->assertNotNull($row);
        $this->assertSame(1, $row['mudurluk_sayisi']);
        $this->assertSame('Test Mudurluk', $row['mudurlukler'][0]['name']);
    }

    public function test_hafta_and_madde_persist(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'x',
            'role' => User::ROLE_MUDURLUK,
        ]);

        $hafta = KoordinasyonHafta::query()->create([
            'yil' => 2026,
            'ay' => 8,
            'hafta' => 1,
            'checklist' => KoordinasyonHafta::defaultChecklist(),
            'ozet_not' => 'Sync yapildi',
            'updated_by' => $admin->id,
        ]);

        $madde = KoordinasyonTakipMadde::query()->create([
            'yil' => 2026,
            'ay' => 8,
            'hafta' => 1,
            'baslik' => 'Fen isleri sapma',
            'durum' => KoordinasyonTakipMadde::DURUM_DUZELTME,
            'saha_kontrolu' => true,
            'created_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('koordinasyon_haftalar', [
            'id' => $hafta->id,
            'ozet_not' => 'Sync yapildi',
        ]);
        $this->assertDatabaseHas('koordinasyon_takip_maddeleri', [
            'id' => $madde->id,
            'durum' => 'duzeltme',
            'saha_kontrolu' => 1,
        ]);
    }
}
