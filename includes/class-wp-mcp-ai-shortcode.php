<?php
/**
 * Shortcode renderer for the front-end chat interface.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the [mcp_ai_chat] shortcode and enqueues frontend assets.
 */
class WP_MCP_AI_Shortcode {
	const SHORTCODE = 'mcp_ai_chat';

	/**
	 * Script handle for the chat interface.
	 */
	const SCRIPT_HANDLE = 'wp-mcp-ai-chat';

	/**
	 * Style handle for the chat interface.
	 */
	const STYLE_HANDLE = 'wp-mcp-ai-chat';

	/**
	 * Bootstraps hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );

		add_action( 'enqueue_block_assets', array( $this, 'maybe_enqueue_style_for_block_themes' ) );
	}

	/**
	 * Register assets used by the shortcode.
	 */
	public function register_assets() {
		$script_path = WP_MCP_AI_URL . 'assets/js/chat.js';
		$style_path  = WP_MCP_AI_URL . 'assets/css/chat.css';

		wp_register_style(
			self::STYLE_HANDLE,
			$style_path,
			array(),
			WP_MCP_AI_VERSION
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			$script_path,
			array(),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'wpMcpAiChat',
			array(
				'restUrl'        => esc_url_raw( rest_url( WP_MCP_AI_REST::REST_NAMESPACE ) ),
				'uploadEndpoint' => esc_url_raw( rest_url( 'wp/v2/media' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'strings'        => array(
					'placeholder'        => __( 'Ask something…', 'wp-mcp-ai' ),
					'send'               => __( 'Send', 'wp-mcp-ai' ),
					'sending'            => __( 'Sending message…', 'wp-mcp-ai' ),
					'waiting'            => __( 'Waiting for the assistant…', 'wp-mcp-ai' ),
					'error'              => __( 'Something went wrong. Please try again.', 'wp-mcp-ai' ),
					'missingAssistant'   => __( 'Assistant configuration was not found.', 'wp-mcp-ai' ),
					'notAuthorized'      => __( 'You do not have permission to chat with this assistant.', 'wp-mcp-ai' ),
					'toolExecuting'      => __( 'Running tool: %s', 'wp-mcp-ai' ),
					'toolSuccess'        => __( 'Tool response ready.', 'wp-mcp-ai' ),
					'toolError'          => __( 'The tool request failed.', 'wp-mcp-ai' ),
					'emptyMessage'       => __( 'Enter a message before sending.', 'wp-mcp-ai' ),
					'attachFile'         => __( 'Attach file', 'wp-mcp-ai' ),
					'attachmentsLabel'   => __( 'Attachments', 'wp-mcp-ai' ),
					'removeAttachment'   => __( 'Remove', 'wp-mcp-ai' ),
					'uploadingFile'      => __( 'Uploading “%s”…', 'wp-mcp-ai' ),
					'uploadError'        => __( 'The file could not be uploaded. Please try again.', 'wp-mcp-ai' ),
					'uploadInProgress'   => __( 'Please wait for uploads to finish before sending.', 'wp-mcp-ai' ),
					'downloadAttachment' => __( 'Download attachment', 'wp-mcp-ai' ),
				),
			)
		);
	}

	/**
	 * Ensure block themes and the Site Editor receive the base styles when editing.
	 */
	public function maybe_enqueue_style_for_block_themes() {
		if ( is_admin() && wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::STYLE_HANDLE );
		}
	}

	/**
	 * Render the chat shortcode.
	 *
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Content (unused).
	 * @param string $tag     Shortcode tag.
	 *
	 * @return string
	 */
	public function render_shortcode( $atts, $content = '', $tag = '' ) {
		$atts = shortcode_atts(
			array(
				'assistant' => '',
			),
			$atts,
			$tag
		);

		$assistant_id = absint( $atts['assistant'] );

		if ( ! $assistant_id ) {
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$assistant_id = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
		}

		if ( ! $assistant_id ) {
			return '<div class="wp-mcp-ai-chat__notice">' . esc_html__( 'No assistant has been selected. Please provide an assistant attribute or configure a default.', 'wp-mcp-ai' ) . '</div>';
		}

		$assistant = get_post( $assistant_id );
		if ( ! $assistant || WP_MCP_AI_Assistant_CPT::POST_TYPE !== $assistant->post_type || 'publish' !== $assistant->post_status ) {
			return '<div class="wp-mcp-ai-chat__notice">' . esc_html__( 'The requested assistant is not available.', 'wp-mcp-ai' ) . '</div>';
		}

		$capability = wp_mcp_ai_get_required_chat_capability( $assistant_id, 'shortcode' );

		if ( $capability && 'public' !== $capability && ! current_user_can( $capability ) ) {
			return '<div class="wp-mcp-ai-chat__notice">' . esc_html__( 'You do not have permission to chat with this assistant.', 'wp-mcp-ai' ) . '</div>';
		}

		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			$this->register_assets();
		}

		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_enqueue_style( self::STYLE_HANDLE );

		$instance_id = wp_unique_id( 'wp-mcp-ai-chat-' );
		$textarea_id = $instance_id . '-input';

		$config = array(
			'id'               => $instance_id,
			'assistantId'      => $assistant_id,
			'messagesEndpoint' => esc_url_raw( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/chat' ) ),
			'toolsEndpoint'    => esc_url_raw( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/tools' ) ),
		);

		$inline_config  = 'window.wpMcpAiChatInstances = window.wpMcpAiChatInstances || {};';
		$inline_config .= 'window.wpMcpAiChatInstances[' . wp_json_encode( $instance_id ) . '] = ' . wp_json_encode( $config ) . ';';
		wp_add_inline_script( self::SCRIPT_HANDLE, $inline_config, 'before' );

		ob_start();
		?>
		<div class="wp-mcp-ai-chat" id="<?php echo esc_attr( $instance_id ); ?>" data-wp-mcp-ai-chat>
			<div class="wp-mcp-ai-chat__messages" aria-live="polite"></div>
			<div class="wp-mcp-ai-chat__status" role="status" aria-live="polite" hidden></div>
			<form class="wp-mcp-ai-chat__form" data-instance-id="<?php echo esc_attr( $instance_id ); ?>">
				<label class="wp-mcp-ai-chat__label" for="<?php echo esc_attr( $textarea_id ); ?>">
					<?php echo esc_html( get_the_title( $assistant_id ) ); ?>
				</label>
				<textarea id="<?php echo esc_attr( $textarea_id ); ?>" class="wp-mcp-ai-chat__input" rows="4" placeholder="<?php echo esc_attr__( 'Ask something…', 'wp-mcp-ai' ); ?>" required></textarea>
				<div class="wp-mcp-ai-chat__attachments" hidden>
					<div class="wp-mcp-ai-chat__attachments-header"><?php esc_html_e( 'Attachments', 'wp-mcp-ai' ); ?></div>
					<ul class="wp-mcp-ai-chat__attachments-list"></ul>
				</div>
				<div class="wp-mcp-ai-chat__actions">
					<input type="file" class="wp-mcp-ai-chat__file-input" multiple hidden />
					<button type="button" class="wp-mcp-ai-chat__attach">
						<?php esc_html_e( 'Attach file', 'wp-mcp-ai' ); ?>
					</button>
					<button type="submit" class="wp-mcp-ai-chat__submit">
						<?php esc_html_e( 'Send', 'wp-mcp-ai' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
}
