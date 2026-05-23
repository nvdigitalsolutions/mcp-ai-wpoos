<?php
/**
 * Tool for syncing ECAs from iSAMS/SOCS.
 *
 * Allows AI assistants to sync ECA data from iSAMS/SOCS School Management System.
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
 * Syncs Extra-Curricular Activities from iSAMS/SOCS into WordPress.
 */
class WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'sync_ecas_from_isams';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Sync ECAs from iSAMS/SOCS', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Syncs Extra-Curricular Activity (ECA) data from iSAMS/SOCS School Management System into WordPress. Can sync individual ECAs by ID or bulk sync all ECAs with pagination support.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id'   => array(
					'type'        => 'string',
					'description' => __( 'Optional Remote Sites connection ID for iSAMS. If not provided, will use settings-based configuration.', 'mcp-ai-wpoos-pro' ),
				),
				'sync_type'       => array(
					'type'        => 'string',
					'description' => __( 'Type of sync to perform', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'single', 'all' ),
					'default'     => 'all',
				),
				'eca_id'          => array(
					'type'        => 'string',
					'description' => __( 'iSAMS/SOCS ECA ID (required when sync_type is "single")', 'mcp-ai-wpoos-pro' ),
				),
				'page'            => array(
					'type'        => 'integer',
					'description' => __( 'Page number for bulk sync (optional, default: 1)', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
				'limit'           => array(
					'type'        => 'integer',
					'description' => __( 'Number of ECAs to sync per page (optional, default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'update_existing' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to update existing ECAs (default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'sync_type' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'external-api', 'database-write' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// ECA management is a Pro feature.
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

		return __( 'ECA sync tool is only available in the Pro version.', 'mcp-ai-wpoos-pro' );
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
				__( 'You do not have permission to sync ECAs from iSAMS.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get iSAMS tool instance.
		if ( ! class_exists( 'WP_MCP_AI_Tool_ISAMS_Query' ) ) {
			return new WP_Error(
				'wp_mcp_ai_isams_unavailable',
				__( 'iSAMS integration tool is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get connection_id if provided and pass it along.
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : null;

		// Validate connection if provided.
		if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( null === $connection ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_not_found',
					__( 'Connection not found. Please check the connection ID.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Validate connection type.
			if ( empty( $connection['connection_type'] ) || 'isams' !== $connection['connection_type'] ) {
				return new WP_Error(
					'wp_mcp_ai_pro_wrong_connection_type',
					__( 'This connection is not an iSAMS connection.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Check if connection is enabled.
			if ( empty( $connection['enabled'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_disabled',
					__( 'This connection is disabled. Please enable it in Remote Sites settings.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		$isams_tool = new WP_MCP_AI_Tool_ISAMS_Query();

		// Validate sync type.
		$sync_type        = isset( $arguments['sync_type'] ) ? sanitize_key( $arguments['sync_type'] ) : 'all';
		$valid_sync_types = array( 'single', 'all' );
		if ( ! in_array( $sync_type, $valid_sync_types, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_sync_type',
				__( 'Invalid sync type.', 'mcp-ai-wpoos-pro' )
			);
		}

		$update_existing = isset( $arguments['update_existing'] ) ? (bool) $arguments['update_existing'] : true;
		$page            = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$limit           = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 20;
		$limit           = min( $limit, 100 ); // Cap at 100.

		// Handle different sync types.
		switch ( $sync_type ) {
			case 'single':
				return $this->sync_single_eca( $isams_tool, $arguments, $context, $update_existing );

			case 'all':
				return $this->sync_all_ecas( $isams_tool, $arguments, $context, $page, $limit, $update_existing );

			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_sync_type',
					__( 'Invalid sync type.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Sync a single ECA by ID.
	 *
	 * @param WP_MCP_AI_Tool_ISAMS_Query $isams_tool      ISAMS tool instance.
	 * @param array                      $arguments       Tool arguments.
	 * @param array                      $context         Execution context.
	 * @param bool                       $update_existing Whether to update existing ECAs.
	 * @return array|WP_Error Sync results or error.
	 */
	private function sync_single_eca( $isams_tool, $arguments, $context, $update_existing ) {
		$eca_id = isset( $arguments['eca_id'] ) ? sanitize_text_field( $arguments['eca_id'] ) : '';

		if ( empty( $eca_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_eca_id',
				__( 'ECA ID is required for single sync.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Prepare query arguments, including connection_id if provided.
		$query_args = array(
			'endpoint' => 'activities', // This endpoint may need to be added to isams_query tool.
			'id'       => $eca_id,
		);

		// Pass connection_id if provided.
		if ( isset( $arguments['connection_id'] ) ) {
			$query_args['connection_id'] = $arguments['connection_id'];
		}

		// Fetch ECA from iSAMS - using a hypothetical 'activities' or 'ecas' endpoint.
		// Note: The actual endpoint name may vary based on iSAMS API documentation.
		$isams_result = $isams_tool->execute( $query_args, $context );

		if ( is_wp_error( $isams_result ) ) {
			return $isams_result;
		}

		if ( empty( $isams_result['data'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_eca_not_found',
				__( 'ECA not found in iSAMS.', 'mcp-ai-wpoos-pro' )
			);
		}

		$eca_data = $isams_result['data'];
		$result   = $this->create_or_update_eca( $eca_data, $update_existing, $context );

		return array(
			'success'     => true,
			'sync_type'   => 'single',
			'ecas_synced' => 1,
			'eca'         => $result,
			'message'     => __( 'ECA synced successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Sync all ECAs from iSAMS.
	 *
	 * @param WP_MCP_AI_Tool_ISAMS_Query $isams_tool      ISAMS tool instance.
	 * @param array                      $arguments       Tool arguments.
	 * @param array                      $context         Execution context.
	 * @param int                        $page            Page number.
	 * @param int                        $limit           ECAs per page.
	 * @param bool                       $update_existing Whether to update existing ECAs.
	 * @return array|WP_Error Sync results or error.
	 */
	private function sync_all_ecas( $isams_tool, $arguments, $context, $page, $limit, $update_existing ) {
		// Prepare query arguments, including connection_id if provided.
		$query_args = array(
			'endpoint' => 'activities', // This endpoint may need to be added to isams_query tool.
			'page'     => $page,
			'limit'    => $limit,
		);

		// Pass connection_id if provided.
		if ( isset( $arguments['connection_id'] ) ) {
			$query_args['connection_id'] = $arguments['connection_id'];
		}

		// Fetch ECAs from iSAMS.
		$isams_result = $isams_tool->execute( $query_args, $context );

		if ( is_wp_error( $isams_result ) ) {
			return $isams_result;
		}

		if ( empty( $isams_result['data'] ) ) {
			return array(
				'success'     => true,
				'sync_type'   => 'all',
				'ecas_synced' => 0,
				'page'        => $page,
				'message'     => __( 'No ECAs found to sync.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$ecas_data   = is_array( $isams_result['data'] ) ? $isams_result['data'] : array( $isams_result['data'] );
		$synced_ecas = array();
		$errors      = array();

		foreach ( $ecas_data as $eca_data ) {
			try {
				$result        = $this->create_or_update_eca( $eca_data, $update_existing, $context );
				$synced_ecas[] = $result;
			} catch ( Exception $e ) {
				$errors[] = array(
					'eca_name' => isset( $eca_data['name'] ) ? $eca_data['name'] : 'Unknown',
					'error'    => $e->getMessage(),
				);
			}
		}

		return array(
			'success'     => true,
			'sync_type'   => 'all',
			'ecas_synced' => count( $synced_ecas ),
			'page'        => $page,
			'ecas'        => $synced_ecas,
			'errors'      => $errors,
			'has_more'    => count( $ecas_data ) === $limit,
			'message'     => sprintf(
				/* translators: %d: Number of ECAs synced */
				__( '%d ECAs synced successfully.', 'mcp-ai-wpoos-pro' ),
				count( $synced_ecas )
			),
		);
	}

	/**
	 * Create or update an ECA from iSAMS data.
	 *
	 * @param array $eca_data        ECA data from iSAMS.
	 * @param bool  $update_existing Whether to update existing ECAs.
	 * @param array $context         Execution context.
	 * @return array ECA details.
	 * @throws Exception If ECA creation/update fails.
	 */
	private function create_or_update_eca( $eca_data, $update_existing, $context ) {
		// Extract iSAMS ID.
		$isams_id = isset( $eca_data['id'] ) ? sanitize_text_field( $eca_data['id'] ) : '';
		if ( empty( $isams_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new Exception( __( 'iSAMS ECA ID is missing.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check if ECA already exists by iSAMS sync ID.
		$existing_post_id = $this->find_eca_by_isams_id( $isams_id );

		if ( $existing_post_id && ! $update_existing ) {
			// ECA exists but we're not updating.
			return array(
				'eca_id'   => $existing_post_id,
				'action'   => 'skipped',
				'name'     => get_the_title( $existing_post_id ),
				'isams_id' => $isams_id,
			);
		}

		// Map iSAMS data to ECA fields.
		$eca_name = isset( $eca_data['name'] ) ? sanitize_text_field( $eca_data['name'] ) : '';
		if ( empty( $eca_name ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new Exception( __( 'ECA name is missing in iSAMS data.', 'mcp-ai-wpoos-pro' ) );
		}

		// Prepare ECA arguments for create_eca tool.
		$eca_args = array(
			'name'              => $eca_name,
			'description'       => isset( $eca_data['description'] ) ? wp_kses_post( $eca_data['description'] ) : '',
			'eca_code'          => isset( $eca_data['code'] ) ? sanitize_text_field( $eca_data['code'] ) : $isams_id,
			'eca_type'          => $this->map_eca_type( $eca_data ),
			'day'               => isset( $eca_data['day'] ) ? sanitize_text_field( $eca_data['day'] ) : '',
			'start_time'        => isset( $eca_data['start_time'] ) ? sanitize_text_field( $eca_data['start_time'] ) : '',
			'end_time'          => isset( $eca_data['end_time'] ) ? sanitize_text_field( $eca_data['end_time'] ) : '',
			'venue'             => isset( $eca_data['venue'] ) ? sanitize_text_field( $eca_data['venue'] ) : '',
			'max_students'      => isset( $eca_data['capacity'] ) ? absint( $eca_data['capacity'] ) : 0,
			'year_groups'       => $this->map_year_groups( $eca_data ),
			'teachers'          => $this->map_teachers( $eca_data ),
			'is_paid'           => isset( $eca_data['is_paid'] ) ? (bool) $eca_data['is_paid'] : false,
			'cost'              => isset( $eca_data['cost'] ) ? floatval( $eca_data['cost'] ) : 0,
			'cost_period'       => isset( $eca_data['cost_period'] ) ? sanitize_key( $eca_data['cost_period'] ) : 'term',
			'requires_audition' => isset( $eca_data['requires_audition'] ) ? (bool) $eca_data['requires_audition'] : false,
			'booking_type'      => $this->map_booking_type( $eca_data ),
			'status'            => $this->map_status( $eca_data ),
			'isams_sync_id'     => $isams_id,
		);

		if ( $existing_post_id ) {
			// Update existing ECA.
			$post_data = array(
				'ID'           => $existing_post_id,
				'post_title'   => $eca_name,
				'post_content' => $eca_args['description'],
				'post_status'  => 'publish',
			);

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new Exception( $result->get_error_message() );
			}

			$post_id = $existing_post_id;
			$action  = 'updated';
		} else {
			// Create new ECA.
			$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

			$post_data = array(
				'post_title'   => $eca_name,
				'post_content' => $eca_args['description'],
				'post_status'  => 'publish',
				'post_type'    => 'mcp_ai_eca',
				'post_author'  => $current_user_id,
			);

			$post_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $post_id ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new Exception( $post_id->get_error_message() );
			}

			$action = 'created';
		}

		// Update all meta fields.
		update_post_meta( $post_id, '_eca_code', $eca_args['eca_code'] );
		update_post_meta( $post_id, '_eca_type', $eca_args['eca_type'] );
		update_post_meta( $post_id, '_eca_day', $eca_args['day'] );
		update_post_meta( $post_id, '_eca_start_time', $eca_args['start_time'] );
		update_post_meta( $post_id, '_eca_end_time', $eca_args['end_time'] );
		update_post_meta( $post_id, '_eca_venue', $eca_args['venue'] );
		update_post_meta( $post_id, '_eca_max_students', $eca_args['max_students'] );
		update_post_meta( $post_id, '_eca_year_groups', $eca_args['year_groups'] );
		update_post_meta( $post_id, '_eca_teachers', $eca_args['teachers'] );
		update_post_meta( $post_id, '_eca_is_paid', $eca_args['is_paid'] ? 'yes' : 'no' );
		update_post_meta( $post_id, '_eca_cost', $eca_args['cost'] );
		update_post_meta( $post_id, '_eca_cost_period', $eca_args['cost_period'] );
		update_post_meta( $post_id, '_eca_requires_audition', $eca_args['requires_audition'] ? 'yes' : 'no' );
		update_post_meta( $post_id, '_eca_booking_type', $eca_args['booking_type'] );
		update_post_meta( $post_id, '_eca_status', $eca_args['status'] );
		update_post_meta( $post_id, '_eca_isams_sync_id', $isams_id );

		// Initialize enrollment count if new.
		if ( 'created' === $action ) {
			update_post_meta( $post_id, '_eca_current_enrollment', 0 );
		}

		// Mark as synced.
		update_post_meta( $post_id, '_eca_isams_synced', 'yes' );
		update_post_meta( $post_id, '_eca_isams_last_sync', current_time( 'mysql' ) );

		return array(
			'eca_id'   => $post_id,
			'action'   => $action,
			'name'     => $eca_name,
			'isams_id' => $isams_id,
			'url'      => get_permalink( $post_id ),
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
		);
	}

	/**
	 * Find ECA by iSAMS sync ID.
	 *
	 * @param string $isams_id ISAMS ID.
	 * @return int|null Post ID if found, null otherwise.
	 */
	private function find_eca_by_isams_id( $isams_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_eca',
				'post_status'    => 'any',
				'meta_key'       => '_eca_isams_sync_id',
				'meta_value'     => $isams_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return $query->have_posts() ? $query->posts[0] : null;
	}

	/**
	 * Map iSAMS ECA type to plugin ECA type.
	 *
	 * @param array $eca_data ECA data from iSAMS.
	 * @return string ECA type.
	 */
	private function map_eca_type( $eca_data ) {
		$type = isset( $eca_data['type'] ) ? strtolower( sanitize_key( $eca_data['type'] ) ) : '';

		// Map common type variations.
		$type_map = array(
			'club'          => 'club',
			'society'       => 'society',
			'sport'         => 'sport_squad',
			'sports'        => 'sport_squad',
			'sport_squad'   => 'sport_squad',
			'sport_academy' => 'sport_academy',
			'academy'       => 'sport_academy',
			'activity'      => 'activity',
		);

		return isset( $type_map[ $type ] ) ? $type_map[ $type ] : 'club';
	}

	/**
	 * Map iSAMS year groups to array.
	 *
	 * @param array $eca_data ECA data from iSAMS.
	 * @return array Year groups.
	 */
	private function map_year_groups( $eca_data ) {
		if ( isset( $eca_data['year_groups'] ) && is_array( $eca_data['year_groups'] ) ) {
			return array_map( 'sanitize_text_field', $eca_data['year_groups'] );
		}

		// Handle comma-separated string.
		if ( isset( $eca_data['year_groups'] ) && is_string( $eca_data['year_groups'] ) ) {
			return array_map( 'trim', explode( ',', $eca_data['year_groups'] ) );
		}

		return array();
	}

	/**
	 * Map iSAMS teachers to array.
	 *
	 * @param array $eca_data ECA data from iSAMS.
	 * @return array Teachers.
	 */
	private function map_teachers( $eca_data ) {
		if ( isset( $eca_data['teachers'] ) && is_array( $eca_data['teachers'] ) ) {
			return array_map( 'sanitize_text_field', $eca_data['teachers'] );
		}

		// Handle comma-separated string.
		if ( isset( $eca_data['teachers'] ) && is_string( $eca_data['teachers'] ) ) {
			return array_map( 'trim', explode( ',', $eca_data['teachers'] ) );
		}

		// Handle single teacher.
		if ( isset( $eca_data['teacher'] ) ) {
			return array( sanitize_text_field( $eca_data['teacher'] ) );
		}

		return array();
	}

	/**
	 * Map iSAMS booking type to plugin booking type.
	 *
	 * @param array $eca_data ECA data from iSAMS.
	 * @return string Booking type.
	 */
	private function map_booking_type( $eca_data ) {
		$booking_type = isset( $eca_data['booking_type'] ) ? strtolower( sanitize_key( $eca_data['booking_type'] ) ) : '';

		// Map SOCS booking types.
		$type_map = array(
			'first_come_first_served' => 'first_come_first_served',
			'preference_based'        => 'preference_based',
			'preselected'             => 'preselected',
			'signup'                  => 'signup',
			'preference'              => 'preference_based',
			'fcfs'                    => 'first_come_first_served',
		);

		return isset( $type_map[ $booking_type ] ) ? $type_map[ $booking_type ] : 'first_come_first_served';
	}

	/**
	 * Map iSAMS status to plugin status.
	 *
	 * @param array $eca_data ECA data from iSAMS.
	 * @return string Status.
	 */
	private function map_status( $eca_data ) {
		$status = isset( $eca_data['status'] ) ? strtolower( sanitize_key( $eca_data['status'] ) ) : '';

		// Map status variations.
		$status_map = array(
			'active'    => 'active',
			'inactive'  => 'inactive',
			'full'      => 'full',
			'cancelled' => 'cancelled',
			'open'      => 'active',
			'closed'    => 'inactive',
		);

		return isset( $status_map[ $status ] ) ? $status_map[ $status ] : 'active';
	}
}
