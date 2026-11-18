<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->e164PhoneNumber(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => fake()->randomElement(['sales', 'warehouse', 'accountant', 'branch_manager']),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Authorization reads roles from the `role_user` pivot, so translate the
     * legacy `role` shorthand into a real role assignment.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if ($user->roles()->exists()) {
                return;
            }

            $slug = self::roleSlugFor((string) $user->role);
            if ($slug === null) {
                return;
            }

            $role = Role::query()->firstOrCreate(['slug' => $slug], ['name' => Str::headline($slug)]);
            $user->roles()->attach($role->id);
            $user->forgetPermissionCache();
        });
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'admin']);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    private static function roleSlugFor(string $legacyRole): ?string
    {
        return match ($legacyRole) {
            'admin' => 'admin',
            'branch_manager' => 'branch-manager',
            'warehouse' => 'warehouse',
            'accountant' => 'accountant',
            'sales' => 'sales',
            default => null,
        };
    }
}
