<?php
/**
 * Pro Inline Assistant — Gutenberg sidebar plugin for inline AI text transformation.
 *
 * Zed equivalent: Inline Assistant (Ctrl+Enter) — select text → describe
 * transformation → model rewrites selection in place.
 *
 * Registers a Gutenberg sidebar panel that appears in the post editor,
 * allowing users to select text and transform it with AI prompts.
 *
 * @package NV_oOS_Pro
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_Inline_Assistant
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Pro_Inline_Assistant {

	/**
	 * Register hooks.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function init() {
		// Only load in admin with block editor.
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Enqueue the inline assistant JavaScript and CSS for the block editor.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function enqueue_assets() {
		// Only enqueue if the block editor is active.
		$screen = get_current_screen();
		if ( ! $screen || ! $screen->is_block_editor() ) {
			return;
		}

		$dist_dir   = WP_MCP_AI_PRO_PATH . 'assets/spa/dist/';
		$dist_url   = WP_MCP_AI_PRO_URL . 'assets/spa/dist/';
		$asset_file = $dist_dir . 'inline-assistant.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset_data = include $asset_file;

		wp_enqueue_script(
			'wp-mcp-ai-inline-assistant',
			$dist_url . 'inline-assistant.js',
			array_merge( $asset_data['dependencies'], array( 'wp-plugins', 'wp-edit-post', 'wp-data', 'wp-rich-text', 'wp-keycodes' ) ),
			$asset_data['version'],
			true
		);

		wp_localize_script(
			'wp-mcp-ai-inline-assistant',
			'wpMcpAiInline',
			array(
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'restUrl' => rest_url(),
				'i18n'    => array(
					'title'        => __( 'AI Inline Assistant', 'mcp-ai-wpoos' ),
					'placeholder'  => __( 'Describe the transformation…', 'mcp-ai-wpoos' ),
					'transform'    => __( 'Transform', 'mcp-ai-wpoos' ),
					'transforming' => __( 'Transforming…', 'mcp-ai-wpoos' ),
					'replace'      => __( 'Replace Selection', 'mcp-ai-wpoos' ),
					'insertAfter'  => __( 'Insert After', 'mcp-ai-wpoos' ),
					'noSelection'  => __( 'Select text in the editor, then describe how you want to transform it.', 'mcp-ai-wpoos' ),
					'error'        => __( 'Transformation failed. Please try again.', 'mcp-ai-wpoos' ),
				),
			)
		);
	}

	/**
	 * Register REST endpoint for inline transformation.
	 *
	 * This is a simplified chat endpoint that sends a single-turn request
	 * for text transformation without the full agentic loop.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'mcp-ai-pro/v1',
			'/inline/transform',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_transform' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'text'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'prompt'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'model'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'gpt-4.1',
					),
					'provider' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'openai',
					),
				),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @since 1.7.0
	 * @return bool|WP_Error
	 */
	public static function check_permission() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You do not have permission to use the inline assistant.', 'mcp-ai-wpoos' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Handle the inline transform request.
	 *
	 * Sends a single-turn request to the AI provider with the selected text
	 * and the user's transformation prompt. No tools, no agentic loop —
	 * just a simple text-in/text-out transformation.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_transform( $request ) {
		$text     = $request->get_param( 'text' );
		$prompt   = $request->get_param( 'prompt' );
		$model    = $request->get_param( 'model' );
		$provider = $request->get_param( 'provider' );

		// Build the system prompt for text transformation.
		$system_prompt = sprintf(
			"You are an inline text transformation assistant. The user will provide text and a transformation instruction.\n\n" .
			"RULES:\n" .
			"1. Return ONLY the transformed text — no explanations, no markdown framing, no \"Here is the result\".\n" .
			"2. Preserve the original formatting (paragraphs, line breaks) unless the instruction says otherwise.\n" .
			"3. If the instruction is unclear, make your best guess and transform the text.\n" .
			"4. Do NOT add any text that wasn't requested.\n\n" .
			"SELECTED TEXT:\n%s\n\n" .
			'TRANSFORMATION INSTRUCTION: %s',
			$text,
			$prompt
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $system_prompt,
			),
		);

		// Resolve the provider client.
		$client = self::get_provider_client( $provider );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Make the API call.
		$response = $client->chat_completion(
			$messages,
			array(
				'model'       => $model,
				'temperature' => 0.3,
				'max_tokens'  => min( 4096, self::estimate_max_tokens( $text ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$transformed = isset( $response['choices'][0]['message']['content'] )
			? $response['choices'][0]['message']['content']
			: '';

		// Strip any markdown code block wrapping the model might add.
		$transformed = preg_replace( '/^```[\w]*\n?/', '', $transformed );
		$transformed = preg_replace( '/\n?```$/', '', $transformed );
		$transformed = trim( $transformed );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'transformed_text' => $transformed,
					'model'            => $model,
				),
			)
		);
	}

	/**
	 * Resolve a provider client instance.
	 *
	 * @since 1.7.0
	 *
	 * @param string $provider Provider slug.
	 * @return object|WP_Error
	 */
	private static function get_provider_client( $provider ) {
		// Try the language model router first.
		if ( class_exists( 'WP_MCP_AI_Language_Model_Router' ) ) {
			$container = wp_mcp_ai_container();
			if ( $container ) {
				try {
					return $container->get( 'client.' . $provider );
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- Intentional: fall through to direct instantiation.
				} catch ( \Exception $e ) {
					// Fall through.
				}
			}
		}

		// Direct client instantiation as fallback.
		switch ( $provider ) {
			case 'openai':
				if ( class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
					return new WP_MCP_AI_OpenAI_Client();
				}
				break;
			case 'anthropic':
				if ( class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
					return new WP_MCP_AI_Anthropic_Client();
				}
				break;
			case 'google':
			case 'gemini':
				if ( class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
					return new WP_MCP_AI_Gemini_Client();
				}
				break;
			case 'deepseek':
				if ( class_exists( 'WP_MCP_AI_DeepSeek_Client' ) ) {
					return new WP_MCP_AI_DeepSeek_Client();
				}
				break;
			case 'openrouter':
				if ( class_exists( 'WP_MCP_AI_OpenRouter_Client' ) ) {
					return new WP_MCP_AI_OpenRouter_Client();
				}
				break;
		}

		return new WP_Error(
			'provider_not_found',
			sprintf(
				/* translators: %s: provider name */
				__( 'Provider "%s" is not available.', 'mcp-ai-wpoos' ),
				esc_html( $provider )
			)
		);
	}

	/**
	 * Estimate max tokens based on input length.
	 *
	 * @since 1.7.0
	 *
	 * @param string $text Input text.
	 * @return int
	 */
	private static function estimate_max_tokens( $text ) {
		// Rough estimate: ~4 chars per token.
		$input_tokens = ceil( strlen( $text ) / 4 );

		// Return enough tokens for transformation (at least 256, up to 4096).
		return max( 256, min( 4096, $input_tokens * 3 ) );
	}
}
