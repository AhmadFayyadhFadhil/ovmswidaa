<?php

namespace App\Providers;

use App\Models\Request;
use App\Models\Vehicle;
use App\Models\Assignment;
use App\Observers\RequestObserver;
use App\Observers\VehicleObserver;
use App\Observers\AssignmentObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request as HttpRequest;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register observers for audit logging
        Request::observe(RequestObserver::class);
        Vehicle::observe(VehicleObserver::class);
        Assignment::observe(AssignmentObserver::class);

        // Register the global 'api' rate limiter
        RateLimiter::for('api', function (HttpRequest $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
