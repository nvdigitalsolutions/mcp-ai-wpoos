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
        'tools' => 'api/v1/tools',
        'upload' => 'api/v1/media',
        'assistants' => 'api/v1/assistants',
        'settings' => 'api/v1/settings',
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
     * Contract version carried by the capability manifest.
     *
     * v1 was the bare `endpoints` boolean map. v2 adds a supply class per key
     * (`reasons`) and the derived `gaps` list. The boolean map is unchanged, so
     * a console that ignores the new fields behaves exactly as it did before.
     */
    public const CONTRACT_VERSION = 2;

    /** This runtime is meant to serve the key, and does. */
    private const ROUTE = 'route';

    /** Portable surface WordPress already has; not ported to this runtime yet. */
    private const RUNTIME_ADAPTABLE = 'runtime-adaptable';

    /** Supplied by the toolkit manifest engine, which does not exist yet. */
    private const TOOLKIT_SHAPED = 'toolkit-shaped';

    /** Needs an external messaging or media service to be configured first. */
    private const CONNECTED_SERVICE = 'connected-service';

    /** Supplied by a separate plugin or product — discover it, never reimplement it. */
    private const EXTERNAL_PRODUCT = 'external-product';

    /**
     * The reason vocabulary, in the order a reader should meet it.
     *
     * Public so the contract tests and the console can assert membership
     * without keeping a second copy of the list.
     *
     * @var string[]
     */
    public const REASON_CODES = array(
        self::ROUTE,
        self::RUNTIME_ADAPTABLE,
        self::TOOLKIT_SHAPED,
        self::CONNECTED_SERVICE,
        self::EXTERNAL_PRODUCT,
    );

    /**
     * Endpoint key => why this runtime either serves it or deliberately does not.
     *
     * Availability is runtime truth, read from the router. This is the supply
     * class, and the two together answer the question a `false` cannot answer on
     * its own: is this a regression, or is it expected? Only a `route` key is
     * meant to be served here, so only a `false` `route` key is a gap.
     *
     * Mirrored in `contracts/console-endpoints.json`; the contract test fails if
     * the two disagree. That file is what the console's Vitest suite reads, so a
     * key classified on one side only fails on both CIs.
     */
    private const REASONS = array(
        'approvals' => self::ROUTE,
        'assistants' => self::ROUTE,
        'blueprints' => self::ROUTE,
        'chat' => self::ROUTE,
        'chatChannels' => self::TOOLKIT_SHAPED,
        'chatClient' => self::ROUTE,
        'commandCenter' => self::RUNTIME_ADAPTABLE,
        'contentGraph' => self::EXTERNAL_PRODUCT,
        'cost' => self::ROUTE,
        'dashboardsSchema' => self::ROUTE,
        'diagnostics' => self::ROUTE,
        'docsHub' => self::EXTERNAL_PRODUCT,
        'documentEditor' => self::EXTERNAL_PRODUCT,
        'mediaCc' => self::CONNECTED_SERVICE,
        'mediaStudio' => self::EXTERNAL_PRODUCT,
        'mcpApps' => self::RUNTIME_ADAPTABLE,
        'memory' => self::ROUTE,
        'okf' => self::ROUTE,
        'operations' => self::ROUTE,
        'orchestrationStats' => self::ROUTE,
        'orgTeams' => self::ROUTE,
        'paperStore' => self::ROUTE,
        'portability' => self::ROUTE,
        'proOps' => self::ROUTE,
        'professions' => self::ROUTE,
        'recordsSchema' => self::ROUTE,
        'remoteSites' => self::RUNTIME_ADAPTABLE,
        'scheduleManager' => self::ROUTE,
        'security' => self::ROUTE,
        'settings' => self::ROUTE,
        'shortcuts' => self::RUNTIME_ADAPTABLE,
        'skills' => self::ROUTE,
        'slashCommands' => self::RUNTIME_ADAPTABLE,
        'teams' => self::ROUTE,
        'tokenManager' => self::ROUTE,
        'toolkits' => self::ROUTE,
        'tools' => self::ROUTE,
        'transcripts' => self::ROUTE,
        'triggers' => self::ROUTE,
        'upload' => self::ROUTE,
        'workflowBuilder' => self::ROUTE,
        'workflowNodes' => self::ROUTE,
        'workflowPresets' => self::ROUTE,
        'workflowRuns' => self::ROUTE,
        'wpBase' => self::ROUTE,
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
     * The capability manifest (090-i phase 1).
     *
     * `endpoints` is runtime truth, `reasons` the supply class, and `gaps` the
     * derived answer to "what is missing that should not be". An empty `gaps`
     * list is the invariant: every unavailable key is unavailable on purpose.
     *
     * @return array{version: int, endpoints: array<string, bool>, reasons: array<string, string>, gaps: string[]}
     */
    public static function manifest(): array
    {
        $endpoints = self::map();

        return array(
            'version' => self::CONTRACT_VERSION,
            'endpoints' => $endpoints,
            'reasons' => self::REASONS,
            'gaps' => self::gaps($endpoints),
        );
    }

    /**
     * Supply class per endpoint key.
     *
     * @return array<string, string>
     */
    public static function reasons(): array
    {
        return self::REASONS;
    }

    /**
     * Keys this runtime is meant to serve but currently does not.
     *
     * An unclassified key counts as a gap: if the manifest cannot say a key is
     * deliberate, it has to say it is missing.
     *
     * @param array<string, bool>|null $endpoints Pre-computed map, to avoid a
     *                                            second router walk.
     *
     * @return string[]
     */
    public static function gaps(?array $endpoints = null): array
    {
        $endpoints = $endpoints ?? self::map();
        $gaps = array();

        foreach ($endpoints as $key => $available) {
            if ($available) {
                continue;
            }

            if (self::ROUTE === (self::REASONS[$key] ?? self::ROUTE)) {
                $gaps[] = $key;
            }
        }

        return $gaps;
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
