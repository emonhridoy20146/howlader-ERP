<?php

namespace App\Support;

use App\Models\User;

final class UserImpersonation
{
    public const SESSION_KEY = 'impersonator_id';

    public static function isImpersonating(): bool
    {
        return self::impersonatorId() !== null;
    }

    public static function impersonatorId(): ?int
    {
        $id = session(self::SESSION_KEY);

        return is_numeric($id) ? (int) $id : null;
    }

    public static function impersonator(): ?User
    {
        $id = self::impersonatorId();
        if ($id === null) {
            return null;
        }

        return User::query()->find($id);
    }

    public static function canImpersonate(User $actor, User $target): bool
    {
        if (self::isImpersonating()) {
            return false;
        }

        if (! $actor->hasPermission('user.impersonate')) {
            return false;
        }

        if ((int) $actor->id === (int) $target->id) {
            return false;
        }

        if (! $target->is_active) {
            return false;
        }

        if ($target->isAdmin()) {
            return false;
        }

        return true;
    }
}
