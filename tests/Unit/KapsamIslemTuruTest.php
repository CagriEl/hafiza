<?php

namespace Tests\Unit;

use App\Support\KapsamIslemTuru;
use Tests\TestCase;

class KapsamIslemTuruTest extends TestCase
{
    public function test_requires_date_range_for_surec_gunluk_and_haftalik(): void
    {
        $this->assertTrue(KapsamIslemTuru::requiresProcessDateRange(KapsamIslemTuru::SUREC));
        $this->assertTrue(KapsamIslemTuru::requiresProcessDateRange(KapsamIslemTuru::HAFTALIK));
        $this->assertTrue(KapsamIslemTuru::requiresDailyDate(KapsamIslemTuru::GUNLUK));
        $this->assertTrue(KapsamIslemTuru::requiresDateRange(KapsamIslemTuru::SUREC));
        $this->assertTrue(KapsamIslemTuru::requiresDateRange(KapsamIslemTuru::GUNLUK));
        $this->assertTrue(KapsamIslemTuru::requiresDateRange(KapsamIslemTuru::HAFTALIK));
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

    public function test_haftalik_replaces_gunluk_when_requested(): void
    {
        $default = KapsamIslemTuru::options(false, false);
        $this->assertArrayHasKey(KapsamIslemTuru::GUNLUK, $default);
        $this->assertArrayNotHasKey(KapsamIslemTuru::HAFTALIK, $default);

        $itm05 = KapsamIslemTuru::options(false, true);
        $this->assertArrayHasKey(KapsamIslemTuru::HAFTALIK, $itm05);
        $this->assertArrayNotHasKey(KapsamIslemTuru::GUNLUK, $itm05);
        $this->assertSame(KapsamIslemTuru::HAFTALIK, KapsamIslemTuru::normalize('haftalik', false, true));
        $this->assertNull(KapsamIslemTuru::normalize('haftalik', false, false));
        $this->assertSame(KapsamIslemTuru::HAFTALIK, KapsamIslemTuru::normalizeStored('haftalik'));
    }

    public function test_normalize_accepts_known_values(): void
    {
        $this->assertSame(KapsamIslemTuru::ANLIK, KapsamIslemTuru::normalize('anlik'));
        $this->assertNull(KapsamIslemTuru::normalize('unknown'));
    }
}
