<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\UserImpersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserImpersonationController extends Controller
{
    public function store(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();
        abort_unless($admin && UserImpersonation::canImpersonate($admin, $user), 403);

        $request->session()->put(UserImpersonation::SESSION_KEY, (int) $admin->id);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('dashboard')
            ->with('status', 'You are now logged in as '.$user->displayName().'.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $adminId = UserImpersonation::impersonatorId();
        if ($adminId === null) {
            return redirect()->route('dashboard');
        }

        $request->session()->forget(UserImpersonation::SESSION_KEY);

        $admin = User::query()
            ->whereKey($adminId)
            ->where('is_active', true)
            ->first();

        if ($admin === null || ! $admin->isAdmin()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->with('status', 'Impersonation session ended. Please sign in again.');
        }

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()
            ->route('users.index')
            ->with('status', 'Logged back in as administrator.');
    }
}
