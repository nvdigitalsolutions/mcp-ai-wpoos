<?php
/**
 * JetEngine Custom Content Type registration for the vitals log.
 *
 * Stores structured vital-sign log entries (blood pressure, heart rate,
 * temperature, weight/BMI, glucose, SpO2, respiratory rate, and kidney
 * indicators) as first-class CCT items when JetEngine is active.  This CCT is
 * the primary destination for compiled log data written by the log_vital_signs
 * tool; the older vital_signs CCT is retained for backward compatibility.
 *
 * Each row represents a single measurement event linked to a health member,
 * with a precise logged_at timestamp in addition to the measurement date/time.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provision and interact with the vitals_log CCT.
 */
class WP_MCP_AI_JetEngine_Vitals_Log_CCT {

	/**
	 * CCT slug.
	 */
	const SLUG = 'vitals_log';

	/**
	 * Base ID for meta field identifiers (44000 range).
	 *
	 * Uses a distinct range from the legacy vital_signs CCT (43000) to avoid
	 * any field-ID collisions inside JetEngine's internal registry.
	 */
	const FIELD_ID_BASE = 44000;

	/**
	 * Hook into JetEngine to provision the content type.
	 */
	public static function bootstrap() {
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 100 );
	}

	/**
	 * Return the CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Return the raw database table name for direct queries.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'jet_cct_' . self::SLUG;
	}

	/**
	 * Check whether the CCT database table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Insert a vitals log record into the CCT.
	 *
	 * @param int   $member_id WordPress post ID of the member.
	 * @param array $data      Flat key/value pairs matching the CCT schema.
	 * @return int|false       Inserted CCT item ID, or false on failure.
	 */
	public static function insert( $member_id, array $data ) {
		$member_id = absint( $member_id );
		if ( ! $member_id ) {
			return false;
		}

		$handler = self::get_item_handler();

		$record = array_merge(
			array(
				'member_id'  => $member_id,
				'cct_status' => 'publish',
			),
			$data
		);

		if ( $handler && method_exists( $handler, 'create_item' ) ) {
			$result = $handler->create_item( $record );
			return is_numeric( $result ) ? (int) $result : false;
		}

		if ( self::table_exists() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( self::get_table_name(), $record );
			return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
		}

		return false;
	}

	/**
	 * Retrieve vitals log records for a member, optionally filtered by date.
	 *
	 * @param int    $member_id  Member post ID.
	 * @param string $after_date Optional ISO date string 'YYYY-MM-DD'. Only
	 *                           records with measurement_date >= this value are
	 *                           returned.
	 * @param int    $limit      Maximum number of rows to return (0 = all).
	 * @return array             Array of CCT row objects, newest first.
	 */
	public static function get_for_member( $member_id, $after_date = '', $limit = 0 ) {
		$member_id = absint( $member_id );
		if ( ! $member_id || ! self::table_exists() ) {
			return array();
		}

		global $wpdb;
		$table = self::get_table_name();

		if ( $after_date ) {
			$after_date = sanitize_text_field( $after_date );
			if ( $limit > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE member_id = %d AND measurement_date >= %s ORDER BY measurement_date DESC, _ID DESC LIMIT %d", $member_id, $after_date, $limit ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE member_id = %d AND measurement_date >= %s ORDER BY measurement_date DESC, _ID DESC", $member_id, $after_date ) );
		}

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE member_id = %d ORDER BY measurement_date DESC, _ID DESC LIMIT %d", $member_id, $limit ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE member_id = %d ORDER BY measurement_date DESC, _ID DESC", $member_id ) );
	}

	/**
	 * Retrieve the most recent vitals log record for a member.
	 *
	 * @param int $member_id Member post ID.
	 * @return object|null   CCT row object or null.
	 */
	public static function get_latest( $member_id ) {
		$rows = self::get_for_member( absint( $member_id ), '', 1 );
		return ! empty( $rows ) ? $rows[0] : null;
	}

	/**
	 * Retrieve the JetEngine item handler.
	 *
	 * @return object|null
	 */
	public static function get_item_handler() {
		$module = self::get_cct_module();
		if ( ! $module || empty( $module->manager ) ) {
			return null;
		}

		if ( ! self::cct_exists( $module ) ) {
			self::maybe_register_cct();
		}

		$instance = $module->manager->get_content_types( self::SLUG );
		if ( ! $instance ) {
			return null;
		}

		return $instance->get_item_handler();
	}

	/**
	 * Register the CCT in JetEngine if not already registered.
	 */
	public static function maybe_register_cct() {
		$module = self::get_cct_module();
		if ( ! $module ) {
			return;
		}

		if ( self::cct_exists( $module ) ) {
			return;
		}

		if ( empty( $module->manager ) || empty( $module->manager->data ) ) {
			return;
		}

		$module->manager->data->set_request( self::get_registration_request() );
		$module->manager->data->create_item( false );
	}

	/**
	 * Get the JetEngine CCT module instance.
	 *
	 * @return object|null
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
	 * Check whether the CCT slug exists in JetEngine.
	 *
	 * @param object $module CCT module instance.
	 * @return bool
	 */
	protected static function cct_exists( $module ) {
		if ( empty( $module->manager ) || empty( $module->manager->data ) || empty( $module->manager->data->db ) ) {
			return false;
		}

		$records = $module->manager->data->db->query(
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
	 * Build the JetEngine registration request payload.
	 *
	 * @return array
	 */
	protected static function get_registration_request() {
		$label = __( 'Vitals Log', 'mcp-ai-wpoos-pro' );

		return array(
			'name'        => $label,
			'slug'        => self::SLUG,
			'args'        => self::get_cct_args( $label ),
			'meta_fields' => self::get_fields_schema(),
		);
	}

	/**
	 * Assemble JetEngine CCT arguments.
	 *
	 * @param string $label Human-readable label.
	 * @return array
	 */
	protected static function get_cct_args( $label ) {
		return array(
			'name'                => $label,
			'slug'                => self::SLUG,
			'position'            => '-1',
			'icon'                => 'dashicons-list-view',
			'capability'          => 'read',
			'has_single'          => false,
			'create_index'        => true,
			'hide_field_names'    => false,
			'rest_get_enabled'    => true,
			'rest_put_enabled'    => true,
			'rest_post_enabled'   => true,
			'rest_delete_enabled' => true,
			'rest_get_access'     => 'read',
			'rest_put_access'     => 'edit_posts',
			'rest_post_access'    => 'read',
			'rest_delete_access'  => 'edit_posts',
			'admin_columns'       => array(
				'_ID'              => array( 'enabled' => true, 'prefix' => '#', 'is_sortable' => true, 'is_num' => true ),
				'member_id'        => array( 'enabled' => true, 'is_sortable' => true, 'is_num' => true ),
				'measurement_date' => array( 'enabled' => true, 'is_sortable' => true ),
				'logged_at'        => array( 'enabled' => true, 'is_sortable' => true ),
				'bp_systolic'      => array( 'enabled' => true, 'is_sortable' => true, 'is_num' => true ),
				'heart_rate'       => array( 'enabled' => true, 'is_sortable' => true, 'is_num' => true ),
				'source'           => array( 'enabled' => true, 'is_sortable' => true ),
			),
		);
	}

	/**
	 * Field schema for the vitals_log CCT.
	 *
	 * @return array
	 */
	protected static function get_fields_schema() {
		$b = self::FIELD_ID_BASE;

		return array(
			// ── Core identifiers ──────────────────────────────────────────
			array(
				'id'          => $b + 1,
				'title'       => __( 'Member ID', 'mcp-ai-wpoos-pro' ),
				'name'        => 'member_id',
				'type'        => 'number',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'is_required' => true,
				'description' => __( 'WordPress post ID of the member (mcp_ai_member)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 2,
				'title'       => __( 'Measurement Date', 'mcp-ai-wpoos-pro' ),
				'name'        => 'measurement_date',
				'type'        => 'text',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Date of measurement (YYYY-MM-DD)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 3,
				'title'       => __( 'Measurement Time', 'mcp-ai-wpoos-pro' ),
				'name'        => 'measurement_time',
				'type'        => 'text',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Time of measurement (HH:MM)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 4,
				'title'       => __( 'Logged At', 'mcp-ai-wpoos-pro' ),
				'name'        => 'logged_at',
				'type'        => 'text',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'MySQL datetime when this log entry was created (YYYY-MM-DD HH:MM:SS)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 5,
				'title'       => __( 'Source', 'mcp-ai-wpoos-pro' ),
				'name'        => 'source',
				'type'        => 'select',
				'search'      => true,
				'width'       => '33%',
				'default_val' => 'manual',
				'options'     => array(
					array( 'key' => 'manual', 'value' => __( 'Manual Entry', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'tma',    'value' => __( 'Telegram Mini App', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'api',    'value' => __( 'External API', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'import', 'value' => __( 'Data Import', 'mcp-ai-wpoos-pro' ) ),
				),
				'description' => __( 'How this measurement was captured', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 6,
				'title'       => __( 'Notes', 'mcp-ai-wpoos-pro' ),
				'name'        => 'notes',
				'type'        => 'textarea',
				'search'      => false,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Optional contextual notes for this measurement', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 7,
				'title'       => __( 'Logged By (User ID)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'logged_by',
				'type'        => 'number',
				'search'      => false,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'WordPress user ID of who created this record', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 8,
				'title'       => __( 'Entry ID', 'mcp-ai-wpoos-pro' ),
				'name'        => 'entry_id',
				'type'        => 'text',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Options-storage entry ID (vs_TIMESTAMP_RAND) for cross-referencing', 'mcp-ai-wpoos-pro' ),
			),

			// ── Blood pressure ────────────────────────────────────────────
			array(
				'id'          => $b + 10,
				'title'       => __( 'BP Systolic (mmHg)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'bp_systolic',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Systolic blood pressure in mmHg', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 11,
				'title'       => __( 'BP Diastolic (mmHg)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'bp_diastolic',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Diastolic blood pressure in mmHg', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 12,
				'title'       => __( 'BP Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'bp_status',
				'type'        => 'text',
				'search'      => true,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Assessed blood pressure status (normal, elevated, stage_1_hypertension, stage_2_hypertension, hypertensive_crisis)', 'mcp-ai-wpoos-pro' ),
			),

			// ── Heart rate ────────────────────────────────────────────────
			array(
				'id'          => $b + 13,
				'title'       => __( 'Heart Rate (bpm)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'heart_rate',
				'type'        => 'number',
				'search'      => false,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Heart rate in beats per minute', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 14,
				'title'       => __( 'Heart Rate Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'heart_rate_status',
				'type'        => 'text',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Assessed heart rate status (low, normal, high)', 'mcp-ai-wpoos-pro' ),
			),

			// ── Temperature ───────────────────────────────────────────────
			array(
				'id'          => $b + 15,
				'title'       => __( 'Temperature', 'mcp-ai-wpoos-pro' ),
				'name'        => 'temperature',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Body temperature value', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 16,
				'title'       => __( 'Temperature Unit', 'mcp-ai-wpoos-pro' ),
				'name'        => 'temperature_unit',
				'type'        => 'text',
				'search'      => false,
				'width'       => '33%',
				'default_val' => 'F',
				'description' => __( 'Temperature unit: F or C', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 17,
				'title'       => __( 'Temperature Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'temperature_status',
				'type'        => 'text',
				'search'      => true,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Assessed temperature status (low, normal, elevated, fever)', 'mcp-ai-wpoos-pro' ),
			),

			// ── Weight & BMI ──────────────────────────────────────────────
			array(
				'id'          => $b + 18,
				'title'       => __( 'Weight', 'mcp-ai-wpoos-pro' ),
				'name'        => 'weight',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Body weight value', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 19,
				'title'       => __( 'Weight Unit', 'mcp-ai-wpoos-pro' ),
				'name'        => 'weight_unit',
				'type'        => 'text',
				'search'      => false,
				'width'       => '33%',
				'default_val' => 'lbs',
				'description' => __( 'Weight unit: lbs or kg', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 20,
				'title'       => __( 'BMI', 'mcp-ai-wpoos-pro' ),
				'name'        => 'bmi',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Calculated BMI value', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 21,
				'title'       => __( 'BMI Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'bmi_status',
				'type'        => 'text',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Assessed BMI status (underweight, normal, overweight, obese)', 'mcp-ai-wpoos-pro' ),
			),

			// ── Blood glucose ─────────────────────────────────────────────
			array(
				'id'          => $b + 22,
				'title'       => __( 'Blood Glucose (mg/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'blood_glucose',
				'type'        => 'number',
				'search'      => false,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Blood glucose level in mg/dL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 23,
				'title'       => __( 'Blood Glucose Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'blood_glucose_status',
				'type'        => 'text',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Assessed glucose status (low, normal, prediabetic, diabetic_range)', 'mcp-ai-wpoos-pro' ),
			),

			// ── Oxygen saturation ─────────────────────────────────────────
			array(
				'id'          => $b + 24,
				'title'       => __( 'SpO2 (%)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'oxygen_saturation',
				'type'        => 'number',
				'search'      => false,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Oxygen saturation percentage (SpO2)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 25,
				'title'       => __( 'SpO2 Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'oxygen_saturation_status',
				'type'        => 'text',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Assessed SpO2 status (critical, low, normal)', 'mcp-ai-wpoos-pro' ),
			),

			// ── Respiratory rate ──────────────────────────────────────────
			array(
				'id'          => $b + 26,
				'title'       => __( 'Respiratory Rate (breaths/min)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'respiratory_rate',
				'type'        => 'number',
				'search'      => false,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Respiratory rate in breaths per minute', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 27,
				'title'       => __( 'Respiratory Rate Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'respiratory_rate_status',
				'type'        => 'text',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Assessed respiratory rate status (low, normal, high)', 'mcp-ai-wpoos-pro' ),
			),

			// ── Kidney health indicators ──────────────────────────────────
			array(
				'id'          => $b + 28,
				'title'       => __( 'eGFR (mL/min/1.73m²)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'egfr',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Estimated Glomerular Filtration Rate — CKD stage indicator', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 29,
				'title'       => __( 'Creatinine (mg/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'creatinine',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Serum creatinine level in mg/dL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 30,
				'title'       => __( 'BUN (mg/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'bun',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Blood Urea Nitrogen in mg/dL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 31,
				'title'       => __( 'Potassium K+ (mEq/L)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'potassium',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Serum potassium in mEq/L', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 32,
				'title'       => __( 'Sodium Na+ (mg/day)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'sodium',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Sodium intake in mg/day (kidney-friendly target ≤ 2300 mg)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 33,
				'title'       => __( 'Phosphorus PO4 (mg/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'phosphorus',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Serum phosphorus in mg/dL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 34,
				'title'       => __( 'Albumin (g/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'albumin',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Serum albumin in g/dL — nutritional/kidney health marker', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}
