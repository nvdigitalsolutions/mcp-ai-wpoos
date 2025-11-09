<?php
/**
 * Gutenberg blocks integration for WP oOS Chat widgets.
 *
 * Registers chat-related blocks for use in the block editor.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Gutenberg block registration for chat widgets.
 */
class WP_MCP_AI_Chat_Blocks {

	/**
	 * Initialize the blocks integration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor_assets' ) );
	}

	/**
	 * Register chat blocks.
	 */
	public static function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Chat Block (Main Widget).
		register_block_type(
			'wp-mcp-ai/chat',
			array(
				'render_callback' => array( __CLASS__, 'render_chat_block' ),
				'attributes'      => array(
					'assistant'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'allowGuests'      => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'saveTranscript'   => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'enableStreaming'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		// Chat Intro Block.
		register_block_type(
			'wp-mcp-ai/chat-intro',
			array(
				'render_callback' => array( __CLASS__, 'render_chat_intro_block' ),
				'attributes'      => array(
					'title'       => array(
						'type'    => 'string',
						'default' => __( 'Welcome to WP oOS Chat', 'wp-mcp-ai' ),
					),
					'description' => array(
						'type'    => 'string',
						'default' => __( 'Start a conversation with your AI assistant to plan tasks, explore MCP tools, or keep track of ongoing projects.', 'wp-mcp-ai' ),
					),
					'buttonText'  => array(
						'type'    => 'string',
						'default' => __( 'Open Chat', 'wp-mcp-ai' ),
					),
					'buttonUrl'   => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		// Chat FAQ Block.
		register_block_type(
			'wp-mcp-ai/chat-faq',
			array(
				'render_callback' => array( __CLASS__, 'render_chat_faq_block' ),
				'attributes'      => array(
					'title' => array(
						'type'    => 'string',
						'default' => __( 'How the chat works', 'wp-mcp-ai' ),
					),
				),
			)
		);

		// Chat Usage Timer Block.
		register_block_type(
			'wp-mcp-ai/chat-usage-timer',
			array(
				'render_callback' => array( __CLASS__, 'render_chat_usage_timer_block' ),
				'attributes'      => array(
					'title'        => array(
						'type'    => 'string',
						'default' => __( 'Chat Usage Timer', 'wp-mcp-ai' ),
					),
					'assistantId'  => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Enqueue block editor assets.
	 */
	public static function enqueue_block_editor_assets() {
		$asset_file = WP_MCP_AI_PATH . 'assets/js/chat-blocks.asset.php';
		$asset_data = file_exists( $asset_file ) ? include $asset_file : array( 'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components' ), 'version' => WP_MCP_AI_VERSION );

		wp_enqueue_script(
			'wp-mcp-ai-chat-blocks',
			WP_MCP_AI_URL . 'assets/js/chat-blocks.js',
			$asset_data['dependencies'],
			$asset_data['version'],
			true
		);

		wp_localize_script(
			'wp-mcp-ai-chat-blocks',
			'wpMcpAiChatBlocks',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_chat' ),
			)
		);
	}

	/**
	 * Render the chat block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_chat_block( $attributes ) {
		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			return '<p>' . esc_html__( 'WP oOS plugin is not fully loaded.', 'wp-mcp-ai' ) . '</p>';
		}

		$shortcode_attrs = array();

		if ( ! empty( $attributes['assistant'] ) ) {
			$shortcode_attrs['assistant'] = absint( $attributes['assistant'] );
		}

		if ( ! empty( $attributes['allowGuests'] ) ) {
			$shortcode_attrs['allow_guests'] = 'true';
		}

		if ( isset( $attributes['saveTranscript'] ) && ! $attributes['saveTranscript'] ) {
			$shortcode_attrs['save_transcript'] = 'false';
		}

		if ( ! empty( $attributes['enableStreaming'] ) ) {
			$shortcode_attrs['enable_streaming'] = 'true';
		}

		$shortcode = '[' . WP_MCP_AI_Shortcode::SHORTCODE;

		foreach ( $shortcode_attrs as $key => $value ) {
			$shortcode .= ' ' . $key . '="' . esc_attr( $value ) . '"';
		}

		$shortcode .= ']';

		return do_shortcode( $shortcode );
	}

	/**
	 * Render the chat intro block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_chat_intro_block( $attributes ) {
		$title       = isset( $attributes['title'] ) ? $attributes['title'] : __( 'Welcome to WP oOS Chat', 'wp-mcp-ai' );
		$description = isset( $attributes['description'] ) ? $attributes['description'] : '';
		$button_text = isset( $attributes['buttonText'] ) ? $attributes['buttonText'] : '';
		$button_url  = isset( $attributes['buttonUrl'] ) ? $attributes['buttonUrl'] : '';

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-chat-intro wp-mcp-ai-chat-intro">
			<?php if ( ! empty( $title ) ) : ?>
				<h2 class="wp-mcp-ai-chat-intro__title"><?php echo esc_html( $title ); ?></h2>
			<?php endif; ?>

			<?php if ( ! empty( $description ) ) : ?>
				<div class="wp-mcp-ai-chat-intro__description"><?php echo wp_kses_post( wpautop( $description ) ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $button_text ) && ! empty( $button_url ) ) : ?>
				<a class="wp-mcp-ai-chat-intro__button" href="<?php echo esc_url( $button_url ); ?>"><?php echo esc_html( $button_text ); ?></a>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the chat FAQ block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_chat_faq_block( $attributes ) {
		$title = isset( $attributes['title'] ) ? $attributes['title'] : __( 'How the chat works', 'wp-mcp-ai' );

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-chat-faq wp-mcp-ai-chat-faq">
			<h2 class="wp-mcp-ai-chat-faq__title"><?php echo esc_html( $title ); ?></h2>
			<dl class="wp-mcp-ai-chat-faq__list">
				<dt class="wp-mcp-ai-chat-faq__question"><?php esc_html_e( 'Do I need to sign in to chat?', 'wp-mcp-ai' ); ?></dt>
				<dd class="wp-mcp-ai-chat-faq__answer"><?php esc_html_e( 'Guests can start chatting when temporary tokens are allowed, or you can require authenticated users only.', 'wp-mcp-ai' ); ?></dd>
				<dt class="wp-mcp-ai-chat-faq__question"><?php esc_html_e( 'How do I provide more context?', 'wp-mcp-ai' ); ?></dt>
				<dd class="wp-mcp-ai-chat-faq__answer"><?php esc_html_e( 'Upload files or paste notes directly into the conversation to give the assistant additional detail.', 'wp-mcp-ai' ); ?></dd>
			</dl>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the chat usage timer block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_chat_usage_timer_block( $attributes ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view chat usage.', 'wp-mcp-ai' ) . '</p>';
		}

		$title = isset( $attributes['title'] ) ? $attributes['title'] : __( 'Chat Usage Timer', 'wp-mcp-ai' );

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-chat-usage-timer">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php esc_html_e( 'Chat usage statistics displayed here. Use Elementor widget for full functionality.', 'wp-mcp-ai' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}
}

// Initialize the blocks.
WP_MCP_AI_Chat_Blocks::init();
