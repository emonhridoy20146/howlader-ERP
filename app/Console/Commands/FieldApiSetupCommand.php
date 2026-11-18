<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates (or refreshes) the dedicated, non-admin integration user and role for the field
 * API and prints a new bearer token once. Only the token's SHA-256 goes into .env.
 */
class FieldApiSetupCommand extends Command
{
    protected $signature = 'field-api:setup
        {--email=field-integration@localhost : Email of the integration user}
        {--allow-same-day : Also grant installment.allow-same-day-duplicate (owner decision OD-27)}';

    protected $description = 'Create the field integration role/user and print a new API token (shown once)';

    /** Least privilege: read customers/invoices/plans, create payments. */
    private const PERMISSIONS = ['payment.create'];

    public function handle(): int
    {
        $slugs = self::PERMISSIONS;
        if ($this->option('allow-same-day')) {
            $slugs[] = 'installment.allow-same-day-duplicate';
        }

        $missing = array_diff($slugs, Permission::query()->whereIn('slug', $slugs)->pluck('slug')->all());
        if ($missing !== []) {
            $this->error('Missing permissions in the catalogue: '.implode(', ', $missing).' (run the permission sync first).');

            return self::FAILURE;
        }

        $role = Role::query()->firstOrCreate(['slug' => 'field_integration'], ['name' => 'Field Integration']);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id')->all());

        $user = User::query()->updateOrCreate(
            ['email' => (string) $this->option('email')],
            [
                'name' => 'Field Integration',
                'full_name' => 'Field Integration (API)',
                // Random password nobody knows: this account never logs in to the web UI.
                'password' => Hash::make(Str::random(64)),
                'is_active' => true,
            ],
        );
        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync(Location::query()->where('is_active', true)->pluck('id')->all());

        if ($user->isAdmin()) {
            $this->error('The integration user must not be an admin (admins bypass the one-payment-per-day rule).');

            return self::FAILURE;
        }

        $token = Str::random(48);
        $this->info('Integration user id: '.$user->id);
        $this->line('Add to .env:');
        $this->line('FIELD_API_ENABLED=true');
        $this->line('FIELD_API_USER_ID='.$user->id);
        $this->line('FIELD_API_TOKEN_SHA256='.hash('sha256', $token));
        $this->newLine();
        $this->warn('Give this token to field-api (ERP_API_TOKEN). It is shown only once:');
        $this->line($token);

        return self::SUCCESS;
    }
}
