<?php
/**
 * Tool that retrieves account data from Firefly III API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-firefly-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for retrieving accounts from Firefly III.
 */
class WP_MCP_AI_Tool_Firefly_Get_Accounts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'firefly_get_accounts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Firefly III Get Accounts', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve financial accounts from Firefly III personal finance manager including asset accounts (checking, savings), expense accounts, revenue accounts, and liabilities. Supports filtering by account type and pagination for large datasets.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Remote Sites connection ID for Firefly III. If not provided, will use settings-based configuration.', 'mcp-ai-wpoos' ),
				),
				'type'          => array(
					'type'        => 'string',
					'description' => __( 'Filter by account type (e.g., "asset", "expense", "revenue", "liability", "reconciliation", "initial-balance", "liabilities", "mortgage").', 'mcp-ai-wpoos' ),
					'enum'        => array( 'asset', 'expense', 'revenue', 'liability', 'reconciliation', 'initial-balance', 'liabilities', 'mortgage' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of accounts to retrieve (1-100).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'page'          => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination (starts at 1).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'default'     => 1,
				),
				'timeout'       => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds (5-60).', 'mcp-ai-wpoos' ),
					'minimum'     => 5,
					'maximum'     => 60,
					'default'     => 30,
				),
			),
			'required'             => array(),
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
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to retrieve Firefly III accounts.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			// Require edit_posts or manage_options capability.
			if ( ! user_can( $user_id, 'edit_posts' ) && ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to retrieve financial data.', 'mcp-ai-wpoos' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
			}
		}

		// Get connection_id if provided.
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : null;

		// Validate connection if provided.
		if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( null === $connection ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_not_found',
					__( 'Connection not found. Please check the connection ID.', 'mcp-ai-wpoos' )
				);
			}

			// Validate connection type.
			if ( empty( $connection['connection_type'] ) || 'firefly' !== $connection['connection_type'] ) {
				return new WP_Error(
					'wp_mcp_ai_pro_wrong_connection_type',
					__( 'This connection is not a Firefly III connection.', 'mcp-ai-wpoos' )
				);
			}

			// Check if connection is enabled.
			if ( empty( $connection['enabled'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_disabled',
					__( 'This connection is disabled. Please enable it in Remote Sites settings.', 'mcp-ai-wpoos' )
				);
			}
		}

		$client  = new WP_MCP_AI_Firefly_Client( $connection_id );
		$options = array();

		if ( isset( $arguments['type'] ) ) {
			$options['type'] = sanitize_text_field( $arguments['type'] );
		}
		if ( isset( $arguments['limit'] ) ) {
			$options['limit'] = max( 1, min( 100, absint( $arguments['limit'] ) ) );
		}
		if ( isset( $arguments['page'] ) ) {
			$options['page'] = max( 1, absint( $arguments['page'] ) );
		}
		if ( isset( $arguments['timeout'] ) ) {
			$options['timeout'] = max( 5, min( 60, absint( $arguments['timeout'] ) ) );
		}

		$result = $client->get_accounts( $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Extract data from Firefly III JSON:API response format.
		$accounts = array();
		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $account ) {
				if ( ! isset( $account['attributes'] ) ) {
					continue;
				}

				$attrs = $account['attributes'];
				$accounts[] = array(
					'id'              => isset( $account['id'] ) ? $account['id'] : '',
					'name'            => isset( $attrs['name'] ) ? $attrs['name'] : '',
					'type'            => isset( $attrs['type'] ) ? $attrs['type'] : '',
					'account_role'    => isset( $attrs['account_role'] ) ? $attrs['account_role'] : '',
					'currency_code'   => isset( $attrs['currency_code'] ) ? $attrs['currency_code'] : '',
					'current_balance' => isset( $attrs['current_balance'] ) ? $attrs['current_balance'] : '0',
					'iban'            => isset( $attrs['iban'] ) ? $attrs['iban'] : '',
					'active'          => isset( $attrs['active'] ) ? $attrs['active'] : true,
					'created_at'      => isset( $attrs['created_at'] ) ? $attrs['created_at'] : '',
					'updated_at'      => isset( $attrs['updated_at'] ) ? $attrs['updated_at'] : '',
				);
			}
		}

		// Add summary for frontend display.
		$summary = sprintf(
			/* translators: %d: number of accounts */
			__( 'Retrieved %d account(s) from Firefly III', 'mcp-ai-wpoos' ),
			count( $accounts )
		);

		$response = array(
			'message'  => $summary,
			'summary'  => $summary,
			'accounts' => $accounts,
			'total'    => count( $accounts ),
		);

		// Include pagination metadata if available.
		if ( isset( $result['meta'] ) ) {
			$response['meta'] = $result['meta'];
		}

		/**
		 * Allow third parties to filter the Firefly III accounts result.
		 *
		 * @param array $response  Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		$response = apply_filters( 'wp_mcp_ai_firefly_get_accounts_result', $response, $arguments, $context );

		return $response;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'external-api',         // Makes external API calls.
			'requires-credentials', // Requires Firefly III API credentials.
			'requires-capability',  // Requires user capabilities.
			'read-only',            // Only reads data, does not modify state.
			'rate-limited',         // Subject to Firefly III API rate limits.
			'paginated',            // Supports pagination.
			'pii-data',             // Contains personally identifiable information.
			'cacheable',            // Results can be cached.
		);
	}
}
