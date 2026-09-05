<?php
/**
 * WordPress-specific tool that probes the local chat endpoint.
 *
 * Lives in the WordPress adapter because it directly calls the WordPress
 * REST controller, CPT classes, and settings API — operations that are
 * inherently tied to the WordPress runtime.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Tool\AbstractTool;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Executes a probe request against a published assistant.
 *
 * Does not contact the AI model provider — it verifies that the
 * WordPress MCP stack (REST controller, assistant CPT, settings)
 * is responsive and correctly configured.
 */
class ProbeChatTool extends AbstractTool {

	public function getSlug(): string {
		return 'probe_chat';
	}

	public function getName(): string {
		return 'Probe Assistant Chat';
	}

	public function getDescription(): string {
		return 'Runs an internal chat probe against a selected assistant to confirm the MCP stack is responsive.';
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'assistant_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Published assistant post ID to probe.',
				),
				'message'      => array(
					'type'        => 'string',
					'description' => 'Optional probe message stored in the transcript preview.',
				),
			),
			'required'             => array( 'assistant_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the probe.
	 *
	 * @param array $arguments Tool arguments (assistant_id, message).
	 * @param array $context   Execution context (user_id, etc.).
	 * @return array|WP_Error  Probe result or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$user_id = isset( $context['user_id'] )
			? \absint( $context['user_id'] )
			: \get_current_user_id();

		if ( ! $user_id || ! \user_can( $user_id, 'manage_options' ) ) {
			return $this->errors->forbidden(
				'You do not have permission to probe assistant chats.'
			);
		}

		if ( \is_multisite() && ! \is_user_member_of_blog( $user_id, \get_current_blog_id() ) ) {
			return $this->errors->forbidden(
				'You do not have access to this site.'
			);
		}

		$assistant_id = isset( $arguments['assistant_id'] )
			? \absint( $arguments['assistant_id'] )
			: 0;

		if ( $assistant_id <= 0 ) {
			return $this->errors->validationFailed(
				'Provide a valid assistant ID to run the probe.',
				array( 'assistant_id' => array( 'This field is required.' ) ),
			);
		}

		$message = isset( $arguments['message'] )
			? \trim( (string) $arguments['message'] )
			: '';

		if ( '' === $message ) {
			$message = 'Diagnostics probe issued from the NV oOS troubleshooting tool.';
		}

		// Access the WordPress REST controller via the global registry.
		// Standalone gate: the base REST class references WP_MCP_AI_PATH
		// at file scope, so the instanceof probe must not autoload it.
		$controller = $GLOBALS['wp_mcp_ai_rest_controller'] ?? null;

		if ( ! \defined( 'WP_MCP_AI_PATH' ) || ! $controller instanceof \WP_MCP_AI_REST ) {
			return $this->errors->create(
				'wp_mcp_ai_rest_unavailable',
				'The NV oOS REST controller is not available for probing.',
			);
		}

		$rest_namespace = \WP_MCP_AI_REST::REST_NAMESPACE;

		$request = new WP_REST_Request( 'POST', '/' . $rest_namespace . '/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => \sanitize_textarea_field( $message ),
				),
			)
		);
		$request->set_param(
			'options',
			array( 'probe' => true )
		);

		$response = $controller->handle_chat_request( $request );

		if ( \is_wp_error( $response ) ) {
			return $this->errors->create(
				$response->get_error_code(),
				$response->get_error_message(),
			);
		}

		$data = ( $response instanceof WP_REST_Response )
			? $response->get_data()
			: $response;

		$assistant_summary = $this->summariseAssistant( $assistant_id );
		$warnings          = $this->buildWarnings( $assistant_summary );

		return $this->success(
			'Probe completed.',
			array(
				'checked_at' => \gmdate( 'c' ),
				'assistant'  => $assistant_summary,
				'probe'      => $data['probe'] ?? $data,
				'message'    => $data['message'] ?? '',
				'warnings'   => $warnings,
			)
		);
	}

	/**
	 * Summarise assistant configuration for diagnostics output.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array{id: int, exists: bool, ...}
	 */
	private function summariseAssistant( int $assistant_id ): array {
		// Standalone gate: the base assistant CPT and settings classes are
		// absent; fall back to a plain post check with the literal slug.
		if ( ! \defined( 'WP_MCP_AI_PATH' ) || ! \class_exists( 'WP_MCP_AI_Assistant_CPT' ) || ! \class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$post = \get_post( $assistant_id );
			return array(
				'id'     => $assistant_id,
				'exists' => (bool) $post && 'mcp_ai_assistant' === $post->post_type,
			);
		}

		$post_type = \WP_MCP_AI_Assistant_CPT::POST_TYPE;
		$post      = \get_post( $assistant_id );

		if ( ! $post || $post_type !== $post->post_type ) {
			return array(
				'id'     => $assistant_id,
				'exists' => false,
			);
		}

		$settings = \WP_MCP_AI_Admin_Settings::get_settings();
		$config   = \WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$provider = $config['provider'] ?? '';
		if ( '' === $provider ) {
			$provider = $settings['default_provider'] ?? '';
		}

		$model = $config['model'] ?? '';
		if ( '' === $model ) {
			$model = ( 'gemini' === $provider )
				? ( $settings['default_gemini_model'] ?? '' )
				: ( $settings['default_model'] ?? '' );
		}

		$temperature = isset( $config['temperature'] ) ? (float) $config['temperature'] : null;

		return array(
			'id'                  => $post->ID,
			'title'               => \get_the_title( $post ),
			'status'              => \get_post_status( $post ),
			'provider'            => $provider,
			'model'               => $model,
			'temperature'         => $temperature,
			'tool_count'          => isset( $config['tools'] ) && \is_array( $config['tools'] ) ? \count( $config['tools'] ) : 0,
			'shortcut_count'      => isset( $config['tool_shortcuts'] ) && \is_array( $config['tool_shortcuts'] ) ? \count( $config['tool_shortcuts'] ) : 0,
			'memory_file_count'   => isset( $config['memory_files'] ) && \is_array( $config['memory_files'] ) ? \count( $config['memory_files'] ) : 0,
			'vector_store_active' => ! empty( $config['vector_store_id'] ),
			'permalink'           => \get_permalink( $post ),
			'edit_link'           => \current_user_can( 'edit_post', $post->ID )
				? \get_edit_post_link( $post->ID, 'raw' )
				: null,
		);
	}

	/**
	 * Highlight assistant-specific warnings (missing providers, API keys, etc.).
	 *
	 * @param array $summary Assistant metadata from summariseAssistant().
	 * @return string[]
	 */
	private function buildWarnings( array $summary ): array {
		$warnings = array();

		if ( empty( $summary['exists'] ) && empty( $summary['title'] ) ) {
			$warnings[] = 'The assistant could not be loaded. Confirm it is published and accessible to administrators.';
			return $warnings;
		}

		$settings = \WP_MCP_AI_Admin_Settings::get_settings();
		$provider = $summary['provider'] ?? '';

		if ( '' === $provider ) {
			$warnings[] = 'No language model provider is configured for this assistant.';
		} elseif ( 'openai' === $provider && empty( $settings['openai_api_key'] ) ) {
			$warnings[] = 'OpenAI is selected but the site is missing an API key in the NV oOS settings.';
		} elseif ( 'gemini' === $provider && empty( $settings['gemini_api_key'] ) ) {
			$warnings[] = 'Gemini is selected but the site is missing a Gemini API key.';
		}

		if ( isset( $summary['tool_count'] ) && 0 === (int) $summary['tool_count'] ) {
			$warnings[] = 'The assistant has no tools enabled. Enable at least one tool to test tool execution flows.';
		}

		return \array_values( \array_unique( $warnings ) );
	}
}
