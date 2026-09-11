<?php
/**
 * Markup subsystem bootstrap (Wave E6, sub-cluster 2).
 *
 * Aligned port of the base plugin's `includes/markup-init.php`: the
 * byte-identical hook surface — interceptor + telemetry registration
 * (`plugins_loaded`, priority 20), the REST route registration
 * (`rest_api_init`), the asset registration (`init`, priority 5), the
 * chat-shim auto-enqueue (`wp_enqueue_scripts`, priority 20, base chat
 * bundle probe), the admin fallback + telemetry pages (`init`,
 * priority 30, is_admin), the daily `wp_mcp_ai_markup_cleanup` cron
 * with its store-sweep handler, the `markup_*` recent-activity
 * allowlist extension, and the `/markup-stats` slash-command
 * registration on `wp_mcp_ai_default_slash_commands_loaded`.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - The base's inline `require_once` block disappears (PSR-4
 *    autoloading) and the base's file-level hook wiring becomes a
 *    static `register()` — wired standalone-only via
 *    `Plugin::registerEngine()`; the base init file owns the same
 *    hooks monolith (double registration would double-count telemetry
 *    and double-register the REST routes).
 *  - The slash command registered is this addon's
 *    `MarkupStatsSlashCommand` (the base command class does not exist
 *    standalone; the platform addon's dormant E2 blanket-port copy
 *    stays unwired).
 *  - The base's `defined( 'WP_MCP_AI_REST::REST_NAMESPACE' )` probe is
 *    a constant-form quirk whose both branches resolve to the same
 *    handle — collapsed to the literal (byte-identical behavior).
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Markup
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Markup;

/**
 * Wires the markup subsystem hooks (standalone-only).
 *
 * @since 1.1.0
 */
final class MarkupBootstrap {

	/**
	 * Wiring state.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register the markup subsystem hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// Interceptor + telemetry — registered immediately so the interceptor
		// can short-circuit any tool execution that requests markup.
		\add_action(
			'plugins_loaded',
			static function (): void {
				$interceptor = new MarkupLoopInterceptor();
				$interceptor->register();

				$telemetry = new MarkupTelemetry();
				$telemetry->register();
			},
			20
		);

		// REST routes.
		\add_action(
			'rest_api_init',
			static function (): void {
				$controller = new MarkupRestController();
				$controller->register_routes();
			}
		);

		// Register markup assets early so chat surfaces and the admin
		// fallback page can enqueue them on demand.
		\add_action(
			'init',
			static function (): void {
				if ( \function_exists( 'wp_register_script' ) ) {
					MarkupAssets::register();
				}
			},
			5
		);

		// Auto-enqueue the chat client integration shim alongside the main
		// chat bundle so SSE `markup_elicitation` events render the canvas
		// widget without modifications to the chat bundle.
		\add_action(
			'wp_enqueue_scripts',
			static function (): void {
				if ( ! MarkupLoopInterceptor::is_enabled() ) {
					return;
				}
				// Byte-identical to the base's constant probe: both branches
				// resolve to the base chat bundle handle.
				$chat_handle = 'wp-mcp-ai-chat';
				if ( \wp_script_is( $chat_handle, 'enqueued' ) ||
					\wp_script_is( $chat_handle, 'registered' ) ) {
					MarkupAssets::enqueue_widget();
				}
			},
			20
		);

		// Mount the admin fallback page (used by URL-mode elicitation) and
		// the read-only markup telemetry dashboard.
		\add_action(
			'init',
			static function (): void {
				if ( ! \is_admin() ) {
					return;
				}
				$page = new MarkupAdminPage();
				$page->register();

				$telemetry_page = new MarkupTelemetryAdminPage();
				$telemetry_page->register();
			},
			30
		);

		// Daily cleanup of expired markup transients.
		\add_action(
			'init',
			static function (): void {
				if ( ! \wp_next_scheduled( 'wp_mcp_ai_markup_cleanup' ) ) {
					\wp_schedule_event( \time() + HOUR_IN_SECONDS, 'daily', 'wp_mcp_ai_markup_cleanup' );
				}
			}
		);

		\add_action(
			'wp_mcp_ai_markup_cleanup',
			static function (): void {
				$store = new MarkupStore();
				$store->cleanup_expired();
			}
		);

		// Allow the markup_* event types produced by MarkupTelemetry to flow
		// into the recent-activity feed when logging is enabled.
		\add_filter(
			'wp_mcp_ai_recent_activity_types',
			static function ( $types ) {
				if ( ! \is_array( $types ) ) {
					$types = array();
				}
				foreach ( MarkupTelemetry::outcomes() as $outcome ) {
					$types[] = 'markup_' . $outcome;
				}
				return \array_values( \array_unique( $types ) );
			}
		);

		// Register the `/markup-stats` slash command once the default command
		// set has been loaded.
		\add_action(
			'wp_mcp_ai_default_slash_commands_loaded',
			static function ( $handler ): void {
				if ( ! \is_object( $handler ) || ! \method_exists( $handler, 'register' ) ) {
					return;
				}

				$command = new MarkupStatsSlashCommand();
				$handler->register(
					'markup-stats',
					array(
						'handler'     => array( $command, 'execute' ),
						'description' => __( 'Show markup subsystem telemetry — totals, per-tool / per-mode breakdowns, and last-seen timestamps.', 'nvoos-content-graph-ai' ),
						'usage'       => '/markup-stats [--verbose|-v] [--json] [--reset]',
						'capability'  => 'edit_posts',
						'aliases'     => array( 'markup' ),
						'parameters'  => array(
							'--verbose' => array(
								'description' => __( 'Show every per-tool / per-mode row instead of the top 5.', 'nvoos-content-graph-ai' ),
								'required'    => false,
							),
							'--json'    => array(
								'description' => __( 'Return the raw summary as JSON for programmatic consumption.', 'nvoos-content-graph-ai' ),
								'required'    => false,
							),
							'--reset'   => array(
								'description' => __( 'Reset the telemetry counters (requires manage_options).', 'nvoos-content-graph-ai' ),
								'required'    => false,
							),
						),
					)
				);
			}
		);
	}
}
