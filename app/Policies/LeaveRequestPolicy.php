<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy extends BaseModelPolicy
{
    public function approve(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->allows($user, 'approve');
    }

    public function reject(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->allows($user, 'reject');
    }
}
