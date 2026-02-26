<?php
/**
 * AI Assistant Metabox for WordPress Content.
 *
 * Provides an embedded AI assistant interface within the edit screens
 * for posts, pages, and other content types, enabling context-aware AI assistance.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Content_Assistant_Metabox
 *
 * Renders an AI assistant chat interface in WordPress content edit screens.
 */
class WP_MCP_AI_Content_Assistant_Metabox {

	/**
	 * Metabox ID.
	 *
	 * @var string
	 */
	const METABOX_ID = 'wp_mcp_ai_content_assistant';

	/**
	 * Initialize the metabox.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the metabox for content post types.
	 */
	public function register_metabox() {
		$post_types = $this->get_enabled_post_types();

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				self::METABOX_ID,
				__( 'AI Assistant', 'mcp-ai-wpoos' ),
				array( $this, 'render' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Get enabled post types for the content assistant.
	 *
	 * @return array List of post types.
	 */
	protected function get_enabled_post_types() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Get post types from settings, default to post and page.
		$default_post_types = array( 'post', 'page' );
		$post_types         = isset( $settings['content_assistant_post_types'] ) ? $settings['content_assistant_post_types'] : $default_post_types;

		// Ensure it's an array.
		if ( ! is_array( $post_types ) ) {
			$post_types = $default_post_types;
		}

		/**
		 * Filters the post types that support the AI Content Assistant metabox.
		 *
		 * @since 1.1.0
		 *
		 * @param array $post_types Array of post type slugs.
		 */
		return apply_filters( 'wp_mcp_ai_content_assistant_post_types', $post_types );
	}

	/**
	 * Enqueue assets for the AI assistant metabox.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Only load on post edit screens.
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		global $post;
		$enabled_post_types = $this->get_enabled_post_types();
		if ( ! $post || ! in_array( $post->post_type, $enabled_post_types, true ) ) {
			return;
		}

		// Check if shortcode class is available.
		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			return;
		}

		// Register chat assets if not already registered.
		if ( ! wp_script_is( WP_MCP_AI_Shortcode::SCRIPT_HANDLE, 'registered' ) ) {
			$shortcode_instance = new WP_MCP_AI_Shortcode();
			$shortcode_instance->register_assets();
		}

		// Enqueue chat assets.
		wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
		wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );

		// Ensure chat script localization is available in admin context.
		$this->ensure_chat_localization();

		// Enqueue content assistant styles and scripts.
		wp_enqueue_style(
			'wp-mcp-ai-content-assistant',
			WP_MCP_AI_URL . 'assets/css/admin-content-assistant.css',
			array( WP_MCP_AI_Shortcode::STYLE_HANDLE ),
			WP_MCP_AI_VERSION
		);

		// Build dependencies array.
		$script_dependencies = array( 'jquery', WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		if ( wp_script_is( 'wp-dom-ready', 'registered' ) ) {
			$script_dependencies[] = 'wp-dom-ready';
		}

		wp_enqueue_script(
			'wp-mcp-ai-content-assistant',
			WP_MCP_AI_URL . 'assets/js/admin-content-assistant.js',
			$script_dependencies,
			WP_MCP_AI_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'wp-mcp-ai-content-assistant',
			'wpMcpAiContentAssistant',
			array(
				'postId'      => $post->ID,
				'postType'    => $post->post_type,
				'contextData' => $this->get_context_data( $post ),
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_content_assistant' ),
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'strings'     => array(
					'assistantTitle' => $this->get_assistant_title( $post->post_type ),
					'placeholder'    => $this->get_placeholder_text( $post->post_type ),
					'error'          => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos' ),
					'applied'        => __( 'AI suggestion applied!', 'mcp-ai-wpoos' ),
				),
			)
		);
	}

	/**
	 * Ensure chat script localization is available.
	 */
	protected function ensure_chat_localization() {
		// Check if localization was already done.
		$wp_scripts = wp_scripts();
		if ( isset( $wp_scripts->registered[ WP_MCP_AI_Shortcode::SCRIPT_HANDLE ] ) ) {
			$script_data = $wp_scripts->registered[ WP_MCP_AI_Shortcode::SCRIPT_HANDLE ];
			if ( isset( $script_data->extra['data'] ) && false !== strpos( $script_data->extra['data'], 'var wpMcpAiChat' ) ) {
				return;
			}
		}

		// Verify required classes are available.
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) || ! class_exists( 'WP_MCP_AI_REST' ) || ! class_exists( 'WP_MCP_AI_Request_Context' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Content Assistant: Required classes not available for chat localization' );
			}
			return;
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		wp_localize_script(
			WP_MCP_AI_Shortcode::SCRIPT_HANDLE,
			'wpMcpAiChat',
			array(
				'restUrl'             => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE ) ) ) ),
				'uploadEndpoint'      => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( 'wp/v2/media' ) ) ),
				'prepareEndpoint'     => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/attachments/prepare' ) ) ),
				'filesEndpoint'       => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/files' ) ) ) ),
				'toolsEndpoint'       => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/tools' ) ) ),
				'transcriptsEndpoint' => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/chat-transcripts' ) ) ),
				'historyPerPage'      => 20,
				'currentUserId'       => get_current_user_id(),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'showUsageCosts'      => isset( $settings['show_usage_costs'] ) ? (bool) $settings['show_usage_costs'] : false,
				'asyncToolTimeout'    => isset( $settings['async_tool_timeout'] ) ? absint( $settings['async_tool_timeout'] ) * 1000 : 300000,
				'strings'             => array(
					'placeholder' => __( 'Ask something…', 'mcp-ai-wpoos' ),
				),
			)
		);
	}

	/**
	 * Get context data for the current post.
	 *
	 * @param WP_Post $post Post object.
	 * @return array Context data.
	 */
	protected function get_context_data( $post ) {
		return array(
			'post_id'      => $post->ID,
			'post_type'    => $post->post_type,
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_status'  => $post->post_status,
			'post_author'  => $post->post_author,
			'post_date'    => $post->post_date,
			'post_excerpt' => $post->post_excerpt,
		);
	}

	/**
	 * Get assistant title based on post type.
	 *
	 * @param string $post_type Post type.
	 * @return string Title.
	 */
	protected function get_assistant_title( $post_type ) {
		$post_type_object = get_post_type_object( $post_type );
		$label            = $post_type_object ? $post_type_object->labels->singular_name : __( 'Content', 'mcp-ai-wpoos' );

		/* translators: %s: Post type singular name (e.g., "Post", "Page") */
		return sprintf( __( '%s AI Assistant', 'mcp-ai-wpoos' ), $label );
	}

	/**
	 * Get placeholder text based on post type.
	 *
	 * @param string $post_type Post type.
	 * @return string Placeholder text.
	 */
	protected function get_placeholder_text( $post_type ) {
		$post_type_object = get_post_type_object( $post_type );
		$label            = $post_type_object ? strtolower( $post_type_object->labels->singular_name ) : __( 'content', 'mcp-ai-wpoos' );

		/* translators: %s: Post type singular name in lowercase (e.g., "post", "page") */
		return sprintf( __( 'Ask me about this %s...', 'mcp-ai-wpoos' ), $label );
	}

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render( $post ) {
		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to use this feature.', 'mcp-ai-wpoos' ) . '</p>';
			return;
		}

		// Check if content assistant is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$enabled  = isset( $settings['enable_content_assistant_metabox'] ) ? $settings['enable_content_assistant_metabox'] : true;

		if ( ! $enabled ) {
			echo '<p>' . esc_html__( 'AI Content Assistant is not enabled.', 'mcp-ai-wpoos' ) . '</p>';
			return;
		}

		// Get available assistants.
		$assistants = $this->get_available_assistants();
		if ( empty( $assistants ) ) {
			echo '<p>' . esc_html__( 'No AI assistants available. Please create an assistant first.', 'mcp-ai-wpoos' ) . '</p>';
			return;
		}

		wp_nonce_field( 'wp_mcp_ai_content_assistant', 'wp_mcp_ai_content_assistant_nonce' );
		?>
		<div class="wp-mcp-ai-content-assistant-wrapper">
			<div class="wp-mcp-ai-content-assistant-info">
				<p><?php esc_html_e( 'Get AI assistance with your content creation and editing.', 'mcp-ai-wpoos' ); ?></p>
			</div>

			<!-- Assistant selector -->
			<div class="wp-mcp-ai-content-assistant-selector" style="margin-bottom: 15px;">
				<label for="wp-mcp-ai-content-assistant-select">
					<?php esc_html_e( 'Select Assistant:', 'mcp-ai-wpoos' ); ?>
				</label>
				<select id="wp-mcp-ai-content-assistant-select" class="widefat">
					<option value=""><?php esc_html_e( '— Select Assistant —', 'mcp-ai-wpoos' ); ?></option>
					<?php foreach ( $assistants as $assistant ) : ?>
						<option value="<?php echo esc_attr( $assistant['id'] ); ?>" data-title="<?php echo esc_attr( $assistant['title'] ); ?>">
							<?php echo esc_html( $assistant['title'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<!-- Quick action buttons -->
			<?php $this->render_quick_actions( $post ); ?>

			<!-- Open Assistant button -->
			<button
				type="button"
				class="button button-primary button-large wp-mcp-ai-content-open-assistant"
				data-post-id="<?php echo esc_attr( $post->ID ); ?>"
				data-post-type="<?php echo esc_attr( $post->post_type ); ?>"
				disabled
			>
				<span class="dashicons dashicons-format-chat"></span>
				<?php esc_html_e( 'Open AI Assistant', 'mcp-ai-wpoos' ); ?>
			</button>
		</div>

		<!-- Modal container for AI Assistant -->
		<?php $this->render_ai_modal( $post ); ?>
		<?php
	}

	/**
	 * Render quick action buttons.
	 *
	 * @param WP_Post $post Post object.
	 */
	protected function render_quick_actions( $post ) {
		$actions = $this->get_quick_actions();
		?>
		<div class="wp-mcp-ai-content-quick-actions" style="margin-bottom: 15px;">
			<p class="description">
				<?php esc_html_e( 'Quick AI Actions:', 'mcp-ai-wpoos' ); ?>
			</p>

			<?php foreach ( $actions as $action ) : ?>
				<p>
					<button
						type="button"
						class="button button-secondary wp-mcp-ai-content-action-btn"
						data-action="<?php echo esc_attr( $action['slug'] ); ?>"
						data-post-id="<?php echo esc_attr( $post->ID ); ?>"
						disabled
					>
						<span class="dashicons dashicons-<?php echo esc_attr( $action['icon'] ); ?>"></span>
						<?php echo esc_html( $action['label'] ); ?>
					</button>
				</p>
			<?php endforeach; ?>

			<div class="wp-mcp-ai-content-action-result" style="margin-top: 15px; display: none;">
				<div class="notice notice-info inline">
					<p class="wp-mcp-ai-content-action-result-content"></p>
				</div>
			</div>

			<div class="wp-mcp-ai-content-action-loading" style="display: none;">
				<p>
					<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>
					<?php esc_html_e( 'AI is thinking...', 'mcp-ai-wpoos' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Get quick actions for content assistance.
	 *
	 * @return array Quick actions.
	 */
	protected function get_quick_actions() {
		$actions = array(
			array(
				'slug'  => 'improve_content',
				'label' => __( 'Improve Content', 'mcp-ai-wpoos' ),
				'icon'  => 'edit',
			),
			array(
				'slug'  => 'generate_outline',
				'label' => __( 'Generate Outline', 'mcp-ai-wpoos' ),
				'icon'  => 'list-view',
			),
			array(
				'slug'  => 'seo_optimize',
				'label' => __( 'SEO Optimize', 'mcp-ai-wpoos' ),
				'icon'  => 'chart-bar',
			),
			array(
				'slug'  => 'rewrite',
				'label' => __( 'Rewrite', 'mcp-ai-wpoos' ),
				'icon'  => 'text',
			),
			array(
				'slug'  => 'expand',
				'label' => __( 'Expand', 'mcp-ai-wpoos' ),
				'icon'  => 'plus-alt',
			),
			array(
				'slug'  => 'summarize',
				'label' => __( 'Summarize', 'mcp-ai-wpoos' ),
				'icon'  => 'excerpt-view',
			),
		);

		/**
		 * Filters the quick actions available in the content assistant metabox.
		 *
		 * @since 1.1.0
		 *
		 * @param array $actions Quick actions array.
		 */
		return apply_filters( 'wp_mcp_ai_content_assistant_quick_actions', $actions );
	}

	/**
	 * Render AI modal.
	 *
	 * @param WP_Post $post Post object.
	 */
	protected function render_ai_modal( $post ) {
		?>
		<div id="wp-mcp-ai-content-assistant-modal" class="wp-mcp-ai-content-modal" style="display: none;">
			<div class="wp-mcp-ai-content-modal__backdrop"></div>
			<div class="wp-mcp-ai-content-modal__panel">
				<div class="wp-mcp-ai-content-modal__header">
					<h2 id="wp-mcp-ai-content-modal-title"><?php echo esc_html( $this->get_assistant_title( $post->post_type ) ); ?></h2>
					<button type="button" class="wp-mcp-ai-content-modal__close" aria-label="<?php esc_attr_e( 'Close', 'mcp-ai-wpoos' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<div class="wp-mcp-ai-content-modal__body">
					<div id="wp-mcp-ai-content-assistant-chat-container" class="wp-mcp-ai-content-assistant-chat-container">
						<!-- Chat interface will be rendered here when modal opens -->
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get available AI assistants.
	 *
	 * @return array List of assistants.
	 */
	protected function get_available_assistants() {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$assistants = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$assistants[] = array(
					'id'    => get_the_ID(),
					'title' => get_the_title(),
				);
			}
			wp_reset_postdata();
		}

		return $assistants;
	}
}
