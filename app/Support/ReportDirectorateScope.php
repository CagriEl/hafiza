<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Rapor ve müdürlük verisi: kullanıcı yalnızca izin verilen user_id (müdürlük hesabı) sahipliğine erişir.
 * id=1 sistem yöneticisi: kısıtlama yok. Başkan yardımcısı: bağlı müdürlük kullanıcıları.
 */
final class ReportDirectorateScope
{
    public static function constrain(Builder $query, string $userIdColumn = 'user_id'): Builder
    {
        if (! QuerySafety::shouldApplyFilters($query)) {
            return $query;
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            return $query->whereRaw('0 = 1');
        }

        $ids = $user->reportAudienceUserIds();
        if ($ids === null) {
            return $query;
        }

        if ($ids === []) {
            return $query->whereRaw('0 = 1');
        }

        // qualifyColumn() Eloquent Builder'da modele delege edilir; model yoksa null üzerinde çağrı hatası oluşur.
        $column = $userIdColumn;
        if ($query instanceof Builder && $query->getModel() !== null) {
            $column = $query->qualifyColumn($userIdColumn);
        }

        return $query->whereIn($column, $ids);
    }
}
