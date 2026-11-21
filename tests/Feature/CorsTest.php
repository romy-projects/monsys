<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 16A / T16-01 — CORS is an explicit allow-list and never credentialed.
 */
class CorsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function credentials_are_never_allowed(): void
    {
        $this->assertFalse(config('cors.supports_credentials'));
        $this->assertContains('api/*', (array) config('cors.paths'));
    }

    #[Test]
    public function a_preflight_from_an_allowed_origin_is_accepted(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.test']]);

        $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.test')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    #[Test]
    public function a_preflight_from_an_unknown_origin_gets_no_cors_headers(): void
    {
        // Two origins, so php-cors does not use its single-origin shortcut
        // (which always echoes the configured origin).
        config(['cors.allowed_origins' => ['https://app.example.test', 'https://admin.example.test']]);

        $response = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function actual_api_requests_from_an_allowed_origin_carry_the_header(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.test']]);

        $this->getJson('/api/v1/me', ['Origin' => 'https://app.example.test'])
            ->assertUnauthorized()
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.test');
    }

    #[Test]
    public function no_origin_is_allowed_when_the_allow_list_is_empty(): void
    {
        config(['cors.allowed_origins' => []]);

        $this->getJson('/api/v1/me', ['Origin' => 'https://app.example.test'])
            ->assertUnauthorized()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
