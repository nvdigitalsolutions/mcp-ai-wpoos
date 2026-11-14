<?php

namespace App\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Which console endpoints this backend can actually serve.
 *
 * The console builds its endpoint map from a base URL per backend
 * (`spa-v2/src/runtime-config.ts`), so a page assumes its endpoint exists. When
 * the runtime has no matching route the page does not degrade — it fires a
 * request and gets a 404.
 *
 * An empty endpoint is how the console already signals "this runtime cannot do
 * that" (it is how `toolkits`, `professions` and `orgTeams` are blanked on
 * WordPress, and how the Content Graph / Docs Hub pages degrade). This class
 * lets the backend answer the same question without the console hardcoding a
 * backend-by-backend matrix that silently rots.
 *
 * Each key mirrors `ProSpaEndpoints` in `addons/pro/assets/spa-v2/src/api/config.ts`.
 * The value is a URI fragment that must match at least one registered route;
 * matching is boundary-aware so a collection root (e.g. `api/v1/pro/schedules`)
 * counts when only sub-routes are registered, while a sibling that merely shares
 * a prefix (`api/v1/chat-transcripts` for `api/v1/chat`) does not.
 *
 * Adding a route to `routes/api.php` flips its key to `true` with no change
 * here — the map is read from the router, not maintained by hand.
 */
final class EndpointCapabilities
{
    /**
     * Console endpoint key => URI fragment that must exist.
     *
     * Asset-URL keys (`mediaStudioAsset`, `documentEditorAsset`, and their CSS
     * counterparts) are deliberately absent: they are build outputs, not
     * routes, and the console owns them.
     */
    private const ROUTES = array(
        'chat' => 'api/v1/chat',
        'chatClient' => 'api/v1/chat-client',
        'transcripts' => 'api/v1/chat-transcripts',
        'memory' => 'api/v1/chat-memory',
        'threads' => 'api/v1/threads',
        'tools' => 'api/v1/tools',
        'upload' => 'api/v1/media',
        'assistants' => 'api/v1/assistants',
        'settings' => 'api/v1/settings',
        'workflows' => 'api/v1/pro/workflows',
        'approvals' => 'api/v1/approvals',
        'triggers' => 'api/v1/orchestration/triggers',
        'teams' => 'api/v1/teams',
        'portability' => 'api/v1/assistants',
        'security' => 'api/v1/security',
        'operations' => 'api/v1/operations',
        'diagnostics' => 'api/v1/diagnostics',
        'paperStore' => 'api/v1/paper-store',
        'tokenManager' => 'api/v1/token-manager',
        'cost' => 'api/v1/cost',
        'orchestrationStats' => 'api/v1/orchestration/stats',
        'proOps' => 'api/v1/pro/ops',
        'scheduleManager' => 'api/v1/pro/schedules',
        'workflowBuilder' => 'api/v1/orchestration/workflows',
        'workflowPresets' => 'api/v1/pro/workflow-presets',
        'workflowNodes' => 'api/v1/pro/workflow-builder',
        'workflowRuns' => 'api/v1/orchestration/runs',
        'wpBase' => 'api/v1/wp',
        'recordsSchema' => 'api/v1/pro/records/schema',
        'toolkits' => 'api/v1/pro/toolkits',
        'dashboardsSchema' => 'api/v1/pro/dashboards',
        'chatChannels' => 'api/v1/pro/chat-channels',
        'remoteSites' => 'api/v1/pro/remote-sites',
        'skills' => 'api/v1/skills',
        'mcpApps' => 'api/v1/mcp-apps',
        'mediaCc' => 'api/v1/pro/media-cc',
        'professions' => 'api/v1/professions',
        'orgTeams' => 'api/v1/org/teams',
        'blueprints' => 'api/v1/blueprints',
        'commandCenter' => 'api/v1/pro/command-center',
        'shortcuts' => 'api/v1/pro/tool-shortcuts',
        'slashCommands' => 'api/v1/pro/slash-commands',
        'okf' => 'api/v1/pro/okf',
        'contentGraph' => 'nvoos-content-graph',
        'docsHub' => 'nvoos-docs',
        'mediaStudio' => 'nvoos-media-studio',
        'documentEditor' => 'nvoos-document-editor',
    );

    /**
     * Resolve every key against the registered routes.
     *
     * @return array<string, bool> Endpoint key => servable.
     */
    public static function map(): array
    {
        $uris = self::registeredUris();
        $map = array();

        foreach (self::ROUTES as $key => $fragment) {
            $map[$key] = self::hasFragment($uris, $fragment);
        }

        return $map;
    }

    /**
     * Does a registered URI serve this endpoint fragment?
     *
     * The match is boundary-aware: a fragment matches itself or any route
     * underneath it, never a sibling that merely shares a prefix. A plain
     * `str_starts_with()` check said `api/v1/chat` was served by
     * `api/v1/chat-transcripts` and `api/v1/chat-memory`, which is how the
     * `chat` key could read true while no chat route existed at all.
     *
     * Public so the rule can be unit-tested without a router.
     *
     * @param string $uri      Registered route URI, without a leading slash.
     * @param string $fragment Endpoint fragment the key maps to.
     */
    public static function matches(string $uri, string $fragment): bool
    {
        $fragment = rtrim($fragment, '/');

        if ('' === $fragment) {
            return false;
        }

        return $uri === $fragment || str_starts_with($uri, $fragment . '/');
    }

    /**
     * Every registered URI, normalised (no leading slash).
     *
     * @return string[]
     */
    private static function registeredUris(): array
    {
        $uris = array();

        /** @var Route $route */
        foreach (RouteFacade::getRoutes() as $route) {
            $uris[] = ltrim((string) $route->uri(), '/');
        }

        return $uris;
    }

    /**
     * @param string[] $uris
     */
    private static function hasFragment(array $uris, string $fragment): bool
    {
        foreach ($uris as $uri) {
            if (self::matches($uri, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
