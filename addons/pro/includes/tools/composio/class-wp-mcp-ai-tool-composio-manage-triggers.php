<?php
/**
 * Tool: composio_manage_triggers — manage Composio trigger instances.
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
 * Composio — Manage triggers.
 */
class WP_MCP_AI_Tool_Composio_Manage_Triggers implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Envelope;

	/**
	 * Allowed actions.
	 */
	const ACTIONS = array( 'list_types', 'list_active', 'upsert', 'disable', 'enable', 'delete' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'composio_manage_triggers';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Composio — Manage Triggers', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Manage Composio triggers: discover trigger types, list active triggers, and create, enable, disable or delete trigger instances. Trigger events are delivered to this site via the Composio webhook receiver.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'               => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: list_types, list_active, upsert, disable, enable, delete.', 'mcp-ai-wpoos-pro' ),
					'enum'        => self::ACTIONS,
				),
				'trigger_slug'         => array(
					'type'        => 'string',
					'description' => __( 'Trigger slug for upsert (e.g. GMAIL_NEW_MESSAGE).', 'mcp-ai-wpoos-pro' ),
				),
				'trigger_id'           => array(
					'type'        => 'string',
					'description' => __( 'Trigger instance ID for disable/enable/delete.', 'mcp-ai-wpoos-pro' ),
				),
				'connected_account_id' => array(
					'type'        => 'string',
					'description' => __( 'Composio connected-account nanoid ("ca_...") to pin the trigger to (upsert). NOT a "conn_..." connection ID — get one from composio_list_connected_accounts.', 'mcp-ai-wpoos-pro' ),
				),
				'trigger_config'       => array(
					'type'        => 'object',
					'description' => __( 'Optional trigger configuration (upsert).', 'mcp-ai-wpoos-pro' ),
				),
				'toolkit'              => array(
					'type'        => 'string',
					'description' => __( 'Optional toolkit filter for list_types.', 'mcp-ai-wpoos-pro' ),
				),
				'connection_id'        => array(
					'type'        => 'string',
					'description' => __( 'Optional NV oOS Composio connection ID ("conn_..."), identifying this site\'s Composio project integration. NOT a connected-account ID — do not pass a "ca_..." value here. Omit it to use the first enabled Composio connection.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action' ),
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
		$action       = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';
		$trigger_slug = isset( $arguments['trigger_slug'] ) ? sanitize_text_field( $arguments['trigger_slug'] ) : '';
		$trigger_id   = isset( $arguments['trigger_id'] ) ? sanitize_text_field( $arguments['trigger_id'] ) : '';
		$account_id   = isset( $arguments['connected_account_id'] ) ? sanitize_text_field( $arguments['connected_account_id'] ) : '';
		$toolkit      = isset( $arguments['toolkit'] ) ? sanitize_key( $arguments['toolkit'] ) : '';
		$config       = isset( $arguments['trigger_config'] ) && is_array( $arguments['trigger_config'] ) ? $arguments['trigger_config'] : array();

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new WP_Error( 'invalid_action', __( 'Invalid trigger action.', 'mcp-ai-wpoos-pro' ) );
		}

		// Catch a connection ID passed where an account ID belongs.
		$account_check = WP_MCP_AI_Composio_Tools::validate_account_id( $account_id );

		if ( is_wp_error( $account_check ) ) {
			return $account_check;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = null;
		$resolved   = WP_MCP_AI_Composio_Tools::resolve_connection( $arguments, $connection );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$client = WP_MCP_AI_Composio_Tools::build_client( $connection );

		switch ( $action ) {
			case 'list_types':
				$filters = array();
				if ( '' !== $toolkit ) {
					$filters['toolkit'] = $toolkit;
				}
				$result   = $client->list_trigger_types( $filters );
				$message  = __( 'Trigger types retrieved.', 'mcp-ai-wpoos-pro' );
				$data_key = 'types';
				break;

			case 'list_active':
				$result   = $client->list_active_triggers();
				$message  = __( 'Active triggers retrieved.', 'mcp-ai-wpoos-pro' );
				$data_key = 'triggers';
				break;

			case 'upsert':
				if ( '' === $trigger_slug ) {
					return new WP_Error( 'missing_params', __( 'trigger_slug is required for upsert.', 'mcp-ai-wpoos-pro' ) );
				}
				$upsert_config = $config;
				if ( '' !== $account_id ) {
					$upsert_config['connected_account_id'] = $account_id;
				}
				$result   = $client->upsert_trigger( $trigger_slug, $upsert_config );
				$message  = /* translators: %s: trigger slug */ sprintf( __( 'Trigger %s upserted.', 'mcp-ai-wpoos-pro' ), esc_html( $trigger_slug ) );
				$data_key = 'trigger';
				break;

			case 'disable':
				if ( '' === $trigger_id ) {
					return new WP_Error( 'missing_params', __( 'trigger_id is required for disable.', 'mcp-ai-wpoos-pro' ) );
				}
				$result   = $client->set_trigger_status( $trigger_id, 'disable' );
				$message  = __( 'Trigger disabled.', 'mcp-ai-wpoos-pro' );
				$data_key = 'trigger';
				break;

			case 'enable':
				if ( '' === $trigger_id ) {
					return new WP_Error( 'missing_params', __( 'trigger_id is required for enable.', 'mcp-ai-wpoos-pro' ) );
				}
				$result   = $client->set_trigger_status( $trigger_id, 'enable' );
				$message  = __( 'Trigger enabled.', 'mcp-ai-wpoos-pro' );
				$data_key = 'trigger';
				break;

			case 'delete':
				if ( '' === $trigger_id ) {
					return new WP_Error( 'missing_params', __( 'trigger_id is required for delete.', 'mcp-ai-wpoos-pro' ) );
				}
				$result   = $client->delete_trigger( $trigger_id );
				$message  = __( 'Trigger deleted.', 'mcp-ai-wpoos-pro' );
				$data_key = 'trigger';
				break;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			$message,
			array(
				'action'  => esc_html( $action ),
				$data_key => $result,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'write', 'state-changing', 'pro', 'requires-capability', 'remote-api' );
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
