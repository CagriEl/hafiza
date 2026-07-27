<?php

namespace Tests\Unit;

use App\Models\RaporHaftaTanimi;
use App\Models\User;
use App\Support\ReportPeriodWeeks;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RaporHaftaTanimiResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('rapor_hafta_tanimlari');
        Schema::create('rapor_hafta_tanimlari', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('yil');
            $table->string('ay', 2);
            $table->unsignedTinyInteger('hafta');
            $table->date('baslangic');
            $table->date('bitis');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['yil', 'ay', 'hafta']);
        });
    }

    public function test_weeks_for_month_uses_custom_definitions_when_present(): void
    {
        RaporHaftaTanimi::query()->create([
            'yil' => 2026,
            'ay' => '07',
            'hafta' => 1,
            'baslangic' => '2026-07-01',
            'bitis' => '2026-07-07',
        ]);
        RaporHaftaTanimi::query()->create([
            'yil' => 2026,
            'ay' => '07',
            'hafta' => 2,
            'baslangic' => '2026-07-08',
            'bitis' => '2026-07-14',
        ]);

        $weeks = ReportPeriodWeeks::weeksForMonth(2026, 7);

        $this->assertCount(2, $weeks);
        $this->assertSame('01.07.2026', ReportPeriodWeeks::formatDate($weeks[0]['baslangic']));
        $this->assertSame('07.07.2026', ReportPeriodWeeks::formatDate($weeks[0]['bitis']));
        $this->assertSame('08.07.2026', ReportPeriodWeeks::formatDate($weeks[1]['baslangic']));
    }

    public function test_weeks_for_month_falls_back_to_computed_when_no_definitions(): void
    {
        $weeks = ReportPeriodWeeks::weeksForMonth(2026, 7);

        $this->assertCount(4, $weeks);
        $this->assertSame('29.06.2026', ReportPeriodWeeks::formatDate($weeks[0]['baslangic']));
    }

    public function test_analiz_ekibi_needs_flag_to_manage_weeks(): void
    {
        $user = new User([
            'role' => User::ROLE_ANALIZ_EKIBI,
            'can_manage_rapor_haftalari' => false,
        ]);
        $user->id = 5;

        $this->assertFalse($user->canManageRaporHaftalari());

        $user->can_manage_rapor_haftalari = true;
        $this->assertTrue($user->canManageRaporHaftalari());

        $admin = new User([
            'role' => User::ROLE_ANALIZ_EKIBI,
            'can_manage_rapor_haftalari' => false,
        ]);
        $admin->id = 1;
        $this->assertTrue($admin->canManageRaporHaftalari());
    }
}
