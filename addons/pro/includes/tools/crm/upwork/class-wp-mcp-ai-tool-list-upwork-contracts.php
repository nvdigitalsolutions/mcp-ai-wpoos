<?php
/**
 * Tool for listing Upwork contracts for the authenticated freelancer/agency.
 *
 * Queries the Upwork GraphQL API for active, completed, and pending
 * contracts, with optional filtering by status and date range.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.10.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists Upwork contracts.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Tool_List_Upwork_Contracts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * GraphQL query for listing contracts.
	 *
	 * @var string
	 */
	const CONTRACTS_QUERY = '
		query ListContracts($filter: ContractsFilter, $paging: Paging) {
			contracts(filter: $filter, paging: $paging) {
				totalCount
				edges {
					node {
						id
						title
						description
						status
						startDate
						endDate
						totalBudget { amount currency }
						client {
							name
							country
						}
						freelancer {
							name
						}
					}
					cursor
				}
				pageInfo { endCursor hasNextPage }
			}
		}
	';

	/**
	 * Determine whether CRM toolkit and Upwork client are available.
	 *
	 * @since 2.10.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] ) && class_exists( 'WP_MCP_AI_Upwork_Client' );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return __( 'The List Upwork Contracts tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'The List Upwork Contracts tool requires the Upwork client integration.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_upwork_contracts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Upwork Contracts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List active, completed, and pending Upwork contracts for the authenticated freelancer or agency account.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Filter contracts by status.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'completed', 'cancelled', 'pending', 'all' ),
					'default'     => 'active',
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results (1–50).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Remote Sites Upwork connection ID.', 'mcp-ai-wpoos-pro' ),
				),
			),
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
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'requires-capability',
			'external-api',
			'rate-limited',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to list Upwork contracts.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check for valid connection.
		$connection_id = $this->resolve_connection_id( $arguments );

		if ( empty( $connection_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_upwork_no_connection',
				__( 'No Upwork connection configured. Please connect an Upwork account via Remote Sites.', 'mcp-ai-wpoos-pro' )
			);
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-upwork-client.php';
		$client = new WP_MCP_AI_Upwork_Client( $connection_id );

		$status = isset( $arguments['status'] ) ? sanitize_text_field( $arguments['status'] ) : 'active';
		$limit  = isset( $arguments['limit'] ) ? min( 50, max( 1, absint( $arguments['limit'] ) ) ) : 10;

		$filter = array();
		if ( 'all' !== $status ) {
			$filter['status'] = strtoupper( $status );
		}

		$variables = array(
			'paging' => array( 'first' => $limit ),
		);
		if ( ! empty( $filter ) ) {
			$variables['filter'] = $filter;
		}

		$result = $client->graphql( self::CONTRACTS_QUERY, $variables );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$contracts_data = isset( $result['data']['contracts'] ) ? $result['data']['contracts'] : array();
		$edges          = isset( $contracts_data['edges'] ) ? $contracts_data['edges'] : array();
		$page_info      = isset( $contracts_data['pageInfo'] ) ? $contracts_data['pageInfo'] : array();
		$total          = isset( $contracts_data['totalCount'] ) ? (int) $contracts_data['totalCount'] : 0;

		$contracts = array();
		foreach ( $edges as $edge ) {
			$node = isset( $edge['node'] ) ? $edge['node'] : array();
			if ( empty( $node ) ) {
				continue;
			}

			$contracts[] = array(
				'id'             => isset( $node['id'] ) ? $node['id'] : '',
				'title'          => isset( $node['title'] ) ? $node['title'] : '',
				'description'    => isset( $node['description'] ) ? wp_trim_words( $node['description'], 40 ) : '',
				'status'         => isset( $node['status'] ) ? $node['status'] : '',
				'start_date'     => isset( $node['startDate'] ) ? $node['startDate'] : '',
				'end_date'       => isset( $node['endDate'] ) ? $node['endDate'] : '',
				'budget'         => isset( $node['totalBudget'] ) ? $node['totalBudget'] : null,
				'client'         => isset( $node['client']['name'] ) ? $node['client']['name'] : '',
				'client_country' => isset( $node['client']['country'] ) ? $node['client']['country'] : '',
				'cursor'         => isset( $edge['cursor'] ) ? $edge['cursor'] : '',
			);
		}

		return array(
			'success'       => true,
			'mode'          => 'api',
			'status_filter' => $status,
			'total_count'   => $total,
			'count'         => count( $contracts ),
			'contracts'     => $contracts,
			'has_next_page' => isset( $page_info['hasNextPage'] ) ? (bool) $page_info['hasNextPage'] : false,
			'end_cursor'    => isset( $page_info['endCursor'] ) ? $page_info['endCursor'] : null,
		);
	}

	/**
	 * Resolve the Upwork connection ID from arguments or CRM defaults.
	 *
	 * @param array $arguments Tool arguments.
	 * @return string Connection ID or empty string.
	 */
	protected function resolve_connection_id( $arguments ) {
		if ( ! empty( $arguments['connection_id'] ) ) {
			return sanitize_text_field( $arguments['connection_id'] );
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			return isset( $settings['external_sourcing']['upwork']['default_connection_id'] )
				? $settings['external_sourcing']['upwork']['default_connection_id']
				: '';
		}

		return '';
	}
}
