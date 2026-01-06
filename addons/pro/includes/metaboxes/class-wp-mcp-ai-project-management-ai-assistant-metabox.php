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

		// Ensure chat script localization is available in admin context.
		// The shortcode's register_assets() may have been called during init hook,
		// but we need to ensure wpMcpAiChat global is available for the modal.
		$this->ensure_chat_localization();

		// Enqueue modal styles (required for popup overlay).
		wp_enqueue_style(
			'wp-mcp-ai-cpt-assistant',
			WP_MCP_AI_PRO_URL . 'assets/css/cpt-assistant.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);

		// Enqueue unified PM AI assistant script (replaces both ai-assistant.js and ai-actions.js).
		$unified_script_url = WP_MCP_AI_PRO_URL . 'assets/js/admin-pm-ai-assistant-unified.js';
		$style_url          = WP_MCP_AI_PRO_URL . 'assets/css/admin-pm-ai-assistant.css';

		// Build dependencies array - include wp-dom-ready if available for block editor support.
		$script_dependencies = array( 'jquery', WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		if ( wp_script_is( 'wp-dom-ready', 'registered' ) ) {
			$script_dependencies[] = 'wp-dom-ready';
		}

		wp_enqueue_script(
			'wp-mcp-ai-pm-ai-assistant-unified',
			$unified_script_url,
			$script_dependencies,
			WP_MCP_AI_PRO_VERSION,
			true
		);

		wp_enqueue_style(
			'wp-mcp-ai-pm-ai-assistant',
			$style_url,
			array( WP_MCP_AI_Shortcode::STYLE_HANDLE ),
			WP_MCP_AI_PRO_VERSION
		);

		// Localize script for AI actions.
		wp_localize_script(
			'wp-mcp-ai-pm-ai-assistant-unified',
			'wpMcpAiPmAi',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_pm_ai_actions' ),
				'strings' => array(
					'error'      => __( 'An error occurred. Please try again.', 'wp-mcp-ai' ),
					'noTitle'    => __( 'Please add a title first.', 'wp-mcp-ai' ),
					'applied'    => __( 'AI suggestion applied!', 'wp-mcp-ai' ),
					'viewTasks'  => __( 'View suggested tasks below:', 'wp-mcp-ai' ),
					'copyToDesc' => __( 'Copy to Description', 'wp-mcp-ai' ),
				),
			)
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
			'wp-mcp-ai-pm-ai-assistant-unified',
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

		// Add nonce for AI actions.
		wp_nonce_field( 'wp_mcp_ai_pm_ai_actions', 'wp_mcp_ai_pm_ai_actions_nonce' );

		$post_type = get_post_type( $post );
		$context_type = $this->get_context_type( $post_type );
		?>
		<div class="wp-mcp-ai-pm-assistant-wrapper">
			<div class="wp-mcp-ai-pm-assistant-info">
				<p><?php esc_html_e( 'Get AI assistance with your project management tasks.', 'wp-mcp-ai' ); ?></p>
			</div>

			<!-- Assistant selector -->
			<div class="wp-mcp-ai-pm-assistant-selector" style="margin-bottom: 15px;">
				<label for="wp-mcp-ai-pm-assistant-select">
					<?php esc_html_e( 'Select Assistant:', 'wp-mcp-ai' ); ?>
				</label>
				<select id="wp-mcp-ai-pm-assistant-select" class="widefat">
					<option value=""><?php esc_html_e( '— Select Assistant —', 'wp-mcp-ai' ); ?></option>
					<?php foreach ( $assistants as $assistant ) : ?>
						<option value="<?php echo esc_attr( $assistant['id'] ); ?>" data-title="<?php echo esc_attr( $assistant['title'] ); ?>">
							<?php echo esc_html( $assistant['title'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<!-- Quick action buttons (disabled until assistant selected) -->
			<?php $this->render_ai_quick_actions( $post ); ?>

			<!-- Open Assistant button -->
			<button 
				type="button" 
				class="button button-primary button-large wp-mcp-ai-pm-open-assistant" 
				data-post-id="<?php echo esc_attr( $post->ID ); ?>" 
				data-post-type="<?php echo esc_attr( $post->post_type ); ?>"
				disabled
			>
				<span class="dashicons dashicons-format-chat"></span>
				<?php esc_html_e( 'Open AI Assistant', 'wp-mcp-ai' ); ?>
			</button>
		</div>

		<!-- Modal container for AI Assistant -->
		<?php $this->render_ai_modal( $post, $context_type ); ?>
		<?php
	}

	/**
	 * Render AI quick actions based on post type.
	 *
	 * @param WP_Post $post Post object.
	 */
	private function render_ai_quick_actions( $post ) {
		$post_type = get_post_type( $post );
		?>
		<div class="wp-mcp-ai-pm-ai-actions" style="margin-bottom: 15px;">
			<p class="description">
				<?php esc_html_e( 'Quick AI Actions:', 'wp-mcp-ai' ); ?>
			</p>

			<?php if ( 'mcp_ai_project' === $post_type ) : ?>
				<p>
					<button type="button" class="button button-secondary wp-mcp-ai-pm-ai-btn" data-action="generate_description" data-post-id="<?php echo esc_attr( $post->ID ); ?>" disabled>
						<span class="dashicons dashicons-edit"></span>
						<?php esc_html_e( 'Generate Description', 'wp-mcp-ai' ); ?>
					</button>
				</p>
				<p>
					<button type="button" class="button button-secondary wp-mcp-ai-pm-ai-btn" data-action="suggest_tasks" data-post-id="<?php echo esc_attr( $post->ID ); ?>" disabled>
						<span class="dashicons dashicons-list-view"></span>
						<?php esc_html_e( 'Suggest Tasks', 'wp-mcp-ai' ); ?>
					</button>
				</p>
				<p>
					<button type="button" class="button button-secondary wp-mcp-ai-pm-ai-btn" data-action="analyze_project" data-post-id="<?php echo esc_attr( $post->ID ); ?>" disabled>
						<span class="dashicons dashicons-chart-bar"></span>
						<?php esc_html_e( 'Analyze Project', 'wp-mcp-ai' ); ?>
					</button>
				</p>
			<?php elseif ( 'mcp_ai_task' === $post_type ) : ?>
				<p>
					<button type="button" class="button button-secondary wp-mcp-ai-pm-ai-btn" data-action="generate_description" data-post-id="<?php echo esc_attr( $post->ID ); ?>" disabled>
						<span class="dashicons dashicons-edit"></span>
						<?php esc_html_e( 'Generate Description', 'wp-mcp-ai' ); ?>
					</button>
				</p>
				<p>
					<button type="button" class="button button-secondary wp-mcp-ai-pm-ai-btn" data-action="estimate_time" data-post-id="<?php echo esc_attr( $post->ID ); ?>" disabled>
						<span class="dashicons dashicons-clock"></span>
						<?php esc_html_e( 'Estimate Duration', 'wp-mcp-ai' ); ?>
					</button>
				</p>
			<?php elseif ( 'mcp_ai_event' === $post_type ) : ?>
				<p>
					<button type="button" class="button button-secondary wp-mcp-ai-pm-ai-btn" data-action="generate_description" data-post-id="<?php echo esc_attr( $post->ID ); ?>" disabled>
						<span class="dashicons dashicons-edit"></span>
						<?php esc_html_e( 'Generate Description', 'wp-mcp-ai' ); ?>
					</button>
				</p>
				<p>
					<button type="button" class="button button-secondary wp-mcp-ai-pm-ai-btn" data-action="suggest_agenda" data-post-id="<?php echo esc_attr( $post->ID ); ?>" disabled>
						<span class="dashicons dashicons-text-page"></span>
						<?php esc_html_e( 'Suggest Agenda', 'wp-mcp-ai' ); ?>
					</button>
				</p>
			<?php endif; ?>

			<div class="wp-mcp-ai-pm-ai-result" style="margin-top: 15px; display: none;">
				<div class="notice notice-info inline">
					<p class="wp-mcp-ai-pm-ai-result-content"></p>
				</div>
			</div>

			<div class="wp-mcp-ai-pm-ai-loading" style="display: none;">
				<p>
					<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>
					<?php esc_html_e( 'AI is thinking...', 'wp-mcp-ai' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render AI modal.
	 *
	 * @param WP_Post $post Post object.
	 * @param string  $context_type Context type (project, task, or event).
	 */
	private function render_ai_modal( $post, $context_type ) {
		?>
		<div id="wp-mcp-ai-pm-assistant-modal" class="wp-mcp-ai-cpt-modal" style="display: none;">
			<div class="wp-mcp-ai-cpt-modal__backdrop"></div>
			<div class="wp-mcp-ai-cpt-modal__panel">
				<div class="wp-mcp-ai-cpt-modal__header">
					<h2 id="wp-mcp-ai-pm-modal-title"><?php echo esc_html( $this->get_assistant_title( $context_type ) ); ?></h2>
					<button type="button" class="wp-mcp-ai-cpt-modal__close" aria-label="<?php esc_attr_e( 'Close', 'wp-mcp-ai' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<div class="wp-mcp-ai-cpt-modal__body">
					<div id="wp-mcp-ai-pm-assistant-chat-container" class="wp-mcp-ai-pm-assistant-chat-container">
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
	 * Ensure chat script localization is available.
	 *
	 * The shortcode's register_assets() method should handle this, but we ensure
	 * it's available in the admin context for the modal-based chat interface.
	 */
	private function ensure_chat_localization() {
		// Check if localization was already done by checking if wpMcpAiChat is attached to the script.
		$wp_scripts = wp_scripts();
		if ( isset( $wp_scripts->registered[ WP_MCP_AI_Shortcode::SCRIPT_HANDLE ] ) ) {
			$script_data = $wp_scripts->registered[ WP_MCP_AI_Shortcode::SCRIPT_HANDLE ];
			// Check if localization is already attached by looking for the JavaScript variable declaration.
			// WordPress outputs localization as: var wpMcpAiChat = {...};
			if ( isset( $script_data->extra['data'] ) && false !== strpos( $script_data->extra['data'], 'var wpMcpAiChat' ) ) {
				// Localization already exists, no need to add it again.
				return;
			}
		}

		// Verify required classes are available before attempting localization.
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) || ! class_exists( 'WP_MCP_AI_REST' ) || ! class_exists( 'WP_MCP_AI_Request_Context' ) ) {
			// Log error if debugging is enabled.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'PM AI Assistant: Required classes not available for chat localization' );
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
				'filesEndpoint'       => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/files' ) ) ) ),
				'toolsEndpoint'       => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/tools' ) ) ),
				'transcriptsEndpoint' => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/chat-transcripts' ) ) ),
				'historyPerPage'      => 20,
				'currentUserId'       => get_current_user_id(),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'showUsageCosts'      => isset( $settings['show_usage_costs'] ) ? (bool) $settings['show_usage_costs'] : false,
				'asyncToolTimeout'    => isset( $settings['async_tool_timeout'] ) ? absint( $settings['async_tool_timeout'] ) * 1000 : 300000,
				'strings'             => array(
					'placeholder' => __( 'Ask something…', 'wp-mcp-ai' ),
				),
			)
		);
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
}
