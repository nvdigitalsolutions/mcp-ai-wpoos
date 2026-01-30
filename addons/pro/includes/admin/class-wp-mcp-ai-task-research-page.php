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

		?>
		<div class="wrap wp-mcp-ai-research-page">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Research & Add Task', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<?php self::render_chat_interface( $assistant_id ); ?>
		</div>
		<?php
	}

	/**
	 * Render the chat interface.
	 *
	 * @param int $assistant_id Assistant ID.
	 */
	protected static function render_chat_interface( $assistant_id ) {
		?>
			<div class="wp-mcp-ai-research-container">
				<div class="wp-mcp-ai-research-sidebar">
					<div class="wp-mcp-ai-research-intro">
						<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos-pro' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Search existing tasks or brainstorm new ones', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Use AI to break down complex tasks into subtasks', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Set priorities, due dates, and project associations', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create tasks directly or save for later editing', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search first:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'List existing tasks to avoid duplicates', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Be specific:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Define clear objectives and deliverables', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Set priorities:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Specify low, medium, high, or urgent', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Add context:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Include project associations and dependencies', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="List all existing tasks">
								<?php esc_html_e( '"List existing tasks"', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create a high priority task for website redesign with subtasks">
								<?php esc_html_e( '"Create website redesign task..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Break down content strategy task into smaller tasks">
								<?php esc_html_e( '"Break down content strategy..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_task' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Tasks', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_task' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Task Manually', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
					</div>
				</div>

				<div class="wp-mcp-ai-research-main">
					<!-- Workflow Mode Selector -->
					<div class="wp-mcp-ai-workflow-selector">
						<h2><?php esc_html_e( 'Choose Your Workflow', 'mcp-ai-wpoos-pro' ); ?></h2>
						<div class="workflow-options">
							<button type="button" class="workflow-option active" data-workflow="research">
								<span class="dashicons dashicons-format-chat"></span>
								<strong><?php esc_html_e( 'AI Research', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Research and create tasks with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import task data', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View task quality and completeness', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive task tools.
							// Includes task creation, management, and project association tools.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="create_task,list_tasks,update_task,delete_task,create_project,list_projects,web_search,search_content"]'
							);
							?>
						</div>

					<?php else : ?>
						<div class="notice notice-error">
							<p>
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: Link to create assistant */
										__( 'No AI assistant found. Please <a href="%s">create an assistant</a> first.', 'mcp-ai-wpoos-pro' ),
										admin_url( 'post-new.php?post_type=mcp_ai_assistant' )
									)
								);
								?>
							</p>
						</div>
					<?php endif; ?>
					</div>

					<!-- Import Data Workflow -->
					<div id="workflow-import" class="workflow-content">
						<?php self::render_import_workflow(); ?>
					</div>

					<!-- Review & Quality Workflow -->
					<div id="workflow-review" class="workflow-content">
						<?php self::render_review_workflow(); ?>
					</div>
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
				++$results['error_count'];
				$results['errors'][ $index ] = $validation->get_error_message();
				continue;
			}

			// Create task.
			$result = $tool->execute( $task_data, array( 'user_id' => get_current_user_id() ) );

			if ( is_wp_error( $result ) ) {
				++$results['error_count'];
				$results['errors'][ $index ] = $result->get_error_message();
			} else {
				++$results['success_count'];
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
	 * Render import workflow.
	 */
	protected static function render_import_workflow() {
		?>
		<div class="wp-mcp-ai-import-section">
			<h2><?php esc_html_e( 'Import Task Data', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import tasks from CSV, JSON, or paste structured data. The AI will automatically parse and organize the task information.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			
			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include task title and description', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Specify priority (low, medium, high, urgent) and status', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Add due dates and project associations', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Include assignee information when available', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_import_tasks', 'import_nonce' ); ?>
					
					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-import-file-input" name="import_file" accept=".csv,.json,.txt" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Choose File', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Supported: CSV, JSON, TXT', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<p><strong><?php esc_html_e( 'OR', 'mcp-ai-wpoos-pro' ); ?></strong></p>

					<textarea 
						id="wp-mcp-ai-import-text" 
						name="import_data" 
						class="widefat" 
						rows="12" 
						placeholder="<?php esc_attr_e( 'Example:\n\nTitle: Update website homepage\nDescription: Redesign and update homepage layout\nPriority: high\nStatus: todo\nDue Date: 2024-02-15\n\nTitle: Write blog post\nDescription: Create content for product launch\nPriority: medium\nStatus: in-progress', 'mcp-ai-wpoos-pro' ); ?>"
					></textarea>
					
					<div class="import-options">
						<label>
							<input type="checkbox" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create tasks (recommended)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<label>
							<input type="checkbox" name="validate_data" value="1" checked>
							<?php esc_html_e( 'Validate data quality before importing', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</div>

					<p>
						<button type="submit" class="button button-primary button-large">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Import & Process', 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</p>
					<div class="import-result" style="display: none;"></div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render review workflow.
	 */
	protected static function render_review_workflow() {
		// Get task statistics.
		$total_tasks     = wp_count_posts( 'mcp_ai_task' );
		$published_count = isset( $total_tasks->publish ) ? $total_tasks->publish : 0;

		// Calculate data quality metrics.
		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count = 0;
		$with_priority  = 0;
		$with_status    = 0;

		foreach ( $tasks as $task ) {
			$priority = get_post_meta( $task->ID, 'priority', true );
			$status   = get_post_meta( $task->ID, 'status', true );
			$has_desc = ! empty( $task->post_content );

			if ( ! empty( $priority ) ) {
				++$with_priority;
			}
			if ( ! empty( $status ) ) {
				++$with_status;
			}
			if ( ! empty( $priority ) && ! empty( $status ) && $has_desc ) {
				++$complete_count;
			}
		}

		$completeness = $published_count > 0 ? round( ( $complete_count / $published_count ) * 100 ) : 0;

		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Task Quality Dashboard', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="quality-dashboard">
				<h3><?php esc_html_e( 'Overall Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Tasks', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_priority ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Priority', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_status ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Status', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Task completeness is %d%%. Consider adding priorities, statuses, and descriptions to improve quality.', 'mcp-ai-wpoos-pro' ),
								esc_html( $completeness )
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<?php self::render_quality_table(); ?>

			<div class="items-list-table">
				<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_task' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Tasks', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_task' ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Task', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<button type="button" class="button refresh-quality-data">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Refresh Data', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Get supported import formats.
	 *
	 * @return array Import formats.
	 */
	protected static function get_import_formats() {
		return array(
			'csv'  => 'CSV',
			'json' => 'JSON',
		);
	}

	/**
	 * Process imported data.
	 *
	 * @param string $data   Import data.
	 * @param string $format Data format.
	 * @return array|WP_Error Result or error.
	 */
	protected static function process_import_data( $data, $format ) {
		return self::parse_import_data( $data, $format );
	}

	/**
	 * Get validation schema.
	 *
	 * @return array Validation schema.
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'title'       => __( 'Title', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Description', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'priority' => __( 'Priority', 'mcp-ai-wpoos-pro' ),
				'status'   => __( 'Status', 'mcp-ai-wpoos-pro' ),
				'due_date' => __( 'Due Date', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'priority' => array(
					'type'   => 'enum',
					'values' => array( 'low', 'medium', 'high', 'urgent' ),
				),
				'status'   => array(
					'type'   => 'enum',
					'values' => array( 'todo', 'in-progress', 'review', 'completed', 'cancelled' ),
				),
			),
			'quality_dimensions' => array(
				'completeness',
				'priority_assignment',
				'status_tracking',
			),
		);
	}

	/**
	 * Calculate completeness.
	 *
	 * @return array Completeness data.
	 */
	protected static function calculate_completeness() {
		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total_tasks    = count( $tasks );
		$complete_tasks = 0;
		$missing        = array();

		foreach ( $tasks as $task ) {
			$priority = get_post_meta( $task->ID, 'priority', true );
			$status   = get_post_meta( $task->ID, 'status', true );
			if ( ! empty( $priority ) && ! empty( $status ) && ! empty( $task->post_content ) ) {
				++$complete_tasks;
			}
		}

		$percentage = $total_tasks > 0 ? round( ( $complete_tasks / $total_tasks ) * 100 ) : 0;

		if ( $complete_tasks < $total_tasks ) {
			$missing[] = sprintf(
				/* translators: %d: Number of incomplete tasks */
				__( '%d tasks missing descriptions, priority, or status', 'mcp-ai-wpoos-pro' ),
				$total_tasks - $complete_tasks
			);
		}

		return array(
			'percentage'  => $percentage,
			'missing'     => $missing,
			'suggestions' => array(
				__( 'Add descriptions to all tasks', 'mcp-ai-wpoos-pro' ),
				__( 'Set priority levels for all tasks', 'mcp-ai-wpoos-pro' ),
				__( 'Define status for task tracking', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Items.
	 */
	protected static function get_items_for_review() {
		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $tasks as $task ) {
			$items[] = array(
				'id'    => $task->ID,
				'title' => $task->post_title,
				'meta'  => array(
					'priority' => get_post_meta( $task->ID, 'priority', true ),
					'status'   => get_post_meta( $task->ID, 'status', true ),
					'due_date' => get_post_meta( $task->ID, 'due_date', true ),
				),
			);
		}

		return $items;
	}

	/**
	 * Calculate quality score for item.
	 *
	 * @param array $item Item data.
	 * @return array Quality data.
	 */
	protected static function calculate_quality_score( $item ) {
		$score  = 0;
		$issues = array();

		// Check priority (30 points).
		if ( ! empty( $item['meta']['priority'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing priority', 'mcp-ai-wpoos-pro' );
		}

		// Check status (30 points).
		if ( ! empty( $item['meta']['status'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing status', 'mcp-ai-wpoos-pro' );
		}

		// Check due date (20 points).
		if ( ! empty( $item['meta']['due_date'] ) ) {
			$score += 20;
		} else {
			$issues[] = __( 'Missing due date', 'mcp-ai-wpoos-pro' );
		}

		// Check title (20 points).
		if ( ! empty( $item['title'] ) && strlen( $item['title'] ) > 5 ) {
			$score += 20;
		} else {
			$issues[] = __( 'Title needs improvement', 'mcp-ai-wpoos-pro' );
		}

		// Determine level.
		if ( $score >= 80 ) {
			$level = 'high';
		} elseif ( $score >= 50 ) {
			$level = 'medium';
		} else {
			$level = 'low';
		}

		return array(
			'score'  => $score,
			'level'  => $level,
			'status' => 'high' === $level ? __( 'Complete', 'mcp-ai-wpoos-pro' ) : __( 'Needs Work', 'mcp-ai-wpoos-pro' ),
			'issues' => $issues,
		);
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
			<div class="wp-mcp-ai-consolidation-header">
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
