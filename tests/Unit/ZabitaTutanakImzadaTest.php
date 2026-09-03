<?php

namespace Tests\Unit;

use App\Filament\Resources\AylikFaaliyetResource;
use Tests\TestCase;

class ZabitaTutanakImzadaTest extends TestCase
{
    public function test_detects_zabita_mudurluk(): void
    {
        $this->assertTrue(AylikFaaliyetResource::isZabitaMudurlukName('Zabıta Müdürlüğü'));
        $this->assertFalse(AylikFaaliyetResource::isZabitaMudurlukName('Fen İşleri Müdürlüğü'));
    }

    public function test_detects_tutanak_kalem_names(): void
    {
        $this->assertTrue(AylikFaaliyetResource::kalemNameIsTutanak('Tutanak'));
        $this->assertTrue(AylikFaaliyetResource::kalemNameIsTutanak('olay / tutanak'));
        $this->assertFalse(AylikFaaliyetResource::kalemNameIsTutanak('tebliğ'));
    }
}
