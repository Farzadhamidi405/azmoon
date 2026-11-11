<?php

namespace App\Providers;

use App\Models\Exam;
use App\Models\Incident;
use App\Models\ManualOverride;
use App\Policies\ExamPolicy;
use App\Policies\IncidentPolicy;
use App\Policies\ManualOverridePolicy;
use Illuminate\Support\Facades\Gate;
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
     * ثبت Policyها برای کنترل دسترسی.
     */
    public function boot(): void
    {
        Gate::policy(Exam::class, ExamPolicy::class);
        Gate::policy(Incident::class, IncidentPolicy::class);
        Gate::policy(ManualOverride::class, ManualOverridePolicy::class);
    }
}