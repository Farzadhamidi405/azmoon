<?php

use App\Models\Candidate;
use App\Models\User;

return [

    'defaults' => [
        'guard'     => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        // Laravel default — kept for framework compatibility.
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],

        // Staff/Admin guard — uses Sanctum token driver for API.
        'admin' => [
            'driver'   => 'sanctum',
            'provider' => 'users',
        ],

        // Candidate guard — uses the session driver (SPA cookie login).
        'candidate' => [
            'driver'   => 'session',
            'provider' => 'candidates',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => User::class,
        ],

        'candidates' => [
            'driver' => 'eloquent',
            'model'  => Candidate::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table'    => env(
                'AUTH_PASSWORD_RESET_TOKEN_TABLE',
                'password_reset_tokens'
            ),
            'expire'   => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];