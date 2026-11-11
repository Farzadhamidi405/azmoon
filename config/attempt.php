<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Heartbeat timeout (seconds)
    |--------------------------------------------------------------------------
    | An in-progress attempt whose last heartbeat is strictly older than this
    | many seconds is marked as disconnected by attempts:detect-disconnected.
    | Must be an integer >= 1, otherwise the command fails without changes.
    */
    'heartbeat_timeout_seconds' => (int) env('ATTEMPT_HEARTBEAT_TIMEOUT_SECONDS', 90),

    /*
    |--------------------------------------------------------------------------
    | Disconnect notification de-duplication window (seconds)
    |--------------------------------------------------------------------------
    | A recipient does not receive a second "candidate disconnected"
    | notification for the same attempt within this window.
    | Must be an integer >= 1.
    */
    'disconnect_notification_dedupe_seconds' => 600,

];