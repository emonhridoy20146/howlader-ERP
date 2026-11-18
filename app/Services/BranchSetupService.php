<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodWalletMap;
use App\Models\Wallet;

class BranchSetupService
{
    /**
     * Automatically sets up a default cash wallet and payment method for a newly created branch.
     * It also links the payment method to the wallet.
     */
    public function setupDefaultCashWalletAndPaymentMethod(Location $branch): void
    {
        if ($branch->type !== 'branch') {
            return;
        }

        // 1. Create or Find a Cash Payment Method for this branch
        $paymentMethod = PaymentMethod::firstOrCreate(
            ['location_id' => $branch->id, 'type' => 'cash'],
            [
                'code' => 'CASH-PM-'.$branch->id,
                'name' => 'Cash ('.$branch->name.')',
                'is_active' => true,
            ]
        );

        // 2. Check if a cash wallet already exists for this branch
        $wallet = $branch->wallets()->where('kind', 'cash')->first();

        if (! $wallet) {
            // Ensure Ledger Account exists
            $ledgerCode = 'W-CASH-'.$branch->id;
            $ledger = LedgerAccount::firstOrCreate(
                ['code' => $ledgerCode],
                [
                    'name' => 'Cash - '.$branch->name,
                    'type' => 'asset',
                    'is_system' => false,
                    'is_active' => true,
                ]
            );

            // Ensure Wallet exists
            $walletCode = 'CASH-'.$branch->id;
            $wallet = Wallet::firstOrCreate(
                ['code' => $walletCode],
                [
                    'ledger_account_id' => $ledger->id,
                    'name' => 'Cash - '.$branch->name,
                    'kind' => 'cash',
                    'is_active' => true,
                ]
            );

            // Attach Wallet to Branch
            $wallet->locations()->syncWithoutDetaching([$branch->id]);
        }

        // 3. Map the payment method to the wallet
        PaymentMethodWalletMap::firstOrCreate([
            'location_id' => $branch->id,
            'payment_method_id' => $paymentMethod->id,
            'wallet_id' => $wallet->id,
        ]);
    }
}
