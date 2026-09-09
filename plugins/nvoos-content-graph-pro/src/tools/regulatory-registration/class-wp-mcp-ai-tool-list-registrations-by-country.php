<?php
/**
 * WP_MCP_AI_Tool_List_Registrations_By_Country (ecosystem port - Wave F2, regulatory-registration tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/regulatory-registration/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
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
		return __( 'List Registrations by Country', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists registrations grouped by country with statistics. Provides country-specific registration overview including status distribution and expiry tracking.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country'         => array(
					'type'        => 'string',
					'description' => __( 'Country code to filter by (optional, if not provided returns all countries)', 'nvoos-content-graph-pro' ),
				),
				'include_stats'   => array(
					'type'        => 'boolean',
					'description' => __( 'Include statistics for each country (optional, default: true)', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'group_by_status' => array(
					'type'        => 'boolean',
					'description' => __( 'Group registrations by status within each country (optional, default: false)', 'nvoos-content-graph-pro' ),
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
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Build query arguments.
		$query_args = array(
			'post_type'      => 'mcp_ai_registration',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'list_registrations_by_country', 0, 1000 ) : 1000,
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
				$country         = get_post_meta( $post->ID, 'country', true );
				$authority       = get_post_meta( $post->ID, 'authority', true );
				$product_id      = get_post_meta( $post->ID, 'product_id', true );
				$cos_number      = get_post_meta( $post->ID, 'cos_number', true );
				$expiry_date     = get_post_meta( $post->ID, 'expiry_date', true );
				$submission_date = get_post_meta( $post->ID, 'submission_date', true );
				$approval_date   = get_post_meta( $post->ID, 'approval_date', true );

				// Get status. The taxonomy may be unregistered (e.g. when the
				// toolkit module is gated), in which case wp_get_object_terms()
				// returns a WP_Error — treat it as "Unknown".
				$status_terms = wp_get_object_terms( $post->ID, 'mcp_ai_reg_status', array( 'fields' => 'names' ) );
				$status       = ( ! is_wp_error( $status_terms ) && ! empty( $status_terms ) ) ? $status_terms[0] : 'Unknown';

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
			'success'             => true,
			'countries'           => $countries_array,
			'total_countries'     => count( $countries_array ),
			'total_registrations' => $query->found_posts,
			'message'             => sprintf(
				/* translators: 1: number of registrations, 2: number of countries */
				__( 'Found %1$d registration(s) across %2$d country/countries.', 'nvoos-content-graph-pro' ),
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
			'total'               => count( $registrations ),
			'approved'            => 0,
			'pending'             => 0,
			'expired'             => 0,
			'expiring_soon'       => 0,
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
