<?php
/**
 * Tool for listing registrations by country in the Regulatory Registration system.
 *
 * Allows AI assistants to filter and group registrations by country.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists registrations by country.
 */
class WP_MCP_AI_Tool_List_Registrations_By_Country implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_registrations_by_country';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Registrations by Country', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists registrations grouped by country with statistics. Provides country-specific registration overview including status distribution and expiry tracking.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country'          => array(
					'type'        => 'string',
					'description' => __( 'Country code to filter by (optional, if not provided returns all countries)', 'mcp-ai-wpoos-pro' ),
				),
				'include_stats'    => array(
					'type'        => 'boolean',
					'description' => __( 'Include statistics for each country (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'group_by_status'  => array(
					'type'        => 'boolean',
					'description' => __( 'Group registrations by status within each country (optional, default: false)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
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
	public function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( $arguments, $context = array() ) {
		// Build query arguments.
		$query_args = array(
			'post_type'      => 'mcp_ai_registration',
			'posts_per_page' => -1,
			'orderby'        => 'meta_value',
			'meta_key'       => 'country',
			'order'          => 'ASC',
		);

		// Filter by country if provided.
		if ( ! empty( $arguments['country'] ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => 'country',
					'value' => sanitize_text_field( $arguments['country'] ),
				),
			);
		}

		// Query registrations.
		$query = new WP_Query( $query_args );

		// Group registrations by country.
		$countries = array();
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$country = get_post_meta( $post->ID, 'country', true );
				$authority = get_post_meta( $post->ID, 'authority', true );
				$product_id = get_post_meta( $post->ID, 'product_id', true );
				$cos_number = get_post_meta( $post->ID, 'cos_number', true );
				$expiry_date = get_post_meta( $post->ID, 'expiry_date', true );
				$submission_date = get_post_meta( $post->ID, 'submission_date', true );
				$approval_date = get_post_meta( $post->ID, 'approval_date', true );

				// Get status.
				$status_terms = wp_get_object_terms( $post->ID, 'mcp_ai_reg_status', array( 'fields' => 'names' ) );
				$status = ! empty( $status_terms ) ? $status_terms[0] : 'Unknown';

				// Calculate expiry status.
				$expiry_status = 'valid';
				if ( ! empty( $expiry_date ) ) {
					$days_to_expiry = floor( ( strtotime( $expiry_date ) - time() ) / DAY_IN_SECONDS );
					if ( $days_to_expiry < 0 ) {
						$expiry_status = 'expired';
					} elseif ( $days_to_expiry <= 90 ) {
						$expiry_status = 'expiring_soon';
					}
				}

				$registration_data = array(
					'registration_id' => $post->ID,
					'title'           => $post->post_title,
					'product_id'      => $product_id,
					'authority'       => $authority,
					'status'          => $status,
					'cos_number'      => $cos_number,
					'submission_date' => $submission_date,
					'approval_date'   => $approval_date,
					'expiry_date'     => $expiry_date,
					'expiry_status'   => $expiry_status,
				);

				if ( ! isset( $countries[ $country ] ) ) {
					$countries[ $country ] = array(
						'country'       => $country,
						'registrations' => array(),
						'count'         => 0,
					);
				}

				if ( ! empty( $arguments['group_by_status'] ) ) {
					if ( ! isset( $countries[ $country ]['by_status'][ $status ] ) ) {
						$countries[ $country ]['by_status'][ $status ] = array();
					}
					$countries[ $country ]['by_status'][ $status ][] = $registration_data;
				} else {
					$countries[ $country ]['registrations'][] = $registration_data;
				}

				++$countries[ $country ]['count'];
			}
		}

		// Add statistics if requested.
		if ( ! empty( $arguments['include_stats'] ) ) {
			foreach ( $countries as $country => &$country_data ) {
				$country_data['stats'] = $this->calculate_country_stats( $country_data['registrations'] ?? array() );
			}
		}

		// Convert to indexed array.
		$countries_array = array_values( $countries );

		return array(
			'success'        => true,
			'countries'      => $countries_array,
			'total_countries' => count( $countries_array ),
			'total_registrations' => $query->found_posts,
			'message'        => sprintf(
				/* translators: 1: number of registrations, 2: number of countries */
				__( 'Found %1$d registration(s) across %2$d country/countries.', 'mcp-ai-wpoos-pro' ),
				$query->found_posts,
				count( $countries_array )
			),
		);
	}

	/**
	 * Calculate statistics for a country.
	 *
	 * @param array $registrations Registrations array.
	 * @return array Statistics.
	 */
	private function calculate_country_stats( $registrations ) {
		$stats = array(
			'total'              => count( $registrations ),
			'approved'           => 0,
			'pending'            => 0,
			'expired'            => 0,
			'expiring_soon'      => 0,
			'status_distribution' => array(),
		);

		foreach ( $registrations as $registration ) {
			// Count by status.
			$status = $registration['status'];
			if ( ! isset( $stats['status_distribution'][ $status ] ) ) {
				$stats['status_distribution'][ $status ] = 0;
			}
			++$stats['status_distribution'][ $status ];

			// Count approved.
			if ( in_array( strtolower( $status ), array( 'approved', 'active' ), true ) ) {
				++$stats['approved'];
			}

			// Count pending (draft, submitted, under review).
			if ( in_array( strtolower( $status ), array( 'draft', 'submitted', 'under review', 'pending' ), true ) ) {
				++$stats['pending'];
			}

			// Count expiry status.
			if ( 'expired' === $registration['expiry_status'] ) {
				++$stats['expired'];
			} elseif ( 'expiring_soon' === $registration['expiry_status'] ) {
				++$stats['expiring_soon'];
			}
		}

		return $stats;
	}
}
