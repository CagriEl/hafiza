<?php

namespace App\Support;

use App\Models\Directorate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class MaliHizmetlerAccess
{
    public static function maliHizmetlerUserId(): ?int
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached > 0 ? $cached : null;
        }

        $directorate = Directorate::query()->where('code', 'MHM')->first();
        if ($directorate?->mudurluk_user_id) {
            $cached = (int) $directorate->mudurluk_user_id;

            return $cached;
        }

        $userId = User::query()
            ->whereKeyNot(1)
            ->where(function (Builder $q): void {
                $q->whereHas('directorate', fn (Builder $d) => $d->where('code', 'MHM'))
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%mali hizmetler%']);
            })
            ->value('id');

        $cached = (int) ($userId ?? 0);

        return $cached > 0 ? $cached : null;
    }

    public static function userCanManageMaliRaporlar(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ($user->isReportingSuperAdmin()) {
            return true;
        }

        if ($user->isMaliHizmetlerAccount()) {
            return true;
        }

        $maliId = self::maliHizmetlerUserId();
        if ($maliId === null) {
            return false;
        }

        if ($user->isViceMayorAccount()) {
            $audience = $user->reportAudienceUserIds() ?? [];

            return in_array($maliId, $audience, true);
        }

        if ($user->isControlTeam()) {
            $dirIds = $user->assignedDirectorates()
                ->pluck('users.id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            return in_array($maliId, $dirIds, true);
        }

        return false;
    }

    public static function resolveReportingUser(?User $user): ?User
    {
        if (! $user instanceof User) {
            return null;
        }

        if ($user->isMaliHizmetlerAccount() && ! $user->isReportingSuperAdmin()) {
            return $user;
        }

        $maliId = self::maliHizmetlerUserId();
        if ($maliId === null) {
            return null;
        }

        return User::query()->find($maliId);
    }
}
