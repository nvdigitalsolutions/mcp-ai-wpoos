<?php

namespace Tests\Feature;

use App\Support\EndpointCapabilities;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * 090-B — the console's endpoint-availability map.
 *
 * The console builds an endpoint per feature from a base URL, so a page
 * assumes its route exists. This map is how the backend says otherwise: an
 * endpoint reported `false` is blanked by the console and the page renders its
 * unavailable state instead of firing a request that 404s.
 *
 * The map is read from the router, not maintained by hand, so adding a route
 * flips its key without touching the catalogue. The tests below pin the
 * *current* answer so a change is deliberate rather than silent.
 */
class EndpointCapabilitiesTest extends TestCase
{
    public function test_the_features_endpoint_reports_an_endpoint_map(): void
    {
        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.backend', 'laravel')
            ->assertJsonStructure([
                'data' => [
                    'features',
                    'endpoints',
                ],
            ]);
    }

    public function test_routes_this_runtime_serves_are_reported_available(): void
    {
        $endpoints = $this->endpoints();

        // One per phase of the port, so a regression in any area shows up.
        foreach ([
            'chat',
            'chatClient',
            'transcripts',
            'memory',
            'tools',
            'upload',
            'assistants',
            'settings',
            'approvals',
            'triggers',
            'teams',
            'portability',
            'security',
            'operations',
            'diagnostics',
            'paperStore',
            'cost',
            'proOps',
            'scheduleManager',
            'orchestrationStats',
            'tokenManager',
            'wpBase',
            'recordsSchema',
            'dashboardsSchema',
            'toolkits',
            'professions',
            'orgTeams',
            'blueprints',
            // 090-h H1/H2 — the preset catalogue and workflow graph storage.
            'workflowPresets',
            'workflowBuilder',
            // 090-h H3 — execution, run history and the per-node runner.
            'workflowNodes',
            'workflowRuns',
            // 090-b — the Agent Skills catalogue (read-only slice).
            'skills',
            // 090-b — the OKF knowledge drawer (read-only: bundles, concepts,
            // search). `/skills` is honest-empty; there is no grant store.
            'okf',
            // 090-i — assistant MCP app assignments (read + replace).
            'mcpApps',
        ] as $key) {
            $this->assertTrue($endpoints[$key] ?? false, $key.' should be available.');
        }
    }

    public function test_routes_this_runtime_does_not_serve_are_reported_unavailable(): void
    {
        $endpoints = $this->endpoints();

        foreach ([
            // WordPress-only concepts.
            'chatChannels',
            'remoteSites',
            'mediaCc',
            'commandCenter',
            'shortcuts',
            'slashCommands',
            'contentGraph',
            'docsHub',
            'mediaStudio',
            'documentEditor',
        ] as $key) {
            $this->assertFalse($endpoints[$key] ?? true, $key.' should be unavailable.');
        }
    }

    public function test_the_matcher_is_boundary_aware(): void
    {
        // The defect this pins: a plain prefix check said `api/v1/chat` was
        // served by `api/v1/chat-transcripts` and `api/v1/chat-memory`, so the
        // `chat` key could read true while no chat route existed at all.
        $this->assertFalse(EndpointCapabilities::matches('api/v1/chat-transcripts', 'api/v1/chat'));
        $this->assertFalse(EndpointCapabilities::matches('api/v1/chat-memory', 'api/v1/chat'));
        $this->assertFalse(EndpointCapabilities::matches('api/v1/chat-client', 'api/v1/chat'));

        // Exact and descendant matches still count — a collection root is
        // served when only its sub-routes are registered.
        $this->assertTrue(EndpointCapabilities::matches('api/v1/chat', 'api/v1/chat'));
        $this->assertTrue(EndpointCapabilities::matches('api/v1/chat/stream', 'api/v1/chat'));
        $this->assertTrue(EndpointCapabilities::matches('api/v1/pro/schedules/7', 'api/v1/pro/schedules'));

        // A trailing slash on the fragment is normalised; an empty one never
        // matches, so a misconfigured key fails closed.
        $this->assertTrue(EndpointCapabilities::matches('api/v1/tools', 'api/v1/tools/'));
        $this->assertFalse(EndpointCapabilities::matches('api/v1/tools', '/'));
        $this->assertFalse(EndpointCapabilities::matches('api/v1/tools', ''));
    }

    public function test_the_chat_client_route_is_registered_and_authenticated(): void
    {
        $route = null;

        foreach (RouteFacade::getRoutes() as $candidate) {
            if ('api/v1/chat-client' === $candidate->uri()) {
                $route = $candidate;
            }
        }

        $this->assertNotNull($route, 'The console chat transport POSTs to /api/v1/chat-client.');
        $this->assertContains('POST', $route->methods());
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());

        // Declared but unreachable would still read `true` from the map, so the
        // route is exercised too. No DB needed: auth rejects before validation.
        $this->postJson('/api/v1/chat-client', ['messages' => []])
            ->assertStatus(401);
    }

    public function test_the_map_covers_the_console_contract_exactly(): void
    {
        // The shared contract, asserted from the console's Vitest suite too —
        // drift fails on both CIs instead of on a user's browser. Add a key
        // there first, then wire it here.
        $contract = $this->contract();

        $expected = (array) ( $contract['route_backed'] ?? array() );
        $actual   = array_keys($this->endpoints());

        $this->assertNotEmpty($expected, 'The contract must list the route-backed keys.');

        sort($expected);
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            'A key was added to, or dropped from, the console endpoint contract.'
        );
    }

    public function test_the_contract_classifies_every_route_backed_key(): void
    {
        // The reason map is what turns a `false` into a fact rather than a
        // mystery. Every key the console builds needs exactly one class, and the
        // class must come from the shared vocabulary — otherwise a typo reads as
        // a deliberate deferral.
        $contract    = $this->contract();
        $routeBacked = (array) ( $contract['route_backed'] ?? array() );
        $reasons     = (array) ( $contract['reasons'] ?? array() );

        $classified = array_keys($reasons);

        sort($routeBacked);
        sort($classified);

        $this->assertSame(
            $routeBacked,
            $classified,
            'Every route-backed key needs exactly one reason in contracts/console-endpoints.json.'
        );

        $unknown = array_values(array_diff(array_values($reasons), EndpointCapabilities::REASON_CODES));

        $this->assertSame(
            array(),
            $unknown,
            'Unknown reason codes in the contract: '.implode(', ', $unknown)
        );
    }

    public function test_the_contract_reasons_match_the_runtime(): void
    {
        // Two copies of the classification exist by design: the JSON is read by
        // the console suite, the constant by the backend. They must not drift,
        // or a page can be told "not yet" while the backend says "missing"
        // (or the reverse).
        $expected = (array) ( $this->contract()['reasons'] ?? array() );
        $actual   = EndpointCapabilities::reasons();

        ksort($expected);
        ksort($actual);

        $this->assertSame(
            $expected,
            $actual,
            'EndpointCapabilities::REASONS and contracts/console-endpoints.json disagree.'
        );
    }

    public function test_no_route_supplied_endpoint_is_a_gap(): void
    {
        // The invariant the manifest exists for: a key this runtime is meant to
        // serve must not be silently missing. A non-empty list names either the
        // route that was dropped or the key nobody classified.
        $manifest = EndpointCapabilities::manifest();

        $this->assertSame(
            array(),
            $manifest['gaps'],
            'Unexplained endpoint gaps: '.implode(', ', $manifest['gaps'])
        );
    }

    public function test_unavailable_endpoints_are_not_classified_as_route_supplied(): void
    {
        // The complement of the gap check: nothing that is false may claim to be
        // served. Together the two assertions make `false` mean something.
        $manifest = EndpointCapabilities::manifest();

        foreach ($manifest['endpoints'] as $key => $available) {
            if ($available) {
                continue;
            }

            $this->assertNotSame(
                EndpointCapabilities::REASON_CODES[0],
                $manifest['reasons'][$key] ?? '',
                $key.' is unavailable, so it cannot be classified as route-served.'
            );
        }
    }

    public function test_the_features_endpoint_reports_the_manifest_version(): void
    {
        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('data.endpoint_contract_version', EndpointCapabilities::CONTRACT_VERSION)
            ->assertJsonPath('data.endpoint_reasons.chat', 'route')
            ->assertJsonPath('data.endpoint_reasons.mcpApps', 'route')
            ->assertJsonPath('data.endpoint_reasons.remoteSites', 'runtime-adaptable')
            ->assertJsonPath('data.endpoint_reasons.contentGraph', 'external-product')
            ->assertJsonPath('data.endpoint_gaps', array());
    }

    /**
     * The shared console endpoint contract, parsed.
     *
     * @return array<string, mixed>
     */
    private function contract(): array
    {
        $contract = json_decode(
            (string) file_get_contents(base_path('contracts/console-endpoints.json')),
            true
        );

        $this->assertIsArray($contract, 'contracts/console-endpoints.json must be valid JSON.');

        return $contract;
    }

    /**
     * @return array<string, bool>
     */
    private function endpoints(): array
    {
        /** @var array<string, bool> $endpoints */
        $endpoints = $this->getJson('/api/v1/features')->json('data.endpoints') ?? array();

        return $endpoints;
    }
}
