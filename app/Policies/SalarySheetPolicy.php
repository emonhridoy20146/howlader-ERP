<?php

namespace App\Policies;

use App\Models\SalarySheet;
use App\Models\User;

class SalarySheetPolicy extends BaseModelPolicy
{
    public function generate(User $user): bool
    {
        return $this->allows($user, 'generate');
    }

    public function finalize(User $user, SalarySheet $salarySheet): bool
    {
        return $this->allows($user, 'finalize');
    }
}
