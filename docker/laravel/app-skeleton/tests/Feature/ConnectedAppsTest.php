<?php

namespace Tests\Feature;

use App\Support\ConnectedApps\ConnectedAppRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 090-i Phase 4 — connected-app discovery (Class C), hardened in 090-p W3.
 *
 * The four product keys are never reimplemented here (ADR-11). A configured
 * product base is probed, cached, and reported through the capability
 * manifest: its key flips true and `endpoint_urls` publishes the base the
 * console mounts the island against. Everything else stays false with the
 * `external-product` reason, so a false is never a gap.
 *
 * Since W3 the probe resolves the hostname, validates every address (OWASP:
 * validate the resolved IP, not just the hostname) and pins the validated
 * address with CURLOPT_RESOLVE. These tests inject a resolver so they never
 * depend on the network.
 */
class ConnectedAppsTest extends TestCase
{
    /** A public address used as the fake resolution answer. */
    private const PUBLIC_IP = '93.184.216.34';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['oos.connected_apps' => array()]);
    }

    /**
     * A registry whose DNS answer is fixed for the test.
     *
     * @param string[] $ips
     */
    private function registry(array $ips = array(self::PUBLIC_IP)): ConnectedAppRegistry
    {
        return new ConnectedAppRegistry(fn (): array => $ips);
    }

    /**
     * Bind the fake-resolver registry into the container (for endpoint tests).
     *
     * @param string[] $ips
     */
    private function bindResolver(array $ips = array(self::PUBLIC_IP)): ConnectedAppRegistry
    {
        $registry = $this->registry($ips);
        $this->app->instance(ConnectedAppRegistry::class, $registry);

        return $registry;
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
        $this->bindResolver();
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
        $this->bindResolver();
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
            $this->registry()->available()
        );
    }

    public function test_a_transport_error_leaves_the_app_unavailable(): void
    {
        config(['oos.connected_apps' => array(
            'documentEditor' => array('url' => 'https://docs.example.com/wp-json/nvoos-document-editor/v1'),
        )]);
        $this->bindResolver();
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('data.endpoints.documentEditor', false);
    }

    public function test_a_hostname_resolving_to_a_private_address_is_refused(): void
    {
        config(['oos.connected_apps' => array(
            'docsHub' => array('url' => 'https://docs.example.com/wp-json/nvoos-docs/v1'),
        )]);
        Http::fake();

        $this->assertSame(array(), $this->registry(array('10.0.0.5'))->available());
        Http::assertNothingSent();
    }

    public function test_a_mixed_resolution_is_refused(): void
    {
        // One private answer among public ones is enough to refuse: the client
        // could otherwise be steered to the private address on a retry.
        config(['oos.connected_apps' => array(
            'docsHub' => array('url' => 'https://docs.example.com/wp-json/nvoos-docs/v1'),
        )]);
        Http::fake();

        $this->assertSame(
            array(),
            $this->registry(array(self::PUBLIC_IP, '169.254.169.254'))->available()
        );
        Http::assertNothingSent();
    }

    public function test_an_unresolvable_host_is_refused(): void
    {
        config(['oos.connected_apps' => array(
            'docsHub' => array('url' => 'https://docs.example.com/wp-json/nvoos-docs/v1'),
        )]);
        Http::fake();

        $this->assertSame(array(), $this->registry(array())->available());
        Http::assertNothingSent();
    }

    public function test_a_configured_token_is_sent_as_a_bearer(): void
    {
        config(['oos.connected_apps' => array(
            'docsHub' => array(
                'url' => 'https://docs.example.com/wp-json/nvoos-docs/v1',
                'token' => 'secret-token',
            ),
        )]);
        Http::fake(array('*' => Http::response(array('ok' => true))));

        $this->registry()->available();

        Http::assertSent(
            fn (Request $request): bool => 'Bearer secret-token' === $request->header('Authorization')[0]
                && str_ends_with($request->url(), '/nvoos-docs/v1/health')
        );
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

    /**
     * @dataProvider public_ip_cases
     */
    public function test_is_public_ip(string $ip, bool $expected): void
    {
        $this->assertSame($expected, app(ConnectedAppRegistry::class)->isPublicIp($ip));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function public_ip_cases(): array
    {
        return array(
            'public v4'        => array('93.184.216.34', true),
            'public v6'        => array('2001:4860:4860::8888', true),
            'rfc1918'          => array('10.0.0.1', false),
            'cgnat'            => array('100.64.0.1', false),
            'benchmarking'     => array('198.18.0.1', false),
            'loopback'         => array('127.0.0.1', false),
            'link-local'       => array('169.254.169.254', false),
            'documentation v4' => array('192.0.2.1', false),
            'documentation v6' => array('2001:db8::1', false),
            'unspecified v6'   => array('::', false),
            'not an address'   => array('example.com', false),
        );
    }

    public function test_localhost_resolves_and_is_not_blocked(): void
    {
        $resolution = app(ConnectedAppRegistry::class)->resolveHost('localhost');

        $this->assertNotSame(array(), $resolution['ips']);
        $this->assertFalse($resolution['blocked']);
    }

    public function test_results_are_cached_per_configuration(): void
    {
        config(['oos.connected_apps' => array(
            'docsHub' => array('url' => 'https://docs.example.com/wp-json/nvoos-docs/v1'),
        )]);
        Http::fake(array('*' => Http::response(array('ok' => true))));

        $registry = $this->registry();

        $registry->available();
        $registry->available();

        Http::assertSentCount(1);
    }
}
