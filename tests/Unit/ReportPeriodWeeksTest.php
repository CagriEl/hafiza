<?php

namespace Tests\Unit;

use App\Support\ReportPeriodWeeks;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ReportPeriodWeeksTest extends TestCase
{
    public function test_july_first_week_is_full_calendar_week_from_monday(): void
    {
        $weeks = ReportPeriodWeeks::computedWeeksForMonth(2026, 7);

        $this->assertCount(4, $weeks);
        $this->assertSame(1, $weeks[0]['hafta']);
        $this->assertSame('29.06.2026', ReportPeriodWeeks::formatDate($weeks[0]['baslangic']));
        $this->assertSame('05.07.2026', ReportPeriodWeeks::formatDate($weeks[0]['bitis']));
        $this->assertSame('06.07.2026', ReportPeriodWeeks::formatDate($weeks[1]['baslangic']));
        $this->assertSame('12.07.2026', ReportPeriodWeeks::formatDate($weeks[1]['bitis']));
        $this->assertSame('13.07.2026', ReportPeriodWeeks::formatDate($weeks[2]['baslangic']));
        $this->assertSame('19.07.2026', ReportPeriodWeeks::formatDate($weeks[2]['bitis']));
        $this->assertSame('20.07.2026', ReportPeriodWeeks::formatDate($weeks[3]['baslangic']));
        $this->assertSame('31.07.2026', ReportPeriodWeeks::formatDate($weeks[3]['bitis']));
    }

    public function test_month_starts_on_monday_first_week_is_full_calendar_week(): void
    {
        $weeks = ReportPeriodWeeks::computedWeeksForMonth(2026, 6);

        $this->assertSame('01.06.2026', ReportPeriodWeeks::formatDate($weeks[0]['baslangic']));
        $this->assertSame('07.06.2026', ReportPeriodWeeks::formatDate($weeks[0]['bitis']));
        $this->assertSame('08.06.2026', ReportPeriodWeeks::formatDate($weeks[1]['baslangic']));
        $this->assertSame('14.06.2026', ReportPeriodWeeks::formatDate($weeks[1]['bitis']));
    }

    public function test_february_last_week_ends_on_month_end(): void
    {
        $weeks = ReportPeriodWeeks::computedWeeksForMonth(2026, 2);

        $this->assertSame('28.02.2026', ReportPeriodWeeks::formatDate($weeks[3]['bitis']));
    }

    public function test_week_select_options_show_full_week_labels(): void
    {
        $options = ReportPeriodWeeks::selectOptions(2026, 3);

        $this->assertArrayHasKey(2, $options);
        $this->assertSame('2. Hafta', $options[2]);
    }

    public function test_resolve_week_for_current_date_in_report_period(): void
    {
        $today = Carbon::create(2026, 6, 10)->startOfDay();
        $this->assertSame(2, ReportPeriodWeeks::resolveWeekForReportPeriod(2026, 6, $today));
    }

    public function test_resolve_week_for_july_third_is_first_week(): void
    {
        $today = Carbon::create(2026, 7, 3)->startOfDay();

        $this->assertSame(1, ReportPeriodWeeks::resolveWeekForReportPeriod(2026, 7, $today));
    }

    public function test_resolve_week_before_report_period_defaults_to_first_week(): void
    {
        $today = Carbon::create(2026, 5, 31)->startOfDay();
        $this->assertSame(1, ReportPeriodWeeks::resolveWeekForReportPeriod(2026, 6, $today));
    }

    public function test_resolve_week_after_report_period_defaults_to_last_week(): void
    {
        $today = Carbon::create(2026, 7, 1)->startOfDay();
        $this->assertSame(4, ReportPeriodWeeks::resolveWeekForReportPeriod(2026, 6, $today));
    }

    public function test_resolve_week_on_sunday_at_block_start_uses_previous_friday_week(): void
    {
        $sunday = Carbon::create(2026, 6, 7)->startOfDay();

        $this->assertTrue($sunday->isSunday());
        $this->assertSame(1, ReportPeriodWeeks::resolveWeekForReportPeriod(2026, 6, $sunday));
    }

    public function test_reporting_reference_date_on_weekend_returns_friday(): void
    {
        $saturday = Carbon::create(2026, 6, 13)->startOfDay();
        $sunday = Carbon::create(2026, 6, 14)->startOfDay();

        $this->assertSame('12.06.2026', ReportPeriodWeeks::formatDate(ReportPeriodWeeks::reportingReferenceDate($saturday)));
        $this->assertSame('12.06.2026', ReportPeriodWeeks::formatDate(ReportPeriodWeeks::reportingReferenceDate($sunday)));
    }

    public function test_system_record_date_keeps_actual_calendar_day_on_weekend(): void
    {
        $saturday = Carbon::create(2026, 6, 13)->startOfDay();

        $this->assertSame('2026-06-13', ReportPeriodWeeks::systemRecordDateString($saturday));
        $this->assertSame('13.06.2026', ReportPeriodWeeks::formatDate(ReportPeriodWeeks::systemRecordDate($saturday)));
    }

    public function test_record_period_label_formats_month_range(): void
    {
        $this->assertSame(
            'Mart 2026 (01.03.2026 - 31.03.2026)',
            ReportPeriodWeeks::recordPeriodLabel(2026, '03')
        );
    }

    public function test_period_select_options_for_monthly_frequency(): void
    {
        $options = ReportPeriodWeeks::periodSelectOptions(2026, 6, 'Aylık');

        $this->assertArrayHasKey(ReportPeriodWeeks::MONTHLY_VALUE, $options);
        $this->assertArrayNotHasKey(1, $options);
    }

    public function test_period_select_options_for_weekly_frequency(): void
    {
        $options = ReportPeriodWeeks::periodSelectOptions(2026, 6, 'Haftalık');

        $this->assertArrayHasKey(1, $options);
        $this->assertArrayNotHasKey(ReportPeriodWeeks::MONTHLY_VALUE, $options);
    }

    public function test_period_select_options_for_combined_frequency(): void
    {
        $options = ReportPeriodWeeks::periodSelectOptions(2026, 6, 'Haftalık / Aylık');

        $this->assertArrayHasKey(1, $options);
        $this->assertArrayHasKey(ReportPeriodWeeks::MONTHLY_VALUE, $options);
    }

    public function test_default_period_for_monthly_only_frequency(): void
    {
        $period = ReportPeriodWeeks::defaultPeriodForReportingFrequency(2026, 6, 'Aylık');

        $this->assertSame(ReportPeriodWeeks::MONTHLY_VALUE, $period);
    }

    public function test_week_label_for_monthly_period(): void
    {
        $this->assertSame(
            'Aylık',
            ReportPeriodWeeks::weekLabelForRecord(2026, '06', ReportPeriodWeeks::MONTHLY_VALUE)
        );
    }

    public function test_detects_weekly_reporting_frequency(): void
    {
        $this->assertTrue(ReportPeriodWeeks::isWeeklyReportingFrequency('Haftalık / Aylık'));
        $this->assertFalse(ReportPeriodWeeks::isWeeklyReportingFrequency('Aylık'));
    }
}
