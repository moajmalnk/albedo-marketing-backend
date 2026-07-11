<?php

namespace App\Providers;

use App\Models\Enrollment;
use App\Models\Lead;
use App\Models\LeadStageTransition;
use App\Models\User;
use App\Observers\EnrollmentObserver;
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
        Enrollment::observe(EnrollmentObserver::class);
    }
}
