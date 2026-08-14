<?php

use App\Support\CatalogKalemRevisions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    public function up(): void
    {
        if (! class_exists(CatalogKalemRevisions::class)) {
            return;
        }

        CatalogKalemRevisions::resetEnsureState();
        $stats = CatalogKalemRevisions::apply(false);
        Cache::forever('catalog_kalem_revisions_applied', CatalogKalemRevisions::VERSION);

        if (isset($stats) && function_exists('logger')) {
            logger()->info('catalog_kalem_revisions_v2_applied', $stats);
        }
    }

    public function down(): void
    {
        //
    }
};
