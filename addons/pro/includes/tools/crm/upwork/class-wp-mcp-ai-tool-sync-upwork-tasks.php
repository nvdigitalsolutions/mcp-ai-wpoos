<?php
/**
 * Tool for syncing Upwork contract tasks/milestones into CRM Tasks.
 *
 * Pulls task and milestone data from an Upwork contract via the GraphQL API
 * and creates or updates corresponding CRM Task records.
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
 * Syncs Upwork contract tasks into CRM Tasks.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Tool_Sync_Upwork_Tasks implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * GraphQL query for fetching contract milestones/tasks.
	 *
	 * @var string
	 */
	const TASKS_QUERY = '
		query GetContractMilestones($contractId: ID!, $paging: Paging) {
			contract(id: $contractId) {
				id
				title
				milestones(paging: $paging) {
					totalCount
					edges {
						node {
							id
							title
							description
							status
							dueDate
							budget { amount currency }
							completedDate
						}
						cursor
					}
					pageInfo { endCursor hasNextPage }
				}
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
			return __( 'The Sync Upwork Tasks tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'The Sync Upwork Tasks tool requires the Upwork client integration.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'sync_upwork_tasks';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Sync Upwork Tasks', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sync milestones and tasks from an Upwork contract into the CRM as Task records for tracking and follow-up.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'contract_id'   => array(
					'type'        => 'string',
					'description' => __( 'Upwork contract ID to sync tasks from.', 'mcp-ai-wpoos-pro' ),
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Remote Sites Upwork connection ID.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of tasks to sync (1–50).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 25,
				),
			),
			'required'   => array( 'contract_id' ),
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
			'write',
			'state-changing',
			'reversible',
			'idempotent',
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
				__( 'You do not have permission to sync Upwork tasks.', 'mcp-ai-wpoos-pro' )
			);
		}

		$contract_id = sanitize_text_field( $arguments['contract_id'] );

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

		$limit = isset( $arguments['limit'] ) ? min( 50, max( 1, absint( $arguments['limit'] ) ) ) : 25;

		$variables = array(
			'contractId' => $contract_id,
			'paging'     => array( 'first' => $limit ),
		);

		$result = $client->graphql( self::TASKS_QUERY, $variables );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$contract_data = isset( $result['data']['contract'] ) ? $result['data']['contract'] : array();
		$milestones    = isset( $contract_data['milestones'] ) ? $contract_data['milestones'] : array();
		$edges         = isset( $milestones['edges'] ) ? $milestones['edges'] : array();
		$total         = isset( $milestones['totalCount'] ) ? (int) $milestones['totalCount'] : 0;

		$synced   = 0;
		$task_ids = array();

		foreach ( $edges as $edge ) {
			$node = isset( $edge['node'] ) ? $edge['node'] : array();
			if ( empty( $node ) ) {
				continue;
			}

			$task_title       = ! empty( $node['title'] ) ? sanitize_text_field( $node['title'] ) : '';
			$task_description = ! empty( $node['description'] ) ? sanitize_textarea_field( $node['description'] ) : '';
			$task_due         = ! empty( $node['dueDate'] ) ? sanitize_text_field( $node['dueDate'] ) : '';
			$task_status      = ! empty( $node['status'] ) ? sanitize_text_field( $node['status'] ) : '';

			// Map Upwork status to CRM priority.
			$priority = 'medium';
			if ( 'overdue' === $task_status || strtotime( $task_due ) < time() ) {
				$priority = 'high';
			} elseif ( 'completed' === $task_status ) {
				$priority = 'low';
			}

			$meta_input = array(
				'_wp_mcp_ai_task_source'    => 'upwork',
				'_wp_mcp_ai_task_status'    => $task_status,
				'_wp_mcp_ai_task_due_date'  => $task_due,
				'_wp_mcp_ai_task_upwork_id' => isset( $node['id'] ) ? sanitize_text_field( $node['id'] ) : '',
			);

			// Check for existing task with this Upwork ID (idempotent sync).
			$existing = get_posts(
				array(
					'post_type'      => 'mcp_ai_task',
					'meta_key'       => '_wp_mcp_ai_task_upwork_id',
					'meta_value'     => $meta_input['_wp_mcp_ai_task_upwork_id'],
					'posts_per_page' => 1,
					'post_status'    => 'any',
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $existing ) ) {
				// Update existing task.
				$post_data = array(
					'ID'           => $existing[0],
					'post_title'   => $task_title,
					'post_content' => $task_description,
				);
				$post_id   = wp_update_post( $post_data, true );
			} else {
				// Create new task.
				$post_data = array(
					'post_type'    => 'mcp_ai_task',
					'post_title'   => $task_title,
					'post_content' => $task_description,
					'post_status'  => 'publish',
					'meta_input'   => $meta_input,
				);
				$post_id   = wp_insert_post( $post_data, true );
			}

			if ( ! is_wp_error( $post_id ) && $post_id > 0 ) {
				++$synced;
				$task_ids[] = (int) $post_id;
			}
		}

		return array(
			'success'      => true,
			'mode'         => 'api',
			'contract_id'  => $contract_id,
			'total_remote' => $total,
			'synced'       => $synced,
			'task_ids'     => $task_ids,
			'message'      => sprintf(
				/* translators: 1: number synced, 2: total remote tasks */
				_n(
					'Synced %1$d of %2$d Upwork milestone/task.',
					'Synced %1$d of %2$d Upwork milestones/tasks.',
					$synced,
					'mcp-ai-wpoos-pro'
				),
				$synced,
				$total
			),
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
