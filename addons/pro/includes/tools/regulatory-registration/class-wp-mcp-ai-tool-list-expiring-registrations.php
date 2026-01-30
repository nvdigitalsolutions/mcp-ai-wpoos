<?php
/**
 * Tool for listing expiring registrations in the Regulatory Registration system.
 *
 * Allows AI assistants to get registrations that are expiring soon.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists expiring registrations.
 */
class WP_MCP_AI_Tool_List_Expiring_Registrations implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_expiring_registrations';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Expiring Registrations', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Gets a list of registrations that are expiring soon or have already expired. Critical for proactive renewal management.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'warning_days'     => array(
					'type'        => 'integer',
					'description' => __( 'Days ahead to include (optional, default: 90)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 365,
					'default'     => 90,
				),
				'country'          => array(
					'type'        => 'string',
					'description' => __( 'Filter by country (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'include_expired'  => array(
					'type'        => 'boolean',
					'description' => __( 'Include already expired registrations (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
			'cacheable',            // Results can be cached.
			'idempotent',           // Can be called multiple times safely with same result.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view registrations.', 'mcp-ai-wpoos-pro' ) );
		}

		$warning_days = isset( $arguments['warning_days'] ) ? absint( $arguments['warning_days'] ) : 90;
		$include_expired = isset( $arguments['include_expired'] ) ? (bool) $arguments['include_expired'] : true;
		$today = time();
		$warning_threshold = $today + ( $warning_days * DAY_IN_SECONDS );

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_registration',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'     => 'expiry_date',
					'value'   => '',
					'compare' => '!=',
				),
			),
		);

		// Filter by country if specified.
		if ( ! empty( $arguments['country'] ) ) {
			$query_args['meta_query'][] = array(
				'key'   => 'country',
				'value' => sanitize_text_field( $arguments['country'] ),
			);
		}

		$query = new WP_Query( $query_args );

		$expired = array();
		$expiring_soon = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$expiry_date = get_post_meta( $post->ID, 'expiry_date', true );
				if ( ! $expiry_date ) {
					continue;
				}

				$expiry = strtotime( $expiry_date );
				$days_to_expiry = floor( ( $expiry - $today ) / DAY_IN_SECONDS );

				// Skip if expired and we're not including expired.
				if ( ! $include_expired && $expiry < $today ) {
					continue;
				}

				// Skip if not within warning period and not expired.
				if ( $expiry >= $warning_threshold ) {
					continue;
				}

				$reg_data = array(
					'id'              => $post->ID,
					'title'           => $post->post_title,
					'product_id'      => absint( get_post_meta( $post->ID, 'product_id', true ) ),
					'country'         => get_post_meta( $post->ID, 'country', true ),
					'authority'       => get_post_meta( $post->ID, 'authority', true ),
					'cos_number'      => get_post_meta( $post->ID, 'cos_number', true ),
					'expiry_date'     => $expiry_date,
					'days_to_expiry'  => $days_to_expiry,
					'is_expired'      => $expiry < $today,
				);

				// Get status.
				$statuses = wp_get_post_terms( $post->ID, 'mcp_ai_reg_status' );
				if ( ! empty( $statuses ) && ! is_wp_error( $statuses ) ) {
					$reg_data['status'] = $statuses[0]->name;
				}

				// Get product name.
				if ( $reg_data['product_id'] ) {
					$product = get_post( $reg_data['product_id'] );
					if ( $product ) {
						$reg_data['product_name'] = $product->post_title;
					}
				}

				if ( $expiry < $today ) {
					$expired[] = $reg_data;
				} else {
					$expiring_soon[] = $reg_data;
				}
			}
		}

		// Sort by days to expiry (most urgent first).
		usort( $expired, function( $a, $b ) {
			return $b['days_to_expiry'] - $a['days_to_expiry'];
		});

		usort( $expiring_soon, function( $a, $b ) {
			return $a['days_to_expiry'] - $b['days_to_expiry'];
		});

		return array(
			'success'           => true,
			'expired_count'     => count( $expired ),
			'expiring_soon_count' => count( $expiring_soon ),
			'expired'           => $expired,
			'expiring_soon'     => $expiring_soon,
			'warning_days'      => $warning_days,
			'summary'           => sprintf(
				/* translators: 1: expired count, 2: expiring soon count, 3: warning days */
				__( '%1$d expired registrations, %2$d expiring within %3$d days', 'mcp-ai-wpoos-pro' ),
				count( $expired ),
				count( $expiring_soon ),
				$warning_days
			),
		);
	}
}
