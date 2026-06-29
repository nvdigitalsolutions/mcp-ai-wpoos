<?php
/**
 * Tool for creating and updating bookable services.
 *
 * Fills the missing single-service CRUD gap in the Calendar Booking Toolkit.
 * Allows AI assistants to create, read, and update service records with
 * duration, pricing, buffer times, and optional place linking.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Calendar_Booking_Toolkit
 * @since     1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates or updates a bookable service (mcp_service CPT).
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Tool_Create_Service implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Service CPT post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_service';

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'Calendar Booking Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_service';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Service', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new bookable service or updates an existing one if service_id is provided. Includes duration, pricing, buffer time, category, and optional place linking for tours/activities.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'service_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Optional service ID. If provided, updates the existing service instead of creating a new one.', 'mcp-ai-wpoos-pro' ),
				),
				'name'                => array(
					'type'        => 'string',
					'description' => __( 'Service name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description'         => array(
					'type'        => 'string',
					'description' => __( 'Service description', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'duration_minutes'    => array(
					'type'        => 'integer',
					'description' => __( 'Service duration in minutes', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'price'               => array(
					'type'        => 'number',
					'description' => __( 'Service price', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'buffer_time_minutes' => array(
					'type'        => 'integer',
					'description' => __( 'Buffer time between appointments in minutes', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
					'minimum'     => 0,
				),
				'category'            => array(
					'type'        => 'string',
					'description' => __( 'Service category (auto-created if it does not exist)', 'mcp-ai-wpoos-pro' ),
				),
				'place_id'            => array(
					'type'        => 'integer',
					'description' => __( 'Linked place ID (e.g., the destination or attraction this service relates to)', 'mcp-ai-wpoos-pro' ),
				),
				'max_participants'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of participants per session', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'image_urls'          => array(
					'type'        => 'array',
					'description' => __( 'Image URLs to sideload as service images', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'   => 'string',
						'format' => 'uri',
					),
				),
				'tags'                => array(
					'type'        => 'array',
					'description' => __( 'Custom tags', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'name' ),
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
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'calendar-booking',
			'post_type'             => self::POST_TYPE,
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'travel_agent', 'business_owner', 'content_creator' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create services.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_toolkit_disabled', self::get_unavailable_reason() );
		}

		$name = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'wp_mcp_ai_missing_name', __( 'Service name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$service_id = isset( $arguments['service_id'] ) ? absint( $arguments['service_id'] ) : 0;
		$is_update  = false;

		if ( $service_id ) {
			$existing = get_post( $service_id );
			if ( ! $existing || self::POST_TYPE !== $existing->post_type ) {
				return new WP_Error( 'wp_mcp_ai_service_not_found', __( 'Service not found.', 'mcp-ai-wpoos-pro' ) );
			}

			$is_author       = absint( $existing->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );
			if ( ! $is_author && ! $can_edit_others ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this service.', 'mcp-ai-wpoos-pro' ) );
			}

			$is_update = true;
		}

		$description = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';

		$post_data = array(
			'post_type'    => self::POST_TYPE,
			'post_title'   => $name,
			'post_content' => $description,
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		);

		if ( $is_update ) {
			$post_data['ID'] = $service_id;
			$result          = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$service_id = $is_update ? $service_id : $result;

		// Save service meta.
		self::save_service_meta( $service_id, $arguments );

		// Sideload images.
		if ( ! empty( $arguments['image_urls'] ) ) {
			self::sideload_service_images( $service_id, $arguments['image_urls'] );
		}

		return array(
			'success'    => true,
			'message'    => $is_update
				? sprintf( /* translators: %s: service name */ __( 'Service updated: %s', 'mcp-ai-wpoos-pro' ), $name )
				: __( 'Service created successfully.', 'mcp-ai-wpoos-pro' ),
			'service_id' => $service_id,
			'service'    => array(
				'id'                  => $service_id,
				'name'                => $name,
				'description'         => $description,
				'duration_minutes'    => isset( $arguments['duration_minutes'] ) ? absint( $arguments['duration_minutes'] ) : null,
				'price'               => isset( $arguments['price'] ) ? floatval( $arguments['price'] ) : null,
				'buffer_time_minutes' => isset( $arguments['buffer_time_minutes'] ) ? absint( $arguments['buffer_time_minutes'] ) : 0,
				'place_id'            => isset( $arguments['place_id'] ) ? absint( $arguments['place_id'] ) : null,
			),
			'updated'    => $is_update,
		);
	}

	/**
	 * Save service meta fields.
	 *
	 * @param int   $service_id Service post ID.
	 * @param array $arguments  Tool arguments.
	 * @return void
	 */
	private static function save_service_meta( $service_id, array $arguments ) {
		if ( isset( $arguments['duration_minutes'] ) ) {
			update_post_meta( $service_id, '_service_duration', absint( $arguments['duration_minutes'] ) );
		}

		if ( isset( $arguments['price'] ) ) {
			update_post_meta( $service_id, '_service_price', floatval( $arguments['price'] ) );
		}

		if ( isset( $arguments['buffer_time_minutes'] ) ) {
			update_post_meta( $service_id, '_service_buffer_time', absint( $arguments['buffer_time_minutes'] ) );
		}

		if ( isset( $arguments['place_id'] ) ) {
			update_post_meta( $service_id, '_service_place_id', absint( $arguments['place_id'] ) );
		}

		if ( isset( $arguments['max_participants'] ) ) {
			update_post_meta( $service_id, '_service_max_participants', absint( $arguments['max_participants'] ) );
		}

		// Category taxonomy.
		if ( ! empty( $arguments['category'] ) ) {
			$category = sanitize_text_field( $arguments['category'] );
			if ( ! term_exists( $category, 'mcp_service_category' ) ) {
				wp_insert_term( $category, 'mcp_service_category' );
			}
			wp_set_object_terms( $service_id, $category, 'mcp_service_category', false );
		}

		// Source URL for import tracking.
		if ( ! empty( $arguments['source_url'] ) ) {
			update_post_meta( $service_id, '_service_source_url', esc_url_raw( $arguments['source_url'] ) );
		}
	}

	/**
	 * Sideload images for a service.
	 *
	 * @param int   $service_id Service post ID.
	 * @param array $urls       Image URLs.
	 * @return void
	 */
	private static function sideload_service_images( $service_id, array $urls ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_ids = array();

		foreach ( $urls as $index => $url ) {
			if ( empty( $url ) ) {
				continue;
			}

			$attachment_id = media_sideload_image( $url, $service_id, null, 'id' );

			if ( ! is_wp_error( $attachment_id ) ) {
				$attachment_ids[] = $attachment_id;

				// Set first image as featured.
				if ( 0 === $index ) {
					set_post_thumbnail( $service_id, $attachment_id );
				}
			}
		}

		if ( ! empty( $attachment_ids ) ) {
			update_post_meta( $service_id, '_service_gallery', $attachment_ids );
		}
	}
}
