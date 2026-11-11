<?php

namespace App\Providers;

use App\Models\Question;
use App\Policies\QuestionPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * ثبت Policy‌های سیستم — هر Model به Policy خودش وصل می‌شود.
     */
    protected $policies = [
        Question::class => QuestionPolicy::class,
    ];

    public function boot(): void
    {
        //
    }
}