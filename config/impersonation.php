<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Impersonation ("Login As")
    |--------------------------------------------------------------------------
    |
    | Impersonation is session-only: the real account stays in the session and
    | is restored when the session ends. The reason, when required, is stored
    | in the session and copied into the audit trail.
    |
    */

    'require_reason' => env('IMPERSONATION_REQUIRE_REASON', false),

];
