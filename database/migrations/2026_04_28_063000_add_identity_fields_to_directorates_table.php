<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('directorates')) {
            return;
        }

        Schema::table('directorates', function (Blueprint $table): void {
            if (! Schema::hasColumn('directorates', 'name')) {
                $table->string('name')->nullable()->after('id');
            }

            if (! Schema::hasColumn('directorates', 'slug')) {
                $table->string('slug')->nullable()->after('name');
            }

            if (! Schema::hasColumn('directorates', 'short_name')) {
                $table->string('short_name', 20)->nullable()->after('slug');
            }

            if (! Schema::hasColumn('directorates', 'code')) {
                $table->string('code', 20)->nullable()->after('short_name');
            }
        });

        Schema::table('directorates', function (Blueprint $table): void {
            if (Schema::hasColumn('directorates', 'name') && ! $this->indexExists('directorates', 'directorates_name_unique')) {
                $table->unique('name');
            }
            if (Schema::hasColumn('directorates', 'slug') && ! $this->indexExists('directorates', 'directorates_slug_unique')) {
                $table->unique('slug');
            }
            if (Schema::hasColumn('directorates', 'short_name') && ! $this->indexExists('directorates', 'directorates_short_name_index')) {
                $table->index('short_name');
            }
            if (Schema::hasColumn('directorates', 'code') && ! $this->indexExists('directorates', 'directorates_code_index')) {
                $table->index('code');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('directorates')) {
            return;
        }

        Schema::table('directorates', function (Blueprint $table): void {
            if (Schema::hasColumn('directorates', 'code')) {
                if ($this->indexExists('directorates', 'directorates_code_index')) {
                    $table->dropIndex(['code']);
                }
            }
            if (Schema::hasColumn('directorates', 'short_name')) {
                if ($this->indexExists('directorates', 'directorates_short_name_index')) {
                    $table->dropIndex(['short_name']);
                }
            }
            if (Schema::hasColumn('directorates', 'slug')) {
                if ($this->indexExists('directorates', 'directorates_slug_unique')) {
                    $table->dropUnique(['slug']);
                }
                $table->dropColumn('slug');
            }
            if (Schema::hasColumn('directorates', 'name')) {
                if ($this->indexExists('directorates', 'directorates_name_unique')) {
                    $table->dropUnique(['name']);
                }
                $table->dropColumn('name');
            }
            if (Schema::hasColumn('directorates', 'short_name')) {
                $table->dropColumn('short_name');
            }
            if (Schema::hasColumn('directorates', 'code')) {
                $table->dropColumn('code');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}`");

        foreach ($indexes as $index) {
            if (($index->Key_name ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
