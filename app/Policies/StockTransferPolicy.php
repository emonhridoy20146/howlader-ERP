<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StockTransferApprovalService;

class StockTransferPolicy extends BaseModelPolicy
{
    public function __construct(
        private readonly StockTransferApprovalService $approvalService,
    ) {}

    public function approve(User $user, StockTransfer $transfer): bool
    {
        return $this->approveSource($user, $transfer) || $this->approveDestination($user, $transfer);
    }

    public function approveSource(User $user, StockTransfer $transfer): bool
    {
        return $this->approvalService->canApproveSource($user, $transfer);
    }

    public function approveDestination(User $user, StockTransfer $transfer): bool
    {
        return $this->approvalService->canApproveDestination($user, $transfer);
    }

    public function issue(User $user, StockTransfer $transfer): bool
    {
        return $this->allows($user, 'issue');
    }

    public function receive(User $user, StockTransfer $transfer): bool
    {
        return $this->allows($user, 'receive');
    }

    public function cancel(User $user, StockTransfer $transfer): bool
    {
        if (! in_array($transfer->status, ['draft', 'approved'], true)) {
            return false;
        }

        return $this->allows($user, 'cancel');
    }
}
