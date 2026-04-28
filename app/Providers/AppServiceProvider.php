<?php

namespace App\Providers;

use App\Models\Feedback;
use App\Policies\FeedbackPolicy;
use App\Services\ActivityService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ActivityService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Feedback::class, FeedbackPolicy::class);
    }
}
