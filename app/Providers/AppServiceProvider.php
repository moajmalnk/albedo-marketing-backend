<?php

namespace App\Providers;

use App\Models\Enrollment;
use App\Models\Lead;
use App\Models\LeadStageTransition;
use App\Models\User;
use App\Observers\EnrollmentObserver;
use App\Observers\LeadObserver;
use App\Observers\LeadStageTransitionObserver;
use App\Observers\UserObserver;
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
        Lead::observe(LeadObserver::class);
        User::observe(UserObserver::class);
        Enrollment::observe(EnrollmentObserver::class);
        LeadStageTransition::observe(LeadStageTransitionObserver::class);
    }
}
