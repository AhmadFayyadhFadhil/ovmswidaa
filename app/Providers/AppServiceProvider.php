<?php

namespace App\Providers;

use App\Models\Request;
use App\Models\Vehicle;
use App\Models\Assignment;
use App\Observers\RequestObserver;
use App\Observers\VehicleObserver;
use App\Observers\AssignmentObserver;
use Illuminate\Support\ServiceProvider;

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
    }
}
