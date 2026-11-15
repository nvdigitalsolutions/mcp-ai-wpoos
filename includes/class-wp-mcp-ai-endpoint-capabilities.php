<?php
/**
 * Console endpoint capabilities for the WordPress runtime.
 *
 * The Complete Console builds one endpoint map for two backends (Proposal
 * 090-B, ADR-6). The Laravel runtime answers that question from its router
 * (`EndpointCapabilities`, `GET /api/v1/features`); until this class existed,
 * WordPress answered it only from a hardcoded client-side matrix in
 * `examples/nvoos-complete-velocity/src/runtime-config.ts`, which silently
 * rots whenever a route moves.
 *
 * This class is the WordPress twin of the Laravel support class:
 *
 * - `ROUTES` maps every console key to a URI fragment that must be registered.
 * - `REASONS` classifies each key, so a `false` reads as expected (external
 *   product, toolkit family, config-gated) or as a regression.
 * - `manifest()` returns the capability document served by
 *   `GET /mcp-ai/v1/features` — `endpoints`, `reasons` and the derived `gaps`.
 *
 * Availability is runtime truth: the map is read from the live REST server,
 * so registering a route flips its key with no change here. The shared
 * contract (`docker/laravel/app-skeleton/contracts/console-endpoints.json`)
 * is the fixture both runtimes and the console assert against; the PHPUnit
 * suite fails if this class and that file disagree.
 *
 * @package WP_MCP_AI
 * @since   1.2.22
 * @license GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Console endpoint capability manifest for WordPress.
 *
 * @since 1.2.22
 */
class WP_MCP_AI_Endpoint_Capabilities {

	/**
	 * Contract version carried by the manifest.
	 *
	 * Version 1 was the bare `endpoints` boolean map. Version 2 adds the supply
	 * class per key (`reasons`) and the derived `gaps` list. The boolean map is
	 * unchanged, so a console that ignores the new fields behaves exactly as it
	 * did before.
	 *
	 * @var int
	 */
	const CONTRACT_VERSION = 2;

	/** This runtime is meant to serve the key, and does. */
	const ROUTE = 'route';

	/** Portable surface this runtime has in admin, but not yet as a route. */
	const RUNTIME_ADAPTABLE = 'runtime-adaptable';

	/** Supplied by an addon that is not active on this install. */
	const NOT_INSTALLED = 'not-installed';

	/** Supplied by a toolkit family that does not exist yet. */
	const TOOLKIT_SHAPED = 'toolkit-shaped';

	/** Needs an external service to be configured first. */
	const CONNECTED_SERVICE = 'connected-service';

	/** Supplied by a separate plugin — discover it, never reimplement it. */
	const EXTERNAL_PRODUCT = 'external-product';

	/**
	 * The reason vocabulary, in the order a reader should meet it.
	 *
	 * Public so the contract tests and the console can assert membership
	 * without keeping a second copy of the list.
	 *
	 * @var string[]
	 */
	const REASON_CODES = array(
		self::ROUTE,
		self::RUNTIME_ADAPTABLE,
		self::NOT_INSTALLED,
		self::TOOLKIT_SHAPED,
		self::CONNECTED_SERVICE,
		self::EXTERNAL_PRODUCT,
	);

	/**
	 * Console endpoint key => URI fragment that must be registered.
	 *
	 * Mirrors `ProSpaEndpoints` in
	 * `addons/pro/assets/spa-v2/src/api/config.ts` and the Laravel
	 * `EndpointCapabilities::ROUTES`. Asset-URL keys (`mediaStudioAsset`,
	 * `documentEditorAsset`, and their CSS counterparts) are deliberately
	 * absent: they are build outputs, not routes, and the console owns them.
	 *
	 * @var array<string, string>
	 */
	const ROUTES = array(
		'chat'               => 'mcp-ai/v1/chat',
		'chatClient'         => 'mcp-ai/v1/chat-client',
		'transcripts'        => 'mcp-ai/v1/chat-transcripts',
		'memory'             => 'mcp-ai/v1/chat-memory',
		'tools'              => 'mcp-ai/v1/tools',
		'upload'             => 'wp/v2/media',
		'assistants'         => 'mcp-ai/v1/assistants',
		'settings'           => 'mcp-ai/v1/settings',
		'cost'               => 'mcp-ai/v1/cost',
		'approvals'          => 'mcp-ai/v1/approvals',
		'triggers'           => 'mcp-ai/v1/orchestration/triggers',
		'teams'              => 'mcp-ai/v1/teams',
		'portability'        => 'mcp-ai/v1/assistants',
		'security'           => 'mcp-ai/v1/security',
		'operations'         => 'mcp-ai/v1/operations',
		'diagnostics'        => 'mcp-ai/v1/diagnostics',
		'paperStore'         => 'mcp-ai/v1/paper-store',
		'tokenManager'       => 'mcp-ai/v1/token-manager',
		'orchestrationStats' => 'mcp-ai/v1/orchestration/stats',
		'proOps'             => 'mcp-ai-pro/v1/ops',
		'scheduleManager'    => 'mcp-ai-pro/v1/schedules',
		'workflowBuilder'    => 'mcp-ai/v1/orchestration/workflows',
		'workflowPresets'    => 'mcp-ai-pro/v1/workflow-presets',
		'workflowNodes'      => 'mcp-ai-pro/v1/workflow-builder',
		'workflowRuns'       => 'mcp-ai/v1/orchestration/runs',
		'wpBase'             => 'wp/v2',
		'recordsSchema'      => 'mcp-ai-pro/v1/records/schema',
		'toolkits'           => 'mcp-ai-pro/v1/toolkit-manifests',
		'dashboardsSchema'   => 'mcp-ai-pro/v1/dashboards',
		'chatChannels'       => 'mcp-ai-pro/v1/chat-channels',
		'remoteSites'        => 'mcp-ai-pro/v1/remote-sites',
		'skills'             => 'mcp-ai/v1/skills',
		'mcpApps'            => 'mcp-ai/v1/mcp-apps',
		'mediaCc'            => 'mcp-ai-pro/v1/media-cc',
		'professions'        => 'mcp-ai/v1/professions',
		'orgTeams'           => 'mcp-ai/v1/org/teams',
		'blueprints'         => 'mcp-ai-pro/v1/blueprints',
		'commandCenter'      => 'mcp-ai-pro/v1/command-center',
		'shortcuts'          => 'mcp-ai-pro/v1/tool-shortcuts',
		'slashCommands'      => 'mcp-ai-pro/v1/slash-commands',
		'okf'                => 'mcp-ai-pro/v1/okf',
		'contentGraph'       => 'nvoos-content-graph',
		'docsHub'            => 'nvoos-docs',
		'mediaStudio'        => 'nvoos-media-studio',
		'documentEditor'     => 'nvoos-document-editor',
	);

	/**
	 * Endpoint key => supply class on the WordPress runtime.
	 *
	 * Starts from the shared contract's Laravel classification and applies the
	 * WordPress overrides:
	 *
	 * - `mediaCc` is `route` here: the WordPress health snapshot is computed
	 *   locally (attachments, files, alt text, compression stats, queue), so it
	 *   does not need a media worker the way the Laravel runtime does.
	 * - `professions` / `orgTeams` are `runtime-adaptable`: WordPress owns the
	 *   data (profession catalogue, users/roles) but exposes no REST route yet,
	 *   so the console hides the surfaces without calling it a regression.
	 * - `chatChannels` stays `toolkit-shaped`: the admin page points at
	 *   `mcp-ai-pro/v1/chat-channels`, but no controller registers that route.
	 *   That is the deferred communications-toolkit family, not a broken route.
	 *
	 * `not-installed` is applied at runtime for Pro-supplied keys when the Pro
	 * addon is inactive, so a Base install reports a reason instead of a gap.
	 *
	 * @var array<string, string>
	 */
	const REASONS = array(
		'approvals'          => self::ROUTE,
		'assistants'         => self::ROUTE,
		'blueprints'         => self::ROUTE,
		'chat'               => self::ROUTE,
		'chatChannels'       => self::TOOLKIT_SHAPED,
		'chatClient'         => self::ROUTE,
		'commandCenter'      => self::ROUTE,
		'contentGraph'       => self::EXTERNAL_PRODUCT,
		'cost'               => self::ROUTE,
		'dashboardsSchema'   => self::ROUTE,
		'diagnostics'        => self::ROUTE,
		'docsHub'            => self::EXTERNAL_PRODUCT,
		'documentEditor'     => self::EXTERNAL_PRODUCT,
		'mediaCc'            => self::ROUTE,
		'mediaStudio'        => self::EXTERNAL_PRODUCT,
		'mcpApps'            => self::ROUTE,
		'memory'             => self::ROUTE,
		'okf'                => self::ROUTE,
		'operations'         => self::ROUTE,
		'orchestrationStats' => self::ROUTE,
		'orgTeams'           => self::RUNTIME_ADAPTABLE,
		'paperStore'         => self::ROUTE,
		'portability'        => self::ROUTE,
		'proOps'             => self::ROUTE,
		'professions'        => self::RUNTIME_ADAPTABLE,
		'recordsSchema'      => self::ROUTE,
		'remoteSites'        => self::ROUTE,
		'scheduleManager'    => self::ROUTE,
		'security'           => self::ROUTE,
		'settings'           => self::ROUTE,
		'shortcuts'          => self::ROUTE,
		'skills'             => self::ROUTE,
		'slashCommands'      => self::ROUTE,
		'teams'              => self::ROUTE,
		'tokenManager'       => self::ROUTE,
		'toolkits'           => self::ROUTE,
		'tools'              => self::ROUTE,
		'transcripts'        => self::ROUTE,
		'triggers'           => self::ROUTE,
		'upload'             => self::ROUTE,
		'workflowBuilder'    => self::ROUTE,
		'workflowNodes'      => self::ROUTE,
		'workflowPresets'    => self::ROUTE,
		'workflowRuns'       => self::ROUTE,
		'wpBase'             => self::ROUTE,
	);

	/**
	 * Keys whose route is registered by the Pro addon.
	 *
	 * When Pro is inactive the route cannot exist, so the key reports
	 * `not-installed` rather than becoming a gap. Keys classified as anything
	 * other than `route` keep their classification (they are not gaps either
	 * way).
	 *
	 * @var string[]
	 */
	const PRO_ONLY = array(
		'blueprints',
		'commandCenter',
		'dashboardsSchema',
		'mcpApps',
		'mediaCc',
		'okf',
		'proOps',
		'recordsSchema',
		'remoteSites',
		'scheduleManager',
		'toolkits',
		'workflowNodes',
		'workflowPresets',
	);

	/**
	 * The capability manifest served by `GET /mcp-ai/v1/features`.
	 *
	 * `endpoints` is runtime truth, `reasons` the supply class, and `gaps` the
	 * derived answer to "what is missing that should not be". An empty `gaps`
	 * list is the invariant: every unavailable key is unavailable on purpose.
	 *
	 * @return array{version: int, endpoints: array<string, bool>, reasons: array<string, string>, gaps: string[]}
	 */
	public static function manifest() {
		$endpoints = self::map();

		return array(
			'version'   => self::CONTRACT_VERSION,
			'endpoints' => $endpoints,
			'reasons'   => self::reasons(),
			'gaps'      => self::gaps( $endpoints ),
		);
	}

	/**
	 * Resolve every key against the registered REST routes.
	 *
	 * @return array<string, bool> Endpoint key => servable.
	 */
	public static function map() {
		$uris = self::registered_uris();
		$map  = array();

		foreach ( self::ROUTES as $key => $fragment ) {
			$map[ $key ] = self::has_fragment( $uris, $fragment );
		}

		return $map;
	}

	/**
	 * Supply class per endpoint key.
	 *
	 * @return array<string, string>
	 */
	public static function reasons() {
		$reasons = self::REASONS;

		if ( defined( 'WP_MCP_AI_PRO_FILE' ) ) {
			return $reasons;
		}

		foreach ( self::PRO_ONLY as $key ) {
			if ( self::ROUTE === ( $reasons[ $key ] ?? self::ROUTE ) ) {
				$reasons[ $key ] = self::NOT_INSTALLED;
			}
		}

		return $reasons;
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
	public static function gaps( $endpoints = null ) {
		$endpoints = null === $endpoints ? self::map() : $endpoints;
		$reasons   = self::reasons();
		$gaps      = array();

		foreach ( $endpoints as $key => $available ) {
			if ( $available ) {
				continue;
			}

			if ( self::ROUTE === ( $reasons[ $key ] ?? self::ROUTE ) ) {
				$gaps[] = $key;
			}
		}

		return $gaps;
	}

	/**
	 * Does a registered route satisfy this endpoint fragment?
	 *
	 * WordPress stores route patterns as regexes (e.g.
	 * `/mcp-ai-pro/v1/remote-sites/(?P<id>[\w-]+)`), so the comparison uses
	 * the literal head of the pattern. The match is boundary-aware: a fragment
	 * matches itself or any route underneath it, never a sibling that merely
	 * shares a prefix (`mcp-ai/v1/chat` must not be satisfied by
	 * `mcp-ai/v1/chat-transcripts`).
	 *
	 * Public so the rule can be unit-tested without a router.
	 *
	 * @param string $route    Registered route pattern, with or without a leading slash.
	 * @param string $fragment Endpoint fragment the key maps to.
	 * @return bool
	 */
	public static function matches( $route, $fragment ) {
		$fragment = rtrim( $fragment, '/' );
		$head     = self::literal_head( ltrim( $route, '/' ) );

		if ( '' === $fragment || '' === $head ) {
			return false;
		}

		return $head === $fragment || str_starts_with( $head, $fragment . '/' );
	}

	/**
	 * The literal (non-regex) head of a registered route pattern.
	 *
	 * @param string $route Route pattern without a leading slash.
	 * @return string
	 */
	private static function literal_head( $route ) {
		// The first regex metacharacter ends the literal part of the route.
		$length = strcspn( $route, '([\\?*+{^$|' );

		return rtrim( substr( $route, 0, $length ), '/' );
	}

	/**
	 * Every registered REST route, without a leading slash.
	 *
	 * @return string[]
	 */
	private static function registered_uris() {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return array();
		}

		$routes = rest_get_server()->get_routes();
		$uris   = array();

		foreach ( array_keys( $routes ) as $route ) {
			$uris[] = ltrim( (string) $route, '/' );
		}

		return $uris;
	}

	/**
	 * Does any registered route satisfy the fragment?
	 *
	 * @param string[] $uris     Registered routes without a leading slash.
	 * @param string   $fragment Endpoint fragment.
	 * @return bool
	 */
	private static function has_fragment( array $uris, $fragment ) {
		foreach ( $uris as $uri ) {
			if ( self::matches( $uri, $fragment ) ) {
				return true;
			}
		}

		return false;
	}
}
