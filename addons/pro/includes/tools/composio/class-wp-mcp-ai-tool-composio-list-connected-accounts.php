<?php
/**
 * Tool: composio_list_connected_accounts — list authenticated user accounts.
 *
 * Pro tool (PHP 8.1+). Requires manage_options capability.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composio — List connected accounts.
 */
class WP_MCP_AI_Tool_Composio_List_Connected_Accounts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'composio_list_connected_accounts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Composio — List Connected Accounts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'List the Composio connected accounts (authenticated app connections such as a user\'s Gmail or Slack) with their status. Filter by toolkit or status.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'toolkit'       => array(
					'type'        => 'string',
					'description' => __( 'Optional toolkit filter (e.g. "gmail").', 'mcp-ai-wpoos-pro' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Optional status filter: active, inactive, failed, expired.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'inactive', 'failed', 'expired' ),
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Composio connection ID.', 'mcp-ai-wpoos-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability(): string {
		return 'manage_options';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1 — Sanitize at entry.
		$toolkit = isset( $arguments['toolkit'] ) ? sanitize_key( $arguments['toolkit'] ) : '';
		$status  = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = null;
		$resolved   = WP_MCP_AI_Composio_Tools::resolve_connection( $arguments, $connection );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$filters = array();
		if ( '' !== $toolkit ) {
			$filters['toolkit'] = $toolkit;
		}
		if ( '' !== $status ) {
			$filters['status'] = $status;
		}

		$client   = WP_MCP_AI_Composio_Tools::build_client( $connection );
		$accounts = $client->list_connected_accounts( $filters );

		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}

		$connection_id = isset( $connection['id'] ) ? $connection['id'] : '';

		$items = array();
		foreach ( $accounts as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}

			$account_id = isset( $account['id'] ) ? (string) $account['id'] : '';

			// v3.1 nests the toolkit under { slug: "..." }; older payloads carry
			// a flat toolkit string.
			$toolkit = isset( $account['toolkit'] ) ? $account['toolkit'] : '';
			if ( is_array( $toolkit ) ) {
				$toolkit = isset( $toolkit['slug'] ) ? (string) $toolkit['slug'] : '';
			}

			$items[] = array(
				'id'      => esc_html( $account_id ),
				'alias'   => isset( $account['alias'] ) ? esc_html( (string) $account['alias'] ) : '',
				'toolkit' => esc_html( (string) $toolkit ),
				'status'  => isset( $account['status'] ) ? esc_html( (string) $account['status'] ) : '',
				'expired' => '' !== $account_id && class_exists( 'WP_MCP_AI_Composio_Auth_Handler' )
					? WP_MCP_AI_Composio_Auth_Handler::is_account_expired( $connection_id, $account_id )
					: false,
			);
		}

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			sprintf(
				/* translators: %d: number of accounts */
				__( 'Found %d connected accounts.', 'mcp-ai-wpoos-pro' ),
				count( $items )
			),
			array(
				'accounts' => $items,
				'count'    => count( $items ),
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'read', 'pro', 'requires-capability', 'remote-api', 'sensitive-data' );
	}

	/**
	 * Get extended tool definition.
	 *
	 * @return array
	 */
	public function get_definition(): array {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'composio',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'risk_level'            => 'medium',
		);
	}
}
