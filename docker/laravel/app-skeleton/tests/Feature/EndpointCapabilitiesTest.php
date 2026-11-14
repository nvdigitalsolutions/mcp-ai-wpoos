<?php

namespace Tests\Feature;

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
            'wpBase',
            'recordsSchema',
            'toolkits',
            'professions',
            'orgTeams',
            'blueprints',
        ] as $key) {
            $this->assertTrue($endpoints[$key] ?? false, $key.' should be available.');
        }
    }

    public function test_routes_this_runtime_does_not_serve_are_reported_unavailable(): void
    {
        $endpoints = $this->endpoints();

        foreach ([
            // Orchestration engine — not ported. The overview route exists
            // (`orchestrationStats`), the builder and runs do not.
            'workflows',
            'workflowBuilder',
            'workflowPresets',
            'workflowNodes',
            'workflowRuns',
            // Surfaces whose stores exist but whose routes do not.
            'tokenManager',
            'threads',
            'dashboardsSchema',
            // Deliberately deferred inside the assistant editor.
            'skills',
            'mcpApps',
            // WordPress-only concepts.
            'chatChannels',
            'remoteSites',
            'mediaCc',
            'commandCenter',
            'shortcuts',
            'slashCommands',
            'okf',
            'contentGraph',
            'docsHub',
            'mediaStudio',
            'documentEditor',
        ] as $key) {
            $this->assertFalse($endpoints[$key] ?? true, $key.' should be unavailable.');
        }
    }

    public function test_the_map_covers_the_console_contract_exactly(): void
    {
        // The shared contract, asserted from the console's Vitest suite too —
        // drift fails on both CIs instead of on a user's browser. Add a key
        // there first, then wire it here.
        $contract = json_decode(
            (string) file_get_contents(base_path('contracts/console-endpoints.json')),
            true
        );

        $this->assertIsArray($contract, 'contracts/console-endpoints.json must be valid JSON.');

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
