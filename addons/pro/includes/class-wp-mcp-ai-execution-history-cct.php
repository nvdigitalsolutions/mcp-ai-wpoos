<?php
/**
 * JetEngine Custom Content Type registration for execution history.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the execution history CCT for Ralph orchestration pattern.
 * High-volume tool call logs for analytics and debugging.
 */
class WP_MCP_AI_Execution_History_CCT {
	const SLUG = 'mcp_execution_history';

	/**
	 * Default row cap for session-history reads when no limit is supplied.
	 */
	const DEFAULT_QUERY_LIMIT = 500;

	/**
	 * Retention sweep cron hook.
	 */
	const CRON_HOOK = 'wp_mcp_ai_execution_history_retention_sweep';

	/**
	 * Execution-history rows older than this many days are pruned daily.
	 */
	const RETENTION_DAYS = 90;

	/**
	 * Rows deleted per batch during the retention sweep.
	 */
	const PRUNE_BATCH_SIZE = 1000;

	/**
	 * Maximum batches processed per sweep run.
	 */
	const PRUNE_MAX_BATCHES = 50;

	/**
	 * Base ID for meta field identifiers.
	 * Using 32000 range to avoid conflicts with other CCT fields.
	 */
	const FIELD_ID_BASE = 32000;

	/**
	 * Per-request storage readiness cache, keyed by table name.
	 *
	 * The probe result is cached because the physical table schema cannot
	 * change after `init` (the registration hook runs at priority 11).
	 * Tests reset it via reset_storage_cache().
	 *
	 * @var array
	 */
	private static $storage_ready_cache = array();

	/**
	 * Hook into JetEngine to provision the execution history content type.
	 */
	public static function bootstrap() {
		// JetEngine's CCT module hydrates its table cache on `init` at priorities
		// 1-10; registering inside that window races with it and stomps
		// JetEngine's CCT state. Priority 11 is the documented safe window.
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 11 );

		// Ensure data stores module is enabled when JetEngine is active.
		add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 11 );

		// Daily retention sweep: prune execution-history rows older than 90 days.
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_retention_sweep' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_retention' ), 12 );
	}

	/**
	 * Retrieve the execution history CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Retrieve the JetEngine item handler for the execution history content type.
	 *
	 * Returns null when the content type is not registered or when its physical
	 * table is missing (e.g. registered but never created). Consumers must skip
	 * CCT access in that case instead of querying a non-existent table, which
	 * would make `$wpdb` print an error into the response body.
	 *
	 * @return object|null
	 */
	public static function get_item_handler() {
		$module = self::get_cct_module();

		if ( ! $module ) {
			return null;
		}

		if ( empty( $module->manager ) ) {
			return null;
		}

		$instance = $module->manager->get_content_types( self::SLUG );

		if ( ! $instance ) {
			return null;
		}

		if ( ! self::is_storage_ready() ) {
			return null;
		}

		return $instance->get_item_handler();
	}

	/**
	 * Determine whether the physical CCT table exists in the database.
	 *
	 * JetEngine reports a content type as registered even when its backing
	 * table was never created (failed provisioning, missing DB privileges,
	 * partial migrations). Queries against such a table fail at the SQL level
	 * and `$wpdb` prints the error into the output, corrupting JSON responses
	 * such as the orchestration dashboard AJAX payload.
	 *
	 * @return bool True when the CCT table exists.
	 */
	public static function table_exists() {
		global $wpdb;

		$table = $wpdb->prefix . 'jet_cct_' . self::SLUG;

		$wpdb->last_error = '';

		// Suppress the expected table-missing error output; the probe is
		// checking for exactly that condition.
		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from the fixed plugin slug; a direct SELECT probe is transactional-DDL safe on MySQL 8.0 where SHOW TABLES is not.
		$wpdb->get_var( "SELECT 1 FROM `{$table}` LIMIT 1" );

		$wpdb->suppress_errors( $suppress );

		return '' === $wpdb->last_error;
	}

	/**
	 * Determine whether the CCT storage is fully ready for read/write.
	 *
	 * Stronger than table_exists(): the physical table must exist AND carry
	 * every column this class reads or writes (JetEngine built-ins plus the
	 * meta-field set). A registered content type whose table is missing
	 * columns (schema drift, partial migration) fails this probe so consumers
	 * fall back to transients instead of running queries that error.
	 *
	 * @return bool True when the table and its full schema are present.
	 */
	public static function is_storage_ready() {
		global $wpdb;

		$table = $wpdb->prefix . 'jet_cct_' . self::SLUG;

		if ( array_key_exists( $table, self::$storage_ready_cache ) ) {
			return self::$storage_ready_cache[ $table ];
		}

		if ( ! self::table_exists() ) {
			self::$storage_ready_cache[ $table ] = false;
			return false;
		}

		$required = array_merge(
			array( '_id', 'cct_status', 'cct_created', 'cct_modified', 'cct_author_id' ),
			array_map( 'strtolower', wp_list_pluck( static::get_meta_fields(), 'name' ) )
		);

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column probe against the fixed plugin-owned table name.
		$existing = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );

		$wpdb->suppress_errors( $suppress );

		if ( ! is_array( $existing ) ) {
			self::$storage_ready_cache[ $table ] = false;
			return false;
		}

		$missing = array_diff( $required, array_map( 'strtolower', $existing ) );
		$ready   = empty( $missing );

		self::$storage_ready_cache[ $table ] = $ready;

		if ( ! $ready && class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_error(
				'[CCT ' . self::SLUG . '] Storage not ready: missing columns ' . implode( ', ', $missing ),
				array( 'table' => $table )
			);
		}

		return $ready;
	}

	/**
	 * Reset the per-request storage readiness cache.
	 *
	 * @internal Test seam: the single-process suite creates and drops the
	 * probe tables mid-process, so tests must clear the cached probe result.
	 */
	public static function reset_storage_cache() {
		self::$storage_ready_cache = array();
	}

	/**
	 * Get execution history for a session.
	 *
	 * @param string $session_id Session identifier.
	 * @param array  $args       Query arguments.
	 * @return array List of execution records.
	 */
	public static function get_session_history( $session_id, $args = array() ) {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return array();
		}

		$factory = $handler->get_factory();

		if ( ! $factory || empty( $factory->db ) ) {
			return array();
		}

		$defaults = array(
			'session_id' => $session_id,
			'orderby'    => 'executed_at',
			'order'      => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		// Bound unbounded reads: a long-running session can accumulate tens of
		// thousands of rows, which must never be loaded in a single query.
		if ( empty( $args['limit'] ) ) {
			$args['limit'] = self::DEFAULT_QUERY_LIMIT;
		}

		$items = $factory->db->query( $args );

		return is_array( $items ) ? $items : array();
	}

	/**
	 * Count total execution records, optionally filtered by success.
	 *
	 * @param bool|null $success Optional. Filter by success (true/false) or null for all.
	 * @return int
	 */
	public static function count_total( $success = null ) {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return 0;
		}

		$factory = $handler->get_factory();

		if ( ! $factory || empty( $factory->db ) ) {
			return 0;
		}

		$query = array();
		if ( null !== $success ) {
			$query['success'] = (bool) $success;
		}

		$items = $factory->db->query( $query );

		return is_array( $items ) ? count( $items ) : 0;
	}

	/**
	 * Schedule the daily retention sweep (idempotent).
	 */
	public static function maybe_schedule_retention() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Daily retention sweep: delete rows older than RETENTION_DAYS.
	 *
	 * Deletes in bounded batches so a single sweep never issues one huge DELETE.
	 */
	public static function run_retention_sweep() {
		if ( ! self::table_exists() ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'jet_cct_' . self::SLUG;

		// cct_created is the JetEngine built-in MySQL datetime creation stamp.
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );

		for ( $i = 0; $i < self::PRUNE_MAX_BATCHES; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned table name; batched delete avoids huge single statements.
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE cct_created < %s LIMIT %d", $cutoff, self::PRUNE_BATCH_SIZE ) );
			if ( ! $deleted || $deleted < self::PRUNE_BATCH_SIZE ) {
				break;
			}
		}
	}

	/**
	 * Automatically enable the JetEngine data stores module if it's not already active.
	 */
	public static function maybe_enable_data_stores() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return;
		}

		// Check if data stores module is already active.
		if ( $engine->modules->is_module_active( 'data-stores' ) ) {
			return;
		}

		// Check if the module exists.
		if ( ! method_exists( $engine->modules, 'get_module' ) ) {
			return;
		}

		$module = $engine->modules->get_module( 'data-stores' );

		if ( ! $module ) {
			return;
		}

		// Activate the data stores module.
		if ( method_exists( $engine->modules, 'activate_module' ) ) {
			$engine->modules->activate_module( 'data-stores' );
		}
	}

	/**
	 * Register the execution history CCT if it is missing.
	 */
	public static function maybe_register_cct() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_project_management'] ) ) {
			return;
		}

		$module = self::get_cct_module();

		if ( ! $module ) {
			return;
		}

		if ( empty( $module->manager ) || empty( $module->manager->data ) ) {
			return;
		}

		if ( self::cct_exists( $module ) ) {
			return;
		}

		$data    = $module->manager->data;
		$request = self::get_registration_request();

		$data->set_request( $request );

		if ( method_exists( $data, 'sanitize_item_request' ) && ! $data->sanitize_item_request() ) {
			return;
		}

		$item = $data->sanitize_item_from_request();

		if ( empty( $item ) || ! is_array( $item ) ) {
			return;
		}

		$data->before_item_update( $item, true );

		$item_id = $data->update_item_in_db( $item );

		if ( ! $item_id ) {
			return;
		}

		$item['id'] = $item_id;

		$data->after_item_update( $item, true );

		if ( ! empty( $data->db ) && method_exists( $data->db, 'query_raw' ) ) {
			$data->db->query_raw( 'post_types' );
		}
	}

	/**
	 * Determine whether the execution history CCT already exists.
	 *
	 * @param \Jet_Engine\Modules\Custom_Content_Types\Module $module Module instance.
	 * @return bool
	 */
	protected static function cct_exists( $module ) {
		$data = $module->manager->data;

		if ( empty( $data->db ) ) {
			return false;
		}

		$records = $data->db->query(
			'post_types',
			array(
				'slug'   => self::SLUG,
				'status' => 'content-type',
			),
			null,
			false
		);

		return ! empty( $records );
	}

	/**
	 * Retrieve the JetEngine Custom Content Types module instance.
	 *
	 * @return \Jet_Engine\Modules\Custom_Content_Types\Module|null
	 */
	protected static function get_cct_module() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return null;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return null;
		}

		if ( ! $engine->modules->is_module_active( 'custom-content-types' ) ) {
			return null;
		}

		$module_wrapper = $engine->modules->get_module( 'custom-content-types' );

		if ( empty( $module_wrapper ) || empty( $module_wrapper->instance ) ) {
			return null;
		}

		return $module_wrapper->instance;
	}

	/**
	 * Build the request payload used to register the content type.
	 *
	 * @return array
	 */
	protected static function get_registration_request() {
		$label = __( 'Execution History', 'mcp-ai-wpoos' );

		return array(
			'name'        => $label,
			'slug'        => self::SLUG,
			'args'        => self::get_cct_args( $label ),
			'meta_fields' => self::get_meta_fields(),
		);
	}

	/**
	 * Assemble the JetEngine arguments for the execution history CCT.
	 *
	 * @param string $label Human-readable label for the content type.
	 * @return array
	 */
	protected static function get_cct_args( $label ) {
		return array(
			'name'                => $label,
			'slug'                => self::SLUG,
			'position'            => '-1',
			'icon'                => 'dashicons-chart-line',
			'capability'          => 'edit_posts',
			'has_single'          => false,
			'create_index'        => true,
			'hide_field_names'    => false,
			'rest_get_enabled'    => true,
			'rest_put_enabled'    => false,
			'rest_post_enabled'   => true,
			'rest_delete_enabled' => true,
			'rest_get_access'     => 'edit_posts',
			'rest_post_access'    => 'edit_posts',
			'rest_delete_access'  => 'edit_posts',
			'admin_columns'       => array(
				'_ID'         => array(
					'enabled'     => true,
					'prefix'      => '#',
					'is_sortable' => true,
					'is_num'      => true,
				),
				'session_id'  => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'tool_name'   => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'success'     => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'duration_ms' => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'tokens_used' => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'executed_at' => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
			),
		);
	}

	/**
	 * Define the meta fields for the execution history CCT.
	 *
	 * @return array
	 */
	protected static function get_meta_fields() {
		$fields = array(
			self::build_field(
				32001,
				'session_id',
				__( 'Session ID', 'mcp-ai-wpoos' ),
				'text',
				array(
					'is_required' => true,
					'description' => __( 'Session identifier for this execution.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				32002,
				'iteration',
				__( 'Iteration', 'mcp-ai-wpoos' ),
				'number',
				array(
					'is_required' => true,
					'description' => __( 'Iteration number within the session.', 'mcp-ai-wpoos' ),
					'min'         => 1,
				)
			),
			self::build_field(
				32003,
				'tool_name',
				__( 'Tool Name', 'mcp-ai-wpoos' ),
				'text',
				array(
					'is_required' => true,
					'description' => __( 'Name of the tool executed.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				32004,
				'success',
				__( 'Success', 'mcp-ai-wpoos' ),
				'switcher',
				array(
					'is_required' => true,
					'description' => __( 'Whether the tool execution succeeded.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				32005,
				'error_message',
				__( 'Error Message', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'Error message if execution failed.', 'mcp-ai-wpoos' ),
					'rows'        => 3,
				)
			),
			self::build_field(
				32006,
				'duration_ms',
				__( 'Duration (ms)', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Execution duration in milliseconds.', 'mcp-ai-wpoos' ),
					'min'         => 0,
				)
			),
			self::build_field(
				32007,
				'tokens_used',
				__( 'Tokens Used', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Tokens consumed by this tool call.', 'mcp-ai-wpoos' ),
					'min'         => 0,
				)
			),
			self::build_field(
				32008,
				'input_summary',
				__( 'Input Summary', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'Summary of tool input arguments (first 500 chars).', 'mcp-ai-wpoos' ),
					'rows'        => 3,
				)
			),
			self::build_field(
				32009,
				'output_summary',
				__( 'Output Summary', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'Summary of tool output (first 500 chars).', 'mcp-ai-wpoos' ),
					'rows'        => 3,
				)
			),
			self::build_field(
				32010,
				'executed_at',
				__( 'Executed At', 'mcp-ai-wpoos' ),
				'datetime-local',
				array(
					'is_required' => true,
					'description' => __( 'Timestamp when tool was executed.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				32011,
				'metadata',
				__( 'Metadata', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'JSON metadata for additional execution data.', 'mcp-ai-wpoos' ),
					'rows'        => 2,
				)
			),
		);

		return $fields;
	}

	/**
	 * Build a standardized meta field definition.
	 *
	 * @param int    $id         Unique field identifier.
	 * @param string $name       Field name (key).
	 * @param string $title      Human-readable field title.
	 * @param string $type       Field type (text, textarea, number, select, etc.).
	 * @param array  $extra_args Optional. Additional field arguments.
	 * @return array
	 */
	protected static function build_field( $id, $name, $title, $type, $extra_args = array() ) {
		return wp_parse_args(
			$extra_args,
			array(
				'id'    => $id,
				'name'  => $name,
				'title' => $title,
				'type'  => $type,
			)
		);
	}
}

WP_MCP_AI_Execution_History_CCT::bootstrap();
