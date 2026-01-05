<?php
/**
 * AI-Enhanced Quick Actions for Project Management
 *
 * Provides AI-assisted features for projects, tasks, and events.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles AI-enhanced quick actions for project management.
 */
class WP_MCP_AI_Project_Management_AI_Actions {

	/**
	 * Initialize AI actions.
	 */
	public static function init() {
		// Add metabox for AI suggestions.
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_ai_metabox' ) );

		// Register AJAX handlers.
		add_action( 'wp_ajax_wp_mcp_ai_pm_generate_description', array( __CLASS__, 'ajax_generate_description' ) );
		add_action( 'wp_ajax_wp_mcp_ai_pm_suggest_tasks', array( __CLASS__, 'ajax_suggest_tasks' ) );
		add_action( 'wp_ajax_wp_mcp_ai_pm_analyze_project', array( __CLASS__, 'ajax_analyze_project' ) );
		add_action( 'wp_ajax_wp_mcp_ai_pm_bulk_generate', array( __CLASS__, 'ajax_bulk_generate' ) );

		// Enqueue scripts.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Add AI suggestions metabox.
	 */
	public static function add_ai_metabox() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_project_management'] ) ) {
			return;
		}

		$post_types = array( 'mcp_ai_project', 'mcp_ai_task', 'mcp_ai_event' );
		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'wp_mcp_ai_pm_ai_actions',
				__( '🤖 AI Assistant', 'wp-mcp-ai' ),
				array( __CLASS__, 'render_ai_metabox' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render AI suggestions metabox.
	 *
	 * @param WP_Post $post The post object.
	 */
	public static function render_ai_metabox( $post ) {
		wp_nonce_field( 'wp_mcp_ai_pm_ai_actions', 'wp_mcp_ai_pm_ai_actions_nonce' );

		$post_type = get_post_type( $post );
		?>
		<div class="wp-mcp-ai-pm-ai-actions">
			<p class="description">
				<?php esc_html_e( 'Use AI to enhance your project management:', 'wp-mcp-ai' ); ?>
			</p>

			<?php if ( 'mcp_ai_project' === $post_type ) : ?>
				<p>
					<button type="button" class="button button-secondary wp-mcp-ai-pm-ai-btn" data-action="generate_description" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
						<span class="dashicons dashicons-edit"></span>
						<?php esc_html_e( 'Generate Description', 'wp-mcp-ai' ); ?>
					</button>
				</p>
				<p>
					<button type="button" class="button button-secondary wp-mcp-ai-pm-ai-btn" data-action="suggest_tasks" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
						<span class="dashicons dashicons-list-view"></span>
						<?php esc_html_e( 'Suggest Tasks', 'wp-mcp-ai' ); ?>
					</button>
				</p>
				<p>
					<button type="button" class="button button-secondary wp-mcp-ai-pm-ai-btn" data-action="analyze_project" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
						<span class="dashicons dashicons-chart-bar"></span>
						<?php esc_html_e( 'Analyze Project', 'wp-mcp-ai' ); ?>
					</button>
				</p>
			<?php elseif ( 'mcp_ai_task' === $post_type ) : ?>
				<p>
					<button type="button" class="button button-secondary wp-mcp-ai-pm-ai-btn" data-action="generate_description" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
						<span class="dashicons dashicons-edit"></span>
						<?php esc_html_e( 'Generate Description', 'wp-mcp-ai' ); ?>
					</button>
				</p>
				<p>
					<button type="button" class="button button-secondary wp-mcp-ai-pm-ai-btn" data-action="estimate_time" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
						<span class="dashicons dashicons-clock"></span>
						<?php esc_html_e( 'Estimate Duration', 'wp-mcp-ai' ); ?>
					</button>
				</p>
			<?php elseif ( 'mcp_ai_event' === $post_type ) : ?>
				<p>
					<button type="button" class="button button-secondary wp-mcp-ai-pm-ai-btn" data-action="generate_description" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
						<span class="dashicons dashicons-edit"></span>
						<?php esc_html_e( 'Generate Description', 'wp-mcp-ai' ); ?>
					</button>
				</p>
				<p>
					<button type="button" class="button button-secondary wp-mcp-ai-pm-ai-btn" data-action="suggest_agenda" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
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
	 * Enqueue AI action scripts.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_scripts( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'edit.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( 'mcp_ai_project', 'mcp_ai_task', 'mcp_ai_event' ), true ) ) {
			return;
		}

		// Build dependencies array - include wp-dom-ready if available for block editor support.
		$dependencies = array( 'jquery' );
		if ( wp_script_is( 'wp-dom-ready', 'registered' ) ) {
			$dependencies[] = 'wp-dom-ready';
		}

		wp_enqueue_script(
			'wp-mcp-ai-pm-ai-actions',
			WP_MCP_AI_PRO_URL . 'assets/js/admin-pm-ai-actions.js',
			$dependencies,
			WP_MCP_AI_PRO_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-pm-ai-actions',
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
	}

	/**
	 * AJAX: Generate description using AI.
	 */
	public static function ajax_generate_description() {
		check_ajax_referer( 'wp_mcp_ai_pm_ai_actions', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-mcp-ai' ) ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

		if ( ! $post_id || ! $title ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'wp-mcp-ai' ) ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found.', 'wp-mcp-ai' ) ) );
		}

		// Use AI to generate description based on title and context.
		$description = self::generate_description_with_ai( $post, $title );

		if ( is_wp_error( $description ) ) {
			wp_send_json_error( array( 'message' => $description->get_error_message() ) );
		}

		wp_send_json_success( array( 'description' => $description ) );
	}

	/**
	 * AJAX: Suggest tasks for a project.
	 */
	public static function ajax_suggest_tasks() {
		check_ajax_referer( 'wp_mcp_ai_pm_ai_actions', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-mcp-ai' ) ) );
		}

		$post_id     = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$description = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '';

		if ( ! $post_id || ! $title ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'wp-mcp-ai' ) ) );
		}

		// Use AI to suggest tasks.
		$tasks = self::suggest_tasks_with_ai( $title, $description );

		if ( is_wp_error( $tasks ) ) {
			wp_send_json_error( array( 'message' => $tasks->get_error_message() ) );
		}

		wp_send_json_success( array( 'tasks' => $tasks ) );
	}

	/**
	 * AJAX: Analyze project with AI.
	 */
	public static function ajax_analyze_project() {
		check_ajax_referer( 'wp_mcp_ai_pm_ai_actions', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-mcp-ai' ) ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'wp-mcp-ai' ) ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'mcp_ai_project' !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Invalid project.', 'wp-mcp-ai' ) ) );
		}

		// Analyze project using AI.
		$analysis = self::analyze_project_with_ai( $post );

		if ( is_wp_error( $analysis ) ) {
			wp_send_json_error( array( 'message' => $analysis->get_error_message() ) );
		}

		wp_send_json_success( array( 'analysis' => $analysis ) );
	}

	/**
	 * Generate description using AI.
	 *
	 * @param WP_Post $post  The post object.
	 * @param string  $title The title.
	 * @return string|WP_Error Generated description or error.
	 */
	private static function generate_description_with_ai( $post, $title ) {
		$post_type = get_post_type( $post );

		$type_labels = array(
			'mcp_ai_project' => 'project',
			'mcp_ai_task'    => 'task',
			'mcp_ai_event'   => 'event',
		);
		$type_label  = isset( $type_labels[ $post_type ] ) ? $type_labels[ $post_type ] : 'item';

		// Get assistant service.
		if ( ! class_exists( 'WP_MCP_AI_Assistant_Service' ) ) {
			return new WP_Error( 'service_unavailable', __( 'AI service is not available.', 'wp-mcp-ai' ) );
		}

		$assistant_service = WP_MCP_AI_Assistant_Service::get_instance();

		// Create a simple prompt for description generation.
		$prompt = sprintf(
			'Generate a clear and professional description for a %s titled "%s". The description should be 2-3 sentences explaining what this %s involves and its purpose. Be concise and actionable.',
			$type_label,
			$title,
			$type_label
		);

		// Use the first available assistant or create a temporary one.
		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
			)
		);

		if ( empty( $assistants ) ) {
			return new WP_Error( 'no_assistant', __( 'No AI assistant available. Please create an assistant first.', 'wp-mcp-ai' ) );
		}

		$assistant_id = $assistants[0]->ID;

		// Generate response using the assistant.
		try {
			$response = $assistant_service->chat(
				$assistant_id,
				$prompt,
				array(
					'max_tokens' => 150,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			return isset( $response['content'] ) ? trim( $response['content'] ) : '';
		} catch ( Exception $e ) {
			return new WP_Error( 'ai_error', $e->getMessage() );
		}
	}

	/**
	 * Suggest tasks using AI.
	 *
	 * @param string $title       Project title.
	 * @param string $description Project description.
	 * @return array|WP_Error Array of task suggestions or error.
	 */
	private static function suggest_tasks_with_ai( $title, $description ) {
		if ( ! class_exists( 'WP_MCP_AI_Assistant_Service' ) ) {
			return new WP_Error( 'service_unavailable', __( 'AI service is not available.', 'wp-mcp-ai' ) );
		}

		$assistant_service = WP_MCP_AI_Assistant_Service::get_instance();

		$prompt = sprintf(
			'For a project titled "%s" with description: "%s"\n\nSuggest 5 specific tasks needed to complete this project. For each task, provide only the task title (one line per task). Format as a numbered list.',
			$title,
			$description ? $description : 'No description provided'
		);

		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
			)
		);

		if ( empty( $assistants ) ) {
			return new WP_Error( 'no_assistant', __( 'No AI assistant available.', 'wp-mcp-ai' ) );
		}

		try {
			$response = $assistant_service->chat(
				$assistants[0]->ID,
				$prompt,
				array( 'max_tokens' => 300 )
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$content = isset( $response['content'] ) ? trim( $response['content'] ) : '';

			// Parse numbered list into array.
			$lines = explode( "\n", $content );
			$tasks = array();

			foreach ( $lines as $line ) {
				$line = trim( $line );
				// Remove numbering (e.g., "1. ", "1) ", etc.).
				$line = preg_replace( '/^\d+[\.\)]\s*/', '', $line );
				if ( ! empty( $line ) ) {
					$tasks[] = $line;
				}
			}

			return $tasks;
		} catch ( Exception $e ) {
			return new WP_Error( 'ai_error', $e->getMessage() );
		}
	}

	/**
	 * Analyze project using AI.
	 *
	 * @param WP_Post $post The project post.
	 * @return string|WP_Error Analysis or error.
	 */
	private static function analyze_project_with_ai( $post ) {
		// Get project details and related tasks.
		$status     = get_post_meta( $post->ID, '_project_status', true );
		$start_date = get_post_meta( $post->ID, '_project_start_date', true );
		$end_date   = get_post_meta( $post->ID, '_project_end_date', true );

		// Get related tasks.
		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'posts_per_page' => 50,
				'meta_query'     => array(
					array(
						'key'   => '_task_project_id',
						'value' => $post->ID,
					),
				),
			)
		);

		$task_summary = array(
			'total'      => count( $tasks ),
			'completed'  => 0,
			'in_progress' => 0,
			'todo'       => 0,
		);

		foreach ( $tasks as $task ) {
			$task_status = get_post_meta( $task->ID, '_task_status', true );
			if ( 'completed' === $task_status ) {
				$task_summary['completed']++;
			} elseif ( 'in-progress' === $task_status ) {
				$task_summary['in_progress']++;
			} else {
				$task_summary['todo']++;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Assistant_Service' ) ) {
			return new WP_Error( 'service_unavailable', __( 'AI service is not available.', 'wp-mcp-ai' ) );
		}

		$prompt = sprintf(
			"Analyze this project:\nTitle: %s\nStatus: %s\nStart: %s\nEnd: %s\nTasks: %d total (%d completed, %d in progress, %d todo)\n\nProvide a brief analysis covering: 1) Overall progress, 2) Potential risks or blockers, 3) One actionable recommendation. Keep it under 150 words.",
			$post->post_title,
			$status,
			$start_date ? $start_date : 'Not set',
			$end_date ? $end_date : 'Not set',
			$task_summary['total'],
			$task_summary['completed'],
			$task_summary['in_progress'],
			$task_summary['todo']
		);

		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
			)
		);

		if ( empty( $assistants ) ) {
			return new WP_Error( 'no_assistant', __( 'No AI assistant available.', 'wp-mcp-ai' ) );
		}

		try {
			$assistant_service = WP_MCP_AI_Assistant_Service::get_instance();
			$response          = $assistant_service->chat(
				$assistants[0]->ID,
				$prompt,
				array( 'max_tokens' => 250 )
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			return isset( $response['content'] ) ? trim( $response['content'] ) : '';
		} catch ( Exception $e ) {
			return new WP_Error( 'ai_error', $e->getMessage() );
		}
	}
}
