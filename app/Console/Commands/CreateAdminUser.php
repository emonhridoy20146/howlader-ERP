<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdminUser extends Command
{
    protected $signature = 'admin:create
        {email? : Email address for the administrator}
        {--name= : Display name (defaults to Administrator)}
        {--password= : Password (min 8 characters)}
        {--random-password : Generate a random password and display it once}';

    protected $description = 'Create or update an administrator with the admin role, all permissions, and access to every active location';

    public function handle(PermissionSyncService $permissionSync): int
    {
        $permissionSync->sync();

        $allPermissionIds = Permission::query()->pluck('id');
        $adminRole = Role::query()->firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Administrator']
        );
        $adminRole->permissions()->sync($allPermissionIds->all());

        $email = $this->argument('email') ?: text(
            label: 'Email address',
            required: true,
            validate: fn (string $value) => match (true) {
                Validator::make(['email' => $value], ['email' => 'required|email'])->fails() => 'Enter a valid email address.',
                default => null,
            },
        );

        $name = $this->option('name') ?: text(
            label: 'Full name',
            default: 'Administrator',
            required: true,
        );

        $plainPassword = $this->resolvePassword();
        if ($plainPassword === null) {
            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'full_name' => $name,
                'password' => Hash::make($plainPassword),
                'is_active' => true,
            ]
        );

        $locationIds = Location::query()->where('is_active', true)->orderBy('id')->pluck('id')->all();
        $user->roles()->sync([$adminRole->id]);
        $user->accessibleLocations()->sync($locationIds);

        if ($locationIds !== []) {
            $user->forceFill(['branch_id' => $locationIds[0]])->save();
        }

        $this->components->info('Administrator saved successfully.');
        $this->line("Email: {$user->email}");

        if ($this->option('random-password')) {
            $this->components->warn('Store this password securely; it will not be shown again.');
            $this->line("Password: {$plainPassword}");
        }

        return self::SUCCESS;
    }

    private function resolvePassword(): ?string
    {
        if ($this->option('random-password')) {
            return Str::password(20);
        }

        if ($this->option('password') !== null && $this->option('password') !== '') {
            $validator = Validator::make(
                ['password' => $this->option('password')],
                ['password' => ['required', Password::defaults()]]
            );

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $this->components->error($message);
                }

                return null;
            }

            return $this->option('password');
        }

        $password = password(
            label: 'Password',
            required: true,
            validate: fn (string $value) => match (true) {
                Validator::make(['password' => $value], ['password' => ['required', Password::defaults()]])->fails() => 'Password does not meet the application requirements.',
                default => null,
            },
        );

        $confirm = password(
            label: 'Confirm password',
            required: true,
        );

        if (! hash_equals($password, $confirm)) {
            $this->components->error('Passwords do not match.');

            return null;
        }

        return $password;
    }
}
