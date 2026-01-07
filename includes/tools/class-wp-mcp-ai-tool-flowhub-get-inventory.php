<?php
/**
 * Tool that retrieves inventory data from Flowhub API.
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
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for retrieving inventory data from Flowhub cannabis dispensary system.
 */
class WP_MCP_AI_Tool_Flowhub_Get_Inventory implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'flowhub_get_inventory';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Flowhub Get Inventory', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve cannabis inventory data from Flowhub dispensary system including packages, quantities, locations, and product details. Supports filtering by room and pagination.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of inventory items to retrieve (1-100).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'offset'  => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to skip for pagination.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'room_id' => array(
					'type'        => 'string',
					'description' => __( 'Filter inventory by specific room/location ID.', 'mcp-ai-wpoos' ),
				),
				'timeout' => array(
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to retrieve Flowhub inventory.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			// Require manage_woocommerce or manage_options capability.
			if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to retrieve inventory data.', 'mcp-ai-wpoos' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
			}
		}

		$client  = new WP_MCP_AI_Flowhub_Client();
		$options = array();

		if ( isset( $arguments['limit'] ) ) {
			$options['limit'] = max( 1, min( 100, absint( $arguments['limit'] ) ) );
		}
		if ( isset( $arguments['offset'] ) ) {
			$options['offset'] = max( 0, absint( $arguments['offset'] ) );
		}
		if ( isset( $arguments['room_id'] ) ) {
			$options['room_id'] = sanitize_text_field( $arguments['room_id'] );
		}
		if ( isset( $arguments['timeout'] ) ) {
			$options['timeout'] = max( 5, min( 60, absint( $arguments['timeout'] ) ) );
		}

		$result = $client->get_inventory( $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add summary for frontend display.
		$summary = __( 'Retrieved inventory data from Flowhub', 'mcp-ai-wpoos' );
		if ( isset( $result['total'] ) ) {
			$summary = sprintf(
				/* translators: %d: number of inventory items */
				__( 'Retrieved %d inventory items from Flowhub', 'mcp-ai-wpoos' ),
				absint( $result['total'] )
			);
		}

		$result = array_merge(
			array( 'summary' => $summary ),
			$result
		);

		/**
		 * Allow third parties to filter the Flowhub inventory result.
		 *
		 * @param array $result    Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		$result = apply_filters( 'wp_mcp_ai_flowhub_get_inventory_result', $result, $arguments, $context );

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'external-api',         // Makes external API calls.
			'requires-credentials', // Requires Flowhub API credentials.
			'requires-capability',  // Requires user capabilities.
			'read-only',            // Only reads data, does not modify state.
			'pii-data',             // May return customer-related information.
			'rate-limited',         // Subject to Flowhub API rate limits.
			'paginated',            // Supports pagination.
		);
	}
}
