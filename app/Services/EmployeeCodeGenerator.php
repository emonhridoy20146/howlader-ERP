<?php

namespace App\Services;

use App\Models\Employee;

class EmployeeCodeGenerator
{
    public function next(): string
    {
        $latestId = (int) Employee::query()->max('id');

        return 'EMP-'.str_pad((string) ($latestId + 1), 5, '0', STR_PAD_LEFT);
    }
}
