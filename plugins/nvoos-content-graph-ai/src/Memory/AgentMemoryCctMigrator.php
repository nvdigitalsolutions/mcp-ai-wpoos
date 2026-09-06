<?php
/**
 * Agent memory CCT schema migrator for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's `WP_MCP_AI_Agent_Memory_CCT_Migrator`
 * (behaviour-preserving; base copies retained permanently — ecosystem
 * port plan D-NOBASE). Same version option
 * (`wp_mcp_ai_memory_cct_schema_version`), target version (2), enable
 * filter (`wp_mcp_ai_memory_cct_migrator_enabled`, default false since
 * the base's 1.1.22), and opportunistic version advance when disabled.
 *
 * Decoupling (documented, additive):
 * - `bootstrap()` is called standalone-only by `Plugin.php` — the base
 *   plugin bootstraps the same migrator in monolith installs.
 * - The CCT class resolves per install mode (base
 *   `WP_MCP_AI_JetEngine_Agent_Memories_CCT` monolith / the ported
 *   `AgentMemoriesCct` standalone).
 *
 * @package NvoosContentGraphAi\Memory
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Memory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Idempotent CCT schema upgrader.
 *
 * @since 1.1.0
 */
class AgentMemoryCctMigrator {

	/**
	 * Option name tracking the highest applied schema version.
	 */
	const VERSION_OPTION = 'wp_mcp_ai_memory_cct_schema_version';

	/**
	 * Current target schema version this migrator brings the CCT up to.
	 */
	const CURRENT_VERSION = 2;

	/**
	 * Wire the migrator to run once per admin pageview when the version is stale.
	 *
	 * Disabled by default (byte-identical to the base since 1.1.22) — the
	 * JetEngine admin-UI refresh path is unsafe on existing CCTs; data
	 * writes via the bridge persist every Phase 2+ field regardless. When
	 * disabled, the stored schema version is opportunistically advanced so
	 * downstream health checks short-circuit.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		/**
		 * Master kill-switch for the CCT schema migrator.
		 *
		 * Default: false (byte-identical to the base since 1.1.22).
		 *
		 * @param bool $enabled Default false.
		 */
		if ( ! (bool) apply_filters( 'wp_mcp_ai_memory_cct_migrator_enabled', false ) ) {
			// Opportunistically advance the stored version so downstream
			// consumers see a healthy state. Guarded so a higher value is
			// never rolled backwards.
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
		// requests skip the run but data writes continue to work because they
		// don't depend on the admin UI columns.
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
	 * @param int $from_version Pre-upgrade version found on disk.
	 * @return array Same shape as {@see self::maybe_run()}.
	 */
	protected static function run_upgrade( $from_version ) {
		$failure = static function ( $message ) use ( $from_version ) {
			if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
				\WP_MCP_AI_Logger::log_error(
					'Agent Memory CCT migrator: ' . $message,
					array(
						'from_version'   => $from_version,
						'target_version' => self::CURRENT_VERSION,
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

		$cct_class = self::cct_class();

		if ( ! class_exists( $cct_class ) ) {
			return $failure( 'Agent memories CCT class is unavailable.' );
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
		// place rather than create a duplicate.
		$existing = $data->db->query(
			'post_types',
			array(
				'slug'   => $cct_class::get_slug(),
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
		// (so future schema versions ship without a new migrator class).
		$request        = self::build_registration_request( $cct_class );
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
		} catch ( \Throwable $e ) {
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
	 * Re-derives the request shape from public helpers + reflection on the
	 * protected `get_meta_fields()` / `get_cct_args()` (forward-compatible:
	 * future schema versions ride on whatever fields the CCT class declares).
	 *
	 * @param string $cct_class Resolved CCT class name.
	 * @return array Registration request payload.
	 */
	protected static function build_registration_request( $cct_class ) {
		$ref         = new \ReflectionClass( $cct_class );
		$meta_method = $ref->getMethod( 'get_meta_fields' );
		$meta_method->setAccessible( true );
		$meta_fields = (array) $meta_method->invoke( null );

		$args_method = $ref->getMethod( 'get_cct_args' );
		$args_method->setAccessible( true );
		$label = __( 'AI Agent Memories', 'nvoos-content-graph-ai' );
		$args  = (array) $args_method->invoke( null, $label );

		return array(
			'name'        => $label,
			'slug'        => $cct_class::get_slug(),
			'args'        => $args,
			'meta_fields' => $meta_fields,
		);
	}

	/**
	 * Resolve the CCT class name for the active install mode.
	 *
	 * @return string
	 */
	protected static function cct_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			return 'WP_MCP_AI_JetEngine_Agent_Memories_CCT';
		}

		return __NAMESPACE__ . '\\AgentMemoriesCct';
	}

	/**
	 * Return the installed schema version (0 when unset).
	 *
	 * @return int
	 */
	public static function get_installed_version() {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * Return the target schema version this build provides.
	 *
	 * @return int
	 */
	public static function get_target_version() {
		return self::CURRENT_VERSION;
	}
}
