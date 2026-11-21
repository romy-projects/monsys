<?php

/*
|--------------------------------------------------------------------------
| Security (Phase 16A — T16-01 / T16-02 / T16-03)
|--------------------------------------------------------------------------
|
| Transport security, trusted proxies and optional two-factor authentication.
| Deployments terminate TLS on the same host (nginx -> PHP-FPM), so the
| default trusts only the loopback proxy. Never widen this to "*".
|
*/

$trustedProxies = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')),
)));

$twoFactorRoles = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('TWO_FACTOR_ENFORCED_ROLES', '')),
)));

return [

    /*
    | Force https:// on every generated URL. Defaults to "on in production"
    | so local development over http:// keeps working untouched.
    */
    'force_https' => filter_var(
        env('FORCE_HTTPS', env('APP_ENV') === 'production'),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /*
    | IPs / CIDRs of reverse proxies allowed to set X-Forwarded-* headers.
    | Applied in AppServiceProvider::boot() because config is not available
    | when bootstrap/app.php's middleware callback runs.
    */
    'trusted_proxies' => $trustedProxies,

    /*
    | Response headers applied to every web and API response.
    */
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
    ],

    /*
    | Strict-Transport-Security is only sent over an already-secure request,
    | and only once TLS has been verified end to end.
    */
    'hsts' => [
        'enabled' => filter_var(env('HSTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'max_age' => (int) env('HSTS_MAX_AGE', 31536000),
        'include_subdomains' => filter_var(env('HSTS_INCLUDE_SUBDOMAINS', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    | Password lifecycle (Phase 18). Administrators reset another user's
    | password from Users → Reset Password; the field is pre-filled with
    | "default" so the flow works out of the box, but any deployment should
    | override DEFAULT_USER_PASSWORD with a value of its own.
    */
    'password_reset' => [
        'default' => (string) env('DEFAULT_USER_PASSWORD', 'password'),
        'min_length' => 8,
    ],

    /*
    | Two-factor authentication (TOTP). Optional by default: users enrol from
    | the "Security" page and only enrolled users get a code prompt at login.
    | Add role names to "enforced_roles" to require enrolment for those roles.
    */
    'two_factor' => [
        'issuer' => env('TWO_FACTOR_ISSUER', env('APP_NAME', 'PanelX')),
        'window' => (int) env('TWO_FACTOR_WINDOW', 1),
        'recovery_codes' => 8,
        'max_attempts' => 5,
        'enforced_roles' => $twoFactorRoles,
    ],

];
