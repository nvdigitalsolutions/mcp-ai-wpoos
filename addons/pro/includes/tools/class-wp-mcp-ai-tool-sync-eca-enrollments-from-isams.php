<?php
/**
 * Tool for syncing ECA enrollments from iSAMS.
 *
 * Allows AI assistants to sync student enrollment data for ECAs from iSAMS
 * School Management System into WordPress.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Syncs student enrollment data for ECAs from iSAMS into WordPress.
 */
class WP_MCP_AI_Tool_Sync_ECA_Enrollments_From_ISAMS implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'sync_eca_enrollments_from_isams';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Sync ECA Enrollments from iSAMS', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Syncs student enrollment data for ECAs from iSAMS School Management System. Imports enrollment records, student assignments, and payment status from iSAMS into WordPress ECA records.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional iSAMS connection ID. If not provided, will use settings-based configuration.', 'mcp-ai-wpoos-pro' ),
				),
				'eca_id'        => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of a specific ECA to sync enrollments for. If omitted, syncs enrollments for all ECAs with an iSAMS sync ID.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'sync_mode'     => array(
					'type'        => 'string',
					'description' => __( 'Sync mode: "full" re-imports all enrollments, "incremental" only imports new or changed records.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'full', 'incremental' ),
					'default'     => 'incremental',
				),
				'dry_run'       => array(
					'type'        => 'boolean',
					'description' => __( 'When true, simulates the sync without writing any data.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'education',
			'post_type'             => 'mcp_ai_eca',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'school_admin', 'it_admin' ),
			'risk_level'            => 'elevated',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'external-api' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// ECA enrollment sync is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if iSAMS is configured.
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['isams_api_url'] ) || empty( $settings['isams_api_key'] ) ) {
			return false;
		}

		// Check if ECA management is enabled.
		return ! empty( $settings['enable_eca_management'] );
	}

	/**
	 * Get unavailable reason message.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['isams_api_url'] ) || empty( $settings['isams_api_key'] ) ) {
			return __( 'iSAMS API credentials are not configured.', 'mcp-ai-wpoos-pro' );
		}

		if ( empty( $settings['enable_eca_management'] ) ) {
			return __( 'ECA Management must be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'ECA enrollment sync tool is only available in the Pro version.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to sync ECA enrollments from iSAMS.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get iSAMS connection settings.
		$settings  = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : get_option( 'wp_mcp_ai_settings', array() );
		$isams_url = isset( $settings['isams_api_url'] ) ? esc_url_raw( $settings['isams_api_url'] ) : '';
		$isams_key = isset( $settings['isams_api_key'] ) ? $settings['isams_api_key'] : '';

		if ( empty( $isams_url ) || empty( $isams_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_isams_not_configured',
				__( 'iSAMS API credentials are not configured. Please set isams_api_url and isams_api_key in plugin settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate connection if provided.
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';

		if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( null === $connection ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_not_found',
					__( 'Connection not found. Please check the connection ID.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( empty( $connection['connection_type'] ) || 'isams' !== $connection['connection_type'] ) {
				return new WP_Error(
					'wp_mcp_ai_pro_wrong_connection_type',
					__( 'This connection is not an iSAMS connection.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( empty( $connection['enabled'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_disabled',
					__( 'This connection is disabled. Please enable it in Remote Sites settings.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		$sync_mode = isset( $arguments['sync_mode'] ) ? sanitize_key( $arguments['sync_mode'] ) : 'incremental';
		$dry_run   = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : false;
		$eca_id    = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;

		// Validate sync_mode.
		$valid_modes = array( 'full', 'incremental' );
		if ( ! in_array( $sync_mode, $valid_modes, true ) ) {
			$sync_mode = 'incremental';
		}

		// Build the iSAMS API endpoint for co-curricular enrollment data.
		$api_endpoint = trailingslashit( $isams_url ) . 'api/cocurricular/enrollments';

		// If a specific ECA is requested, get its iSAMS sync ID and filter the query.
		$isams_activity_id = '';
		if ( $eca_id ) {
			$eca_post = get_post( $eca_id );
			if ( ! $eca_post || 'mcp_ai_eca' !== $eca_post->post_type ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_eca',
					__( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' )
				);
			}

			$isams_activity_id = get_post_meta( $eca_id, '_eca_isams_sync_id', true );
			if ( empty( $isams_activity_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_no_isams_id',
					__( 'This ECA does not have an iSAMS sync ID.', 'mcp-ai-wpoos-pro' )
				);
			}

			$api_endpoint = add_query_arg( 'activityId', rawurlencode( $isams_activity_id ), $api_endpoint );
		}

		// Fetch enrollment data from iSAMS.
		$response = wp_remote_get(
			$api_endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $isams_key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_isams_request_failed',
				sprintf(
					/* translators: %s: Error message from the HTTP request */
					__( 'Failed to connect to iSAMS API: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_isams_api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'iSAMS API returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
					$status_code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'wp_mcp_ai_isams_invalid_response',
				__( 'Invalid JSON response from iSAMS API.', 'mcp-ai-wpoos-pro' )
			);
		}

		$enrollments = isset( $data['enrollments'] ) ? $data['enrollments'] : $data;
		if ( ! is_array( $enrollments ) ) {
			$enrollments = array();
		}

		// Process enrollment records.
		$synced_count  = 0;
		$skipped_count = 0;
		$errors        = array();

		foreach ( $enrollments as $enrollment ) {
			$result = $this->process_enrollment( $enrollment, $sync_mode, $dry_run );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'enrollment_id' => isset( $enrollment['id'] ) ? sanitize_text_field( $enrollment['id'] ) : 'unknown',
					'error'         => $result->get_error_message(),
				);
				continue;
			}

			if ( 'skipped' === $result['action'] ) {
				++$skipped_count;
			} else {
				++$synced_count;
			}
		}

		return array(
			'success'       => true,
			'synced_count'  => $synced_count,
			'skipped_count' => $skipped_count,
			'errors'        => $errors,
			'dry_run'       => $dry_run,
			'message'       => $dry_run
				? sprintf(
					/* translators: 1: Number of enrollments that would be synced, 2: Number skipped */
					__( 'Dry run complete. %1$d enrollments would be synced, %2$d skipped.', 'mcp-ai-wpoos-pro' ),
					$synced_count,
					$skipped_count
				)
				: sprintf(
					/* translators: 1: Number of enrollments synced, 2: Number skipped */
					__( '%1$d enrollments synced, %2$d skipped.', 'mcp-ai-wpoos-pro' ),
					$synced_count,
					$skipped_count
				),
		);
	}

	/**
	 * Process a single enrollment record from iSAMS.
	 *
	 * @param array  $enrollment Enrollment data from iSAMS.
	 * @param string $sync_mode  Sync mode (full or incremental).
	 * @param bool   $dry_run    Whether this is a dry run.
	 * @return array|WP_Error Processing result or error.
	 */
	private function process_enrollment( $enrollment, $sync_mode, $dry_run ) {
		// Extract iSAMS identifiers.
		$isams_student_id  = isset( $enrollment['studentId'] ) ? sanitize_text_field( $enrollment['studentId'] ) : '';
		$isams_activity_id = isset( $enrollment['activityId'] ) ? sanitize_text_field( $enrollment['activityId'] ) : '';

		if ( empty( $isams_student_id ) || empty( $isams_activity_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_enrollment_ids',
				__( 'Enrollment record is missing studentId or activityId.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Find matching student in WordPress by iSAMS ID.
		$student_post_id = $this->find_post_by_meta( 'mcp_ai_student', '_student_isams_id', $isams_student_id );
		if ( ! $student_post_id ) {
			return new WP_Error(
				'wp_mcp_ai_student_not_found',
				sprintf(
					/* translators: %s: iSAMS student ID */
					__( 'No WordPress student found for iSAMS student ID: %s', 'mcp-ai-wpoos-pro' ),
					$isams_student_id
				)
			);
		}

		// Find matching ECA in WordPress by iSAMS sync ID.
		$eca_post_id = $this->find_post_by_meta( 'mcp_ai_eca', '_eca_isams_sync_id', $isams_activity_id );
		if ( ! $eca_post_id ) {
			return new WP_Error(
				'wp_mcp_ai_eca_not_found',
				sprintf(
					/* translators: %s: iSAMS activity ID */
					__( 'No WordPress ECA found for iSAMS activity ID: %s', 'mcp-ai-wpoos-pro' ),
					$isams_activity_id
				)
			);
		}

		// Check if enrollment already exists.
		$existing_enrollments = get_post_meta( $eca_post_id, '_student_eca_enrollments', true );
		if ( ! is_array( $existing_enrollments ) ) {
			$existing_enrollments = array();
		}

		$already_enrolled = false;
		foreach ( $existing_enrollments as $existing ) {
			if ( isset( $existing['student_id'] ) && absint( $existing['student_id'] ) === $student_post_id ) {
				$already_enrolled = true;
				break;
			}
		}

		// In incremental mode, skip already enrolled students.
		if ( 'incremental' === $sync_mode && $already_enrolled ) {
			return array(
				'action'     => 'skipped',
				'student_id' => $student_post_id,
				'eca_id'     => $eca_post_id,
				'reason'     => 'already_enrolled',
			);
		}

		// Build enrollment record.
		$payment_status = isset( $enrollment['paymentStatus'] ) ? sanitize_key( $enrollment['paymentStatus'] ) : 'pending';
		$valid_payments = array( 'pending', 'paid', 'partial', 'waived' );
		if ( ! in_array( $payment_status, $valid_payments, true ) ) {
			$payment_status = 'pending';
		}

		$enrollment_record = array(
			'student_id'       => $student_post_id,
			'isams_student_id' => $isams_student_id,
			'enrollment_type'  => isset( $enrollment['type'] ) ? sanitize_key( $enrollment['type'] ) : 'confirmed',
			'payment_status'   => $payment_status,
			'enrolled_date'    => isset( $enrollment['enrolledDate'] ) ? sanitize_text_field( $enrollment['enrolledDate'] ) : current_time( 'mysql' ),
			'synced_from'      => 'isams',
			'synced_at'        => current_time( 'mysql' ),
		);

		if ( $dry_run ) {
			return array(
				'action'     => $already_enrolled ? 'would_update' : 'would_create',
				'student_id' => $student_post_id,
				'eca_id'     => $eca_post_id,
				'enrollment' => $enrollment_record,
			);
		}

		// Write enrollment to ECA meta.
		if ( $already_enrolled ) {
			// Update existing enrollment record.
			foreach ( $existing_enrollments as $key => $existing ) {
				if ( isset( $existing['student_id'] ) && absint( $existing['student_id'] ) === $student_post_id ) {
					$existing_enrollments[ $key ] = $enrollment_record;
					break;
				}
			}
		} else {
			$existing_enrollments[] = $enrollment_record;
		}

		update_post_meta( $eca_post_id, '_student_eca_enrollments', $existing_enrollments );

		// Also store enrollment on the student record.
		$student_ecas = get_post_meta( $student_post_id, '_student_eca_enrollments', true );
		if ( ! is_array( $student_ecas ) ) {
			$student_ecas = array();
		}

		$student_eca_exists = false;
		foreach ( $student_ecas as $key => $entry ) {
			if ( isset( $entry['eca_id'] ) && absint( $entry['eca_id'] ) === $eca_post_id ) {
				$student_ecas[ $key ] = array(
					'eca_id'          => $eca_post_id,
					'enrollment_type' => $enrollment_record['enrollment_type'],
					'payment_status'  => $enrollment_record['payment_status'],
					'enrolled_date'   => $enrollment_record['enrolled_date'],
					'synced_from'     => 'isams',
					'synced_at'       => current_time( 'mysql' ),
				);
				$student_eca_exists   = true;
				break;
			}
		}

		if ( ! $student_eca_exists ) {
			$student_ecas[] = array(
				'eca_id'          => $eca_post_id,
				'enrollment_type' => $enrollment_record['enrollment_type'],
				'payment_status'  => $enrollment_record['payment_status'],
				'enrolled_date'   => $enrollment_record['enrolled_date'],
				'synced_from'     => 'isams',
				'synced_at'       => current_time( 'mysql' ),
			);
		}

		update_post_meta( $student_post_id, '_student_eca_enrollments', $student_ecas );

		return array(
			'action'     => $already_enrolled ? 'updated' : 'created',
			'student_id' => $student_post_id,
			'eca_id'     => $eca_post_id,
		);
	}

	/**
	 * Find a post by meta value.
	 *
	 * @param string $post_type  Post type to search.
	 * @param string $meta_key   Meta key to match.
	 * @param string $meta_value Meta value to match.
	 * @return int|null Post ID if found, null otherwise.
	 */
	private function find_post_by_meta( $post_type, $meta_key, $meta_value ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'meta_query'     => array(
					array(
						'key'     => $meta_key,
						'value'   => $meta_value,
						'compare' => '=',
					),
				),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return $query->have_posts() ? $query->posts[0] : null;
	}
}
