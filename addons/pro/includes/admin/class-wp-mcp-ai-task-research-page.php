<?php
/**
 * Research & Add admin page for Task CPT.
 *
 * Provides a dedicated page for researching tasks before adding them,
 * with full chat interface for AI assistance.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * Task Research Admin Page
 *
 * Adds a submenu page under Tasks menu for AI-powered task research.
 */
class WP_MCP_AI_Task_Research_Page {
	use WP_MCP_AI_Research_Page_Featured_Image;
	use WP_MCP_AI_Research_Page_Import_Handler;
	use WP_MCP_AI_Research_Page_Consolidation;
	use WP_MCP_AI_Research_Page_Data_Validation;
	use WP_MCP_AI_Research_Page_Mode_Tabs;

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'research-task';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_task_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_task', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add submenu page under Tasks menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_task',
			__( 'Research & Add Task', 'mcp-ai-wpoos-pro' ),
			__( 'Research & Add', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for the research page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		// Only load on our research page.
		if ( 'mcp_ai_task_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue chat assets.
		if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			$shortcode_instance = new WP_MCP_AI_Shortcode();
			$shortcode_instance->register_assets();
			wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
			wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		}

		// Enqueue enhanced research page styles.
		wp_enqueue_style(
			'wp-mcp-ai-enhanced-research-page',
			WP_MCP_AI_URL . 'assets/css/enhanced-research-page.css',
			array(),
			WP_MCP_AI_VERSION
		);

		// Enqueue enhanced research page script.
		wp_enqueue_script(
			'wp-mcp-ai-enhanced-research-page',
			WP_MCP_AI_URL . 'assets/js/enhanced-research-page.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'wp-mcp-ai-enhanced-research-page',
			'wpMcpAiResearchPage',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_task' ),
				'entityType' => 'task',
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings (future enhancement - add task-specific settings).
		$settings     = get_option( 'wp_mcp_ai_task_settings', array() );
		$assistant_id = isset( $settings['assistant_id'] ) ? absint( $settings['assistant_id'] ) : 0;

		// If no assistant configured or invalid, get the first available assistant.
		if ( ! $assistant_id || 'publish' !== get_post_status( $assistant_id ) ) {
			$assistants = get_posts(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			$assistant_id = ! empty( $assistants ) ? $assistants[0]->ID : 0;
		}

		// Get current mode
		$current_mode = self::get_current_mode();

		?>
		<div class="wrap wp-mcp-ai-research-page">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Research & Add Task', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<?php self::render_mode_tabs( $current_mode ); ?>

			<div class="wp-mcp-ai-research-content">
				<div id="research-mode" class="research-mode-content <?php echo 'chat' === $current_mode ? 'active' : ''; ?>">
					<?php self::render_chat_interface( $assistant_id ); ?>
				</div>

				<div id="import-mode" class="research-mode-content <?php echo 'import' === $current_mode ? 'active' : ''; ?>">
					<?php self::render_import_section(); ?>
				</div>

				<div id="consolidate-mode" class="research-mode-content <?php echo 'consolidate' === $current_mode ? 'active' : ''; ?>">
					<?php self::render_consolidation_section(); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the chat interface.
	 *
	 * @param int $assistant_id Assistant ID.
	 */
	protected static function render_chat_interface( $assistant_id ) {
		if ( ! $assistant_id ) {
			?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'No assistant configured. Please configure an assistant in Settings or create one in the Assistants menu.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
			<?php
			return;
		}

		// Render the chat UI using shortcode functionality.
		$chat_id = 'task-research-' . wp_generate_password( 8, false );

		?>
		<div class="wp-mcp-ai-research-chat-container">
			<div class="chat-instructions">
				<h2><?php esc_html_e( '🤖 AI Task Assistant', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p><?php esc_html_e( 'Describe the task you want to create, and the AI will help you research, plan, and add it with all relevant details including priorities, due dates, and project associations.', 'mcp-ai-wpoos-pro' ); ?></p>
				
				<details class="chat-tips">
					<summary><?php esc_html_e( 'Tips for better results', 'mcp-ai-wpoos-pro' ); ?></summary>
					<ul>
						<li><?php esc_html_e( '✅ Be specific about task objectives and deliverables', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( '✅ Mention priority levels (low, medium, high, urgent)', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( '✅ Include due dates or deadlines if known', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( '✅ Specify project associations if applicable', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( '✅ Ask for task breakdowns or subtasks for complex work', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( '✅ Request task dependencies if tasks are related', 'mcp-ai-wpoos-pro' ); ?></li>
					</ul>
				</details>
			</div>

			<div id="<?php echo esc_attr( $chat_id ); ?>" 
				 class="wp-mcp-ai-chat-widget" 
				 data-assistant-id="<?php echo esc_attr( $assistant_id ); ?>"
				 data-context="task_research"
				 data-initial-message="<?php esc_attr_e( 'Hello! I can help you research and create tasks. What task would you like to create today?', 'mcp-ai-wpoos-pro' ); ?>">
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to create task from research data.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_task', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to create tasks.', 'mcp-ai-wpoos-pro' ),
				)
			);
		}

		// Get and validate task data.
		$task_data = isset( $_POST['task_data'] ) ? wp_unslash( $_POST['task_data'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $task_data ) || ! is_array( $task_data ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid task data provided.', 'mcp-ai-wpoos-pro' ),
				)
			);
		}

		// Validate required fields.
		$validation_result = self::validate_task_data( $task_data );
		if ( is_wp_error( $validation_result ) ) {
			wp_send_json_error(
				array(
					'message' => $validation_result->get_error_message(),
				)
			);
		}

		// Use the create_task tool to create the task.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Task' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-task.php';
		}

		$tool   = new WP_MCP_AI_Tool_Create_Task();
		$result = $tool->execute( $task_data, array( 'user_id' => get_current_user_id() ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Task created successfully!', 'mcp-ai-wpoos-pro' ),
				'task_id' => $result['task_id'],
				'task'    => $result['task'],
			)
		);
	}

	/**
	 * Handle AJAX import request.
	 */
	public static function ajax_handle_import() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_import_data', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to import tasks.', 'mcp-ai-wpoos-pro' ),
				)
			);
		}

		$import_data = isset( $_POST['import_data'] ) ? wp_unslash( $_POST['import_data'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$format      = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : 'csv';

		if ( empty( $import_data ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No data provided for import.', 'mcp-ai-wpoos-pro' ),
				)
			);
		}

		// Parse import data based on format.
		$tasks = self::parse_import_data( $import_data, $format );

		if ( is_wp_error( $tasks ) ) {
			wp_send_json_error(
				array(
					'message' => $tasks->get_error_message(),
				)
			);
		}

		// Import tasks.
		$results = self::import_tasks( $tasks );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: Number of successful imports, 2: Total number of tasks */
					__( 'Successfully imported %1$d of %2$d tasks.', 'mcp-ai-wpoos-pro' ),
					$results['success_count'],
					$results['total_count']
				),
				'results' => $results,
			)
		);
	}

	/**
	 * Validate task data.
	 *
	 * @param array $task_data Task data to validate.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	protected static function validate_task_data( $task_data ) {
		// Title is required.
		if ( empty( $task_data['title'] ) ) {
			return new WP_Error(
				'missing_title',
				__( 'Task title is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate status if provided.
		$valid_statuses = array( 'todo', 'in-progress', 'review', 'completed', 'cancelled' );
		if ( ! empty( $task_data['status'] ) && ! in_array( $task_data['status'], $valid_statuses, true ) ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
					/* translators: %s: Comma-separated list of valid statuses */
					__( 'Invalid status. Valid values are: %s', 'mcp-ai-wpoos-pro' ),
					implode( ', ', $valid_statuses )
				)
			);
		}

		// Validate priority if provided.
		$valid_priorities = array( 'low', 'medium', 'high', 'urgent' );
		if ( ! empty( $task_data['priority'] ) && ! in_array( $task_data['priority'], $valid_priorities, true ) ) {
			return new WP_Error(
				'invalid_priority',
				sprintf(
					/* translators: %s: Comma-separated list of valid priorities */
					__( 'Invalid priority. Valid values are: %s', 'mcp-ai-wpoos-pro' ),
					implode( ', ', $valid_priorities )
				)
			);
		}

		return true;
	}

	/**
	 * Parse import data based on format.
	 *
	 * @param string $data   Import data.
	 * @param string $format Data format (csv or json).
	 * @return array|WP_Error Array of task data or WP_Error on failure.
	 */
	protected static function parse_import_data( $data, $format ) {
		if ( 'json' === $format ) {
			$tasks = json_decode( $data, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error(
					'invalid_json',
					__( 'Invalid JSON data provided.', 'mcp-ai-wpoos-pro' )
				);
			}
			return is_array( $tasks ) ? $tasks : array( $tasks );
		}

		// Parse CSV.
		$lines = explode( "\n", $data );
		$tasks = array();

		// First line should be headers.
		$headers = str_getcsv( array_shift( $lines ) );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			$values = str_getcsv( $line );
			$task   = array();

			foreach ( $headers as $index => $header ) {
				$task[ sanitize_key( $header ) ] = isset( $values[ $index ] ) ? $values[ $index ] : '';
			}

			if ( ! empty( $task ) ) {
				$tasks[] = $task;
			}
		}

		return $tasks;
	}

	/**
	 * Import multiple tasks.
	 *
	 * @param array $tasks Array of task data.
	 * @return array Import results.
	 */
	protected static function import_tasks( $tasks ) {
		$results = array(
			'total_count'   => count( $tasks ),
			'success_count' => 0,
			'error_count'   => 0,
			'errors'        => array(),
		);

		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Task' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-task.php';
		}

		$tool = new WP_MCP_AI_Tool_Create_Task();

		foreach ( $tasks as $index => $task_data ) {
			// Validate task data.
			$validation = self::validate_task_data( $task_data );
			if ( is_wp_error( $validation ) ) {
				$results['error_count']++;
				$results['errors'][ $index ] = $validation->get_error_message();
				continue;
			}

			// Create task.
			$result = $tool->execute( $task_data, array( 'user_id' => get_current_user_id() ) );

			if ( is_wp_error( $result ) ) {
				$results['error_count']++;
				$results['errors'][ $index ] = $result->get_error_message();
			} else {
				$results['success_count']++;
			}
		}

		return $results;
	}

	/**
	 * Get file accept attribute for import.
	 *
	 * @return string File accept attribute.
	 */
	protected static function get_file_accept_attribute() {
		return '.csv,.json';
	}

	/**
	 * Render import tips section.
	 */
	protected static function render_import_tips() {
		?>
		<div class="wp-mcp-ai-import-tips">
			<h3><?php esc_html_e( '💡 Import Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'CSV files should have headers: title, description, status, priority, due_date, project_id, assigned_to', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'JSON format should be an array of task objects with the same field names', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Only title is required; other fields are optional', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Status values: todo, in-progress, review, completed, cancelled', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Priority values: low, medium, high, urgent', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Date format: YYYY-MM-DD', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render consolidation section.
	 */
	protected static function render_consolidation_section() {
		?>
		<div class="wp-mcp-ai-consolidation-container">
			<div class="consolidation-header">
				<h2><?php esc_html_e( '🔄 Consolidate & Organize Tasks', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p><?php esc_html_e( 'Review, merge, and organize existing tasks with AI assistance.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<div class="consolidation-options">
				<div class="option-card">
					<h3><?php esc_html_e( '🔍 Find Duplicates', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p><?php esc_html_e( 'AI will scan for duplicate or similar tasks and suggest merging them.', 'mcp-ai-wpoos-pro' ); ?></p>
					<button type="button" class="button button-primary" id="find-duplicate-tasks">
						<?php esc_html_e( 'Scan for Duplicates', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				</div>

				<div class="option-card">
					<h3><?php esc_html_e( '📊 Organize by Priority', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p><?php esc_html_e( 'Review and reorganize tasks based on priority and urgency.', 'mcp-ai-wpoos-pro' ); ?></p>
					<button type="button" class="button button-primary" id="organize-by-priority">
						<?php esc_html_e( 'Organize Tasks', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				</div>

				<div class="option-card">
					<h3><?php esc_html_e( '🗂️ Group by Project', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p><?php esc_html_e( 'AI will suggest grouping orphaned tasks into appropriate projects.', 'mcp-ai-wpoos-pro' ); ?></p>
					<button type="button" class="button button-primary" id="group-by-project">
						<?php esc_html_e( 'Suggest Grouping', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				</div>
			</div>

			<div id="consolidation-results" class="consolidation-results" style="display: none;"></div>
		</div>
		<?php
	}
}
