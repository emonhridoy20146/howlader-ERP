<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BaseModelPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'viewAny');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allows($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allows($user, 'update');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->allows($user, 'delete');
    }

    protected function allows(User $user, string $ability): bool
    {
        $policy = class_basename(static::class);
        $modelSlug = Str::of($policy)->beforeLast('Policy')->kebab()->toString();
        $abilitySlug = Str::of($ability)->snake('-')->toString();

        return $user->hasPermission($modelSlug.'.'.$abilitySlug);
    }
}
