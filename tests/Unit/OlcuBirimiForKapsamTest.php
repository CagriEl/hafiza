<?php

namespace Tests\Unit;

use App\Support\OlcuBirimiForKapsam;
use Tests\TestCase;

class OlcuBirimiForKapsamTest extends TestCase
{
    public function test_maps_multi_part_unit_by_kalem_text(): void
    {
        $unit = 'müdürlük / evrak / dosya / talep';

        $this->assertSame('müdürlük', OlcuBirimiForKapsam::resolve('Arşiv devir yapılan müdürlük sayısı', $unit, 0));
        $this->assertSame('evrak', OlcuBirimiForKapsam::resolve('Dosyalanan evrak sayısı', $unit, 1));
        $this->assertSame('dosya', OlcuBirimiForKapsam::resolve('Dosya/Klasör sayısı', $unit, 2));
        $this->assertSame('talep', OlcuBirimiForKapsam::resolve('Arşiv erişim talebi sayısı', $unit, 3));
    }

    public function test_falls_back_to_index_when_text_does_not_match(): void
    {
        $unit = 'müdürlük / evrak / dosya / talep';

        $this->assertSame('talep', OlcuBirimiForKapsam::resolve('İmha işlemi (yıllık)', $unit, 4));
    }

    public function test_single_part_unit_is_reused_for_all_kalemler(): void
    {
        $this->assertSame('denetim sayısı', OlcuBirimiForKapsam::resolve('Pazar düzeni', 'denetim sayısı', 0));
    }
}
