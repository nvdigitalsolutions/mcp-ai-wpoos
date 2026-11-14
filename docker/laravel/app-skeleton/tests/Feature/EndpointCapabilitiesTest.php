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
            // Orchestration engine — not ported.
            'workflows',
            'workflowBuilder',
            'workflowPresets',
            'workflowNodes',
            'workflowRuns',
            'orchestrationStats',
            // Surfaces whose stores exist but whose routes do not.
            'tokenManager',
            'threads',
            'analytics',
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
        // Mirrors ProSpaEndpoints in spa-v2/src/api/config.ts, minus the four
        // island asset URLs, which are build outputs rather than routes.
        $expected = [
            'analytics', 'approvals', 'assistants', 'blueprints', 'chat',
            'chatChannels', 'chatClient', 'commandCenter', 'contentGraph',
            'cost', 'dashboardsSchema', 'diagnostics', 'docsHub',
            'documentEditor', 'mediaCc', 'mediaStudio', 'mcpApps', 'memory',
            'okf', 'operations', 'orchestrationStats', 'orgTeams',
            'paperStore', 'portability', 'proOps', 'professions',
            'recordsSchema', 'remoteSites', 'scheduleManager', 'security',
            'settings', 'shortcuts', 'skills', 'slashCommands',
            'teams', 'threads', 'tokenManager', 'toolkits', 'tools',
            'transcripts', 'triggers', 'upload', 'workflows',
            'workflowBuilder', 'workflowNodes', 'workflowPresets',
            'workflowRuns', 'wpBase',
        ];

        $actual = array_keys($this->endpoints());

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
