<?php

namespace Tests\Unit;

use App\Filament\Resources\ActivityReportResource;
use App\Filament\Resources\AylikFaaliyetResource;
use App\Models\AylikFaaliyet;
use App\Models\User;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ActivityReportResourceListTest extends TestCase
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_activity_reports_list_enables_pagination_without_changing_aylik_faaliyet_table(): void
    {
        $activityTable = $this->makeTable();
        ActivityReportResource::table($activityTable);

        $this->assertTrue($activityTable->isPaginated());
        $this->assertSame([10, 25, 50], $activityTable->getPaginationPageOptions());
        $this->assertSame(25, $activityTable->getDefaultPaginationPageOption());

        $aylikTable = $this->makeTable();
        AylikFaaliyetResource::table($aylikTable);
        $this->assertFalse($aylikTable->isPaginated());
    }

    public function test_activity_reports_query_eager_loads_user_for_grouping(): void
    {
        $admin = User::query()->create([
            'name' => 'Sistem Yöneticisi',
            'email' => 'admin@example.com',
            'password' => 'x',
            'role' => 'admin',
        ]);
        $this->assertSame(1, (int) $admin->id);
        Auth::login($admin);

        AylikFaaliyet::withoutEvents(function () use ($admin): void {
            AylikFaaliyet::query()->create([
                'user_id' => $admin->id,
                'yil' => 2026,
                'ay' => '08',
                'hafta' => '1',
                'faaliyetler' => [],
            ]);
        });

        $eager = ActivityReportResource::getEloquentQuery()->getEagerLoads();
        $this->assertArrayHasKey('user', $eager);
    }

    private function makeTable(): Table
    {
        $livewire = Mockery::mock(HasTable::class)->shouldIgnoreMissing();

        return Table::make($livewire);
    }
}
