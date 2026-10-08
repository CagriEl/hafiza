<?php

namespace Tests\Unit;

use App\Support\ActivityCatalogMetadataByCode;
use Tests\TestCase;

class ActivityCatalogMetadataByCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ActivityCatalogMetadataByCode::forgetCache();
    }

    public function test_resolves_metadata_for_known_activity_code_from_json(): void
    {
        $metadata = ActivityCatalogMetadataByCode::resolveForCode('IKM-03');

        $this->assertSame('Aylık', $metadata['raporlama_sikligi']);
    }

    public function test_merge_prefers_json_over_empty_catalog_values(): void
    {
        $merged = ActivityCatalogMetadataByCode::mergeWithCatalog('IKM-03', '', '');

        $this->assertSame('Aylık', $merged['raporlama_sikligi']);
    }
}
