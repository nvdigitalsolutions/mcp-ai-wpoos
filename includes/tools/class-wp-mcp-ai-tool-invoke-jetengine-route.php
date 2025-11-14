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
class WP_MCP_AI_Tool_Invoke_JetEngine_Route implements WP_MCP_AI_Tool_Interface {
	/**
	 * Determine whether JetEngine is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return WP_MCP_AI_JetEngine_Tool_Handlers::is_available();
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The JetEngine REST proxy tool is disabled because JetEngine is not active.', 'wp-mcp-ai' );
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'invoke_jetengine_route';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Invoke JetEngine REST Route', 'wp-mcp-ai' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Executes JetEngine REST API routes using the authenticated WordPress user context.', 'wp-mcp-ai' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'operation'   => array(
					'type'        => 'string',
					'enum'        => WP_MCP_AI_JetEngine_Tool_Handlers::get_supported_operations(),
					'description' => __( 'JetEngine operation to execute (for example `get_items` or `add_item`).', 'wp-mcp-ai' ),
				),
				'id'          => array(
					'type'        => 'string',
					'description' => __( 'Item identifier required for operations targeting a single entry.', 'wp-mcp-ai' ),
				),
				'instance'    => array(
					'type'        => 'string',
					'description' => __( 'JetEngine instance key used to route the request.', 'wp-mcp-ai' ),
				),
				'params'      => array(
					'type'                 => 'object',
					'description'          => __( 'Additional parameters forwarded to JetEngine.', 'wp-mcp-ai' ),
					'additionalProperties' => true,
				),
				'path_params' => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'description' => __( 'Ordered path parameters that should replace dynamic segments in the selected route.', 'wp-mcp-ai' ),
				),
				'transport'   => array(
					'type'        => 'string',
					'enum'        => array( 'auto', 'rest', 'http' ),
					'description' => __( 'Optional transport hint. Use `rest` to require internal controller dispatch or `http` to force wp_remote_request.', 'wp-mcp-ai' ),
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
			return new WP_Error( 'wp_mcp_ai_jetengine_missing', __( 'JetEngine is not active on this site.', 'wp-mcp-ai' ) );
		}

		$operation = isset( $arguments['operation'] ) ? sanitize_key( $arguments['operation'] ) : '';
		if ( empty( $operation ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_operation', __( 'A JetEngine operation must be provided.', 'wp-mcp-ai' ) );
		}

		$config = WP_MCP_AI_JetEngine_Tool_Handlers::get_operation_config( $operation );
		if ( null === $config ) {
			return new WP_Error( 'wp_mcp_ai_jetengine_unknown_operation', __( 'The requested JetEngine operation is not supported.', 'wp-mcp-ai' ) );
		}

		$params = isset( $arguments['params'] ) && is_array( $arguments['params'] ) ? $arguments['params'] : array();

		if ( ! empty( $arguments['instance'] ) && is_string( $arguments['instance'] ) ) {
			$params['instance'] = sanitize_text_field( $arguments['instance'] );
		}

		if ( ! empty( $config['requires_instance'] ) && empty( $params['instance'] ) ) {
			return new WP_Error( 'wp_mcp_ai_jetengine_missing_instance', __( 'This operation requires a JetEngine instance identifier.', 'wp-mcp-ai' ) );
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

		return $result;
	}
}
