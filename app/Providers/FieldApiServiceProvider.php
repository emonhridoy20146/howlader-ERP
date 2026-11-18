<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateFieldApiToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Self-contained Field Operations integration API (/api/field/v1).
 * Registers its own routes and rate limiter; touches no existing route, table or service.
 * The routes answer 404 until FIELD_API_ENABLED=true (checked per request by the middleware).
 */
class FieldApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('field-api', fn (Request $request) => Limit::perMinute((int) config('field_api.rate_per_minute', 300))
            ->by('field-api|'.sha1((string) $request->bearerToken())));

        if (! $this->app->routesAreCached()) {
            Route::prefix('api/field/v1')
                ->middleware([AuthenticateFieldApiToken::class, 'throttle:field-api', SubstituteBindings::class])
                ->name('field-api.')
                ->group(base_path('routes/field-api.php'));
        }
    }
}
