<?php
/**
 * Tool for listing and searching customers in the CRM.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since     2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List and search customers.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Tool_List_Customers implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] ) && post_type_exists( 'mcp_ai_customer' );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'The List Customers tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_customers';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Customers', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List customers with pagination, filtering by lifecycle stage, owner, or search query.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'          => array(
					'type'        => 'string',
					'enum'        => array( 'list', 'search' ),
					'description' => __( 'Action: list or search.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'list',
				),
				'search_query'    => array(
					'type'        => 'string',
					'description' => __( 'Search by name, email, or company.', 'mcp-ai-wpoos-pro' ),
				),
				'lifecycle_stage' => array(
					'type'        => 'string',
					'description' => __( 'Filter by lifecycle stage.', 'mcp-ai-wpoos-pro' ),
				),
				'contact_owner'   => array(
					'type'        => 'integer',
					'description' => __( 'Filter by owner user ID.', 'mcp-ai-wpoos-pro' ),
				),
				'per_page'        => array(
					'type'        => 'integer',
					'description' => __( 'Results per page.', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
				),
				'page'            => array(
					'type'        => 'integer',
					'description' => __( 'Page number.', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
				),
				'orderby'         => array(
					'type'        => 'string',
					'description' => __( 'Sort field.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'date',
				),
				'order'           => array(
					'type'    => 'string',
					'enum'    => array( 'ASC', 'DESC' ),
					'default' => 'DESC',
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
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
		return array(
			'pro',
			'read-only',
			'requires-capability',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'sales_manager', 'account_executive', 'sdr', 'sales_ops' ),
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
		if ( ! self::is_available() ) {
			return new WP_Error(
				'wp_mcp_ai_crm_toolkit_disabled',
				self::get_unavailable_reason(),
				array( 'status' => 403 )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		$action   = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';
		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$orderby  = isset( $arguments['orderby'] ) ? sanitize_key( $arguments['orderby'] ) : 'date';
		$order    = isset( $arguments['order'] ) ? strtoupper( sanitize_key( $arguments['order'] ) ) : 'DESC';
		$order    = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

		$args = array(
			'post_type'      => 'mcp_ai_customer',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => $orderby,
			'order'          => $order,
		);

		// Meta query filters.
		$meta_query = array();

		if ( ! empty( $arguments['lifecycle_stage'] ) ) {
			$meta_query[] = array(
				'key'   => 'lifecycle_stage',
				'value' => sanitize_key( $arguments['lifecycle_stage'] ),
			);
		}

		if ( ! empty( $arguments['contact_owner'] ) ) {
			$meta_query[] = array(
				'key'   => 'contact_owner',
				'value' => absint( $arguments['contact_owner'] ),
			);
		}

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		// Search query.
		if ( 'search' === $action && ! empty( $arguments['search_query'] ) ) {
			$args['s'] = sanitize_text_field( $arguments['search_query'] );
		}

		$query   = new WP_Query( $args );
		$results = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();

				$customer = array(
					'id'    => $post_id,
					'title' => get_the_title(),
					'date'  => get_the_date( 'Y-m-d' ),
				);

				// Load minimal meta for list view.
				$meta_keys = array( 'email', 'first_name', 'last_name', 'company_name', 'lifecycle_stage', 'contact_owner', 'customer_since', 'total_revenue' );
				foreach ( $meta_keys as $key ) {
					$value = get_post_meta( $post_id, $key, true );
					if ( '' !== $value ) {
						$customer[ $key ] = $value;
					}
				}

				// Enrich owner name.
				if ( ! empty( $customer['contact_owner'] ) ) {
					$owner                  = get_userdata( (int) $customer['contact_owner'] );
					$customer['owner_name'] = $owner ? $owner->display_name : '';
				}

				$results[] = $customer;
			}
			wp_reset_postdata();
		}

		return array(
			'success'     => true,
			'customers'   => $results,
			'total'       => (int) $query->found_posts,
			'per_page'    => $per_page,
			'page'        => $page,
			'total_pages' => (int) $query->max_num_pages,
		);
	}
}
