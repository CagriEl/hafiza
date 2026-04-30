<?php

namespace App\Policies;

use App\Models\Directorate;
use App\Models\Feedback;
use App\Models\User;

class FeedbackPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $this->isMudurluk($user) || $this->isControlTeam($user);
    }

    public function view(User $user, Feedback $feedback): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if ($this->isControlTeam($user)) {
            $directorateId = (int) ($feedback->directorate_id ?? 0);
            if ($directorateId <= 0) {
                return false;
            }

            return in_array($directorateId, $this->resolveDirectorateIdsForControlTeam($user), true);
        }

        return (int) $feedback->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return $this->isMudurluk($user);
    }

    public function update(User $user, Feedback $feedback): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, Feedback $feedback): bool
    {
        return $this->isAdmin($user);
    }

    protected function isAdmin(User $user): bool
    {
        if ((int) $user->id === 1) {
            return true;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('Admin')) {
            return true;
        }

        return mb_strtolower(trim((string) ($user->role ?? ''))) === 'admin';
    }

    protected function isMudurluk(User $user): bool
    {
        return trim((string) ($user->role ?? '')) === User::ROLE_MUDURLUK;
    }

    protected function isControlTeam(User $user): bool
    {
        return trim((string) ($user->role ?? '')) === User::ROLE_ANALIZ_EKIBI;
    }

    /**
     * @return list<int>
     */
    protected function resolveDirectorateIdsForControlTeam(User $user): array
    {
        if (! $this->isControlTeam($user)) {
            return [];
        }

        $assignedDirectorates = $user->assignedDirectorates()
            ->get(['users.id', 'users.directorate_id']);

        $mudurlukUserIds = $assignedDirectorates
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $directDirectorateIds = $assignedDirectorates
            ->pluck('directorate_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $mappedDirectorateIds = [];
        if ($mudurlukUserIds !== []) {
            $mappedDirectorateIds = Directorate::query()
                ->whereIn('mudurluk_user_id', $mudurlukUserIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->values()
                ->all();
        }

        /** @var list<int> $result */
        $result = array_values(array_unique(array_merge($directDirectorateIds, $mappedDirectorateIds)));

        return $result;
    }
}
