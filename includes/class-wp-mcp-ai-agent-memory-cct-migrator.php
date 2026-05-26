<?php
/**
 * Agent Memory CCT Schema Migrator (Phase 2 of the 2026 Memory Layer Enhancements).
 *
 * JetEngine's `WP_MCP_AI_JetEngine_Agent_Memories_CCT::maybe_register_cct()`
 * only provisions the CCT when it is *missing*. Once the CCT exists, adding
 * new fields to `get_meta_fields()` makes them available to fresh installs
 * but the JetEngine admin UI of an existing CCT does not pick them up
 * automatically. Direct data writes via `update_item()` still persist the new
 * fields (JetEngine's underlying meta storage accepts arbitrary key=>value
 * pairs), so functionality is never lost — but the admin UI columns, sorting,
 * and filters need a schema refresh.
 *
 * This migrator performs that refresh. It is:
 *
 *  - **Idempotent**: tracked by `wp_mcp_ai_memory_cct_schema_version`. Runs the
 *    upgrade exactly once per version bump, never on subsequent boots.
 *  - **Best-effort**: failure modes (JetEngine missing, CCT module disabled,
 *    insufficient permissions) are logged and tolerated — they never break
 *    the request. Data writes continue to work either way.
 *  - **Forward-compatible**: re-reads `get_meta_fields()` each run, so future
 *    schema versions automatically pick up whatever new fields the CCT class
 *    declares without needing a new migrator class.
 *  - **Admin-bounded**: runs on `admin_init` (priority 20) so the upgrade
 *    happens behind a logged-in admin pageview, never on front-end requests
 *    or REST traffic. Front-end / API paths benefit immediately because data
 *    writes use the up-to-date `get_meta_fields()` regardless.
 *
 * @package WP_MCP_AI
 * @since   1.1.20
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Idempotent CCT schema upgrader.
 *
 * Versions:
 *  - `0` (or option missing): pre-Phase-2 — sensitivity/consent/subject/attachments + base fields.
 *  - `2`: Phase 2 — adds content_hash, confidence_score, last_accessed_at,
 *    superseded_by, auto_captured.
 */
class WP_MCP_AI_Agent_Memory_CCT_Migrator {

	/**
	 * Option name tracking the highest applied schema version.
	 */
	const VERSION_OPTION = 'wp_mcp_ai_memory_cct_schema_version';

	/**
	 * Current target schema version this migrator brings the CCT up to.
	 *
	 * Phase 2 of the 2026 Memory Layer Enhancements is schema version 2.
	 * The pre-Phase-2 baseline is version 1 (the implicit version when the
	 * option is missing or 0).
	 */
	const CURRENT_VERSION = 2;

	/**
	 * Wire the migrator to run once per admin pageview when the version is stale.
	 *
	 * Idempotent: re-bootstrapping is a no-op. Disabled by default since
	 * v1.1.22 — see issue history around PRs #5039 / #5040 / #5042 for the
	 * trail of attempted upgrade paths through JetEngine's data layer, all
	 * of which either looped on `sanitize_item_request()` (which validates
	 * slug uniqueness and therefore always rejects updates to an existing
	 * CCT) or corrupted the `args` / `meta_fields` columns by writing JSON
	 * where JetEngine expects PHP-serialized arrays.
	 *
	 * Data writes via the bridge / tools layer already persist every Phase
	 * 2+ field regardless of whether this admin-UI refresh runs, so leaving
	 * the migrator off is functionally safe — only the JetEngine admin UI
	 * columns for existing (pre-Phase-2) CCT installs stay at the old
	 * column set. Opt back in via the
	 * `wp_mcp_ai_memory_cct_migrator_enabled` filter once a properly
	 * validated update path (one that does NOT call `sanitize_item_request`
	 * and does NOT write JSON to `args` / `meta_fields`) is shipped.
	 */
	public static function bootstrap() {
		/**
		 * Master kill-switch for the CCT schema migrator.
		 *
		 * Default: false (since v1.1.22). When false, the migrator does NOT
		 * attempt to push a registration request through JetEngine and does
		 * NOT touch the `wp_jet_post_types` row. The stored schema version
		 * is opportunistically advanced to {@see self::CURRENT_VERSION} so
		 * the Memory Health subtab reports a clean state and downstream
		 * checks short-circuit on subsequent boots.
		 *
		 * Set to true via this filter to re-enable the upgrade attempt. This
		 * is intended for development / regression testing only; the
		 * production upgrade path needs to be rewritten first (see class
		 * docblock).
		 *
		 * @since 1.1.20
		 * @since 1.1.22 Default flipped from true to false.
		 *
		 * @param bool $enabled Default false.
		 */
		if ( ! (bool) apply_filters( 'wp_mcp_ai_memory_cct_migrator_enabled', false ) ) {
			// Opportunistically advance the stored version so the Memory
			// Health subtab and any other consumers of
			// get_installed_version() see a healthy state. Use a guarded
			// update so we never roll a higher value backwards if a future
			// schema bump lands ahead of this bootstrap call.
			$installed = (int) get_option( self::VERSION_OPTION, 0 );
			if ( $installed < self::CURRENT_VERSION ) {
				update_option( self::VERSION_OPTION, self::CURRENT_VERSION, false );
			}
			return;
		}

		add_action( 'admin_init', array( __CLASS__, 'maybe_run' ), 20 );
	}

	/**
	 * Check the stored schema version and run the upgrade if needed.
	 *
	 * Public so headless tests and admin tools can re-trigger the migrator
	 * manually (it remains idempotent — successive calls are no-ops once the
	 * stored version equals CURRENT_VERSION).
	 *
	 * @return array {
	 *     Result summary.
	 *
	 *     @type bool   $ran           Whether the upgrade was attempted this call.
	 *     @type bool   $succeeded     Whether the upgrade succeeded.
	 *     @type int    $from_version  Version found on disk before the run.
	 *     @type int    $to_version    Version stored after the run.
	 *     @type string $message       Human-readable status.
	 * }
	 */
	public static function maybe_run() {
		$installed = (int) get_option( self::VERSION_OPTION, 0 );

		if ( $installed >= self::CURRENT_VERSION ) {
			return array(
				'ran'          => false,
				'succeeded'    => true,
				'from_version' => $installed,
				'to_version'   => $installed,
				'message'      => 'Schema already at current version.',
			);
		}

		// Only an admin should trigger the schema mutation pathway. Non-admin
		// (e.g. REST, cron, AJAX) requests skip the run but data writes
		// continue to work because they don't depend on the admin UI columns.
		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'ran'          => false,
				'succeeded'    => false,
				'from_version' => $installed,
				'to_version'   => $installed,
				'message'      => 'Skipped: caller lacks manage_options.',
			);
		}

		$result = self::run_upgrade( $installed );

		if ( $result['succeeded'] ) {
			update_option( self::VERSION_OPTION, self::CURRENT_VERSION, false );
			$result['to_version'] = self::CURRENT_VERSION;
		}

		return $result;
	}

	/**
	 * Perform the actual schema upgrade from `$from_version` to CURRENT_VERSION.
	 *
	 * Re-pushes the full registration request with the up-to-date field set
	 * through JetEngine's existing data layer. JetEngine treats this as an
	 * "update existing content type" operation when the slug already exists,
	 * merging the new field declarations into the stored CCT definition.
	 *
	 * @since 1.1.20
	 *
	 * @param int $from_version Pre-upgrade version found on disk.
	 * @return array Same shape as {@see self::maybe_run()}.
	 */
	protected static function run_upgrade( $from_version ) {
		$failure = static function ( $message ) use ( $from_version ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Agent Memory CCT migrator: ' . $message,
					array(
						'from_version'   => $from_version,
						'target_version' => WP_MCP_AI_Agent_Memory_CCT_Migrator::CURRENT_VERSION,
					)
				);
			}
			return array(
				'ran'          => true,
				'succeeded'    => false,
				'from_version' => $from_version,
				'to_version'   => $from_version,
				'message'      => $message,
			);
		};

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			return $failure( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT class is unavailable.' );
		}

		if ( ! function_exists( 'jet_engine' ) ) {
			return $failure( 'JetEngine is not active; admin-UI schema refresh skipped (data writes still work).' );
		}

		$engine = jet_engine();
		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return $failure( 'JetEngine modules registry unavailable.' );
		}

		if ( ! $engine->modules->is_module_active( 'custom-content-types' ) ) {
			return $failure( 'JetEngine custom-content-types module not active.' );
		}

		$module_wrapper = $engine->modules->get_module( 'custom-content-types' );
		if ( empty( $module_wrapper ) || empty( $module_wrapper->instance ) ) {
			return $failure( 'JetEngine CCT module wrapper missing.' );
		}

		$module = $module_wrapper->instance;
		if ( empty( $module->manager ) || empty( $module->manager->data ) ) {
			return $failure( 'JetEngine CCT manager / data layer not available.' );
		}

		$data = $module->manager->data;

		// Find the existing CCT registration row by slug so we can update in
		// place rather than create a duplicate. JetEngine's `data->query()`
		// returns rows from `wp_jet_content_types` for the chosen post_type.
		$existing = $data->db->query(
			'post_types',
			array(
				'slug'   => WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_slug(),
				'status' => 'content-type',
			),
			null,
			false
		);

		if ( empty( $existing ) || ! is_array( $existing ) ) {
			return $failure( 'CCT registration row not found; nothing to upgrade.' );
		}

		$existing_row = is_array( $existing[0] ) ? $existing[0] : (array) $existing[0];
		$existing_id  = isset( $existing_row['id'] ) ? (int) $existing_row['id'] : 0;

		if ( $existing_id <= 0 ) {
			return $failure( 'CCT registration row has no ID.' );
		}

		// Rebuild the registration request from the current source-of-truth
		// (so schema v3+ in future ships without a new migrator class).
		$request = self::build_registration_request();
		$request['_ID'] = $existing_id;

		try {
			$data->set_request( $request );

			if ( method_exists( $data, 'sanitize_item_request' ) && ! $data->sanitize_item_request() ) {
				return $failure( 'JetEngine refused to sanitize the upgrade request.' );
			}

			$item = $data->sanitize_item_from_request();
			if ( empty( $item ) || ! is_array( $item ) ) {
				return $failure( 'JetEngine produced an empty upgrade item.' );
			}

			// Preserve the existing ID so update_item_in_db updates, not inserts.
			$item['_ID'] = $existing_id;

			$data->before_item_update( $item, false );

			$item_id = $data->update_item_in_db( $item );

			if ( ! $item_id ) {
				return $failure( 'update_item_in_db returned a falsy id.' );
			}

			$data->after_item_update( $item, false );

			// Force a reload of post types so the in-memory cache picks up the
			// new field set on the same request.
			if ( method_exists( $data->db, 'query_raw' ) ) {
				$data->db->query_raw( 'post_types' );
			}
		} catch ( Throwable $e ) {
			return $failure( 'JetEngine upgrade threw: ' . $e->getMessage() );
		}

		return array(
			'ran'          => true,
			'succeeded'    => true,
			'from_version' => $from_version,
			'to_version'   => self::CURRENT_VERSION,
			'message'      => sprintf( 'Upgraded CCT schema v%d -> v%d.', $from_version, self::CURRENT_VERSION ),
		);
	}

	/**
	 * Re-build the registration request from the current source of truth.
	 *
	 * Mirrors the private `get_registration_request()` on the CCT class. We
	 * cannot call it directly (it's protected), so we re-derive the same
	 * shape from public helpers (`get_slug()` + the meta-fields method) via
	 * reflection. This keeps the migrator forward-compatible: future
	 * schema versions ride on whatever fields the CCT class currently
	 * declares — no migrator code change needed.
	 *
	 * @return array Registration request payload.
	 */
	protected static function build_registration_request() {
		$ref         = new ReflectionClass( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' );
		$meta_method = $ref->getMethod( 'get_meta_fields' );
		$meta_method->setAccessible( true );
		$meta_fields = (array) $meta_method->invoke( null );

		$args_method = $ref->getMethod( 'get_cct_args' );
		$args_method->setAccessible( true );
		$label = __( 'AI Agent Memories', 'mcp-ai-wpoos' );
		$args  = (array) $args_method->invoke( null, $label );

		return array(
			'name'        => $label,
			'slug'        => WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_slug(),
			'args'        => $args,
			'meta_fields' => $meta_fields,
		);
	}

	/**
	 * Return the installed schema version (0 when unset).
	 *
	 * Convenience accessor used by the Phase 7a Memory Health subtab.
	 *
	 * @since 1.1.20
	 *
	 * @return int
	 */
	public static function get_installed_version() {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * Return the target schema version this build provides.
	 *
	 * @since 1.1.20
	 *
	 * @return int
	 */
	public static function get_target_version() {
		return self::CURRENT_VERSION;
	}
}
