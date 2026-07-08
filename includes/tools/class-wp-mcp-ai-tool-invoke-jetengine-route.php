<?php
/**
 * Tool proxying requests to JetEngine REST API routes.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
				'prefer_mcp'  => array(
					'type'        => 'boolean',
					'description' => __( 'When true and JetEngine 3.8+ MCP Server is available, route through MCP instead of direct REST. Default: true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'required'             => array( 'operation' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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

		// Try MCP dispatch if preferred and available.
		$prefer_mcp = isset( $arguments['prefer_mcp'] ) ? (bool) $arguments['prefer_mcp'] : true;
		$mcp_tool   = $prefer_mcp ? WP_MCP_AI_JetEngine_Tool_Handlers::get_mcp_tool_name( $operation ) : null;
		if ( null !== $mcp_tool && class_exists( 'WP_MCP_AI_JetEngine_Compat' ) && WP_MCP_AI_JetEngine_Compat::has_mcp_server() ) {
			if ( class_exists( 'WP_MCP_AI_JetEngine_MCP_Client' ) || $this->load_mcp_client() ) {
				$client     = new WP_MCP_AI_JetEngine_MCP_Client();
				$mcp_result = $client->tools_call( $mcp_tool, $params );

				if ( ! is_wp_error( $mcp_result ) ) {
					$summary_text = sprintf(
						/* translators: %s: JetEngine operation */
						__( 'Executed JetEngine operation via MCP: %s', 'mcp-ai-wpoos' ),
						$operation
					);

					return array(
						'message'   => $summary_text,
						'summary'   => $summary_text,
						'result'    => $mcp_result,
						'transport' => 'mcp',
					);
				}
				// MCP failed — fall through to legacy REST.
			}
		}

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

		// Extract JetEngine-level error notices / messages so the AI assistant
		// can see actionable failure details without digging through raw data.
		$extracted = self::extract_jetengine_errors( $result );
		if ( ! empty( $extracted ) ) {
			$result['_errors'] = $extracted;
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
	 * Load the MCP client class if not already loaded.
	 *
	 * @since 2.1.0
	 *
	 * @return bool True if the client class is available.
	 */
	private function load_mcp_client() {
		if ( class_exists( 'WP_MCP_AI_JetEngine_MCP_Client' ) ) {
			return true;
		}

		$client_file = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-mcp-client.php'
			: '';

		if ( ! empty( $client_file ) && file_exists( $client_file ) ) {
			require_once $client_file;
			return true;
		}

		return false;
	}


	/**
	 * Extract actionable error messages from a JetEngine REST response.
	 *
	 * JetEngine endpoints often return `{ success: false, notices: [...],
	 * message: "..." }` with HTTP 200.  This helper pulls out user-facing
	 * error text so the AI assistant sees it immediately without digging
	 * through the raw response data.
	 *
	 * @since 2.1.1
	 *
	 * @param array $result Normalised dispatch result.
	 * @return array<string, mixed> Extracted error info (empty when no errors found).
	 */
	private static function extract_jetengine_errors( array $result ) {
		$errors = array();

		// Only inspect REST / HTTP responses (skip MCP, which has its own format).
		if ( empty( $result['transport'] ) || ! in_array( $result['transport'], array( 'rest', 'http' ), true ) ) {
			return $errors;
		}

		$data = isset( $result['data'] ) ? $result['data'] : array();
		if ( ! is_array( $data ) ) {
			return $errors;
		}

		// JetEngine v2 endpoints: { success: false, message: "...", notices: [...] }
		if ( isset( $data['success'] ) && false === $data['success'] ) {
			$errors['jetengine_success'] = false;

			if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
				$errors['message'] = $data['message'];
			}

			if ( ! empty( $data['notices'] ) && is_array( $data['notices'] ) ) {
				$error_messages = array();
				foreach ( $data['notices'] as $notice ) {
					if ( ! empty( $notice['type'] ) && 'error' === $notice['type'] && ! empty( $notice['message'] ) ) {
						$error_messages[] = $notice['message'];
					}
				}
				if ( ! empty( $error_messages ) ) {
					$errors['notices'] = $error_messages;
				}
			}
		}

		return $errors;
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
			'local-only',          // Dispatches to local site REST API; no external API calls.
		);
	}
}
