<?php
/**
 * AI CPT Management Integration
 *
 * Adds AI assistant metabox to WordPress CPT edit screens (posts, pages, products, terms).
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds AI assistant metabox to WordPress custom post type edit screens.
 *
 * This class integrates with the WordPress post edit screens (post.php, post-new.php)
 * and term edit screens (term.php) to display an AI assistant metabox that allows
 * users to interact with AI tools to help create, edit, and manage content.
 */
class WP_MCP_AI_Pro_CPT_AI_Integration {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Pro_CPT_AI_Integration|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Pro_CPT_AI_Integration
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Only run on admin pages.
		if ( ! is_admin() ) {
			return;
		}

		// Check if feature is enabled.
		if ( ! $this->is_feature_enabled() ) {
			return;
		}

		// Add metabox for posts and pages.
		add_action( 'add_meta_boxes', array( $this, 'add_ai_metabox' ) );

		// Add metabox for terms.
		add_action( 'load-term.php', array( $this, 'add_term_ai_metabox' ) );
		add_action( 'load-edit-tags.php', array( $this, 'add_term_ai_metabox' ) );

		// Enqueue admin scripts and styles.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_wp_mcp_ai_cpt_chat', array( $this, 'handle_ajax_chat' ) );
	}

	/**
	 * Check if the feature is enabled.
	 *
	 * @return bool
	 */
	private function is_feature_enabled() {
		// Feature is Pro only.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_ai_cpt_management'] );
	}

	/**
	 * Add AI assistant metabox to post types.
	 *
	 * @param string $post_type Current post type.
	 */
	public function add_ai_metabox( $post_type ) {
		// Get supported post types.
		$supported_post_types = $this->get_supported_post_types();

		if ( ! in_array( $post_type, $supported_post_types, true ) ) {
			return;
		}

		add_meta_box(
			'wp_mcp_ai_assistant',
			__( 'AI Assistant', 'wp-mcp-ai' ),
			array( $this, 'render_ai_metabox' ),
			$post_type,
			'side',
			'high'
		);
	}

	/**
	 * Add AI assistant metabox for term edit screens.
	 */
	public function add_term_ai_metabox() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';

		if ( ! $taxonomy ) {
			return;
		}

		// Get supported taxonomies.
		$supported_taxonomies = $this->get_supported_taxonomies();

		if ( ! in_array( $taxonomy, $supported_taxonomies, true ) ) {
			return;
		}

		// Add action to render metabox after term form fields.
		add_action( "{$taxonomy}_edit_form", array( $this, 'render_term_ai_metabox' ), 10, 2 );
	}

	/**
	 * Get supported post types.
	 *
	 * @return array
	 */
	private function get_supported_post_types() {
		$post_types = array( 'post', 'page' );

		// Add WooCommerce product if active.
		if ( class_exists( 'WooCommerce' ) ) {
			$post_types[] = 'product';
		}

		/**
		 * Filter the supported post types for AI assistant integration.
		 *
		 * @param array $post_types Supported post types.
		 */
		return apply_filters( 'wp_mcp_ai_cpt_supported_post_types', $post_types );
	}

	/**
	 * Get supported taxonomies.
	 *
	 * @return array
	 */
	private function get_supported_taxonomies() {
		$taxonomies = array( 'category', 'post_tag' );

		// Add WooCommerce taxonomies if active.
		if ( class_exists( 'WooCommerce' ) ) {
			$taxonomies[] = 'product_cat';
			$taxonomies[] = 'product_tag';
		}

		/**
		 * Filter the supported taxonomies for AI assistant integration.
		 *
		 * @param array $taxonomies Supported taxonomies.
		 */
		return apply_filters( 'wp_mcp_ai_cpt_supported_taxonomies', $taxonomies );
	}

	/**
	 * Render AI assistant metabox for posts.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_ai_metabox( $post ) {
		?>
		<div class="wp-mcp-ai-cpt-assistant" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-post-type="<?php echo esc_attr( $post->post_type ); ?>">
			<div class="wp-mcp-ai-cpt-chat-messages" id="wp-mcp-ai-cpt-chat-messages">
				<div class="wp-mcp-ai-cpt-welcome-message">
					<p><?php esc_html_e( '👋 Hi! I\'m your AI assistant. I can help you:', 'wp-mcp-ai' ); ?></p>
					<ul>
						<li><?php esc_html_e( '✍️ Write and edit content', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( '🔍 Research topics and find information', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( '📝 Generate titles and descriptions', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( '🎨 Create images and media', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( '🔧 Use any available AI tools', 'wp-mcp-ai' ); ?></li>
					</ul>
					<p><strong><?php esc_html_e( 'Try asking me:', 'wp-mcp-ai' ); ?></strong></p>
					<ul>
						<li><?php esc_html_e( '"Write an introduction for this post"', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( '"Generate an SEO-friendly title"', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( '"Create a featured image"', 'wp-mcp-ai' ); ?></li>
					</ul>
				</div>
			</div>
			<div class="wp-mcp-ai-cpt-chat-input-wrapper">
				<textarea 
					id="wp-mcp-ai-cpt-chat-input" 
					class="wp-mcp-ai-cpt-chat-input" 
					placeholder="<?php esc_attr_e( 'Ask me anything...', 'wp-mcp-ai' ); ?>"
					rows="3"
				></textarea>
				<button type="button" id="wp-mcp-ai-cpt-send-button" class="button button-primary wp-mcp-ai-cpt-send-button">
					<span class="dashicons dashicons-format-chat"></span>
					<?php esc_html_e( 'Send', 'wp-mcp-ai' ); ?>
				</button>
			</div>
			<div class="wp-mcp-ai-cpt-chat-status" id="wp-mcp-ai-cpt-chat-status"></div>
		</div>
		<?php
	}

	/**
	 * Render AI assistant metabox for terms.
	 *
	 * @param WP_Term $term    Current term object.
	 * @param string  $taxonomy Current taxonomy.
	 */
	public function render_term_ai_metabox( $term, $taxonomy ) {
		?>
		<div class="wp-mcp-ai-cpt-term-assistant-wrapper">
			<h2><?php esc_html_e( 'AI Assistant', 'wp-mcp-ai' ); ?></h2>
			<div class="wp-mcp-ai-cpt-assistant" data-term-id="<?php echo esc_attr( $term->term_id ); ?>" data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
				<div class="wp-mcp-ai-cpt-chat-messages" id="wp-mcp-ai-cpt-chat-messages-term">
					<div class="wp-mcp-ai-cpt-welcome-message">
						<p><?php esc_html_e( '👋 Hi! I\'m your AI assistant. I can help you:', 'wp-mcp-ai' ); ?></p>
						<ul>
							<li><?php esc_html_e( '✍️ Write descriptions for this term', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( '🔍 Research and suggest related content', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( '📝 Generate SEO metadata', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( '🔧 Use any available AI tools', 'wp-mcp-ai' ); ?></li>
						</ul>
					</div>
				</div>
				<div class="wp-mcp-ai-cpt-chat-input-wrapper">
					<textarea 
						id="wp-mcp-ai-cpt-chat-input-term" 
						class="wp-mcp-ai-cpt-chat-input" 
						placeholder="<?php esc_attr_e( 'Ask me anything...', 'wp-mcp-ai' ); ?>"
						rows="3"
					></textarea>
					<button type="button" id="wp-mcp-ai-cpt-send-button-term" class="button button-primary wp-mcp-ai-cpt-send-button">
						<span class="dashicons dashicons-format-chat"></span>
						<?php esc_html_e( 'Send', 'wp-mcp-ai' ); ?>
					</button>
				</div>
				<div class="wp-mcp-ai-cpt-chat-status" id="wp-mcp-ai-cpt-chat-status-term"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on post edit screens and term edit screens.
		$allowed_hooks = array( 'post.php', 'post-new.php', 'term.php', 'edit-tags.php' );
		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

		// Check if we're on a supported post type or taxonomy.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$is_supported = false;

		// Check post types.
		if ( $screen->base === 'post' && in_array( $screen->post_type, $this->get_supported_post_types(), true ) ) {
			$is_supported = true;
		}

		// Check taxonomies.
		if ( in_array( $screen->base, array( 'term', 'edit-tags' ), true ) && isset( $screen->taxonomy ) && in_array( $screen->taxonomy, $this->get_supported_taxonomies(), true ) ) {
			$is_supported = true;
		}

		if ( ! $is_supported ) {
			return;
		}

		// Enqueue CSS.
		wp_enqueue_style(
			'wp-mcp-ai-cpt-assistant',
			WP_MCP_AI_PRO_URL . 'assets/css/cpt-assistant.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);

		// Enqueue JavaScript.
		wp_enqueue_script(
			'wp-mcp-ai-cpt-assistant',
			WP_MCP_AI_PRO_URL . 'assets/js/cpt-assistant.js',
			array( 'jquery', 'wp-api' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Localize script with AJAX data.
		wp_localize_script(
			'wp-mcp-ai-cpt-assistant',
			'wpMcpAiCpt',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'wp_mcp_ai_cpt_chat' ),
				'i18n'          => array(
					'error'        => __( 'An error occurred. Please try again.', 'wp-mcp-ai' ),
					'sending'      => __( 'Sending...', 'wp-mcp-ai' ),
					'thinking'     => __( 'AI is thinking...', 'wp-mcp-ai' ),
					'emptyMessage' => __( 'Please enter a message.', 'wp-mcp-ai' ),
				),
			)
		);
	}

	/**
	 * Handle AJAX chat request.
	 */
	public function handle_ajax_chat() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_cpt_chat', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to use this feature.', 'wp-mcp-ai' ) ) );
		}

		// Get and sanitize input.
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';

		if ( empty( $message ) ) {
			wp_send_json_error( array( 'message' => __( 'Message is required.', 'wp-mcp-ai' ) ) );
		}

		// Build context for the AI.
		$context = array(
			'user_id' => get_current_user_id(),
		);

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$context['post_id'] = $post_id;
				$context['post_type'] = $post->post_type;
				$context['post_title'] = $post->post_title;
				$context['post_content'] = $post->post_content;
				$context['post_status'] = $post->post_status;
			}
		}

		if ( $term_id > 0 && $taxonomy ) {
			$term = get_term( $term_id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$context['term_id'] = $term_id;
				$context['taxonomy'] = $taxonomy;
				$context['term_name'] = $term->name;
				$context['term_description'] = $term->description;
			}
		}

		// Prepare system message with context.
		$system_message = $this->build_system_message( $context );

		// Call the AI assistant (using the existing chat endpoint).
		$response = $this->call_ai_assistant( $message, $system_message, $context );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		wp_send_json_success( array( 'response' => $response ) );
	}

	/**
	 * Build system message with context.
	 *
	 * @param array $context Context data.
	 * @return string
	 */
	private function build_system_message( $context ) {
		$message = __( 'You are an AI assistant helping a WordPress user manage their content. ', 'wp-mcp-ai' );

		if ( isset( $context['post_id'] ) ) {
			$message .= sprintf(
				/* translators: 1: post type, 2: post title, 3: post status */
				__( 'The user is currently editing a %1$s titled "%2$s" (status: %3$s). ', 'wp-mcp-ai' ),
				$context['post_type'],
				$context['post_title'],
				$context['post_status']
			);

			if ( ! empty( $context['post_content'] ) ) {
				$message .= __( 'You have access to the current post content. ', 'wp-mcp-ai' );
			}

			$message .= __( 'You can help them write, edit, optimize, or enhance their content. You have access to tools for content generation, image creation, SEO optimization, and more.', 'wp-mcp-ai' );
		}

		if ( isset( $context['term_id'] ) ) {
			$message .= sprintf(
				/* translators: 1: taxonomy, 2: term name */
				__( 'The user is currently editing a %1$s term named "%2$s". ', 'wp-mcp-ai' ),
				$context['taxonomy'],
				$context['term_name']
			);

			$message .= __( 'You can help them write descriptions, generate SEO metadata, and manage taxonomy terms.', 'wp-mcp-ai' );
		}

		return $message;
	}

	/**
	 * Call AI assistant via REST API.
	 *
	 * @param string $message        User message.
	 * @param string $system_message System context message.
	 * @param array  $context        Additional context.
	 * @return string|WP_Error Response from AI or error.
	 */
	private function call_ai_assistant( $message, $system_message, $context ) {
		// Get the first available assistant.
		$args = array(
			'post_type'      => 'mcp_ai_assistant',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$assistants = get_posts( $args );

		if ( empty( $assistants ) ) {
			return new WP_Error( 'no_assistant', __( 'No AI assistant found. Please create an assistant first.', 'wp-mcp-ai' ) );
		}

		$assistant_id = $assistants[0]->ID;

		// Prepare messages for the chat endpoint.
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system_message,
			),
			array(
				'role'    => 'user',
				'content' => $message,
			),
		);

		// Make request to REST API.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params(
			array(
				'assistant_id' => $assistant_id,
				'messages'     => $messages,
				'context'      => $context,
				'stream'       => false,
			)
		);

		// Execute request.
		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$error_data = $response->get_data();
			return new WP_Error(
				'api_error',
				isset( $error_data['message'] ) ? $error_data['message'] : __( 'API request failed.', 'wp-mcp-ai' )
			);
		}

		$data = $response->get_data();

		if ( ! isset( $data['content'] ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid response from AI assistant.', 'wp-mcp-ai' ) );
		}

		return $data['content'];
	}
}
