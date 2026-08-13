<?php

use App\Support\CatalogKalemRevisions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (! class_exists(CatalogKalemRevisions::class)) {
            return;
        }

        CatalogKalemRevisions::apply(false);
    }

    public function down(): void
    {
        // Kalem revizyonu geri alınmaz; rapor sayısal verileri korunur.
    }
};
