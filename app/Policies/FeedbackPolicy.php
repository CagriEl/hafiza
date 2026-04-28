<?php

namespace App\Policies;

use App\Models\Feedback;
use App\Models\User;

class FeedbackPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $this->isMudurluk($user);
    }

    public function view(User $user, Feedback $feedback): bool
    {
        if ($this->isAdmin($user)) {
            return true;
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
}
