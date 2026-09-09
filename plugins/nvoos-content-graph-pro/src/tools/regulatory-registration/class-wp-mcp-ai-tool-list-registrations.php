<?php
/**
 * WP_MCP_AI_Tool_List_Registrations (ecosystem port - Wave F2, regulatory-registration tool batch).
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
 * Lists regulatory registrations.
 */
class WP_MCP_AI_Tool_List_Registrations implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_registrations';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Registrations', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists registration instances in the regulatory system with optional filtering by country, status, product, or expiry date.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country'              => array(
					'type'        => 'string',
					'description' => __( 'Filter by country (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 100,
				),
				'status'               => array(
					'type'        => 'string',
					'description' => __( 'Filter by status (draft, pending_documents, ready_for_submission, submitted, under_review, approved, rejected, on_hold, renewal_due) (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'draft', 'pending_documents', 'ready_for_submission', 'submitted', 'under_review', 'approved', 'rejected', 'on_hold', 'renewal_due' ),
				),
				'product_id'           => array(
					'type'        => 'integer',
					'description' => __( 'Filter by product ID (optional)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'expiring_within_days' => array(
					'type'        => 'integer',
					'description' => __( 'Filter registrations expiring within X days (optional)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'maximum'     => 365,
				),
				'registration_type'    => array(
					'type'        => 'string',
					'description' => __( 'Filter by registration type: new, renewal, or variation (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'new', 'renewal', 'variation' ),
				),
				'limit'                => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of registrations to return (default: 20, max: 100) (optional)', 'nvoos-content-graph-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'offset'               => array(
					'type'        => 'integer',
					'description' => __( 'Number of registrations to skip for pagination (optional)', 'nvoos-content-graph-pro' ),
					'default'     => 0,
					'minimum'     => 0,
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
			'paginated',            // Supports pagination.
			'idempotent',           // Can be called multiple times safely with same result.
		);
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list registrations.', 'nvoos-content-graph-pro' ) );
		}

		// Parse arguments.
		$country              = ! empty( $arguments['country'] ) ? sanitize_text_field( $arguments['country'] ) : '';
		$status               = ! empty( $arguments['status'] ) ? sanitize_text_field( $arguments['status'] ) : '';
		$product_id           = ! empty( $arguments['product_id'] ) ? absint( $arguments['product_id'] ) : 0;
		$expiring_within_days = ! empty( $arguments['expiring_within_days'] ) ? absint( $arguments['expiring_within_days'] ) : 0;
		$registration_type    = ! empty( $arguments['registration_type'] ) ? sanitize_text_field( $arguments['registration_type'] ) : '';
		$limit                = ! empty( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 20;
		$offset               = ! empty( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;

		// Enforce max limit.
		$limit = min( $limit, 100 );

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_registration',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Build meta query.
		$meta_query = array();

		if ( $country ) {
			$meta_query[] = array(
				'key'     => 'country',
				'value'   => $country,
				'compare' => '=',
			);
		}

		if ( $product_id ) {
			$meta_query[] = array(
				'key'     => 'product_id',
				'value'   => $product_id,
				'compare' => '=',
			);
		}

		if ( $registration_type ) {
			$meta_query[] = array(
				'key'     => 'registration_type',
				'value'   => $registration_type,
				'compare' => '=',
			);
		}

		if ( $expiring_within_days > 0 ) {
			$expiry_date  = gmdate( 'Y-m-d', strtotime( "+{$expiring_within_days} days" ) );
			$meta_query[] = array(
				'key'     => 'expiry_date',
				'value'   => $expiry_date,
				'compare' => '<=',
				'type'    => 'DATE',
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		// Add taxonomy filter for status.
		if ( $status ) {
			$status_slug             = str_replace( '_', '-', strtolower( $status ) );
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'mcp_ai_reg_status',
					'field'    => 'slug',
					'terms'    => $status_slug,
				),
			);
		}

		// Execute query.
		$query = new WP_Query( $query_args );

		$registrations = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$reg_data = array(
					'id'                => $post->ID,
					'title'             => $post->post_title,
					'product_id'        => absint( get_post_meta( $post->ID, 'product_id', true ) ),
					'country'           => get_post_meta( $post->ID, 'country', true ),
					'authority'         => get_post_meta( $post->ID, 'authority', true ),
					'registration_type' => get_post_meta( $post->ID, 'registration_type', true ),
					'cos_number'        => get_post_meta( $post->ID, 'cos_number', true ),
					'submission_date'   => get_post_meta( $post->ID, 'submission_date', true ),
					'approval_date'     => get_post_meta( $post->ID, 'approval_date', true ),
					'expiry_date'       => get_post_meta( $post->ID, 'expiry_date', true ),
					'created_date'      => $post->post_date,
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

				$registrations[] = $reg_data;
			}
		}

		return array(
			'success'       => true,
			'registrations' => $registrations,
			'total'         => $query->found_posts,
			'returned'      => count( $registrations ),
			'limit'         => $limit,
			'offset'        => $offset,
			'has_more'      => ( $offset + $limit ) < $query->found_posts,
		);
	}
}
