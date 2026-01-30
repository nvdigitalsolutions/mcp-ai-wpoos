<?php
/**
 * Tool for listing documents in the Regulatory Registration system.
 *
 * Allows AI assistants to list and filter documents.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists regulatory documents.
 */
class WP_MCP_AI_Tool_List_Reg_Documents implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_reg_documents';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Regulatory Documents', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists documents in the regulatory registration system with filtering by product, registration, document type, and expiry status.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Filter by product ID (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'registration_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Filter by registration ID (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'document_type'    => array(
					'type'        => 'string',
					'description' => __( 'Filter by document type (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'status'           => array(
					'type'        => 'string',
					'description' => __( 'Filter by status: valid, expired, expiring_soon (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'valid', 'expired', 'expiring_soon' ),
				),
				'page'             => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination (optional, default: 1)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'default'     => 1,
				),
				'per_page'         => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (optional, default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view documents.', 'mcp-ai-wpoos-pro' ) );
		}

		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$per_page = min( $per_page, 100 ); // Cap at 100.

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_reg_document',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		// Filter by product or registration.
		if ( ! empty( $arguments['product_id'] ) || ! empty( $arguments['registration_id'] ) ) {
			$query_args['meta_query'] = array( 'relation' => 'OR' );

			if ( ! empty( $arguments['product_id'] ) ) {
				$query_args['meta_query'][] = array(
					'key'   => 'product_id',
					'value' => absint( $arguments['product_id'] ),
				);
			}

			if ( ! empty( $arguments['registration_id'] ) ) {
				$query_args['meta_query'][] = array(
					'key'   => 'registration_id',
					'value' => absint( $arguments['registration_id'] ),
				);
			}
		}

		// Filter by document type.
		if ( ! empty( $arguments['document_type'] ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'mcp_ai_doc_type',
					'field'    => 'name',
					'terms'    => sanitize_text_field( $arguments['document_type'] ),
				),
			);
		}

		$query = new WP_Query( $query_args );

		$documents = array();
		$today = time();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$expiry_date = get_post_meta( $post->ID, 'expiry_date', true );
				$is_expired = false;
				$expiring_soon = false;
				$days_to_expiry = null;

				if ( $expiry_date ) {
					$expiry = strtotime( $expiry_date );
					$days_to_expiry = floor( ( $expiry - $today ) / DAY_IN_SECONDS );
					$is_expired = $days_to_expiry < 0;
					$expiring_soon = $days_to_expiry >= 0 && $days_to_expiry <= 90;
				}

				// Filter by status if requested.
				if ( ! empty( $arguments['status'] ) ) {
					$status_filter = $arguments['status'];
					if ( 'expired' === $status_filter && ! $is_expired ) {
						continue;
					}
					if ( 'expiring_soon' === $status_filter && ! $expiring_soon ) {
						continue;
					}
					if ( 'valid' === $status_filter && ( $is_expired || $expiring_soon ) ) {
						continue;
					}
				}

				$doc_data = array(
					'id'              => $post->ID,
					'title'           => $post->post_title,
					'description'     => $post->post_content,
					'document_type'   => get_post_meta( $post->ID, 'document_type', true ),
					'status'          => get_post_meta( $post->ID, 'status', true ),
					'issue_date'      => get_post_meta( $post->ID, 'issue_date', true ),
					'expiry_date'     => $expiry_date,
					'file_url'        => get_post_meta( $post->ID, 'file_url', true ),
					'version'         => get_post_meta( $post->ID, 'version', true ),
					'product_id'      => absint( get_post_meta( $post->ID, 'product_id', true ) ),
					'registration_id' => absint( get_post_meta( $post->ID, 'registration_id', true ) ),
					'is_expired'      => $is_expired,
					'expiring_soon'   => $expiring_soon,
					'days_to_expiry'  => $days_to_expiry,
				);

				// Get document type from taxonomy.
				$doc_types = wp_get_post_terms( $post->ID, 'mcp_ai_doc_type' );
				if ( ! empty( $doc_types ) && ! is_wp_error( $doc_types ) ) {
					$doc_data['document_type'] = $doc_types[0]->name;
				}

				$documents[] = $doc_data;
			}
		}

		return array(
			'success'    => true,
			'documents'  => $documents,
			'total'      => $query->found_posts,
			'page'       => $page,
			'per_page'   => $per_page,
			'total_pages' => $query->max_num_pages,
		);
	}
}
