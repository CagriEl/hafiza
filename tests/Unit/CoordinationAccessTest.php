<?php

namespace Tests\Unit;

use App\Models\AylikFaaliyet;
use App\Models\User;
use App\Support\CoordinationAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CoordinationAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoordinationAccess::resetRequestMemo();
        Cache::flush();

        Schema::dropIfExists('aylik_faaliyets');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Schema::create('aylik_faaliyets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedSmallInteger('yil')->nullable();
            $table->string('ay')->nullable();
            $table->string('hafta', 16)->nullable();
            $table->json('faaliyetler')->nullable();
            $table->timestamps();
        });
    }

    public function test_incoming_ids_are_reports_where_user_is_coordination_target_not_owner(): void
    {
        [$owner, $partner, $other] = $this->seedUsers();
        $incoming = $this->createReport($owner, [
            $this->coordinationLine([$partner->id]),
        ]);
        $this->createReport($owner, [
            ['faaliyet_turu' => 'Rutin', 'faaliyet_kodu' => 'X-01'],
        ]);
        $this->createReport($partner, [
            $this->coordinationLine([$other->id]),
        ]);

        $ids = CoordinationAccess::incomingAylikFaaliyetIdsForUser((int) $partner->id);

        $this->assertSame([(int) $incoming->id], $ids);
        $this->assertTrue(CoordinationAccess::isIncomingPartnerOnRecord($incoming, (int) $partner->id));
        $this->assertFalse(CoordinationAccess::isIncomingPartnerOnRecord($incoming, (int) $owner->id));
    }

    public function test_incoming_ids_for_multiple_users_use_a_single_table_scan(): void
    {
        [$owner, $partnerA, $partnerB] = $this->seedUsers();
        $reportA = $this->createReport($owner, [
            $this->coordinationLine([(int) $partnerA->id]),
        ]);
        $reportB = $this->createReport($owner, [
            $this->coordinationLine([(int) $partnerB->id]),
        ]);
        $this->createReport($owner, [
            ['faaliyet_turu' => 'Rutin', 'faaliyet_kodu' => 'X-01'],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $ids = CoordinationAccess::incomingAylikFaaliyetIdsForUserIds([
            (int) $partnerA->id,
            (int) $partnerB->id,
        ]);

        $selects = $this->aylikFaaliyetSelects(DB::getQueryLog());
        $this->assertCount(1, $selects);
        $this->assertEqualsCanonicalizing([(int) $reportA->id, (int) $reportB->id], $ids);

        DB::flushQueryLog();
        $again = CoordinationAccess::incomingAylikFaaliyetIdsForUserIds([
            (int) $partnerA->id,
            (int) $partnerB->id,
        ]);
        $this->assertSame($ids, $again);
        $this->assertCount(0, $this->aylikFaaliyetSelects(DB::getQueryLog()));
    }

    public function test_incoming_ids_are_memoized_per_request_and_cached_across_memo_reset(): void
    {
        [$owner, $partner] = $this->seedUsers();
        $report = $this->createReport($owner, [
            $this->coordinationLine([(int) $partner->id]),
        ]);

        $first = CoordinationAccess::incomingAylikFaaliyetIdsForUser((int) $partner->id);
        $this->assertSame([(int) $report->id], $first);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame($first, CoordinationAccess::incomingAylikFaaliyetIdsForUser((int) $partner->id));
        $this->assertCount(0, $this->aylikFaaliyetSelects(DB::getQueryLog()));

        CoordinationAccess::resetRequestMemo();
        DB::flushQueryLog();
        $this->assertSame($first, CoordinationAccess::incomingAylikFaaliyetIdsForUser((int) $partner->id));
        $this->assertCount(0, $this->aylikFaaliyetSelects(DB::getQueryLog()));
    }

    /**
     * @return array{0: User, 1: User, 2: User}
     */
    private function seedUsers(): array
    {
        $owner = User::query()->create([
            'name' => 'Fen İşleri',
            'email' => 'fen@example.com',
            'password' => 'x',
            'role' => User::ROLE_MUDURLUK,
        ]);
        $partnerA = User::query()->create([
            'name' => 'Park Bahçe',
            'email' => 'park@example.com',
            'password' => 'x',
            'role' => User::ROLE_MUDURLUK,
        ]);
        $partnerB = User::query()->create([
            'name' => 'Ulaşım',
            'email' => 'ulasim@example.com',
            'password' => 'x',
            'role' => User::ROLE_MUDURLUK,
        ]);

        return [$owner, $partnerA, $partnerB];
    }

    /**
     * @param  list<int>  $targetIds
     * @return array<string, mixed>
     */
    private function coordinationLine(array $targetIds): array
    {
        return [
            'faaliyet_turu' => 'Koordinasyon',
            'faaliyet_kodu' => 'KOORD-01',
            'isbirligi_hedef_mudurluk_user_ids' => $targetIds,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $faaliyetler
     */
    private function createReport(User $owner, array $faaliyetler): AylikFaaliyet
    {
        return AylikFaaliyet::withoutEvents(function () use ($owner, $faaliyetler) {
            return AylikFaaliyet::query()->create([
                'user_id' => $owner->id,
                'yil' => 2026,
                'ay' => '08',
                'hafta' => '1',
                'faaliyetler' => $faaliyetler,
            ]);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $queryLog
     * @return list<array<string, mixed>>
     */
    private function aylikFaaliyetSelects(array $queryLog): array
    {
        return array_values(array_filter($queryLog, function (array $entry): bool {
            $sql = strtolower((string) ($entry['query'] ?? ''));

            return str_contains($sql, 'aylik_faaliyet') && str_starts_with(ltrim($sql), 'select');
        }));
    }
}
