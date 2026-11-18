<?php

namespace App\Http\Controllers;

use App\Support\InventoryLocationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContextLocationController extends Controller
{
    public function update(Request $request, InventoryLocationContext $context): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['nullable', 'string'],
        ]);

        $raw = $data['location_id'] ?? '';
        $user = $request->user();

        if ($raw === '' || $raw === 'all') {
            if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
                $ids = $user->accessibleLocationIds();
                $context->set($ids[0] ?? null);
            } else {
                $context->set(null);
            }
        } else {
            $context->set((int) $raw);
        }

        return back();
    }
}
