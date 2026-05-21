<?php

namespace App\Providers;

use App\Models\Request;
use App\Models\Vehicle;
use App\Policies\RequestPolicy;
use App\Policies\VehiclePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Request::class => RequestPolicy::class,
        Vehicle::class => VehiclePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Define admin gate
        Gate::define('admin', function ($user) {
            return $user->hasRole('Admin');
        });

        // Define approver gate
        Gate::define('approver', function ($user) {
            return $user->hasAnyRole(['Admin', 'Approver']);
        });

        // Define GA gate
        Gate::define('ga', function ($user) {
            return $user->hasAnyRole(['Admin', 'GA']);
        });
    }
}
