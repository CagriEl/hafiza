<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Create / boş builder senaryolarında qualifyColumn vb. hatalarını önlemek için.
 */
final class QuerySafety
{
    public static function shouldApplyFilters(?Builder $query): bool
    {
        if ($query === null) {
            return false;
        }

        try {
            $model = $query->getModel();
        } catch (\Throwable) {
            return false;
        }

        if (! $model) {
            return false;
        }

        $table = $model->getTable();

        return $table !== null && $table !== '';
    }
}
