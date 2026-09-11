<?php
/**
 * WP_MCP_AI_Tool_Get_Regulatory_Updates (ecosystem port - Wave F2, regulatory-registration tool batch).
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
 * Gets regulatory updates.
 */
class WP_MCP_AI_Tool_Get_Regulatory_Updates implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_regulatory_updates';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Regulatory Updates', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves recent regulatory updates, amendments, and guideline changes for specific countries or authorities. Helps stay informed about compliance changes.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country'     => array(
					'type'        => 'string',
					'description' => __( 'Country code to get updates for (optional, leave empty for all)', 'nvoos-content-graph-pro' ),
				),
				'authority'   => array(
					'type'        => 'string',
					'description' => __( 'Authority name to get updates for (optional)', 'nvoos-content-graph-pro' ),
				),
				'since_date'  => array(
					'type'        => 'string',
					'description' => __( 'Get updates since this date (YYYY-MM-DD, optional, default: 30 days ago)', 'nvoos-content-graph-pro' ),
				),
				'update_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of update: new_regulation, amendment, guideline, restriction, other (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'new_regulation', 'amendment', 'guideline', 'restriction', 'other' ),
				),
				'page'        => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination (optional, default: 1)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'default'     => 1,
				),
				'per_page'    => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (optional, default: 20, max: 100)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
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
		$page     = ! empty( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$per_page = ! empty( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;

		// Default to 30 days ago if not specified.
		$since_date = ! empty( $arguments['since_date'] )
			? sanitize_text_field( $arguments['since_date'] )
			: gmdate( 'Y-m-d', strtotime( '-30 days' ) );

		// Build meta query.
		$meta_query = array( 'relation' => 'AND' );

		if ( ! empty( $arguments['country'] ) ) {
			$meta_query[] = array(
				'key'   => 'country',
				'value' => sanitize_text_field( $arguments['country'] ),
			);
		}

		if ( ! empty( $arguments['authority'] ) ) {
			$meta_query[] = array(
				'key'   => 'authority',
				'value' => sanitize_text_field( $arguments['authority'] ),
			);
		}

		if ( ! empty( $arguments['update_type'] ) ) {
			$meta_query[] = array(
				'key'   => 'update_type',
				'value' => sanitize_text_field( $arguments['update_type'] ),
			);
		}

		// Query updates (stored as a custom post type).
		$query_args = array(
			'post_type'      => 'mcp_ai_reg_update',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'date_query'     => array(
				array(
					'after'     => $since_date,
					'inclusive' => true,
				),
			),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $meta_query ) && count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		$query = new WP_Query( $query_args );

		$updates = array();
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$updates[] = array(
					'update_id'      => $post->ID,
					'title'          => $post->post_title,
					'description'    => $post->post_content,
					'country'        => get_post_meta( $post->ID, 'country', true ),
					'authority'      => get_post_meta( $post->ID, 'authority', true ),
					'update_type'    => get_post_meta( $post->ID, 'update_type', true ),
					'effective_date' => get_post_meta( $post->ID, 'effective_date', true ),
					'reference_url'  => get_post_meta( $post->ID, 'reference_url', true ),
					'published_at'   => $post->post_date,
				);
			}
		}

		// If no updates found in database, provide sample recent updates.
		if ( empty( $updates ) ) {
			$updates = $this->get_sample_updates( $arguments );
		}

		return array(
			'success'    => true,
			'updates'    => $updates,
			'total'      => ! empty( $query->found_posts ) ? $query->found_posts : count( $updates ),
			'page'       => $page,
			'per_page'   => $per_page,
			'since_date' => $since_date,
			'message'    => sprintf(
				/* translators: %d: number of updates */
				__( 'Found %d regulatory update(s).', 'nvoos-content-graph-pro' ),
				count( $updates )
			),
		);
	}

	/**
	 * Get sample regulatory updates.
	 *
	 * @param array $arguments Filter arguments.
	 * @return array Sample updates.
	 */
	private function get_sample_updates( $arguments ) {
		$country = ! empty( $arguments['country'] ) ? strtoupper( $arguments['country'] ) : '';

		$all_updates = array(
			array(
				'title'          => 'Sri Lanka NMRA - New GMP Certificate Requirements',
				'description'    => 'NMRA now requires GMP certificates to be authenticated by Sri Lanka embassy in country of origin. Effective from March 2024.',
				'country'        => 'LK',
				'authority'      => 'NMRA',
				'update_type'    => 'amendment',
				'effective_date' => '2024-03-01',
				'reference_url'  => 'https://www.nmra.gov.lk',
				'published_at'   => '2024-02-15',
			),
			array(
				'title'          => 'UAE MOHAP - Updated Product Notification Form',
				'description'    => 'New product notification form (PNF) version 3.0 must be used for all submissions starting January 2024.',
				'country'        => 'AE',
				'authority'      => 'MOHAP',
				'update_type'    => 'guideline',
				'effective_date' => '2024-01-01',
				'reference_url'  => 'https://www.mohap.gov.ae',
				'published_at'   => '2023-12-01',
			),
			array(
				'title'          => 'Saudi SFDA - Microplastic Restriction',
				'description'    => 'New ban on microplastics in rinse-off cosmetics. All products containing microbeads must be reformulated.',
				'country'        => 'SA',
				'authority'      => 'SFDA',
				'update_type'    => 'restriction',
				'effective_date' => '2024-06-01',
				'reference_url'  => 'https://www.sfda.gov.sa',
				'published_at'   => '2024-01-15',
			),
			array(
				'title'          => 'GCC - Harmonized Cosmetics Regulation',
				'description'    => 'GCC member states adopting unified cosmetics regulation. Mutual recognition of approvals between Saudi Arabia, UAE, Qatar, Kuwait, Oman, and Bahrain.',
				'country'        => 'GCC',
				'authority'      => 'GCC Standardization Organization',
				'update_type'    => 'new_regulation',
				'effective_date' => '2024-07-01',
				'reference_url'  => 'https://www.gso.org.sa',
				'published_at'   => '2024-02-01',
			),
			array(
				'title'          => 'India CDSCO - Online Registration Portal Launch',
				'description'    => 'New online portal for cosmetics registration launched. All new applications must be submitted electronically.',
				'country'        => 'IN',
				'authority'      => 'CDSCO',
				'update_type'    => 'guideline',
				'effective_date' => '2024-04-01',
				'reference_url'  => 'https://www.cdsco.gov.in',
				'published_at'   => '2024-03-01',
			),
		);

		// Filter by country if specified.
		if ( ! empty( $country ) ) {
			$all_updates = array_filter(
				$all_updates,
				function ( $update ) use ( $country ) {
					return $update['country'] === $country || 'GCC' === $update['country'];
				}
			);
		}

		return array_values( $all_updates );
	}
}
