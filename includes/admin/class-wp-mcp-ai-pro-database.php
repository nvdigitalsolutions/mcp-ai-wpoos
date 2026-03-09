<?php
/**
 * Pro Dashboard Database Schema and Management
 *
 * Handles database tables for ISO 27001 controls tracking,
 * evidence collection, audit trails, and risk register.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro Dashboard Database Class
 */
class WP_MCP_AI_Pro_Database {

	/**
	 * Database version
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_create_tables' ) );
	}

	/**
	 * Check if database needs updating
	 *
	 * @return void
	 */
	public function maybe_create_tables() {
		$installed_version = get_option( 'wp_mcp_ai_pro_db_version', '0' );

		if ( version_compare( $installed_version, self::DB_VERSION, '<' ) ) {
			$this->create_tables();
			update_option( 'wp_mcp_ai_pro_db_version', self::DB_VERSION );
		}
	}

	/**
	 * Create database tables
	 *
	 * @return void
	 */
	public function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Controls tracking table.
		$controls_table = $wpdb->prefix . 'mcp_ai_controls';
		$controls_sql   = "CREATE TABLE IF NOT EXISTS $controls_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			control_id varchar(20) NOT NULL,
			category varchar(10) NOT NULL,
			name varchar(255) NOT NULL,
			description text,
			status varchar(20) NOT NULL DEFAULT 'planned',
			implementation_date date,
			last_review_date date,
			next_review_date date,
			owner_user_id bigint(20) unsigned,
			evidence_count int(11) DEFAULT 0,
			notes text,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY control_id (control_id),
			KEY category (category),
			KEY status (status),
			KEY owner_user_id (owner_user_id)
		) $charset_collate;";

		// Evidence collection table.
		$evidence_table = $wpdb->prefix . 'mcp_ai_evidence';
		$evidence_sql   = "CREATE TABLE IF NOT EXISTS $evidence_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			control_id varchar(20) NOT NULL,
			evidence_type varchar(50) NOT NULL,
			title varchar(255) NOT NULL,
			description text,
			file_url varchar(500),
			file_type varchar(50),
			file_size bigint(20) unsigned,
			uploaded_by bigint(20) unsigned,
			upload_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			is_valid tinyint(1) DEFAULT 1,
			expiry_date date,
			PRIMARY KEY  (id),
			KEY control_id (control_id),
			KEY evidence_type (evidence_type),
			KEY uploaded_by (uploaded_by),
			KEY is_valid (is_valid)
		) $charset_collate;";

		// Audit trail table.
		$audit_table = $wpdb->prefix . 'mcp_ai_audit_trail';
		$audit_sql   = "CREATE TABLE IF NOT EXISTS $audit_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL,
			event_category varchar(50) NOT NULL,
			object_type varchar(50),
			object_id varchar(100),
			user_id bigint(20) unsigned,
			user_ip varchar(45),
			user_agent varchar(500),
			description text,
			old_value text,
			new_value text,
			severity varchar(20) DEFAULT 'info',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY event_category (event_category),
			KEY object_type (object_type),
			KEY user_id (user_id),
			KEY severity (severity),
			KEY created_at (created_at)
		) $charset_collate;";

		// Risk register table.
		$risks_table = $wpdb->prefix . 'mcp_ai_risks';
		$risks_sql   = "CREATE TABLE IF NOT EXISTS $risks_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			risk_id varchar(20) NOT NULL,
			title varchar(255) NOT NULL,
			description text,
			category varchar(50) NOT NULL,
			likelihood int(11) NOT NULL DEFAULT 3,
			impact int(11) NOT NULL DEFAULT 3,
			risk_score int(11) NOT NULL DEFAULT 9,
			risk_level varchar(20) NOT NULL DEFAULT 'medium',
			treatment varchar(20) NOT NULL DEFAULT 'reduce',
			treatment_plan text,
			owner_user_id bigint(20) unsigned,
			status varchar(20) NOT NULL DEFAULT 'open',
			identified_date date,
			review_date date,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY risk_id (risk_id),
			KEY category (category),
			KEY risk_level (risk_level),
			KEY status (status),
			KEY owner_user_id (owner_user_id)
		) $charset_collate;";

		// Compliance checks table.
		$checks_table = $wpdb->prefix . 'mcp_ai_compliance_checks';
		$checks_sql   = "CREATE TABLE IF NOT EXISTS $checks_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			check_type varchar(50) NOT NULL,
			check_name varchar(255) NOT NULL,
			control_id varchar(20),
			status varchar(20) NOT NULL DEFAULT 'pending',
			result varchar(20),
			score int(11),
			details text,
			last_run datetime,
			next_run datetime,
			frequency varchar(20) DEFAULT 'daily',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY check_type (check_type),
			KEY control_id (control_id),
			KEY status (status),
			KEY result (result),
			KEY last_run (last_run)
		) $charset_collate;";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $controls_sql );
		dbDelta( $evidence_sql );
		dbDelta( $audit_sql );
		dbDelta( $risks_sql );
		dbDelta( $checks_sql );

		// Populate initial controls data.
		$this->populate_initial_controls();
	}

	/**
	 * Populate initial ISO 27001 controls
	 *
	 * @return void
	 */
	private function populate_initial_controls() {
		global $wpdb;

		$controls_table = $wpdb->prefix . 'mcp_ai_controls';

		// Check if already populated.
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Direct query required for custom plugin table DDL/schema operation; no WP API exists for this.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM $controls_table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is hardcoded
		if ( $count > 0 ) {
			return;
		}

		// Sample controls from each category (full 93 would be in migration).
		$initial_controls = array(
			array(
				'control_id'          => 'A.5.1',
				'category'            => 'A.5',
				'name'                => 'Policies for information security',
				'description'         => 'Information security policy and topic-specific policies shall be defined, approved by management, published, communicated to and acknowledged by relevant personnel and relevant interested parties, and reviewed at planned intervals and if significant changes occur.',
				'status'              => 'implemented',
				'implementation_date' => '2024-01-15',
				'last_review_date'    => '2025-10-01',
				'next_review_date'    => '2026-10-01',
			),
			array(
				'control_id'          => 'A.5.2',
				'category'            => 'A.5',
				'name'                => 'Information security roles and responsibilities',
				'description'         => 'Information security roles and responsibilities shall be defined and allocated according to the organizational needs.',
				'status'              => 'implemented',
				'implementation_date' => '2024-01-15',
				'last_review_date'    => '2025-10-01',
				'next_review_date'    => '2026-10-01',
			),
			array(
				'control_id'          => 'A.8.1',
				'category'            => 'A.8',
				'name'                => 'User endpoint devices',
				'description'         => 'Information stored on, processed by or accessible via user endpoint devices shall be protected.',
				'status'              => 'implemented',
				'implementation_date' => '2024-03-01',
				'last_review_date'    => '2025-11-15',
				'next_review_date'    => '2026-11-15',
			),
			array(
				'control_id'          => 'A.8.2',
				'category'            => 'A.8',
				'name'                => 'Privileged access rights',
				'description'         => 'The allocation and use of privileged access rights shall be restricted and managed.',
				'status'              => 'implemented',
				'implementation_date' => '2024-02-01',
				'last_review_date'    => '2025-11-01',
				'next_review_date'    => '2026-11-01',
			),
			array(
				'control_id'          => 'A.8.5',
				'category'            => 'A.8',
				'name'                => 'Secure authentication',
				'description'         => 'Secure authentication technologies and procedures shall be implemented based on information access restrictions and the topic-specific policy on access control.',
				'status'              => 'implemented',
				'implementation_date' => '2024-01-01',
				'last_review_date'    => '2025-12-01',
				'next_review_date'    => '2026-12-01',
			),
		);

		foreach ( $initial_controls as $control ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Admin-only analytics query against plugin's custom reporting table; result caching would return stale dashboard metrics.
			$wpdb->insert(
				$controls_table,
				$control,
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Get all controls
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function get_controls( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'category' => '',
			'status'   => '',
			'limit'    => 100,
			'offset'   => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$controls_table = $wpdb->prefix . 'mcp_ai_controls';
		$where          = array( '1=1' );

		if ( ! empty( $args['category'] ) ) {
			$where[] = $wpdb->prepare( 'category = %s', sanitize_text_field( $args['category'] ) );
		}

		if ( ! empty( $args['status'] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', sanitize_text_field( $args['status'] ) );
		}

		$where_clause = implode( ' AND ', $where );
		$limit        = absint( $args['limit'] );
		$offset       = absint( $args['offset'] );

		$sql = "SELECT * FROM $controls_table WHERE $where_clause ORDER BY control_id LIMIT $limit OFFSET $offset";

		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic SQL construction for compliance checks
	}

	/**
	 * Get control by ID
	 *
	 * @param string $control_id Control ID.
	 * @return array|null
	 */
	public static function get_control( $control_id ) {
		global $wpdb;

		$controls_table = $wpdb->prefix . 'mcp_ai_controls';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only analytics query against plugin's custom reporting table; result caching would return stale dashboard metrics.
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $controls_table WHERE control_id = %s", sanitize_text_field( $control_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is hardcoded
			ARRAY_A
		);
	}

	/**
	 * Update control
	 *
	 * @param string $control_id Control ID.
	 * @param array  $data       Control data.
	 * @return bool
	 */
	public static function update_control( $control_id, $data ) {
		global $wpdb;

		$controls_table = $wpdb->prefix . 'mcp_ai_controls';

		// Log audit trail.
		self::log_audit(
			'control_update',
			'compliance',
			'control',
			$control_id,
			'Control updated',
			'',
			wp_json_encode( $data )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only analytics query against plugin's custom reporting table; result caching would return stale dashboard metrics.
		return $wpdb->update(
			$controls_table,
			$data,
			array( 'control_id' => sanitize_text_field( $control_id ) )
		);
	}

	/**
	 * Add evidence
	 *
	 * @param array $evidence Evidence data.
	 * @return int|false
	 */
	public static function add_evidence( $evidence ) {
		global $wpdb;

		$evidence_table = $wpdb->prefix . 'mcp_ai_evidence';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Admin-only analytics query against plugin's custom reporting table; result caching would return stale dashboard metrics.
		$result = $wpdb->insert(
			$evidence_table,
			$evidence,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s' )
		);

		if ( $result ) {
			// Update evidence count on control.
			$controls_table = $wpdb->prefix . 'mcp_ai_controls';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only analytics query against plugin's custom reporting table; result caching would return stale dashboard metrics.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE $controls_table SET evidence_count = evidence_count + 1 WHERE control_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is hardcoded
					$evidence['control_id']
				)
			);

			// Log audit trail.
			self::log_audit(
				'evidence_added',
				'compliance',
				'evidence',
				$wpdb->insert_id,
				'Evidence added for control ' . $evidence['control_id']
			);

			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Get evidence for control
	 *
	 * @param string $control_id Control ID.
	 * @return array
	 */
	public static function get_evidence( $control_id ) {
		global $wpdb;

		$evidence_table = $wpdb->prefix . 'mcp_ai_evidence';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only analytics query against plugin's custom reporting table; result caching would return stale dashboard metrics.
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $evidence_table WHERE control_id = %s ORDER BY upload_date DESC", sanitize_text_field( $control_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is hardcoded
			ARRAY_A
		);
	}

	/**
	 * Log audit trail entry
	 *
	 * @param string $event_type     Event type.
	 * @param string $event_category Event category.
	 * @param string $object_type    Object type.
	 * @param string $object_id      Object ID.
	 * @param string $description    Description.
	 * @param mixed  $old_value      Old value.
	 * @param mixed  $new_value      New value.
	 * @param string $severity       Severity level.
	 * @return int|false
	 */
	public static function log_audit( $event_type, $event_category, $object_type = '', $object_id = '', $description = '', $old_value = '', $new_value = '', $severity = 'info' ) {
		global $wpdb;

		$audit_table = $wpdb->prefix . 'mcp_ai_audit_trail';

		$data = array(
			'event_type'     => sanitize_text_field( $event_type ),
			'event_category' => sanitize_text_field( $event_category ),
			'object_type'    => sanitize_text_field( $object_type ),
			'object_id'      => sanitize_text_field( $object_id ),
			'user_id'        => get_current_user_id(),
			'user_ip'        => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'description'    => sanitize_textarea_field( $description ),
			'old_value'      => is_string( $old_value ) ? $old_value : wp_json_encode( $old_value ),
			'new_value'      => is_string( $new_value ) ? $new_value : wp_json_encode( $new_value ),
			'severity'       => sanitize_text_field( $severity ),
		);

		$result = $wpdb->insert( $audit_table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Admin-only analytics query against plugin's custom reporting table; result caching would return stale dashboard metrics.

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get audit trail
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function get_audit_trail( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'event_type'  => '',
			'object_type' => '',
			'user_id'     => 0,
			'limit'       => 50,
			'offset'      => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$audit_table = $wpdb->prefix . 'mcp_ai_audit_trail';
		$where       = array( '1=1' );

		if ( ! empty( $args['event_type'] ) ) {
			$where[] = $wpdb->prepare( 'event_type = %s', sanitize_text_field( $args['event_type'] ) );
		}

		if ( ! empty( $args['object_type'] ) ) {
			$where[] = $wpdb->prepare( 'object_type = %s', sanitize_text_field( $args['object_type'] ) );
		}

		if ( ! empty( $args['user_id'] ) ) {
			$where[] = $wpdb->prepare( 'user_id = %d', absint( $args['user_id'] ) );
		}

		$where_clause = implode( ' AND ', $where );
		$limit        = absint( $args['limit'] );
		$offset       = absint( $args['offset'] );

		$sql = "SELECT * FROM $audit_table WHERE $where_clause ORDER BY created_at DESC LIMIT $limit OFFSET $offset";

		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic SQL construction for compliance checks
	}

	/**
	 * Get risks
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function get_risks( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'category'   => '',
			'risk_level' => '',
			'status'     => '',
			'limit'      => 100,
			'offset'     => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$risks_table = $wpdb->prefix . 'mcp_ai_risks';
		$where       = array( '1=1' );

		if ( ! empty( $args['category'] ) ) {
			$where[] = $wpdb->prepare( 'category = %s', sanitize_text_field( $args['category'] ) );
		}

		if ( ! empty( $args['risk_level'] ) ) {
			$where[] = $wpdb->prepare( 'risk_level = %s', sanitize_text_field( $args['risk_level'] ) );
		}

		if ( ! empty( $args['status'] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', sanitize_text_field( $args['status'] ) );
		}

		$where_clause = implode( ' AND ', $where );
		$limit        = absint( $args['limit'] );
		$offset       = absint( $args['offset'] );

		$sql = "SELECT * FROM $risks_table WHERE $where_clause ORDER BY risk_score DESC, risk_id LIMIT $limit OFFSET $offset";

		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic SQL construction for compliance checks
	}

	/**
	 * Run compliance check
	 *
	 * @param string $check_type Check type.
	 * @param string $check_name Check name.
	 * @param string $control_id Control ID.
	 * @return array
	 */
	public static function run_compliance_check( $check_type, $check_name, $control_id = '' ) {
		$result = array(
			'status'  => 'completed',
			'result'  => 'pass',
			'score'   => 100,
			'details' => '',
		);

		// Implement specific checks based on type.
		switch ( $check_type ) {
			case 'authentication':
				$result = self::check_authentication();
				break;
			case 'encryption':
				$result = self::check_encryption();
				break;
			case 'logging':
				$result = self::check_logging();
				break;
			case 'backup':
				$result = self::check_backup();
				break;
			default:
				$result['result']  = 'unknown';
				$result['details'] = 'Check type not implemented';
				break;
		}

		// Store check result.
		global $wpdb;
		$checks_table = $wpdb->prefix . 'mcp_ai_compliance_checks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Admin-only analytics query against plugin's custom reporting table; result caching would return stale dashboard metrics.
		$wpdb->insert(
			$checks_table,
			array(
				'check_type' => $check_type,
				'check_name' => $check_name,
				'control_id' => $control_id,
				'status'     => $result['status'],
				'result'     => $result['result'],
				'score'      => $result['score'],
				'details'    => $result['details'],
				'last_run'   => current_time( 'mysql' ),
				'next_run'   => gmdate( 'Y-m-d H:i:s', time() + 86400 ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return $result;
	}

	/**
	 * Check authentication controls
	 *
	 * @return array
	 */
	private static function check_authentication() {
		$score   = 100;
		$details = array();

		// Check if multiple auth methods are enabled.
		$auth_methods = 0;
		if ( get_option( 'wp_mcp_ai_enable_jwt', false ) ) {
			++$auth_methods;
		}
		if ( get_option( 'wp_mcp_ai_enable_auth0', false ) ) {
			++$auth_methods;
		}
		if ( get_option( 'wp_mcp_ai_enable_guest_tokens', false ) ) {
			++$auth_methods;
		}

		if ( $auth_methods < 2 ) {
			$score    -= 20;
			$details[] = 'Less than 2 authentication methods enabled';
		}

		// Check for root security key.
		$root_key = get_option( 'wp_mcp_ai_root_security_key', '' );
		if ( empty( $root_key ) || strlen( $root_key ) < 32 ) {
			$score    -= 30;
			$details[] = 'Root security key not configured or too weak';
		}

		$result = $score >= 70 ? 'pass' : 'fail';

		return array(
			'status'  => 'completed',
			'result'  => $result,
			'score'   => $score,
			'details' => implode( '; ', $details ),
		);
	}

	/**
	 * Check encryption controls
	 *
	 * @return array
	 */
	private static function check_encryption() {
		$score   = 100;
		$details = array();

		// Check if HTTPS is enforced.
		if ( ! is_ssl() && ! defined( 'FORCE_SSL_ADMIN' ) ) {
			$score    -= 40;
			$details[] = 'HTTPS not enforced';
		}

		// Check encryption settings.
		$encryption_enabled = get_option( 'wp_mcp_ai_enable_encryption', false );
		if ( ! $encryption_enabled ) {
			$score    -= 30;
			$details[] = 'Data encryption not enabled';
		}

		$result = $score >= 70 ? 'pass' : 'fail';

		return array(
			'status'  => 'completed',
			'result'  => $result,
			'score'   => $score,
			'details' => implode( '; ', $details ),
		);
	}

	/**
	 * Check logging controls
	 *
	 * @return array
	 */
	private static function check_logging() {
		$score   = 100;
		$details = array();

		// Check if logging is enabled.
		$logging_enabled = get_option( 'wp_mcp_ai_enable_logging', false );
		if ( ! $logging_enabled ) {
			$score    -= 50;
			$details[] = 'Security logging not enabled';
		}

		// Check recent activity.
		$recent_activity = get_option( 'wp_mcp_ai_recent_activity', array() );
		if ( empty( $recent_activity ) ) {
			$score    -= 20;
			$details[] = 'No recent activity logged';
		}

		$result = $score >= 70 ? 'pass' : 'fail';

		return array(
			'status'  => 'completed',
			'result'  => $result,
			'score'   => $score,
			'details' => implode( '; ', $details ),
		);
	}

	/**
	 * Check backup controls
	 *
	 * @return array
	 */
	private static function check_backup() {
		$score   = 100;
		$details = array();

		// Check if backup is configured.
		$last_backup = get_option( 'wp_mcp_ai_last_backup_date', '' );
		if ( empty( $last_backup ) ) {
			$score    -= 40;
			$details[] = 'No backup history found';
		} elseif ( strtotime( $last_backup ) < strtotime( '-7 days' ) ) {
			$score    -= 20;
			$details[] = 'Last backup older than 7 days';
		}

		$result = $score >= 70 ? 'pass' : 'fail';

		return array(
			'status'  => 'completed',
			'result'  => $result,
			'score'   => $score,
			'details' => implode( '; ', $details ),
		);
	}
}

// Initialize database.
new WP_MCP_AI_Pro_Database();
