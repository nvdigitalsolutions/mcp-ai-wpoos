<?php

namespace Tests\Feature;

use App\Support\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proposal 149 §184 — the two edge policies the operator can set must be the
 * ones the runtime applies.
 *
 * `cors_allow_origin`, `cors_allowed_origins`, `max_request_body_size_kb` and
 * `max_json_depth` were writable on `/ops/settings` and read by nothing. These
 * assertions drive real requests through the real middleware stack, because the
 * failure mode is not "the value is not stored" — it is "the value is stored and
 * the runtime ignores it", which only a request can catch.
 *
 * The CORS cases also pin the precedence rule. A stored list wins; an empty one
 * must NOT win, or an operator who never opened the admin loses the deployment's
 * `NVOOS_CORS_ORIGINS` on the next request.
 */
final class EdgePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_wildcard_mode_allows_any_origin_without_credentials(): void
    {
        $this->write(array('cors_allow_origin' => 'star'));

        $response = $this->withHeaders(array('Origin' => 'https://anywhere.example'))
            ->getJson('/api/v1/features')
            ->assertOk();

        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));

        // The Fetch standard makes `*` invalid on a credentialed request, and
        // `config/cors.php` defaults credentials to true. The pair must not both
        // be on, or the browser refuses the response.
        $this->assertNotSame('true', (string) $response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_same_origin_mode_allows_only_the_listed_origins(): void
    {
        $this->write(array(
            'cors_allow_origin' => 'site',
            'cors_allowed_origins' => "https://console.example\nhttps://staging.example/",
        ));

        $allowed = $this->withHeaders(array('Origin' => 'https://console.example'))
            ->getJson('/api/v1/features');

        $this->assertSame(
            'https://console.example',
            $allowed->headers->get('Access-Control-Allow-Origin')
        );

        $refused = $this->withHeaders(array('Origin' => 'https://not-listed.example'))
            ->getJson('/api/v1/features');

        $this->assertNotSame(
            'https://not-listed.example',
            $refused->headers->get('Access-Control-Allow-Origin'),
            'An origin that was never listed must not be echoed back.'
        );
    }

    public function test_a_trailing_slash_in_the_list_does_not_break_the_match(): void
    {
        $this->write(array(
            'cors_allow_origin' => 'site',
            'cors_allowed_origins' => 'https://console.example/',
        ));

        $response = $this->withHeaders(array('Origin' => 'https://console.example'))
            ->getJson('/api/v1/features');

        $this->assertSame(
            'https://console.example',
            $response->headers->get('Access-Control-Allow-Origin'),
            'The stored list is normalised, because HandleCors compares the header literally.'
        );
    }

    public function test_a_bare_wildcard_in_the_textarea_is_ignored(): void
    {
        $this->write(array(
            'cors_allow_origin' => 'site',
            'cors_allowed_origins' => "*\nhttps://console.example",
        ));

        $response = $this->withHeaders(array('Origin' => 'https://not-listed.example'))
            ->getJson('/api/v1/features');

        $this->assertNotSame(
            '*',
            $response->headers->get('Access-Control-Allow-Origin'),
            'A wildcard in a textarea must not open the API; that is what the select is for.'
        );
    }

    public function test_an_empty_stored_list_leaves_the_deployment_policy_alone(): void
    {
        // The deployment configured CORS by environment, as it did before this
        // slice. The admin has never been used.
        config(array('cors.allowed_origins' => array('https://env.example')));

        $this->write(array(
            'cors_allow_origin' => 'site',
            'cors_allowed_origins' => '',
        ));

        $response = $this->withHeaders(array('Origin' => 'https://env.example'))
            ->getJson('/api/v1/features');

        $this->assertSame(
            'https://env.example',
            $response->headers->get('Access-Control-Allow-Origin'),
            'An empty setting must not overwrite the configured origins.'
        );
    }

    public function test_a_body_over_the_limit_is_refused_before_authentication(): void
    {
        $this->write(array('max_request_body_size_kb' => 1));

        $this->postJson('/api/v1/session/login', array(
            'email' => str_repeat('a', 2048),
            'password' => 'secret',
        ))->assertStatus(413);
    }

    public function test_zero_disables_the_size_limit(): void
    {
        $this->write(array('max_request_body_size_kb' => 0));

        $response = $this->postJson('/api/v1/session/login', array(
            'email' => str_repeat('a', 2048),
            'password' => 'secret',
        ));

        $this->assertNotSame(413, $response->getStatusCode(), 'Zero means no limit, per the field contract.');
    }

    public function test_a_deeply_nested_body_is_refused(): void
    {
        $this->write(array('max_json_depth' => 3));

        $this->postJson('/api/v1/session/login', array(
            'a' => array('b' => array('c' => array('d' => array('e' => 1)))),
        ))->assertStatus(400);
    }

    public function test_a_normal_body_passes_the_guard(): void
    {
        $response = $this->postJson('/api/v1/session/login', array(
            'email' => 'owner@example.com',
            'password' => 'secret',
        ));

        $this->assertNotContains(
            $response->getStatusCode(),
            array(400, 413),
            'A shallow, small body must not be refused by the guard.'
        );
    }

    /**
     * Write operator-scope settings through the real writer.
     *
     * @param array<string, mixed> $values
     */
    private function write(array $values): void
    {
        $applied = $this->app->make(SettingsService::class)->write(
            $values,
            SettingsService::SURFACE_OPS
        );

        $this->assertSame(
            array_keys($values),
            $applied,
            'The writer refused a key this test needs; the assertion would pass for the wrong reason.'
        );
    }
}
