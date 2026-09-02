<?php

namespace Tests\Unit;

use App\Support\KapsamIslemTuru;
use Tests\TestCase;

class KapsamIslemTuruTest extends TestCase
{
    public function test_requires_date_range_for_surec_and_gunluk_only(): void
    {
        $this->assertTrue(KapsamIslemTuru::requiresProcessDateRange(KapsamIslemTuru::SUREC));
        $this->assertTrue(KapsamIslemTuru::requiresDailyDate(KapsamIslemTuru::GUNLUK));
        $this->assertTrue(KapsamIslemTuru::requiresDateRange(KapsamIslemTuru::SUREC));
        $this->assertTrue(KapsamIslemTuru::requiresDateRange(KapsamIslemTuru::GUNLUK));
        $this->assertFalse(KapsamIslemTuru::requiresDateRange(KapsamIslemTuru::ANLIK));
        $this->assertFalse(KapsamIslemTuru::requiresDateRange(KapsamIslemTuru::IMZADA));
        $this->assertFalse(KapsamIslemTuru::requiresDateRange(null));
    }

    public function test_imzada_option_only_when_requested(): void
    {
        $this->assertArrayNotHasKey(KapsamIslemTuru::IMZADA, KapsamIslemTuru::options(false));
        $this->assertArrayHasKey(KapsamIslemTuru::IMZADA, KapsamIslemTuru::options(true));
        $this->assertSame(KapsamIslemTuru::IMZADA, KapsamIslemTuru::normalize('imzada', true));
        $this->assertNull(KapsamIslemTuru::normalize('imzada', false));
    }

    public function test_normalize_accepts_known_values(): void
    {
        $this->assertSame(KapsamIslemTuru::ANLIK, KapsamIslemTuru::normalize('anlik'));
        $this->assertNull(KapsamIslemTuru::normalize('unknown'));
    }
}
