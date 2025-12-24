<?php
/**
 * Tool that retrieves detailed information about a specific OpenAI model.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves detailed information about a specific OpenAI model.
 */
class WP_MCP_AI_Tool_Get_Model_Information implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_model_information';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Model Information', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a specific OpenAI model. Use this to check model specifications, verify model exists before use, get model context length, or understand model capabilities.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'model_id' => array(
					'type'        => 'string',
					'description' => __( 'Model identifier (e.g., gpt-4o, gpt-4o-mini, text-embedding-3-small).', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'model_id' ),
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

		// Check permissions.
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to retrieve model information.', 'wp-mcp-ai' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate model_id.
		if ( ! isset( $arguments['model_id'] ) || '' === $arguments['model_id'] ) {
			return new WP_Error(
				'wp_mcp_ai_missing_model_id',
				__( 'Model ID is required.', 'wp-mcp-ai' )
			);
		}

		$model_id = sanitize_text_field( $arguments['model_id'] );

		// Call OpenAI API.
		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->get_model( $model_id );

		// Handle errors.
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				$result->get_error_data()
			);
		}

		// Format the response.
		$model_info = array(
			'id'       => isset( $result['id'] ) ? $result['id'] : '',
			'object'   => isset( $result['object'] ) ? $result['object'] : '',
			'created'  => isset( $result['created'] ) ? $result['created'] : 0,
			'owned_by' => isset( $result['owned_by'] ) ? $result['owned_by'] : '',
		);

		// Add optional fields if present.
		if ( isset( $result['permission'] ) ) {
			$model_info['permission'] = $result['permission'];
		}
		if ( isset( $result['root'] ) ) {
			$model_info['root'] = $result['root'];
		}
		if ( isset( $result['parent'] ) ) {
			$model_info['parent'] = $result['parent'];
		}

		return array(
			'success' => true,
			'model'   => $model_info,
			'summary' => sprintf(
				/* translators: 1: model ID, 2: owner */
				__( 'Retrieved information for model "%1$s" owned by %2$s.', 'wp-mcp-ai' ),
				$model_info['id'],
				$model_info['owned_by']
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Makes external API calls to OpenAI.
			'requires-capability',  // Requires 'read' capability.
			'cacheable',            // Results can be cached.
		);
	}
}
