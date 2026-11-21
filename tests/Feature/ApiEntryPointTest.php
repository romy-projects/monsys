<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The API is mounted under the `api/v1` prefix (bootstrap/app.php). These tests
 * pin the two things a caller sees before it ever reaches a controller: the
 * entry point itself, and the answer to an unauthenticated request.
 */
class ApiEntryPointTest extends TestCase
{
    #[Test]
    public function the_api_prefix_answers_with_its_entry_point(): void
    {
        $this->get('/api/v1')
            ->assertOk()
            ->assertJson([
                'version' => 'v1',
                'status' => 'ok',
            ]);
    }

    #[Test]
    public function an_api_guest_gets_a_json_401_without_asking_for_json(): void
    {
        // Browsers do not send `Accept: application/json`, and that path used to
        // die on the missing `login` route instead of answering 401.
        $this->get('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    #[Test]
    public function a_web_guest_is_sent_to_the_panel_login(): void
    {
        $this->get('/export/sales')
            ->assertRedirect(route('filament.admin.auth.login'));
    }
}
