<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    use AuthorizesRequests;

    /**
     * Resolve a safe pagination size from query input.
     */
    protected function resolvePerPage(Request $request, int $default = 15): int
    {
        $allowed = [10, 20, 50, 100, 200, 500];
        $requested = (int) $request->input('per_page', $default);

        return in_array($requested, $allowed, true) ? $requested : $default;
    }
}
