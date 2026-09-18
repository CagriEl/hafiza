<?php

namespace Tests\Unit;

use App\Support\ReportingFrequencyNormalizer;
use PHPUnit\Framework\TestCase;

class ReportingFrequencyNormalizerTest extends TestCase
{
    public function test_maps_operational_category_style_to_haftalik(): void
    {
        $this->assertSame(
            'Haftalık',
            ReportingFrequencyNormalizer::fromReportingStyle('Haftalık sayısal KPI + sapma özeti')
        );
    }

    public function test_maps_support_category_style_to_haftalik_aylik(): void
    {
        $this->assertSame(
            'Haftalık / Aylık',
            ReportingFrequencyNormalizer::fromReportingStyle('Sadece hacimli veya kritik olanlar haftalık; diğerleri aylık')
        );
    }

    public function test_maps_denetim_style_to_haftalik(): void
    {
        $this->assertSame(
            'Haftalık',
            ReportingFrequencyNormalizer::fromReportingStyle('Denetim sayısı + uygunsuzluk + kapanış')
        );
    }

    public function test_maps_emergency_style_to_haftalik_olay(): void
    {
        $this->assertSame(
            'Haftalık / olay anında',
            ReportingFrequencyNormalizer::fromReportingStyle('Her hafta ayrı blokta olay bazlı')
        );
    }
}
