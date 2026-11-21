<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 16A / T16-02 — trusted proxies, HTTPS and security headers.
 */
class HttpSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/security', fn () => response('ok'));
        Route::middleware('web')->get('/_test/ip', fn (Request $request) => response($request->ip()));
        Route::middleware('api')->get('/api/v1/_test/ping', fn () => response()->json(['ok' => true]));
    }

    #[Test]
    public function baseline_security_headers_are_added_to_web_responses(): void
    {
        $this->get('/_test/security')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    #[Test]
    public function security_headers_are_added_to_api_responses(): void
    {
        $this->getJson('/api/v1/_test/ping')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    #[Test]
    public function hsts_is_sent_only_for_secure_requests_when_enabled(): void
    {
        config(['security.hsts.enabled' => true]);

        $this->get('/_test/security', ['X-Forwarded-Proto' => 'https'])
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        config(['security.hsts.enabled' => false]);

        $this->get('/_test/security', ['X-Forwarded-Proto' => 'https'])
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    #[Test]
    public function hsts_is_never_sent_for_plain_http_requests(): void
    {
        config(['security.hsts.enabled' => true]);

        $this->get('/_test/security')->assertHeaderMissing('Strict-Transport-Security');
    }

    #[Test]
    public function the_wildcard_proxy_default_is_gone(): void
    {
        $this->assertNotContains('*', (array) config('security.trusted_proxies'));
        $this->assertSame(['127.0.0.1', '::1'], config('security.trusted_proxies'));
    }

    #[Test]
    public function forwarded_headers_are_honoured_only_for_trusted_proxies(): void
    {
        $this->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.9'])
            ->assertOk()
            ->assertSee('203.0.113.9');

        TrustProxies::at([]);

        $this->get('/_test/ip', ['X-Forwarded-For' => '198.51.100.7'])
            ->assertOk()
            ->assertSee('127.0.0.1');
    }
}
