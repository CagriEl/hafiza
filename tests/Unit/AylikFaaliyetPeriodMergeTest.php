<?php

namespace Tests\Unit;

use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\AylikFaaliyetPeriodMerge;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AylikFaaliyetPeriodMergeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('aylik_faaliyets');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        Schema::create('aylik_faaliyets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedSmallInteger('yil')->nullable();
            $table->string('ay')->nullable();
            $table->string('hafta', 16)->default('1');
            $table->integer('memur')->default(0);
            $table->integer('sozlesmeli_memur')->default(0);
            $table->integer('kadrolu_isci')->default(0);
            $table->integer('sirket_personeli')->default(0);
            $table->integer('gecici_isci')->default(0);
            $table->json('faaliyetler')->nullable();
            $table->timestamps();
        });
    }

    public function test_merge_keeps_different_weeks_separate(): void
    {
        $merged = AylikFaaliyetPeriodMerge::mergeFaaliyetLists([
            [
                [
                    'activity_catalog_id' => 10,
                    'faaliyet_kodu' => 'FNM-01',
                    'hafta' => 1,
                    'hedef' => 5,
                    'gerceklesen' => 2,
                ],
            ],
            [
                [
                    'activity_catalog_id' => 10,
                    'faaliyet_kodu' => 'FNM-01',
                    'hafta' => 2,
                    'hedef' => 5,
                    'gerceklesen' => 3,
                ],
            ],
        ]);

        $this->assertCount(2, $merged['faaliyetler']);
        $weeks = collect($merged['faaliyetler'])->pluck('hafta')->sort()->values()->all();
        $this->assertSame([1, 2], $weeks);
    }

    public function test_merge_combines_same_week_catalog_rows(): void
    {
        $merged = AylikFaaliyetPeriodMerge::mergeFaaliyetLists([
            [
                [
                    'activity_catalog_id' => 10,
                    'faaliyet_kodu' => 'FNM-01',
                    'hafta' => 1,
                    'gerceklesen' => 2,
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'A',
                            'ongorulen' => 10,
                            'gerceklesen' => 2,
                            'haftalik_kayitlar' => [
                                ['hafta' => 1, 'miktar' => 2, 'yapilma_tarihi' => '2026-07-03', 'aciklama' => 'ilk'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                [
                    'activity_catalog_id' => 10,
                    'faaliyet_kodu' => 'FNM-01',
                    'hafta' => 1,
                    'gerceklesen' => 1,
                    'kapsam_verileri' => [
                        [
                            'kalem' => 'A',
                            'ongorulen' => 10,
                            'gerceklesen' => 1,
                            'haftalik_kayitlar' => [
                                ['hafta' => 1, 'miktar' => 1, 'yapilma_tarihi' => '2026-07-04', 'aciklama' => 'ikinci'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $merged['faaliyetler']);
        $this->assertSame(3.0, (float) $merged['faaliyetler'][0]['gerceklesen']);
        $this->assertCount(2, $merged['faaliyetler'][0]['kapsam_verileri'][0]['haftalik_kayitlar']);
    }

    public function test_merge_group_collapses_duplicate_reports(): void
    {
        $user = User::query()->create([
            'name' => 'Fen',
            'email' => 'fen-merge@example.com',
            'password' => 'x',
        ]);

        AylikFaaliyet::withoutEvents(function () use ($user): void {
            AylikFaaliyet::query()->create([
                'user_id' => $user->id,
                'yil' => 2026,
                'ay' => '07',
                'hafta' => '1',
                'faaliyetler' => [
                    ['activity_catalog_id' => 1, 'hafta' => 1, 'gerceklesen' => 1],
                ],
            ]);
            AylikFaaliyet::query()->create([
                'user_id' => $user->id,
                'yil' => 2026,
                'ay' => '7',
                'hafta' => '1',
                'faaliyetler' => [
                    ['activity_catalog_id' => 1, 'hafta' => 2, 'gerceklesen' => 2],
                ],
            ]);
        });

        $this->assertSame(1, AylikFaaliyetPeriodMerge::mergeAllDuplicates());
        $this->assertSame(1, AylikFaaliyet::query()->where('hafta', '1')->count());
        // Farklı hafta ayrı rapor olarak kalabilir; bu test aynı hafta duplicate birleştirir.
        $this->assertSame(1, AylikFaaliyet::query()->count());

        $report = AylikFaaliyet::query()->first();
        $this->assertSame('07', $report->ay);
        $this->assertSame('1', (string) $report->hafta);
        $this->assertCount(2, $report->faaliyetler);
    }

    public function test_exists_for_user_period_week_is_week_scoped(): void
    {
        $user = User::query()->create([
            'name' => 'Fen2',
            'email' => 'fen2@example.com',
            'password' => 'x',
        ]);

        AylikFaaliyet::withoutEvents(function () use ($user): void {
            AylikFaaliyet::query()->create([
                'user_id' => $user->id,
                'yil' => 2026,
                'ay' => '07',
                'hafta' => '1',
                'faaliyetler' => [],
            ]);
        });

        $this->assertTrue(AylikFaaliyet::existsForUserPeriodWeek((int) $user->id, 2026, '07', 1));
        $this->assertFalse(AylikFaaliyet::existsForUserPeriodWeek((int) $user->id, 2026, '07', 2));
        $this->assertTrue(AylikFaaliyet::existsForUserPeriod((int) $user->id, 2026, '7'));
    }
}
