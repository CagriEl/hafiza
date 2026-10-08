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
        CatalogKalemRevisions::apply(false);
        Cache::forever('catalog_kalem_revisions_applied', CatalogKalemRevisions::VERSION);
    }

    public function down(): void
    {
        // Kalem revizyonu geri alınmaz; rapor sayısal verileri korunur.
    }
};
