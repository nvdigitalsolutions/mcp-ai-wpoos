<?php

namespace Tests\Feature;

use App\Support\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proposal 149 §187 — the response security headers.
 *
 * `enable_security_headers`, `enable_hsts`, `hsts_max_age` and
 * `csp_frame_ancestors` were writable on `/ops/settings` and read by nothing. The
 * assertions are about the header a client actually receives, because that is the
 * only thing the control promises.
 */
final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_policy_emits_the_baseline_headers(): void
    {
        config(array('app.url' => 'http://localhost'));

        $response = $this->getJson('/api/v1/features')->assertOk();

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // The field's default is `none`, which blocks all framing — including
        // same-origin. That is what the option says; `self` is the setting that
        // permits same-origin embedding.
        $this->assertSame(
            "frame-ancestors 'none'",
            $response->headers->get('Content-Security-Policy')
        );

        $this->assertNull(
            $response->headers->get('Strict-Transport-Security'),
            'HSTS must not be sent without an explicit max-age over HTTPS.'
        );
    }

    public function test_the_umbrella_switch_removes_every_header(): void
    {
        $this->write(array('enable_security_headers' => '0'));

        $response = $this->getJson('/api/v1/features')->assertOk();

        $this->assertNull($response->headers->get('X-Content-Type-Options'));
        $this->assertNull($response->headers->get('X-Frame-Options'));
    }

    public function test_a_frame_ancestors_policy_supersedes_x_frame_options(): void
    {
        $this->write(array('csp_frame_ancestors' => 'self'));

        $response = $this->getJson('/api/v1/features')->assertOk();

        $this->assertSame(
            "frame-ancestors 'self'",
            $response->headers->get('Content-Security-Policy')
        );

        $this->assertNull(
            $response->headers->get('X-Frame-Options'),
            'Sending both is contradictory; CSP wins in modern browsers.'
        );
    }

    public function test_disabling_csp_framing_falls_back_to_x_frame_options(): void
    {
        $this->write(array('csp_frame_ancestors' => ''));

        $response = $this->getJson('/api/v1/features')->assertOk();

        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function test_a_hand_written_row_cannot_inject_a_header(): void
    {
        // The writer refuses anything outside the select, so bypass it the way an
        // import or a migration would.
        DB::table('nvoos_settings')->insert(array(
            'key' => 'csp_frame_ancestors',
            'value' => "self\r\nX-Evil: 1",
            'created_at' => now(),
            'updated_at' => now(),
        ));

        $response = $this->getJson('/api/v1/features')->assertOk();

        $this->assertNull($response->headers->get('X-Evil'));
        $this->assertNull(
            $response->headers->get('Content-Security-Policy'),
            'An unrecognised value maps to no token; the caller falls back to XFO.'
        );
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function test_hsts_is_sent_over_https_with_a_positive_age(): void
    {
        config(array('app.url' => 'https://console.example'));

        $this->write(array('enable_hsts' => '1', 'hsts_max_age' => 31536000));

        $response = $this->getJson('/api/v1/features')->assertOk();

        $this->assertSame(
            'max-age=31536000',
            $response->headers->get('Strict-Transport-Security')
        );
    }

    public function test_hsts_is_withheld_when_the_age_is_zero(): void
    {
        config(array('app.url' => 'https://console.example'));

        $this->write(array('enable_hsts' => '1', 'hsts_max_age' => 0));

        $response = $this->getJson('/api/v1/features')->assertOk();

        $this->assertNull(
            $response->headers->get('Strict-Transport-Security'),
            'max-age=0 disables HSTS, so a zero is "not configured", not a policy.'
        );
    }

    public function test_hsts_is_withheld_over_plain_http(): void
    {
        config(array('app.url' => 'http://console.example'));

        $this->write(array('enable_hsts' => '1', 'hsts_max_age' => 31536000));

        $response = $this->getJson('/api/v1/features')->assertOk();

        $this->assertNull(
            $response->headers->get('Strict-Transport-Security'),
            'A browser ignores HSTS over HTTP; sending it would be noise, not protection.'
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function write(array $values): void
    {
        $applied = $this->app->make(SettingsService::class)->write(
            $values,
            SettingsService::SURFACE_OPS
        );

        $this->assertSame(array_keys($values), $applied, 'The writer refused a key this test needs.');
    }
}
