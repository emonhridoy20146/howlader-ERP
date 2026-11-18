<?php

namespace App\Services;

use App\Models\Location;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class StockTransferApprovalService
{
    public function canApproveForLocation(User $user, Location $location): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->hasPermission('stock-transfer.approve') && ! $user->hasPermission('transfers.approve')) {
            return false;
        }

        if ($location->manager_user_id) {
            return (int) $location->manager_user_id === (int) $user->id;
        }

        return in_array((int) $location->id, $user->accessibleLocationIds(), true);
    }

    public function canApproveSource(User $user, StockTransfer $transfer): bool
    {
        if ($transfer->status !== 'draft' || $transfer->source_approved_at !== null) {
            return false;
        }

        $transfer->loadMissing('sourceLocation');

        if (! $transfer->sourceLocation) {
            return false;
        }

        return $this->canApproveForLocation($user, $transfer->sourceLocation);
    }

    public function canApproveDestination(User $user, StockTransfer $transfer): bool
    {
        if ($transfer->status !== 'draft' || $transfer->destination_approved_at !== null) {
            return false;
        }

        $transfer->loadMissing('destinationLocation');

        if (! $transfer->destinationLocation) {
            return false;
        }

        return $this->canApproveForLocation($user, $transfer->destinationLocation);
    }

    public function approveSource(StockTransfer $transfer, User $user): StockTransfer
    {
        if (! $this->canApproveSource($user, $transfer)) {
            throw ValidationException::withMessages([
                'transfer' => 'You cannot approve this transfer for the sending branch.',
            ]);
        }

        $transfer->update([
            'source_approved_by' => $user->id,
            'source_approved_at' => now(),
        ]);

        return $this->finalizeIfBothSidesApproved($transfer->fresh());
    }

    public function approveDestination(StockTransfer $transfer, User $user): StockTransfer
    {
        if (! $this->canApproveDestination($user, $transfer)) {
            throw ValidationException::withMessages([
                'transfer' => 'You cannot approve this transfer for the receiving branch.',
            ]);
        }

        $transfer->update([
            'destination_approved_by' => $user->id,
            'destination_approved_at' => now(),
        ]);

        return $this->finalizeIfBothSidesApproved($transfer->fresh());
    }

    public function finalizeIfBothSidesApproved(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->source_approved_at === null || $transfer->destination_approved_at === null) {
            return $transfer;
        }

        if ($transfer->status === 'approved') {
            return $transfer;
        }

        $finalApproverId = $transfer->destination_approved_by ?? $transfer->source_approved_by;
        $finalApprovedAt = $transfer->destination_approved_at ?? $transfer->source_approved_at;

        $transfer->update([
            'status' => 'approved',
            'approved_by' => $finalApproverId,
            'approved_at' => $finalApprovedAt,
        ]);

        return $transfer->fresh();
    }
}
