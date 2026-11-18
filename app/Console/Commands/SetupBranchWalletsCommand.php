<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\BranchSetupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:setup-branch-wallets')]
#[Description('Setup default cash wallets and payment methods for all existing branches')]
class SetupBranchWalletsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BranchSetupService $setupService)
    {
        $this->info('Starting branch wallet and payment method setup...');

        $branches = Location::where('type', 'branch')->get();
        $count = $branches->count();

        $this->info("Found {$count} branches to process.");

        $bar = $this->output->createProgressBar($count);

        foreach ($branches as $branch) {
            $setupService->setupDefaultCashWalletAndPaymentMethod($branch);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Branch setup completed successfully!');
    }
}
