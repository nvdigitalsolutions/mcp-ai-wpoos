<?php
/**
 * Tool that provides access to Bitwarden vault items (read-only).
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-bitwarden-client.php';

/**
 * Provides an assistant tool for accessing Bitwarden vault items.
 */
class WP_MCP_AI_Pro_Tool_Bitwarden_Vault_Access implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'bitwarden_vault_access';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Bitwarden Vault Access', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve credentials and items from Bitwarden vault. Supports listing, searching, and retrieving specific vault items.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'          => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: list (all items), get (specific item), or search (by name/URI).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list', 'get', 'search' ),
					'default'     => 'list',
				),
				'item_id'         => array(
					'type'        => 'string',
					'description' => __( 'Vault item ID (required for get action).', 'mcp-ai-wpoos-pro' ),
				),
				'search_term'     => array(
					'type'        => 'string',
					'description' => __( 'Search term for finding items by name or URI (required for search action).', 'mcp-ai-wpoos-pro' ),
				),
				'type'            => array(
					'type'        => 'integer',
					'description' => __( 'Filter by item type: 1=Login, 2=Secure Note, 3=Card, 4=Identity.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 1, 2, 3, 4 ),
				),
				'favorite_only'   => array(
					'type'        => 'boolean',
					'description' => __( 'Only return favorite items.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'organization_id' => array(
					'type'        => 'string',
					'description' => __( 'Filter items by organization ID.', 'mcp-ai-wpoos-pro' ),
				),
				'collection_id'   => array(
					'type'        => 'string',
					'description' => __( 'Filter items by collection ID.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check capability - allow filtering.
		$required_capability = apply_filters( 'wp_mcp_ai_bitwarden_access_capability', 'manage_options', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_bitwarden_forbidden', __( 'You do not have permission to access Bitwarden vault.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check if Bitwarden is connected.
		if ( ! class_exists( 'WP_MCP_AI_Bitwarden_OAuth_Handler' ) ) {
			return new WP_Error( 'wp_mcp_ai_bitwarden_not_loaded', __( 'Bitwarden integration is not loaded.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! WP_MCP_AI_Bitwarden_OAuth_Handler::is_connected() ) {
			return new WP_Error( 'wp_mcp_ai_bitwarden_not_connected', __( 'Bitwarden account is not connected. Please connect in Settings → NV oOS → Tools → External Tools.', 'mcp-ai-wpoos-pro' ) );
		}

		$action = ! empty( $arguments['action'] ) ? $arguments['action'] : 'list';
		$client = new WP_MCP_AI_Bitwarden_Client();

		// Log the operation.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log(
				sprintf(
					'Bitwarden vault access: %s by user %d',
					$action,
					$user_id
				),
				'info',
				array(
					'action'    => $action,
					'user_id'   => $user_id,
					'arguments' => $arguments,
				)
			);
		}

		switch ( $action ) {
			case 'list':
				return $this->handle_list( $client, $arguments );

			case 'get':
				return $this->handle_get( $client, $arguments );

			case 'search':
				return $this->handle_search( $client, $arguments );

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Handle list action.
	 *
	 * @param WP_MCP_AI_Bitwarden_Client $client Client instance.
	 * @param array                       $arguments Arguments.
	 * @return array|WP_Error Results or error.
	 */
	private function handle_list( $client, $arguments ) {
		$filters = array();

		if ( ! empty( $arguments['type'] ) ) {
			$filters['type'] = (int) $arguments['type'];
		}

		if ( ! empty( $arguments['favorite_only'] ) ) {
			$filters['favorite'] = true;
		}

		if ( ! empty( $arguments['organization_id'] ) ) {
			$filters['organizationId'] = sanitize_text_field( $arguments['organization_id'] );
		}

		if ( ! empty( $arguments['collection_id'] ) ) {
			$filters['collectionId'] = sanitize_text_field( $arguments['collection_id'] );
		}

		$result = $client->list_vault_items( $filters );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Format items for response.
		$items = ! empty( $result['data'] ) ? $result['data'] : $result;

		if ( ! is_array( $items ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid response from Bitwarden API.', 'mcp-ai-wpoos-pro' ) );
		}

		$formatted_items = array_map( array( 'WP_MCP_AI_Bitwarden_Client', 'format_vault_item' ), $items );

		return array(
			'success' => true,
			'items'   => $formatted_items,
			'count'   => count( $formatted_items ),
		);
	}

	/**
	 * Handle get action.
	 *
	 * @param WP_MCP_AI_Bitwarden_Client $client Client instance.
	 * @param array                       $arguments Arguments.
	 * @return array|WP_Error Results or error.
	 */
	private function handle_get( $client, $arguments ) {
		if ( empty( $arguments['item_id'] ) ) {
			return new WP_Error( 'missing_item_id', __( 'item_id is required for get action.', 'mcp-ai-wpoos-pro' ) );
		}

		$item_id = sanitize_text_field( $arguments['item_id'] );
		$result  = $client->get_vault_item( $item_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$formatted_item = WP_MCP_AI_Bitwarden_Client::format_vault_item( $result );

		return array(
			'success' => true,
			'item'    => $formatted_item,
		);
	}

	/**
	 * Handle search action.
	 *
	 * @param WP_MCP_AI_Bitwarden_Client $client Client instance.
	 * @param array                       $arguments Arguments.
	 * @return array|WP_Error Results or error.
	 */
	private function handle_search( $client, $arguments ) {
		if ( empty( $arguments['search_term'] ) ) {
			return new WP_Error( 'missing_search_term', __( 'search_term is required for search action.', 'mcp-ai-wpoos-pro' ) );
		}

		$search_term = sanitize_text_field( $arguments['search_term'] );
		$result      = $client->search_vault( $search_term );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid response from Bitwarden API.', 'mcp-ai-wpoos-pro' ) );
		}

		$formatted_items = array_map( array( 'WP_MCP_AI_Bitwarden_Client', 'format_vault_item' ), $result );

		return array(
			'success'     => true,
			'items'       => $formatted_items,
			'count'       => count( $formatted_items ),
			'search_term' => $search_term,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Tool is part of the Pro tier.
			'read-only',            // Only reads vault data, no modifications.
			'requires-credentials', // Requires Bitwarden OAuth authentication.
			'external-api',         // Makes external API calls to Bitwarden.
			'network-dependent',    // Requires internet connectivity.
			'sensitive-data',       // Handles sensitive credential data.
		);
	}
}
