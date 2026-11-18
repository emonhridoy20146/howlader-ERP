<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var Collection<int, Role>|null */
    private ?Collection $resolvedRoles = null;

    protected $fillable = [
        'name',
        'full_name',
        'email',
        'phone',
        'photo_path',
        'password',
        'role',
        'branch_id',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'branch_id');
    }

    /**
     * Administrators are identified by the `admin` role only. The legacy
     * `users.role` column is retained for reporting and is never consulted here.
     */
    public function isAdmin(): bool
    {
        return in_array('admin', $this->roleSlugs(), true);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function accessibleLocations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class);
    }

    /**
     * @return list<string>
     */
    public function roleSlugs(): array
    {
        return $this->resolvedRoles()->pluck('slug')->all();
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->resolvedRoles()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('slug'))
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Forget the cached role graph, e.g. straight after changing a user's roles.
     */
    public function forgetPermissionCache(): static
    {
        $this->resolvedRoles = null;
        $this->unsetRelation('roles');

        return $this;
    }

    /**
     * Authorization runs many checks per request; load the role graph once.
     *
     * @return Collection<int, Role>
     */
    private function resolvedRoles(): Collection
    {
        return $this->resolvedRoles ??= $this->roles()->with('permissions:id,slug')->get();
    }

    public function accessibleLocationIds(): array
    {
        if ($this->isAdmin() || $this->hasPermission('location.view-all')) {
            return Location::query()->where('is_active', true)->pluck('id')->all();
        }

        return $this->accessibleLocations()
            ->where('is_active', true)
            ->pluck('locations.id')
            ->all();
    }

    public function photoUrl(): ?string
    {
        if ($this->photo_path === null || $this->photo_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->photo_path);
    }

    public function displayName(): string
    {
        return $this->full_name ?: $this->name;
    }
}
