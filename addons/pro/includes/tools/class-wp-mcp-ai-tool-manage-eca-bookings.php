<?php
/**
 * Tool for managing ECA bookings.
 *
 * Allows AI assistants to create, update, and manage student bookings for ECAs.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages ECA bookings for students.
 */
class WP_MCP_AI_Tool_Manage_ECA_Bookings implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_eca_bookings';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage ECA Bookings', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Manages student bookings for Extra-Curricular Activities. Supports creating bookings, updating preferences, and managing allocations for SOCS integration.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'               => array(
					'type'        => 'string',
					'description' => __( 'Action to perform (required)', 'wp-mcp-ai' ),
					'enum'        => array( 'create', 'update', 'list', 'allocate', 'cancel' ),
				),
				'booking_id'           => array(
					'type'        => 'integer',
					'description' => __( 'Booking ID (required for update/cancel actions)', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'student_name'         => array(
					'type'        => 'string',
					'description' => __( 'Student name (required for create)', 'wp-mcp-ai' ),
					'maxLength'   => 200,
				),
				'student_email'        => array(
					'type'        => 'string',
					'description' => __( 'Student/parent email (required for create)', 'wp-mcp-ai' ),
					'format'      => 'email',
					'maxLength'   => 200,
				),
				'student_year'         => array(
					'type'        => 'string',
					'description' => __( 'Student year group (e.g., "Year 7") (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 50,
				),
				'eca_id'               => array(
					'type'        => 'integer',
					'description' => __( 'ECA ID to book (required for create)', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'preference_order'     => array(
					'type'        => 'integer',
					'description' => __( 'Preference order (1 = first choice) (optional)', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 10,
				),
				'status'               => array(
					'type'        => 'string',
					'description' => __( 'Booking status (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'pending', 'confirmed', 'waitlist', 'cancelled' ),
				),
				'isams_student_id'     => array(
					'type'        => 'string',
					'description' => __( 'iSAMS student identifier (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 100,
				),
				'filter_eca_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Filter bookings by ECA ID (for list action)', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'filter_status'        => array(
					'type'        => 'string',
					'description' => __( 'Filter bookings by status (for list action)', 'wp-mcp-ai' ),
					'enum'        => array( 'pending', 'confirmed', 'waitlist', 'cancelled' ),
				),
				'filter_student_email' => array(
					'type'        => 'string',
					'description' => __( 'Filter bookings by student email (for list action)', 'wp-mcp-ai' ),
					'format'      => 'email',
				),
				'limit'                => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of bookings to return (for list action)', 'wp-mcp-ai' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'database-write' );
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
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_eca_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';

		if ( ! $action ) {
			return new WP_Error( 'wp_mcp_ai_missing_action', __( 'Action is required.', 'wp-mcp-ai' ) );
		}

		switch ( $action ) {
			case 'create':
				return $this->create_booking( $arguments, $context );
			case 'update':
				return $this->update_booking( $arguments, $context );
			case 'list':
				return $this->list_bookings( $arguments, $context );
			case 'allocate':
				return $this->allocate_booking( $arguments, $context );
			case 'cancel':
				return $this->cancel_booking( $arguments, $context );
			default:
				return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid action.', 'wp-mcp-ai' ) );
		}
	}

	/**
	 * Create a new booking.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	private function create_booking( array $arguments, array $context ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create bookings.', 'wp-mcp-ai' ) );
		}

		$student_name  = isset( $arguments['student_name'] ) ? sanitize_text_field( $arguments['student_name'] ) : '';
		$student_email = isset( $arguments['student_email'] ) ? sanitize_email( $arguments['student_email'] ) : '';
		$eca_id        = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;

		if ( ! $student_name || ! $student_email || ! $eca_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_fields', __( 'Student name, email, and ECA ID are required.', 'wp-mcp-ai' ) );
		}

		// Verify ECA exists.
		$eca = get_post( $eca_id );
		if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
			return new WP_Error( 'wp_mcp_ai_eca_not_found', __( 'ECA not found.', 'wp-mcp-ai' ) );
		}

		// Create booking post.
		$post_data = array(
			'post_type'   => 'mcp_ai_eca_booking',
			'post_title'  => sprintf( '%s - %s', $student_name, $eca->post_title ),
			'post_status' => 'publish',
			'post_author' => $current_user_id,
		);

		$booking_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $booking_id ) ) {
			return $booking_id;
		}

		// Save booking metadata.
		update_post_meta( $booking_id, '_booking_student_name', $student_name );
		update_post_meta( $booking_id, '_booking_student_email', $student_email );
		update_post_meta( $booking_id, '_booking_eca_id', $eca_id );
		update_post_meta( $booking_id, '_booking_status', 'pending' );

		if ( isset( $arguments['student_year'] ) ) {
			update_post_meta( $booking_id, '_booking_student_year', sanitize_text_field( $arguments['student_year'] ) );
		}

		if ( isset( $arguments['preference_order'] ) ) {
			update_post_meta( $booking_id, '_booking_preference_order', absint( $arguments['preference_order'] ) );
		}

		if ( isset( $arguments['isams_student_id'] ) ) {
			update_post_meta( $booking_id, '_booking_isams_student_id', sanitize_text_field( $arguments['isams_student_id'] ) );
		}

		return array(
			'success'    => true,
			'message'    => __( 'Booking created successfully.', 'wp-mcp-ai' ),
			'booking_id' => $booking_id,
			'booking'    => $this->get_booking_data( $booking_id ),
		);
	}

	/**
	 * Update an existing booking.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	private function update_booking( array $arguments, array $context ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update bookings.', 'wp-mcp-ai' ) );
		}

		$booking_id = isset( $arguments['booking_id'] ) ? absint( $arguments['booking_id'] ) : 0;

		if ( ! $booking_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Booking ID is required.', 'wp-mcp-ai' ) );
		}

		$post = get_post( $booking_id );
		if ( ! $post || 'mcp_ai_eca_booking' !== $post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Booking not found.', 'wp-mcp-ai' ) );
		}

		// Update metadata if provided.
		if ( isset( $arguments['status'] ) ) {
			$status         = sanitize_key( $arguments['status'] );
			$valid_statuses = array( 'pending', 'confirmed', 'waitlist', 'cancelled' );
			if ( in_array( $status, $valid_statuses, true ) ) {
				update_post_meta( $booking_id, '_booking_status', $status );
			}
		}

		if ( isset( $arguments['preference_order'] ) ) {
			update_post_meta( $booking_id, '_booking_preference_order', absint( $arguments['preference_order'] ) );
		}

		return array(
			'success'    => true,
			'message'    => __( 'Booking updated successfully.', 'wp-mcp-ai' ),
			'booking_id' => $booking_id,
			'booking'    => $this->get_booking_data( $booking_id ),
		);
	}

	/**
	 * List bookings.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	private function list_bookings( array $arguments, array $context ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list bookings.', 'wp-mcp-ai' ) );
		}

		$query_args = array(
			'post_type'      => 'mcp_ai_eca_booking',
			'post_status'    => 'publish',
			'posts_per_page' => isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 200 ) : 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$meta_query = array();

		if ( isset( $arguments['filter_eca_id'] ) ) {
			$meta_query[] = array(
				'key'     => '_booking_eca_id',
				'value'   => absint( $arguments['filter_eca_id'] ),
				'compare' => '=',
			);
		}

		if ( isset( $arguments['filter_status'] ) ) {
			$meta_query[] = array(
				'key'     => '_booking_status',
				'value'   => sanitize_key( $arguments['filter_status'] ),
				'compare' => '=',
			);
		}

		if ( isset( $arguments['filter_student_email'] ) ) {
			$meta_query[] = array(
				'key'     => '_booking_student_email',
				'value'   => sanitize_email( $arguments['filter_student_email'] ),
				'compare' => '=',
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$query    = new WP_Query( $query_args );
		$bookings = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$bookings[] = $this->get_booking_data( get_the_ID() );
			}
			wp_reset_postdata();
		}

		return array(
			'success'  => true,
			'count'    => count( $bookings ),
			'total'    => $query->found_posts,
			'bookings' => $bookings,
		);
	}

	/**
	 * Allocate a booking (confirm it).
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	private function allocate_booking( array $arguments, array $context ) {
		$arguments['status'] = 'confirmed';
		return $this->update_booking( $arguments, $context );
	}

	/**
	 * Cancel a booking.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	private function cancel_booking( array $arguments, array $context ) {
		$arguments['status'] = 'cancelled';
		return $this->update_booking( $arguments, $context );
	}

	/**
	 * Get booking data.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array
	 */
	private function get_booking_data( $booking_id ) {
		$eca_id = absint( get_post_meta( $booking_id, '_booking_eca_id', true ) );
		$eca    = $eca_id ? get_post( $eca_id ) : null;

		return array(
			'id'               => $booking_id,
			'student_name'     => get_post_meta( $booking_id, '_booking_student_name', true ),
			'student_email'    => get_post_meta( $booking_id, '_booking_student_email', true ),
			'student_year'     => get_post_meta( $booking_id, '_booking_student_year', true ) ? get_post_meta( $booking_id, '_booking_student_year', true ) : '',
			'eca_id'           => $eca_id,
			'eca_name'         => $eca ? $eca->post_title : '',
			'preference_order' => absint( get_post_meta( $booking_id, '_booking_preference_order', true ) ),
			'status'           => get_post_meta( $booking_id, '_booking_status', true ) ? get_post_meta( $booking_id, '_booking_status', true ) : 'pending',
			'isams_student_id' => get_post_meta( $booking_id, '_booking_isams_student_id', true ) ? get_post_meta( $booking_id, '_booking_isams_student_id', true ) : '',
			'created_at'       => get_the_date( 'c', $booking_id ),
		);
	}
}
