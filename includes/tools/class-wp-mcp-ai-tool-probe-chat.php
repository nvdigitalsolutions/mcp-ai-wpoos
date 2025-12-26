<?php
/**
 * Tool that probes the local chat endpoint without contacting the model provider.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes a probe request against a published assistant.
 */
class WP_MCP_AI_Tool_Probe_Chat implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Shortcuts_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'probe_chat';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Probe Assistant Chat', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Runs an internal chat probe against a selected assistant to confirm the MCP stack is responsive.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'assistant_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Published assistant post ID to probe.', 'wp-mcp-ai' ),
				),
				'message'      => array(
					'type'        => 'string',
					'description' => __( 'Optional probe message stored in the transcript preview.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'assistant_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_shortcut_tasks() {
		return array(
			array(
				'title'       => __( 'Probe the assistant chat endpoint', 'wp-mcp-ai' ),
				'description' => __( 'Verify that a published assistant can load, sanitise messages, and return a chat probe response.', 'wp-mcp-ai' ),
				'arguments'   => new stdClass(),
			),
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

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to probe assistant chats.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}
		$assistant_id = isset( $arguments['assistant_id'] ) ? absint( $arguments['assistant_id'] ) : 0;
		if ( $assistant_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_missing_assistant', __( 'Provide a valid assistant ID to run the probe.', 'wp-mcp-ai' ) );
		}

		$message = isset( $arguments['message'] ) ? (string) $arguments['message'] : '';
		$message = trim( $message );

		if ( '' === $message ) {
			$message = __( 'Diagnostics probe issued from the WP oOS troubleshooting tool.', 'wp-mcp-ai' );
		}

		$controller = isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ? $GLOBALS['wp_mcp_ai_rest_controller'] : null;

		if ( ! $controller instanceof WP_MCP_AI_REST ) {
			return new WP_Error( 'wp_mcp_ai_rest_unavailable', __( 'The WP oOS REST controller is not available for probing.', 'wp-mcp-ai' ) );
		}

		$request = new WP_REST_Request( 'POST', '/' . WP_MCP_AI_REST::REST_NAMESPACE . '/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => sanitize_textarea_field( $message ),
				),
			)
		);
		$request->set_param(
			'options',
			array(
				'probe' => true,
			)
		);

		$response = $controller->handle_chat_request( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response instanceof WP_REST_Response ) {
			$data = $response->get_data();
		} else {
			$data = $response;
		}

		$assistant_summary = $this->summarise_assistant( $assistant_id );
		$warnings          = $this->build_warnings( $assistant_summary );

		return array(
			'checked_at' => gmdate( 'c' ),
			'assistant'  => $assistant_summary,
			'probe'      => isset( $data['probe'] ) ? $data['probe'] : $data,
			'message'    => isset( $data['message'] ) ? $data['message'] : '',
			'warnings'   => $warnings,
		);
	}

	/**
	 * Summarise assistant configuration for diagnostics output.
	 *
	 * @param int $assistant_id Assistant identifier.
	 * @return array
	 */
	protected function summarise_assistant( $assistant_id ) {
		$assistant_post = get_post( $assistant_id );

		if ( ! $assistant_post || WP_MCP_AI_Assistant_CPT::POST_TYPE !== $assistant_post->post_type ) {
			return array(
				'id'     => $assistant_id,
				'exists' => false,
			);
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$config   = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$provider = isset( $config['provider'] ) ? $config['provider'] : '';
		if ( '' === $provider ) {
			$provider = isset( $settings['default_provider'] ) ? $settings['default_provider'] : '';
		}

		$model = isset( $config['model'] ) ? $config['model'] : '';
		if ( '' === $model ) {
			if ( 'gemini' === $provider ) {
				$model = isset( $settings['default_gemini_model'] ) ? $settings['default_gemini_model'] : '';
			} else {
				$model = isset( $settings['default_model'] ) ? $settings['default_model'] : '';
			}
		}

		$temperature = isset( $config['temperature'] ) ? $config['temperature'] : null;
		if ( null !== $temperature ) {
			$temperature = floatval( $temperature );
		}

		return array(
			'id'                  => $assistant_post->ID,
			'title'               => get_the_title( $assistant_post ),
			'status'              => get_post_status( $assistant_post ),
			'provider'            => $provider,
			'model'               => $model,
			'temperature'         => $temperature,
			'tool_count'          => isset( $config['tools'] ) && is_array( $config['tools'] ) ? count( $config['tools'] ) : 0,
			'shortcut_count'      => isset( $config['tool_shortcuts'] ) && is_array( $config['tool_shortcuts'] ) ? count( $config['tool_shortcuts'] ) : 0,
			'memory_file_count'   => isset( $config['memory_files'] ) && is_array( $config['memory_files'] ) ? count( $config['memory_files'] ) : 0,
			'vector_store_active' => ! empty( $config['vector_store_id'] ),
			'permalink'           => get_permalink( $assistant_post ),
			'edit_link'           => current_user_can( 'edit_post', $assistant_post->ID ) ? get_edit_post_link( $assistant_post->ID, 'raw' ) : null,
		);
	}

	/**
	 * Highlight assistant-specific warnings, such as missing providers or API keys.
	 *
	 * @param array $assistant_summary Assistant metadata.
	 * @return array
	 */
	protected function build_warnings( array $assistant_summary ) {
		$warnings = array();

		if ( empty( $assistant_summary['exists'] ) && empty( $assistant_summary['title'] ) ) {
			$warnings[] = __( 'The assistant could not be loaded. Confirm it is published and accessible to administrators.', 'wp-mcp-ai' );
			return $warnings;
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$provider = isset( $assistant_summary['provider'] ) ? $assistant_summary['provider'] : '';

		if ( '' === $provider ) {
			$warnings[] = __( 'No language model provider is configured for this assistant.', 'wp-mcp-ai' );
		} elseif ( 'openai' === $provider && empty( $settings['openai_api_key'] ) ) {
			$warnings[] = __( 'OpenAI is selected but the site is missing an API key in the WP oOS settings.', 'wp-mcp-ai' );
		} elseif ( 'gemini' === $provider && empty( $settings['gemini_api_key'] ) ) {
			$warnings[] = __( 'Gemini is selected but the site is missing a Gemini API key.', 'wp-mcp-ai' );
		}

		if ( isset( $assistant_summary['tool_count'] ) && 0 === (int) $assistant_summary['tool_count'] ) {
			$warnings[] = __( 'The assistant has no tools enabled. Enable at least one tool to test tool execution flows.', 'wp-mcp-ai' );
		}

		return array_values( array_unique( $warnings ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
