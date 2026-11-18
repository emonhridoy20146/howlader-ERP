<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Self-contained SMS module: registers its gate and routes without touching
 * the rest of the application. Nothing here sends SMS on its own.
 */
class SmsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Admins already pass every gate through AppServiceProvider's Gate::before;
        // this keeps the module closed to everyone else unless their role is listed.
        Gate::define('send-sms', function (User $user): bool {
            $allowed = (array) config('sms.allowed_roles', ['admin']);

            return $user->isAdmin() || array_intersect($user->roleSlugs(), $allowed) !== [];
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(base_path('routes/sms.php'));
        }
    }
}
