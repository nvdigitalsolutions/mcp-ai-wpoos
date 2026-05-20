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
	 * Idempotent: re-bootstrapping is a no-op. Disable via the
	 * `wp_mcp_ai_memory_cct_migrator_enabled` filter.
	 */
	public static function bootstrap() {
		/**
		 * Master kill-switch for the CCT schema migrator.
		 *
		 * Default: true. Disabling this prevents the JetEngine admin UI from
		 * showing the Phase 2+ schema columns for existing CCTs, but does
		 * NOT prevent data writes — the new fields are written either way.
		 *
		 * @since 1.1.20
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! (bool) apply_filters( 'wp_mcp_ai_memory_cct_migrator_enabled', true ) ) {
			return;
		}

		// Repair must fire BEFORE JetEngine's CCT manager runs `register_instances()`
		// during its own `plugins_loaded:10` bootstrap. A late priority (e.g. 20)
		// fatals at JE's `factory.php` array_merge() before we ever get a chance to
		// run, so we hook at priority 0.
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_repair_corrupt_cct_args' ), 0 );
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
	 * Writes the up-to-date `args` and `meta_fields` JSON directly into the
	 * `wp_jet_post_types` row, bypassing JetEngine's creation-oriented
	 * `sanitize_item_request()` validator which rejects update requests for
	 * existing CCT slugs. JetEngine's in-memory cache is then refreshed so
	 * the admin UI immediately picks up the new columns.
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
		// returns rows from `wp_jet_post_types` for the chosen post_type.
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
		$request          = self::build_registration_request();
		$request['args']  = self::normalise_cct_args_payload(
			isset( $existing_row['args'] ) ? $existing_row['args'] : array(),
			isset( $request['args'] ) ? (array) $request['args'] : array()
		);

		if ( empty( $request['args'] ) || ! is_array( $request['args'] ) ) {
			return $failure( 'Upgrade item args payload is invalid; aborting to avoid corrupting JetEngine CCT registration.' );
		}

		// Direct DB update: JetEngine's sanitize_item_request() is designed
		// for creation (validates slug uniqueness), so it rejects update
		// requests for existing CCTs. Bypass that validation layer and
		// write the new args + meta_fields JSON directly into the
		// wp_jet_post_types row, then refresh JetEngine's in-memory cache.
		try {
			global $wpdb;

			$table = $wpdb->prefix . 'jet_post_types';
			// JetEngine reads the `args` / `meta_fields` columns via maybe_unserialize().
			// maybe_unserialize() only handles PHP-serialized payloads, so writing JSON
			// (as PR #5039 did) leaves the string unchanged on read and fatals JE's
			// `Factory->__construct()` at array_merge() with a string argument. Match
			// JE's native on-disk format by using maybe_serialize() here.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows  = $wpdb->update(
				$table,
				array(
					'args'        => maybe_serialize( $request['args'] ),
					'meta_fields' => maybe_serialize( $request['meta_fields'] ),
				),
				array( 'id' => $existing_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $rows ) {
				return $failure( 'Direct DB update failed: ' . ( $wpdb->last_error ?: 'unknown error' ) );
			}

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
	 * Recover from older migrator runs that may have persisted a non-array
	 * `args` payload for the agent-memories CCT.
	 *
	 * This runs on `plugins_loaded` at priority 0 so the row is fixed before
	 * JetEngine's CCT manager runs `register_instances()` during its own
	 * `plugins_loaded:10` bootstrap. Hooking later (e.g. priority 20) is too
	 * late — JE's `Factory->__construct()` will already have fatal-errored at
	 * `array_merge()` because the on-disk `args` payload is still a string.
	 *
	 * @since 1.1.20
	 * @return void
	 */
	public static function maybe_repair_corrupt_cct_args() {
		global $wpdb;

		if ( ! ( $wpdb instanceof wpdb ) ) {
			return;
		}

		$table        = self::get_jetengine_cct_table_name( $wpdb );
		$valid_tables = self::get_jetengine_cct_table_candidates( $wpdb );

		if ( '' === $table || ! in_array( $table, $valid_tables, true ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is resolved from a strict internal allowlist.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, args FROM `' . $table . '` WHERE slug = %s AND status = %s LIMIT 1',
				WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_slug(),
				'content-type'
			),
			ARRAY_A
		);

		if ( empty( $row ) || ! is_array( $row ) || empty( $row['id'] ) ) {
			return;
		}

		$request  = self::build_registration_request();
		$fallback = isset( $request['args'] ) ? (array) $request['args'] : array();
		$fixed    = self::normalise_cct_args_payload(
			isset( $row['args'] ) ? $row['args'] : '',
			$fallback
		);

		if ( empty( $fixed ) || ! is_array( $fixed ) ) {
			return;
		}

		$stored_args       = self::normalise_structured_payload(
			isset( $row['args'] ) ? $row['args'] : '',
			array()
		);
		$stored_serialized = maybe_serialize( $stored_args );
		$fixed_serialized  = maybe_serialize( $fixed );

		if ( $stored_serialized === $fixed_serialized ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-row integrity repair before JetEngine init.
		$updated = $wpdb->update(
			$table,
			array( 'args' => $fixed_serialized ),
			array( 'id' => (int) $row['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false !== $updated && class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_warning(
				'Agent Memory CCT migrator: repaired non-array args payload in JetEngine CCT registration.',
				array(
					'cct_id' => (int) $row['id'],
					'table'  => $table,
				)
			);
		} elseif ( false === $updated && class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_error(
				'Agent Memory CCT migrator: failed to repair args payload in JetEngine CCT registration.',
				array(
					'cct_id'     => (int) $row['id'],
					'table'      => $table,
					'last_error' => isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '',
				)
			);
		}
	}

	/**
	 * Resolve the JetEngine CCT registration table name.
	 *
	 * @since 1.1.20
	 *
	 * @param wpdb $wpdb WordPress database handle.
	 * @return string
	 */
	protected static function get_jetengine_cct_table_name( $wpdb ) {
		$candidates = self::get_jetengine_cct_table_candidates( $wpdb );

		foreach ( $candidates as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW TABLES cannot use placeholders for identifiers.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists === $table ) {
				return $table;
			}
		}

		return '';
	}

	/**
	 * Return allowlisted JetEngine CCT registration table names.
	 *
	 * @since 1.1.20
	 *
	 * @param wpdb $wpdb WordPress database handle.
	 * @return array
	 */
	protected static function get_jetengine_cct_table_candidates( $wpdb ) {
		$prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';

		if ( '' === $prefix || 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $prefix ) ) {
			return array();
		}

		return array(
			$prefix . 'jet_post_types',
			$prefix . 'jet_engine_post_types',
		);
	}

	/**
	 * Normalize potentially serialized/JSON payloads to arrays.
	 *
	 * @since 1.1.20
	 *
	 * @param mixed $value    Raw value to normalize.
	 * @param array $fallback Fallback array when value is not coercible.
	 * @return array
	 */
	protected static function normalise_structured_payload( $value, $fallback = array() ) {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) ) {
			return $fallback;
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return $fallback;
		}

		$unserialized = maybe_unserialize( $value );
		if ( is_array( $unserialized ) ) {
			return $unserialized;
		}

		$json = json_decode( $value, true );
		if ( is_array( $json ) ) {
			return $json;
		}

		return $fallback;
	}

	/**
	 * Normalize an `args` payload and merge it over the canonical defaults.
	 *
	 * @since 1.1.20
	 *
	 * @param mixed $value    Raw args value.
	 * @param array $fallback Canonical default args.
	 * @return array
	 */
	protected static function normalise_cct_args_payload( $value, $fallback = array() ) {
		$normalised = self::normalise_structured_payload( $value, array() );

		if ( empty( $fallback ) ) {
			return $normalised;
		}

		if ( empty( $normalised ) ) {
			return $fallback;
		}

		return array_replace_recursive( $fallback, $normalised );
	}

	/**
	 * Return the installed schema version (0 when unset).	 *
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
