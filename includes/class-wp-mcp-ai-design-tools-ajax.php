<?php
/**
 * AJAX handlers for design professional tools.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles AJAX requests for design tools including status checks and downloads.
 */
class WP_MCP_AI_Design_Tools_AJAX {

	/**
	 * Transient prefix for design tool jobs.
	 */
	const TRANSIENT_PREFIX = 'wp_mcp_ai_design_job_';

	/**
	 * Transient expiration time (24 hours).
	 */
	const TRANSIENT_EXPIRATION = DAY_IN_SECONDS;

	/**
	 * Initialize AJAX hooks.
	 */
	public static function init() {
		// AJAX endpoints for authenticated users.
		add_action( 'wp_ajax_wp_mcp_ai_check_design_job_status', array( __CLASS__, 'check_job_status' ) );
		add_action( 'wp_ajax_wp_mcp_ai_download_cad', array( __CLASS__, 'download_cad_drawing' ) );
		add_action( 'wp_ajax_wp_mcp_ai_download_rendering', array( __CLASS__, 'download_rendering' ) );
		add_action( 'wp_ajax_wp_mcp_ai_download_3d_model', array( __CLASS__, 'download_3d_model' ) );
		add_action( 'wp_ajax_wp_mcp_ai_download_logo', array( __CLASS__, 'download_logo' ) );
		add_action( 'wp_ajax_wp_mcp_ai_download_vector', array( __CLASS__, 'download_vector' ) );
		add_action( 'wp_ajax_wp_mcp_ai_download_icon_set', array( __CLASS__, 'download_icon_set' ) );

		// AJAX endpoints for non-authenticated users with token.
		add_action( 'wp_ajax_nopriv_wp_mcp_ai_check_design_job_status', array( __CLASS__, 'check_job_status_nopriv' ) );
	}

	/**
	 * Store design job data in transient.
	 *
	 * @param string $job_id   Unique job identifier.
	 * @param array  $job_data Job data to store.
	 * @return bool Whether the transient was set successfully.
	 */
	public static function store_job_data( $job_id, $job_data ) {
		$key = self::TRANSIENT_PREFIX . sanitize_key( $job_id );

		WP_MCP_AI_Logger::log_event(
			'design_job_stored',
			'Design job data stored in transient',
			array(
				'job_id'     => $job_id,
				'job_type'   => isset( $job_data['type'] ) ? $job_data['type'] : 'unknown',
				'expiration' => self::TRANSIENT_EXPIRATION,
			)
		);

		return set_transient( $key, $job_data, self::TRANSIENT_EXPIRATION );
	}

	/**
	 * Retrieve design job data from transient.
	 *
	 * @param string $job_id Unique job identifier.
	 * @return array|false Job data or false if not found.
	 */
	public static function get_job_data( $job_id ) {
		$key = self::TRANSIENT_PREFIX . sanitize_key( $job_id );
		return get_transient( $key );
	}

	/**
	 * Update design job status.
	 *
	 * @param string $job_id Unique job identifier.
	 * @param string $status New status (processing, completed, failed).
	 * @param array  $data   Additional data to merge.
	 * @return bool Whether the update was successful.
	 */
	public static function update_job_status( $job_id, $status, $data = array() ) {
		$job_data = self::get_job_data( $job_id );

		if ( false === $job_data ) {
			return false;
		}

		$job_data['status']     = sanitize_key( $status );
		$job_data['updated_at'] = current_time( 'mysql' );

		if ( ! empty( $data ) && is_array( $data ) ) {
			$job_data = array_merge( $job_data, $data );
		}

		WP_MCP_AI_Logger::log_event(
			'design_job_updated',
			'Design job status updated',
			array(
				'job_id' => $job_id,
				'status' => $status,
			)
		);

		return self::store_job_data( $job_id, $job_data );
	}

	/**
	 * Delete design job data from transient.
	 *
	 * @param string $job_id Unique job identifier.
	 * @return bool Whether the transient was deleted.
	 */
	public static function delete_job_data( $job_id ) {
		$key = self::TRANSIENT_PREFIX . sanitize_key( $job_id );

		WP_MCP_AI_Logger::log_event(
			'design_job_deleted',
			'Design job data deleted from transient',
			array( 'job_id' => $job_id )
		);

		return delete_transient( $key );
	}

	/**
	 * Check design job status via AJAX (authenticated).
	 */
	public static function check_job_status() {
		check_ajax_referer( 'wp_mcp_ai_design_job', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'wp-mcp-ai' ) ),
				403
			);
		}

		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';

		if ( empty( $job_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Job ID is required.', 'wp-mcp-ai' ) ),
				400
			);
		}

		$job_data = self::get_job_data( $job_id );

		if ( false === $job_data ) {
			wp_send_json_error(
				array( 'message' => __( 'Job not found or expired.', 'wp-mcp-ai' ) ),
				404
			);
		}

		// Verify user has access to this job.
		if ( isset( $job_data['user_id'] ) && absint( $job_data['user_id'] ) !== $user_id ) {
			if ( ! user_can( $user_id, 'edit_others_posts' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Access denied to this job.', 'wp-mcp-ai' ) ),
					403
				);
			}
		}

		wp_send_json_success( $job_data );
	}

	/**
	 * Check design job status via AJAX (non-authenticated with token).
	 */
	public static function check_job_status_nopriv() {
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( empty( $token ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Authentication token is required.', 'wp-mcp-ai' ) ),
				401
			);
		}

		// Validate token using credential system.
		$credential = WP_MCP_AI_Credentials::validate_token( $token );

		if ( is_wp_error( $credential ) ) {
			wp_send_json_error(
				array( 'message' => $credential->get_error_message() ),
				401
			);
		}

		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';

		if ( empty( $job_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Job ID is required.', 'wp-mcp-ai' ) ),
				400
			);
		}

		$job_data = self::get_job_data( $job_id );

		if ( false === $job_data ) {
			wp_send_json_error(
				array( 'message' => __( 'Job not found or expired.', 'wp-mcp-ai' ) ),
				404
			);
		}

		wp_send_json_success( $job_data );
	}

	/**
	 * Handle CAD drawing download.
	 */
	public static function download_cad_drawing() {
		self::handle_download( 'cad', 'CAD drawing' );
	}

	/**
	 * Handle rendering download.
	 */
	public static function download_rendering() {
		self::handle_download( 'rendering', 'rendering' );
	}

	/**
	 * Handle 3D model download.
	 */
	public static function download_3d_model() {
		self::handle_download( '3d_model', '3D model' );
	}

	/**
	 * Handle logo download.
	 */
	public static function download_logo() {
		self::handle_download( 'logo', 'logo' );
	}

	/**
	 * Handle vector design download.
	 */
	public static function download_vector() {
		self::handle_download( 'vector', 'vector design' );
	}

	/**
	 * Handle icon set download.
	 */
	public static function download_icon_set() {
		self::handle_download( 'icon_set', 'icon set' );
	}

	/**
	 * Generic download handler for design files.
	 *
	 * @param string $type      File type identifier.
	 * @param string $type_name Human-readable type name.
	 */
	protected static function handle_download( $type, $type_name ) {
		$user_id = get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wp-mcp-ai' ), 403 );
		}

		$job_id = isset( $_GET[ $type . '_id' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $type . '_id' ] ) ) : '';
		$format = isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : '';

		if ( empty( $job_id ) ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: type name */
						__( '%s ID is required.', 'wp-mcp-ai' ),
						ucfirst( $type_name )
					)
				),
				400
			);
		}

		$job_data = self::get_job_data( $job_id );

		if ( false === $job_data ) {
			wp_die( esc_html__( 'File not found or expired.', 'wp-mcp-ai' ), 404 );
		}

		// Verify user has access.
		if ( isset( $job_data['user_id'] ) && absint( $job_data['user_id'] ) !== $user_id ) {
			if ( ! user_can( $user_id, 'edit_others_posts' ) ) {
				wp_die( esc_html__( 'Access denied to this file.', 'wp-mcp-ai' ), 403 );
			}
		}

		/**
		 * Filters the download file data before sending.
		 *
		 * @param array  $job_data Job data from transient.
		 * @param string $type     File type identifier.
		 * @param string $format   Requested format.
		 * @param int    $user_id  Current user ID.
		 */
		$file_data = apply_filters( 'wp_mcp_ai_design_download_data', $job_data, $type, $format, $user_id );

		WP_MCP_AI_Logger::log_event(
			'design_file_download',
			sprintf( '%s file downloaded', ucfirst( $type_name ) ),
			array(
				'job_id'  => $job_id,
				'type'    => $type,
				'format'  => $format,
				'user_id' => $user_id,
			)
		);

		// @todo Implement actual file streaming for production.
		// This is a placeholder that returns JSON. In production, this should:
		// 1. Generate or retrieve the actual file from storage
		// 2. Set appropriate Content-Type header for the format
		// 3. Stream the file content directly
		// 4. Handle large files efficiently with chunked transfer
		// See: https://github.com/nvdigitalsolutions/wp-mcp-ai/issues/XXX
		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $job_id . '.' . $format ) . '"' );

		echo wp_json_encode(
			array(
				'message' => sprintf(
					/* translators: 1: type name, 2: format */
					__( '%1$s in %2$s format - production implementation would stream actual file', 'wp-mcp-ai' ),
					ucfirst( $type_name ),
					strtoupper( $format )
				),
				'job_data' => $file_data,
			)
		);

		exit;
	}
}

// Initialize AJAX handlers.
add_action( 'init', array( 'WP_MCP_AI_Design_Tools_AJAX', 'init' ) );
