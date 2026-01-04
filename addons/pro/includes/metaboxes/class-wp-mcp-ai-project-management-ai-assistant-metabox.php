<?php
/**
 * AI Assistant Metabox for Project Management CPTs.
 *
 * Provides an embedded AI assistant interface within the edit screens
 * for Projects, Tasks, and Events, enabling context-aware AI assistance.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Project_Management_AI_Assistant_Metabox
 *
 * Renders an AI assistant chat interface in the project management edit screens.
 */
class WP_MCP_AI_Project_Management_AI_Assistant_Metabox {

	/**
	 * Metabox ID.
	 *
	 * @var string
	 */
	const METABOX_ID = 'wp_mcp_ai_pm_ai_assistant';

	/**
	 * Initialize the metabox.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_pm_render_chat', array( $this, 'ajax_render_chat' ) );
	}

	/**
	 * Register the metabox for project management CPTs.
	 */
	public function register_metabox() {
		$post_types = array( 'mcp_ai_project', 'mcp_ai_task', 'mcp_ai_event' );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				self::METABOX_ID,
				__( 'AI Assistant', 'wp-mcp-ai' ),
				array( $this, 'render' ),
				$post_type,
				'side',
				'high'
			);
		}
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
		if ( ! $post || ! in_array( $post->post_type, array( 'mcp_ai_project', 'mcp_ai_task', 'mcp_ai_event' ), true ) ) {
			return;
		}

		// Check if shortcode class is available.
		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			return;
		}

		// Register chat assets if not already registered.
		if ( ! wp_script_is( WP_MCP_AI_Shortcode::SCRIPT_HANDLE, 'registered' ) ) {
			// Call the static register_assets method via a temporary instance.
			// Note: This is a limitation of the current architecture where register_assets is not static.
			$shortcode_instance = new WP_MCP_AI_Shortcode();
			$shortcode_instance->register_assets();
		}

		// Enqueue chat assets.
		wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
		wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );

		// Enqueue custom metabox assets.
		$script_url = WP_MCP_AI_PRO_URL . 'assets/js/admin-pm-ai-assistant.js';
		$style_url  = WP_MCP_AI_PRO_URL . 'assets/css/admin-pm-ai-assistant.css';

		wp_enqueue_script(
			'wp-mcp-ai-pm-ai-assistant',
			$script_url,
			array( 'jquery', WP_MCP_AI_Shortcode::SCRIPT_HANDLE ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		wp_enqueue_style(
			'wp-mcp-ai-pm-ai-assistant',
			$style_url,
			array( WP_MCP_AI_Shortcode::STYLE_HANDLE ),
			WP_MCP_AI_PRO_VERSION
		);

		// Localize script with post context.
		$this->localize_script( $post );
	}

	/**
	 * Localize script with post context data.
	 *
	 * @param WP_Post $post Current post object.
	 */
	private function localize_script( $post ) {
		$context_type = $this->get_context_type( $post->post_type );
		$context_data = $this->get_context_data( $post );

		wp_localize_script(
			'wp-mcp-ai-pm-ai-assistant',
			'wpMcpAiPmAssistant',
			array(
				'contextType' => $context_type,
				'contextData' => $context_data,
				'postId'      => $post->ID,
				'postType'    => $post->post_type,
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_pm_assistant' ),
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'strings'     => array(
					'assistantTitle' => $this->get_assistant_title( $context_type ),
					'placeholder'    => $this->get_placeholder_text( $context_type ),
				),
			)
		);
	}

	/**
	 * Get context type based on post type.
	 *
	 * @param string $post_type Post type.
	 * @return string Context type (project, task, or event).
	 */
	private function get_context_type( $post_type ) {
		$map = array(
			'mcp_ai_project' => 'project',
			'mcp_ai_task'    => 'task',
			'mcp_ai_event'   => 'event',
		);

		return isset( $map[ $post_type ] ) ? $map[ $post_type ] : 'project';
	}

	/**
	 * Get context data for the current post.
	 *
	 * @param WP_Post $post Post object.
	 * @return array Context data.
	 */
	private function get_context_data( $post ) {
		$context = array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'description' => $post->post_content,
			'status'      => $post->post_status,
			'created_at'  => $post->post_date,
			'modified_at' => $post->post_modified,
		);

		// Add type-specific metadata.
		if ( 'mcp_ai_task' === $post->post_type ) {
			$context['task_status']   = get_post_meta( $post->ID, '_task_status', true );
			$context['task_priority'] = get_post_meta( $post->ID, '_task_priority', true );
			$context['project_id']    = absint( get_post_meta( $post->ID, '_task_project_id', true ) );
			$context['due_date']      = get_post_meta( $post->ID, '_task_due_date', true );
			$context['assigned_to']   = absint( get_post_meta( $post->ID, '_task_assigned_to', true ) );
		} elseif ( 'mcp_ai_event' === $post->post_type ) {
			$context['start_date']  = get_post_meta( $post->ID, '_event_start_date', true );
			$context['end_date']    = get_post_meta( $post->ID, '_event_end_date', true );
			$context['location']    = get_post_meta( $post->ID, '_event_location', true );
			$context['event_type']  = get_post_meta( $post->ID, '_event_type', true );
			$context['all_day']     = (bool) get_post_meta( $post->ID, '_event_all_day', true );
		} elseif ( 'mcp_ai_project' === $post->post_type ) {
			$context['project_status']   = get_post_meta( $post->ID, '_project_status', true );
			$context['start_date']       = get_post_meta( $post->ID, '_project_start_date', true );
			$context['end_date']         = get_post_meta( $post->ID, '_project_end_date', true );
			$context['budget']           = get_post_meta( $post->ID, '_project_budget', true );
			$context['completion_percentage'] = absint( get_post_meta( $post->ID, '_project_completion', true ) );
		}

		return $context;
	}

	/**
	 * Get assistant title based on context type.
	 *
	 * @param string $context_type Context type.
	 * @return string Title.
	 */
	private function get_assistant_title( $context_type ) {
		$titles = array(
			'project' => __( 'Project Assistant', 'wp-mcp-ai' ),
			'task'    => __( 'Task Assistant', 'wp-mcp-ai' ),
			'event'   => __( 'Event Assistant', 'wp-mcp-ai' ),
		);

		return isset( $titles[ $context_type ] ) ? $titles[ $context_type ] : __( 'AI Assistant', 'wp-mcp-ai' );
	}

	/**
	 * Get placeholder text based on context type.
	 *
	 * @param string $context_type Context type.
	 * @return string Placeholder text.
	 */
	private function get_placeholder_text( $context_type ) {
		$placeholders = array(
			'project' => __( 'Ask me about this project...', 'wp-mcp-ai' ),
			'task'    => __( 'Ask me about this task...', 'wp-mcp-ai' ),
			'event'   => __( 'Ask me about this event...', 'wp-mcp-ai' ),
		);

		return isset( $placeholders[ $context_type ] ) ? $placeholders[ $context_type ] : __( 'Ask me anything...', 'wp-mcp-ai' );
	}

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render( $post ) {
		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to use this feature.', 'wp-mcp-ai' ) . '</p>';
			return;
		}

		// Check if project management is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_project_management'] ) ) {
			echo '<p>' . esc_html__( 'Project Management features are not enabled.', 'wp-mcp-ai' ) . '</p>';
			return;
		}

		// Get available assistants.
		$assistants = $this->get_available_assistants();
		if ( empty( $assistants ) ) {
			echo '<p>' . esc_html__( 'No AI assistants available. Please create an assistant first.', 'wp-mcp-ai' ) . '</p>';
			return;
		}

		// Render assistant selector and chat container.
		$this->render_assistant_selector( $assistants );
		$this->render_chat_container( $post );
	}

	/**
	 * Get available AI assistants.
	 *
	 * @return array List of assistants.
	 */
	private function get_available_assistants() {
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

	/**
	 * Render assistant selector dropdown.
	 *
	 * @param array $assistants List of assistants.
	 */
	private function render_assistant_selector( $assistants ) {
		?>
		<div class="wp-mcp-ai-pm-assistant-selector">
			<label for="wp-mcp-ai-pm-assistant-select">
				<?php esc_html_e( 'Select Assistant:', 'wp-mcp-ai' ); ?>
			</label>
			<select id="wp-mcp-ai-pm-assistant-select" class="widefat">
				<option value=""><?php esc_html_e( '— Select Assistant —', 'wp-mcp-ai' ); ?></option>
				<?php foreach ( $assistants as $assistant ) : ?>
					<option value="<?php echo esc_attr( $assistant['id'] ); ?>">
						<?php echo esc_html( $assistant['title'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	/**
	 * Render chat container.
	 *
	 * @param WP_Post $post Post object.
	 */
	private function render_chat_container( $post ) {
		$context_type = $this->get_context_type( $post->post_type );
		?>
		<div id="wp-mcp-ai-pm-assistant-chat-wrapper" class="wp-mcp-ai-pm-assistant-chat-wrapper" style="display:none;">
			<div class="wp-mcp-ai-pm-assistant-intro">
				<p><?php echo esc_html( $this->get_intro_text( $context_type ) ); ?></p>
			</div>
			<div id="wp-mcp-ai-pm-assistant-chat-container" class="wp-mcp-ai-pm-assistant-chat-container">
				<!-- Chat interface will be injected here by JavaScript -->
			</div>
		</div>
		<?php
	}

	/**
	 * Get intro text based on context type.
	 *
	 * @param string $context_type Context type.
	 * @return string Intro text.
	 */
	private function get_intro_text( $context_type ) {
		$texts = array(
			'project' => __( 'Ask your AI assistant about this project, request updates, create tasks, or get recommendations.', 'wp-mcp-ai' ),
			'task'    => __( 'Ask your AI assistant about this task, update its status, change priority, or get help with planning.', 'wp-mcp-ai' ),
			'event'   => __( 'Ask your AI assistant about this event, schedule updates, manage attendees, or get recommendations.', 'wp-mcp-ai' ),
		);

		return isset( $texts[ $context_type ] ) ? $texts[ $context_type ] : __( 'Ask your AI assistant for help.', 'wp-mcp-ai' );
	}

	/**
	 * AJAX handler to render chat shortcode.
	 */
	public function ajax_render_chat() {
		// Check if shortcode class is available first (fail fast).
		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			wp_send_json_error( array( 'message' => __( 'Chat functionality not available.', 'wp-mcp-ai' ) ) );
		}

		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wp_mcp_ai_pm_assistant' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'wp-mcp-ai' ) ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to use this feature.', 'wp-mcp-ai' ) ) );
		}

		// Get parameters.
		$assistant_id    = isset( $_POST['assistant_id'] ) ? absint( $_POST['assistant_id'] ) : 0;
		$context_message = isset( $_POST['context_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['context_message'] ) ) : '';
		$post_id         = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $assistant_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid assistant ID.', 'wp-mcp-ai' ) ) );
		}

		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this item.', 'wp-mcp-ai' ) ) );
		}

		// Verify assistant exists.
		$assistant = get_post( $assistant_id );
		if ( ! $assistant || 'mcp_ai_assistant' !== $assistant->post_type || 'publish' !== $assistant->post_status ) {
			wp_send_json_error( array( 'message' => __( 'Invalid or inactive assistant.', 'wp-mcp-ai' ) ) );
		}

		// Build shortcode attributes.
		$shortcode_atts = array(
			'assistant'        => $assistant_id,
			'save_transcript'  => 'false', // Don't save metabox chats to main transcript log.
			'template'         => 'compact', // Use compact template for metabox.
		);

		// Create shortcode instance and render.
		$atts_str = '';
		foreach ( $shortcode_atts as $key => $value ) {
			$atts_str .= ' ' . $key . '="' . esc_attr( $value ) . '"';
		}

		// Render the shortcode.
		$html = do_shortcode( '[mcp_ai_chat' . $atts_str . ']' );

		// Note: Context message display could be implemented via a filter on the chat interface.
		// For now, context is passed in the initial JavaScript configuration.
		// Future enhancement: Store in transient and retrieve via chat service.

		wp_send_json_success( array( 'html' => $html ) );
	}
}
