<?php

namespace App\Support;

use App\Models\User;
use App\Models\Wallet;

final class OwnerCapitalWallet
{
    public static function code(): string
    {
        return (string) config('inventory.owner_capital_wallet_code', 'OWNER-0001');
    }

    public static function isOwnerCapitalWallet(Wallet|string|null $wallet): bool
    {
        if ($wallet === null) {
            return false;
        }

        $code = $wallet instanceof Wallet
            ? (string) $wallet->code
            : $wallet;

        return strtoupper(trim($code)) === strtoupper(self::code());
    }

    /**
     * Active owner-capital wallet available for a branch (ledger account must be active).
     */
    public static function activeForLocation(int $locationId): ?Wallet
    {
        return Wallet::query()
            ->where('code', self::code())
            ->where('is_active', true)
            ->whereAppliesToLocation($locationId)
            ->whereHas('ledgerAccount', fn ($q) => $q->where('is_active', true))
            ->first();
    }

    public static function userCanExpenseFrom(User $user, Wallet $wallet): bool
    {
        return self::userMayOperate($user, $wallet);
    }

    public static function userCanPostOpeningBalance(User $user, Wallet $wallet): bool
    {
        return self::userMayOperate($user, $wallet);
    }

    public static function userCanTransferFrom(User $user, Wallet $wallet): bool
    {
        return self::userMayOperate($user, $wallet);
    }

    private static function userMayOperate(User $user, Wallet $wallet): bool
    {
        if (! self::isOwnerCapitalWallet($wallet)) {
            return true;
        }

        return $user->hasPermission('wallet.owner-capital.manage');
    }

    public static function userCanTransferTo(User $user, Wallet $wallet): bool
    {
        return true;
    }

    public static function assertExpenseFrom(User $user, Wallet $wallet, string $fieldKey = 'wallet_id'): void
    {
        if (self::userCanExpenseFrom($user, $wallet)) {
            return;
        }

        throw \Illuminate\Validation\ValidationException::withMessages([
            $fieldKey => 'You are not permitted to pay expenses from the '.self::code().' wallet.',
        ]);
    }

    public static function assertOpeningBalance(User $user, Wallet $wallet, string $fieldKey = 'wallet_id'): void
    {
        if (self::userCanPostOpeningBalance($user, $wallet)) {
            return;
        }

        throw \Illuminate\Validation\ValidationException::withMessages([
            $fieldKey => 'You are not permitted to post opening balances to the '.self::code().' wallet.',
        ]);
    }

    public static function assertTransferFrom(User $user, Wallet $wallet, string $fieldKey = 'from_wallet_id'): void
    {
        if (self::userCanTransferFrom($user, $wallet)) {
            return;
        }

        throw \Illuminate\Validation\ValidationException::withMessages([
            $fieldKey => 'You are not permitted to transfer funds out of the '.self::code().' wallet. You may transfer into it from another wallet.',
        ]);
    }
}
