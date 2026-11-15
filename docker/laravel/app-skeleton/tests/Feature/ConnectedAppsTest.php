<?php

namespace Tests\Feature;

use App\Support\ConnectedApps\ConnectedAppRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 090-i Phase 4 — connected-app discovery (Class C).
 *
 * The four product keys are never reimplemented here (ADR-11). A configured
 * product base is probed, cached, and reported through the capability
 * manifest: its key flips true and `endpoint_urls` publishes the base the
 * console mounts the island against. Everything else stays false with the
 * `external-product` reason, so a false is never a gap.
 */
class ConnectedAppsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['oos.connected_apps' => array()]);
    }

    public function test_no_configured_apps_means_no_urls_and_no_probe(): void
    {
        Http::fake();

        $this->assertSame(array(), app(ConnectedAppRegistry::class)->available());
        Http::assertNothingSent();

        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('data.endpoints.contentGraph', false)
            ->assertJsonPath('data.endpoint_urls', array());
    }

    public function test_a_reachable_app_flips_its_key_and_publishes_the_url(): void
    {
        $base = 'https://docs.example.com/wp-json/nvoos-docs/v1';

        config(['oos.connected_apps' => array('docsHub' => array('url' => $base))]);
        Http::fake(array($base.'/health' => Http::response(array('status' => 'ok'))));

        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('data.endpoints.docsHub', true)
            ->assertJsonPath('data.endpoint_urls.docsHub', $base)
            ->assertJsonPath('data.endpoint_gaps', array())
            // Availability and supply class are separate facts: the product is
            // still discovered, never ported.
            ->assertJsonPath('data.endpoint_reasons.docsHub', 'external-product');

        Http::assertSent(
            fn (Request $request): bool => str_ends_with($request->url(), '/nvoos-docs/v1/health')
        );
    }

    public function test_a_missing_product_leaves_the_key_unavailable_without_a_gap(): void
    {
        config(['oos.connected_apps' => array(
            'docsHub' => array('url' => 'https://docs.example.com/wp-json/nvoos-docs/v1'),
        )]);
        Http::fake(array('*' => Http::response('No route was found', 404)));

        $this->assertSame(array(), app(ConnectedAppRegistry::class)->available());

        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('data.endpoints.docsHub', false)
            ->assertJsonPath('data.endpoint_gaps', array());
    }

    public function test_an_auth_challenge_counts_as_reached(): void
    {
        config(['oos.connected_apps' => array(
            'mediaStudio' => array('url' => 'https://media.example.com/wp-json/nvoos-media-studio/v1'),
        )]);
        Http::fake(array('*' => Http::response('', 401)));

        $this->assertSame(
            array('mediaStudio' => 'https://media.example.com/wp-json/nvoos-media-studio/v1'),
            app(ConnectedAppRegistry::class)->available()
        );
    }

    public function test_a_transport_error_leaves_the_app_unavailable(): void
    {
        config(['oos.connected_apps' => array(
            'documentEditor' => array('url' => 'https://docs.example.com/wp-json/nvoos-document-editor/v1'),
        )]);
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('data.endpoints.documentEditor', false);
    }

    public function test_private_and_reserved_literals_are_refused_before_any_request(): void
    {
        $registry = app(ConnectedAppRegistry::class);

        $this->assertNotNull($registry->refuse('https://10.0.0.5/wp-json/nvoos-docs/v1'));
        $this->assertNotNull($registry->refuse('https://127.0.0.1/wp-json/nvoos-docs/v1'));
        $this->assertNotNull($registry->refuse('https://169.254.169.254/latest/meta-data'));
        $this->assertNotNull($registry->refuse('https://user:pass@example.com/wp-json/nvoos-docs/v1'));
        $this->assertNotNull($registry->refuse('file:///etc/passwd'));
        $this->assertNull($registry->refuse('https://docs.example.com/wp-json/nvoos-docs/v1'));
        // Localhost is allowed (a local product during migration).
        $this->assertNull($registry->refuse('http://localhost:8080/wp-json/nvoos-docs/v1'));

        config(['oos.connected_apps' => array(
            'docsHub' => array('url' => 'https://10.0.0.5/wp-json/nvoos-docs/v1'),
        )]);

        Http::fake();
        $this->assertSame(array(), $registry->available());
        Http::assertNothingSent();
    }

    public function test_plain_http_is_refused_outside_local_environments(): void
    {
        // HTTPS is required for a public host; loopback stays reachable over
        // http so a local product can be connected during a migration.
        $this->assertNotNull(app(ConnectedAppRegistry::class)->refuse('http://docs.example.com/api'));
    }

    public function test_results_are_cached_per_configuration(): void
    {
        config(['oos.connected_apps' => array(
            'docsHub' => array('url' => 'https://docs.example.com/wp-json/nvoos-docs/v1'),
        )]);
        Http::fake(array('*' => Http::response(array('ok' => true))));

        $registry = app(ConnectedAppRegistry::class);

        $registry->available();
        $registry->available();

        Http::assertSentCount(1);
    }
}
