<?php
/**
 * Tool proxying requests to JetEngine REST API routes.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invoke JetEngine REST routes using authenticated MCP context.
 */
class WP_MCP_AI_Tool_Invoke_JetEngine_Route implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Determine whether JetEngine is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WP_MCP_AI_JetEngine_Tool_Handlers' ) && WP_MCP_AI_JetEngine_Tool_Handlers::is_available();
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The JetEngine REST proxy tool is disabled because JetEngine is not active.', 'mcp-ai-wpoos' );
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'invoke_jetengine_route';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Invoke JetEngine REST Route', 'mcp-ai-wpoos' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Executes JetEngine REST API routes using the authenticated WordPress user context.', 'mcp-ai-wpoos' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'operation'   => array(
					'type'        => 'string',
					'enum'        => WP_MCP_AI_JetEngine_Tool_Handlers::get_supported_operations(),
					'description' => __( 'JetEngine operation to execute (for example `get_items` or `add_item`).', 'mcp-ai-wpoos' ),
				),
				'id'          => array(
					'type'        => 'string',
					'description' => __( 'Item identifier required for operations targeting a single entry.', 'mcp-ai-wpoos' ),
				),
				'instance'    => array(
					'type'        => 'string',
					'description' => __( 'JetEngine instance key used to route the request.', 'mcp-ai-wpoos' ),
				),
				'params'      => array(
					'type'                 => 'object',
					'description'          => __( 'Additional parameters forwarded to JetEngine.', 'mcp-ai-wpoos' ),
					'additionalProperties' => true,
				),
				'path_params' => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'description' => __( 'Ordered path parameters that should replace dynamic segments in the selected route.', 'mcp-ai-wpoos' ),
				),
				'transport'   => array(
					'type'        => 'string',
					'enum'        => array( 'auto', 'rest', 'http' ),
					'description' => __( 'Optional transport hint. Use `rest` to require internal controller dispatch or `http` to force wp_remote_request.', 'mcp-ai-wpoos' ),
					'default'     => 'auto',
				),
			),
			'required'             => array( 'operation' ),
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
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_jetengine_missing', __( 'JetEngine is not active on this site.', 'mcp-ai-wpoos' ) );
		}

		$operation = isset( $arguments['operation'] ) ? sanitize_key( $arguments['operation'] ) : '';
		if ( empty( $operation ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_operation', __( 'A JetEngine operation must be provided.', 'mcp-ai-wpoos' ) );
		}

		$config = WP_MCP_AI_JetEngine_Tool_Handlers::get_operation_config( $operation );
		if ( null === $config ) {
			return new WP_Error( 'wp_mcp_ai_jetengine_unknown_operation', __( 'The requested JetEngine operation is not supported.', 'mcp-ai-wpoos' ) );
		}

		$params = isset( $arguments['params'] ) && is_array( $arguments['params'] ) ? $arguments['params'] : array();

		if ( ! empty( $arguments['instance'] ) && is_string( $arguments['instance'] ) ) {
			$params['instance'] = sanitize_text_field( $arguments['instance'] );
		}

		if ( ! empty( $config['requires_instance'] ) && empty( $params['instance'] ) ) {
			return new WP_Error( 'wp_mcp_ai_jetengine_missing_instance', __( 'This operation requires a JetEngine instance identifier.', 'mcp-ai-wpoos' ) );
		}

		$transport = isset( $arguments['transport'] ) ? sanitize_key( $arguments['transport'] ) : 'auto';
		$id        = isset( $arguments['id'] ) ? sanitize_text_field( (string) $arguments['id'] ) : '';

		$path_params = array();
		if ( isset( $arguments['path_params'] ) && is_array( $arguments['path_params'] ) ) {
			foreach ( $arguments['path_params'] as $param ) {
				if ( is_scalar( $param ) || ( is_object( $param ) && method_exists( $param, '__toString' ) ) ) {
					$path_params[] = trim( sanitize_text_field( (string) $param ) );
				}
			}
		}

		$payload = array(
			'id'        => $id,
			'params'    => $params,
			'transport' => $transport,
		);

		if ( ! empty( $path_params ) ) {
			$payload['path_params'] = $path_params;
		}

		$result = WP_MCP_AI_JetEngine_Tool_Handlers::dispatch( $operation, $payload, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$summary_text = sprintf(
			/* translators: %s: JetEngine operation */
			__( 'Executed JetEngine operation: %s', 'mcp-ai-wpoos' ),
			$operation
		);

		return array(
			'message' => $summary_text,
			'summary' => $summary_text,
			'result'  => $result,
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'integration_external',

			'pattern_compatibility' => array( 'skill_router' ),

			'profession_tags'       => array( 'web_developer', 'api_developer' ),

			'risk_level'            => 'standard',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // For get/list operations.
			'write',               // For add/update/delete operations.
			'state-changing',      // Modifies JetEngine CCT data.
			'requires-plugin',     // Requires JetEngine plugin.
			'requires-capability', // Requires appropriate user capabilities.
			'local-only',          // No external API calls.
		);
	}
}
