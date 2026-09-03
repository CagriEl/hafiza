<?php

namespace Tests\Unit;

use App\Models\RoutineWorkWindow;
use App\Support\RoutineWorkDailyRows;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoutineWorkDailyRowsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('routine_work_items');
        Schema::dropIfExists('routine_work_windows');

        Schema::create('routine_work_windows', function (Blueprint $table) {
            $table->id();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function test_empty_rows_cover_each_day_in_active_window(): void
    {
        RoutineWorkWindow::withoutEvents(function (): void {
            RoutineWorkWindow::query()->create([
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-03',
                'is_active' => true,
            ]);
        });

        $rows = RoutineWorkDailyRows::emptyRowsForCurrentWindow();

        $this->assertCount(3, $rows);
        $this->assertSame('2026-08-01', $rows[0]['start_date']);
        $this->assertSame('2026-08-01', $rows[0]['end_date']);
        $this->assertSame('2026-08-03', $rows[2]['start_date']);
        $this->assertSame('2026-08-03', $rows[2]['end_date']);
    }

    public function test_dates_within_window(): void
    {
        $window = RoutineWorkWindow::withoutEvents(function (): RoutineWorkWindow {
            return RoutineWorkWindow::query()->create([
                'start_date' => Carbon::parse('2026-08-01'),
                'end_date' => Carbon::parse('2026-08-31'),
                'is_active' => false,
            ]);
        });

        $this->assertTrue(RoutineWorkDailyRows::datesWithinWindow('2026-08-05', '2026-08-07', $window));
        $this->assertFalse(RoutineWorkDailyRows::datesWithinWindow('2026-07-31', '2026-08-01', $window));
        $this->assertFalse(RoutineWorkDailyRows::datesWithinWindow('2026-08-10', '2026-08-05', $window));
    }
}
