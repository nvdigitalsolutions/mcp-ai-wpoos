<?php
/**
 * Tool for syncing ECAs to iSAMS.
 *
 * Allows AI assistants to push ECA data from WordPress back to iSAMS
 * School Management System.
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
 * Pushes ECA data from WordPress back to iSAMS School Management System.
 */
class WP_MCP_AI_Tool_Sync_ECAs_To_ISAMS implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'sync_ecas_to_isams';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Sync ECAs to iSAMS', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Pushes ECA data from WordPress back to iSAMS School Management System. Updates or creates activities in iSAMS to reflect changes made in the WordPress ECA system.', 'mcp-ai-wpoos-pro' );
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
				'eca_ids'       => array(
					'type'        => 'array',
					'description' => __( 'Array of WordPress ECA post IDs to push to iSAMS. If omitted, use sync_all to push all ECAs.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'sync_all'      => array(
					'type'        => 'boolean',
					'description' => __( 'When true, syncs all ECAs that have an iSAMS sync ID. Ignored when eca_ids is provided.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'dry_run'       => array(
					'type'        => 'boolean',
					'description' => __( 'When true, simulates the sync without sending data to iSAMS.', 'mcp-ai-wpoos-pro' ),
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
		// ECA sync to iSAMS is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if iSAMS is configured.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
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
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['isams_api_url'] ) || empty( $settings['isams_api_key'] ) ) {
			return __( 'iSAMS API credentials are not configured.', 'mcp-ai-wpoos-pro' );
		}

		if ( empty( $settings['enable_eca_management'] ) ) {
			return __( 'ECA Management must be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'ECA sync to iSAMS tool is only available in the Pro version.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to sync ECAs to iSAMS.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get iSAMS connection settings.
		$settings  = get_option( 'wp_mcp_ai_settings', array() );
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

		$dry_run  = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : false;
		$sync_all = isset( $arguments['sync_all'] ) ? (bool) $arguments['sync_all'] : false;

		// Determine which ECAs to sync.
		$eca_post_ids = $this->resolve_eca_ids( $arguments, $sync_all );
		if ( is_wp_error( $eca_post_ids ) ) {
			return $eca_post_ids;
		}

		if ( empty( $eca_post_ids ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_ecas_to_sync',
				__( 'No ECAs found to sync. Provide eca_ids or set sync_all to true.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Push each ECA to iSAMS.
		$pushed_count  = 0;
		$created_count = 0;
		$updated_count = 0;
		$errors        = array();

		foreach ( $eca_post_ids as $eca_post_id ) {
			$result = $this->push_eca_to_isams( $eca_post_id, $isams_url, $isams_key, $dry_run );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'eca_id' => $eca_post_id,
					'name'   => get_the_title( $eca_post_id ),
					'error'  => $result->get_error_message(),
				);
				continue;
			}

			++$pushed_count;
			if ( 'created' === $result['action'] ) {
				++$created_count;
			} else {
				++$updated_count;
			}
		}

		return array(
			'success'       => true,
			'pushed_count'  => $pushed_count,
			'created_count' => $created_count,
			'updated_count' => $updated_count,
			'errors'        => $errors,
			'dry_run'       => $dry_run,
			'message'       => $dry_run
				? sprintf(
					/* translators: 1: Number of ECAs that would be pushed, 2: created, 3: updated */
					__( 'Dry run complete. %1$d ECAs would be pushed (%2$d created, %3$d updated).', 'mcp-ai-wpoos-pro' ),
					$pushed_count,
					$created_count,
					$updated_count
				)
				: sprintf(
					/* translators: 1: Number of ECAs pushed, 2: created, 3: updated */
					__( '%1$d ECAs pushed to iSAMS (%2$d created, %3$d updated).', 'mcp-ai-wpoos-pro' ),
					$pushed_count,
					$created_count,
					$updated_count
				),
		);
	}

	/**
	 * Resolve the list of ECA post IDs to sync.
	 *
	 * @param array $arguments Tool arguments.
	 * @param bool  $sync_all  Whether to sync all ECAs with an iSAMS sync ID.
	 * @return array|WP_Error Array of post IDs or error.
	 */
	private function resolve_eca_ids( $arguments, $sync_all ) {
		// If explicit IDs provided, validate and use them.
		if ( ! empty( $arguments['eca_ids'] ) && is_array( $arguments['eca_ids'] ) ) {
			$eca_ids = array_map( 'absint', $arguments['eca_ids'] );
			$eca_ids = array_filter( $eca_ids );

			// Validate each post exists and is an ECA.
			foreach ( $eca_ids as $eca_id ) {
				$post = get_post( $eca_id );
				if ( ! $post || 'mcp_ai_eca' !== $post->post_type ) {
					return new WP_Error(
						'wp_mcp_ai_invalid_eca',
						sprintf(
							/* translators: %d: Post ID */
							__( 'Invalid ECA post ID: %d', 'mcp-ai-wpoos-pro' ),
							$eca_id
						)
					);
				}
			}

			return $eca_ids;
		}

		// If sync_all, query all ECAs that have an iSAMS sync ID.
		if ( $sync_all ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_eca',
					'post_status'    => 'publish',
					'meta_query'     => array(
						array(
							'key'     => '_eca_isams_sync_id',
							'compare' => 'EXISTS',
						),
					),
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			return $query->posts;
		}

		return array();
	}

	/**
	 * Push a single ECA to iSAMS.
	 *
	 * @param int    $eca_post_id WordPress ECA post ID.
	 * @param string $isams_url   ISAMS API base URL.
	 * @param string $isams_key   ISAMS API key.
	 * @param bool   $dry_run     Whether this is a dry run.
	 * @return array|WP_Error Push result or error.
	 */
	private function push_eca_to_isams( $eca_post_id, $isams_url, $isams_key, $dry_run ) {
		$eca_post = get_post( $eca_post_id );
		if ( ! $eca_post ) {
			return new WP_Error(
				'wp_mcp_ai_eca_not_found',
				__( 'ECA post not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Gather all ECA meta data.
		$eca_name      = get_the_title( $eca_post_id );
		$eca_code      = get_post_meta( $eca_post_id, '_eca_code', true );
		$eca_type      = get_post_meta( $eca_post_id, '_eca_type', true );
		$eca_day       = get_post_meta( $eca_post_id, '_eca_day', true );
		$start_time    = get_post_meta( $eca_post_id, '_eca_start_time', true );
		$end_time      = get_post_meta( $eca_post_id, '_eca_end_time', true );
		$venue         = get_post_meta( $eca_post_id, '_eca_venue', true );
		$capacity      = get_post_meta( $eca_post_id, '_eca_max_students', true );
		$teachers      = get_post_meta( $eca_post_id, '_eca_teachers', true );
		$status        = get_post_meta( $eca_post_id, '_eca_status', true );
		$isams_sync_id = get_post_meta( $eca_post_id, '_eca_isams_sync_id', true );

		// Build the iSAMS API payload.
		$payload = array(
			'name'        => $eca_name,
			'code'        => $eca_code ? $eca_code : '',
			'type'        => $eca_type ? $eca_type : 'club',
			'day'         => $eca_day ? $eca_day : '',
			'start_time'  => $start_time ? $start_time : '',
			'end_time'    => $end_time ? $end_time : '',
			'venue'       => $venue ? $venue : '',
			'capacity'    => $capacity ? absint( $capacity ) : 0,
			'teachers'    => is_array( $teachers ) ? $teachers : array(),
			'status'      => $status ? $status : 'active',
			'description' => $eca_post->post_content,
		);

		// Determine whether to create or update.
		$is_update = ! empty( $isams_sync_id );

		if ( $dry_run ) {
			return array(
				'action'  => $is_update ? 'would_update' : 'would_create',
				'eca_id'  => $eca_post_id,
				'name'    => $eca_name,
				'payload' => $payload,
			);
		}

		// Build the API endpoint.
		$api_endpoint = trailingslashit( $isams_url ) . 'api/cocurricular/activities';

		if ( $is_update ) {
			// PUT to update an existing activity.
			$api_endpoint = trailingslashit( $api_endpoint ) . rawurlencode( $isams_sync_id );
			$method       = 'PUT';
		} else {
			$method = 'POST';
		}

		$response = wp_remote_request(
			$api_endpoint,
			array(
				'method'  => $method,
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $isams_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_isams_request_failed',
				sprintf(
					/* translators: %s: Error message from the HTTP request */
					__( 'Failed to push ECA to iSAMS: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_isams_api_error',
				sprintf(
					/* translators: 1: ECA name, 2: HTTP status code */
					__( 'iSAMS API returned HTTP %2$d for ECA "%1$s".', 'mcp-ai-wpoos-pro' ),
					$eca_name,
					$status_code
				)
			);
		}

		// If this was a new creation, store the returned iSAMS ID.
		if ( ! $is_update ) {
			$body          = wp_remote_retrieve_body( $response );
			$response_data = json_decode( $body, true );

			if ( is_array( $response_data ) && ! empty( $response_data['id'] ) ) {
				update_post_meta( $eca_post_id, '_eca_isams_sync_id', sanitize_text_field( $response_data['id'] ) );
			}
		}

		// Store sync timestamp.
		update_post_meta( $eca_post_id, '_eca_isams_last_sync', current_time( 'mysql' ) );

		return array(
			'action' => $is_update ? 'updated' : 'created',
			'eca_id' => $eca_post_id,
			'name'   => $eca_name,
		);
	}
}
