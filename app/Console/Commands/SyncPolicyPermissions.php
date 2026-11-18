<?php

namespace App\Console\Commands;

use App\Services\PermissionSyncService;
use Illuminate\Console\Command;

class SyncPolicyPermissions extends Command
{
    protected $signature = 'permissions:sync
        {--keep-stale : Leave permission rows that are no longer in the catalogue}';

    protected $description = 'Write config/permissions.php into the permissions table and remap retired slugs on existing roles';

    protected $aliases = ['permissions:sync-policies'];

    public function handle(PermissionSyncService $service): int
    {
        $result = $service->sync(! $this->option('keep-stale'));

        $this->components->info('Permission catalogue synced.');
        $this->line("Created:  {$result['created']}");
        $this->line("Existing: {$result['existing']}");
        $this->line("Pruned:   {$result['pruned']}");
        $this->line("Remapped onto roles: {$result['remapped']}");

        $missing = $service->abilitiesMissingFromCatalog();
        if ($missing !== []) {
            $this->newLine();
            $this->components->warn('These policy abilities have no entry in config/permissions.php, so no role can be granted them:');
            foreach ($missing as $slug) {
                $this->line('  - '.$slug);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
