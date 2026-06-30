<?php

namespace Tests\Unit;

use App\Support\ReportPeriodWeeks;
use PHPUnit\Framework\TestCase;

class ReportPeriodWeeksTest extends TestCase
{
    public function test_month_is_split_into_four_week_ranges(): void
    {
        $weeks = ReportPeriodWeeks::weeksForMonth(2026, 1);

        $this->assertCount(4, $weeks);
        $this->assertSame(1, $weeks[0]['hafta']);
        $this->assertSame('01.01.2026', ReportPeriodWeeks::formatDate($weeks[0]['baslangic']));
        $this->assertSame('07.01.2026', ReportPeriodWeeks::formatDate($weeks[0]['bitis']));
        $this->assertSame('22.01.2026', ReportPeriodWeeks::formatDate($weeks[3]['baslangic']));
        $this->assertSame('31.01.2026', ReportPeriodWeeks::formatDate($weeks[3]['bitis']));
    }

    public function test_february_last_week_ends_on_month_end(): void
    {
        $weeks = ReportPeriodWeeks::weeksForMonth(2026, 2);

        $this->assertSame('28.02.2026', ReportPeriodWeeks::formatDate($weeks[3]['bitis']));
    }

    public function test_week_select_options_include_date_ranges(): void
    {
        $options = ReportPeriodWeeks::selectOptions(2026, 3);

        $this->assertArrayHasKey(2, $options);
        $this->assertSame('2. Hafta (08.03.2026 - 14.03.2026)', $options[2]);
    }

    public function test_record_period_label_formats_month_range(): void
    {
        $this->assertSame(
            'Mart 2026 (01.03.2026 - 31.03.2026)',
            ReportPeriodWeeks::recordPeriodLabel(2026, '03')
        );
    }

    public function test_detects_weekly_reporting_frequency(): void
    {
        $this->assertTrue(ReportPeriodWeeks::isWeeklyReportingFrequency('Haftalık / Aylık'));
        $this->assertFalse(ReportPeriodWeeks::isWeeklyReportingFrequency('Aylık'));
    }
}
