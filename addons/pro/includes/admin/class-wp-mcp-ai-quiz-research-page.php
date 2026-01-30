<?php
/**
 * Research & Add admin page for Quiz CPT.
 *
 * Provides a dedicated page for researching quiz topics before creating quizzes,
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
 * Quiz Research Admin Page
 *
 * Adds a submenu page under Quizzes menu for AI-powered topic research.
 */
class WP_MCP_AI_Quiz_Research_Page {
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
	const PAGE_SLUG = 'research-quiz';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_quiz_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_quiz', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add submenu page under Quizzes menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_quiz',
			__( 'Research & Add Quiz', 'mcp-ai-wpoos-pro' ),
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
		if ( 'mcp_ai_quiz_page_' . self::PAGE_SLUG !== $hook ) {
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
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_quiz' ),
				'entityType' => 'quiz',
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_quiz_settings', array() );
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
				<?php esc_html_e( 'Research & Add Quiz', 'mcp-ai-wpoos-pro' ); ?>
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
							<li><?php esc_html_e( 'Search the web or existing quizzes for inspiration', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Use AI to research quiz topics and generate questions', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Review and refine the generated content', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create the quiz directly or save for later editing', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search first:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Find existing quizzes or web content for ideas', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Be specific:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Specify difficulty level and question count', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Set parameters:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Request time limits and passing scores', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Review content:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Verify accuracy before creating quiz', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="List all existing quizzes">
								<?php esc_html_e( '"List existing quizzes"', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Search the web for latest information about climate change">
								<?php esc_html_e( '"Search web for climate change..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create 10 intermediate level questions about World War II">
								<?php esc_html_e( '"Create 10 WWII questions..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Find similar quizzes about mathematics">
								<?php esc_html_e( '"Find similar math quizzes..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-preview" id="wp-mcp-ai-quiz-preview" style="display: none;">
						<h3><?php esc_html_e( 'Quiz Preview', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="wp-mcp-ai-preview-content">
							<div class="wp-mcp-ai-preview-loading">
								<span class="spinner is-active"></span>
								<p><?php esc_html_e( 'Building quiz...', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<div class="wp-mcp-ai-preview-data" style="display: none;">
								<div class="wp-mcp-ai-preview-header">
									<h4 class="wp-mcp-ai-preview-title"></h4>
									<p class="wp-mcp-ai-preview-meta"></p>
								</div>
								<div class="wp-mcp-ai-preview-questions"></div>
								<div class="wp-mcp-ai-preview-pagination" style="display: none;">
									<button type="button" class="button wp-mcp-ai-preview-prev" disabled>
										<span class="dashicons dashicons-arrow-left-alt2"></span>
										<?php esc_html_e( 'Previous', 'mcp-ai-wpoos-pro' ); ?>
									</button>
									<span class="wp-mcp-ai-preview-page-info">
										<span class="wp-mcp-ai-preview-current-page">1</span>
										<?php esc_html_e( 'of', 'mcp-ai-wpoos-pro' ); ?>
										<span class="wp-mcp-ai-preview-total-pages">1</span>
									</span>
									<button type="button" class="button wp-mcp-ai-preview-next">
										<?php esc_html_e( 'Next', 'mcp-ai-wpoos-pro' ); ?>
										<span class="dashicons dashicons-arrow-right-alt2"></span>
									</button>
								</div>
							</div>
						</div>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_quiz' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Quizzes', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_quiz' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Quiz Manually', 'mcp-ai-wpoos-pro' ); ?>
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
								<p><?php esc_html_e( 'Research and create quizzes with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import quiz data', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View quiz quality and completeness', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive quiz tools.
							// Includes research, creation, discovery, and content search tools.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="research_quiz_topic,create_quiz,list_quizzes,get_quiz,web_search,search_content,semantic_content_search"]'
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
	 * Handle AJAX request to create quiz from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_quiz', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create quizzes.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$research_data = isset( $_POST['research_data'] ) ? json_decode( wp_unslash( $_POST['research_data'] ), true ) : array();

		if ( empty( $research_data ) || empty( $research_data['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid research data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process featured image generation request.
		$research_data = self::process_featured_image_request( $research_data, $research_data['title'], 'a quiz' );

		// Use the create_quiz tool to create the quiz.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Quiz' ) ) {
			wp_send_json_error( array( 'message' => __( 'Create quiz tool not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$tool   = new WP_MCP_AI_Tool_Create_Quiz();
		$result = $tool->execute(
			$research_data,
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Return success with quiz ID and edit URL.
		$quiz_id  = isset( $result['quiz_id'] ) ? $result['quiz_id'] : 0;
		$edit_url = $quiz_id > 0 ? admin_url( 'post.php?post=' . $quiz_id . '&action=edit' ) : '';

		wp_send_json_success(
			array(
				'message'  => __( 'Quiz created successfully!', 'mcp-ai-wpoos-pro' ),
				'quiz_id'  => $quiz_id,
				'edit_url' => $edit_url,
			)
		);
	}

	/**
	 * Get supported import formats.
	 *
	 * @return array Import formats.
	 */
	protected static function get_import_formats() {
		return array(
			'csv'   => 'CSV',
			'json'  => 'JSON',
			'scorm' => 'SCORM',
			'qti'   => 'QTI',
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
		// Implementation would parse the format and create quizzes.
		return new WP_Error( 'not_implemented', __( 'Quiz import processing coming soon', 'mcp-ai-wpoos-pro' ) );
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
				'questions'   => __( 'Questions', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Description', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'time_limit'    => __( 'Time Limit', 'mcp-ai-wpoos-pro' ),
				'passing_score' => __( 'Passing Score', 'mcp-ai-wpoos-pro' ),
				'difficulty'    => __( 'Difficulty Level', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'questions'     => array(
					'type'      => 'array',
					'min_count' => 1,
				),
				'time_limit'    => array(
					'type'      => 'numeric',
					'min_value' => 0,
				),
				'passing_score' => array(
					'type'      => 'numeric',
					'min_value' => 0,
					'max_value' => 100,
				),
			),
			'quality_dimensions' => array(
				'completeness',
				'accuracy',
				'consistency',
				'scorm_compliance',
			),
		);
	}

	/**
	 * Calculate completeness.
	 *
	 * @return array Completeness data.
	 */
	protected static function calculate_completeness() {
		$quizzes = get_posts(
			array(
				'post_type'      => 'mcp_ai_quiz',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total_quizzes    = count( $quizzes );
		$complete_quizzes = 0;
		$missing          = array();

		foreach ( $quizzes as $quiz ) {
			$questions = get_post_meta( $quiz->ID, 'questions', true );
			if ( ! empty( $questions ) && ! empty( $quiz->post_content ) ) {
				++$complete_quizzes;
			}
		}

		$percentage = $total_quizzes > 0 ? round( ( $complete_quizzes / $total_quizzes ) * 100 ) : 0;

		if ( $complete_quizzes < $total_quizzes ) {
			$missing[] = sprintf(
				/* translators: %d: Number of incomplete quizzes */
				__( '%d quizzes missing descriptions or questions', 'mcp-ai-wpoos-pro' ),
				$total_quizzes - $complete_quizzes
			);
		}

		return array(
			'percentage'  => $percentage,
			'missing'     => $missing,
			'suggestions' => array(
				__( 'Add descriptions to all quizzes', 'mcp-ai-wpoos-pro' ),
				__( 'Ensure each quiz has at least 5 questions', 'mcp-ai-wpoos-pro' ),
				__( 'Set time limits and passing scores', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Items.
	 */
	protected static function get_items_for_review() {
		$quizzes = get_posts(
			array(
				'post_type'      => 'mcp_ai_quiz',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $quizzes as $quiz ) {
			$items[] = array(
				'id'    => $quiz->ID,
				'title' => $quiz->post_title,
				'meta'  => array(
					'questions'     => get_post_meta( $quiz->ID, 'questions', true ),
					'time_limit'    => get_post_meta( $quiz->ID, 'time_limit', true ),
					'passing_score' => get_post_meta( $quiz->ID, 'passing_score', true ),
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

		// Check questions (40 points).
		$questions = isset( $item['meta']['questions'] ) ? $item['meta']['questions'] : array();
		if ( is_array( $questions ) && count( $questions ) >= 5 ) {
			$score += 40;
		} elseif ( is_array( $questions ) && count( $questions ) > 0 ) {
			$score   += 20;
			$issues[] = __( 'Needs more questions', 'mcp-ai-wpoos-pro' );
		} else {
			$issues[] = __( 'Missing questions', 'mcp-ai-wpoos-pro' );
		}

		// Check time limit (20 points).
		if ( ! empty( $item['meta']['time_limit'] ) ) {
			$score += 20;
		} else {
			$issues[] = __( 'Missing time limit', 'mcp-ai-wpoos-pro' );
		}

		// Check passing score (20 points).
		if ( ! empty( $item['meta']['passing_score'] ) ) {
			$score += 20;
		} else {
			$issues[] = __( 'Missing passing score', 'mcp-ai-wpoos-pro' );
		}

		// Check title (20 points).
		if ( ! empty( $item['title'] ) && strlen( $item['title'] ) > 10 ) {
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
	 * Render import workflow.
	 */
	protected static function render_import_workflow() {
		?>
		<div class="wp-mcp-ai-import-section">
			<h2><?php esc_html_e( 'Import Quiz Data', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import quizzes from CSV, JSON, SCORM, QTI, or paste structured data. The AI will automatically parse and organize the quiz information.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			
			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include quiz title, description, and questions', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Specify difficulty level, time limits, and passing scores', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Provide answer options and correct answers', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Include question explanations and feedback', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_import_quizzes', 'import_nonce' ); ?>
					
					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-import-file-input" name="import_file" accept=".csv,.json,.zip,.txt" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Choose File', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Supported: CSV, JSON, SCORM (ZIP), QTI (ZIP), TXT', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<p><strong><?php esc_html_e( 'OR', 'mcp-ai-wpoos-pro' ); ?></strong></p>

					<textarea 
						id="wp-mcp-ai-import-text" 
						name="import_data" 
						class="widefat" 
						rows="12" 
						placeholder="<?php esc_attr_e( 'Example:\n\nQuiz Title: World History Quiz\nDescription: Test your knowledge of world history\nTime Limit: 30 minutes\nPassing Score: 70\nDifficulty: Intermediate\n\nQuestion 1: What year did World War II end?\nA) 1943\nB) 1944\nC) 1945 (correct)\nD) 1946\n\nQuestion 2: Who painted the Mona Lisa?\nA) Michelangelo\nB) Leonardo da Vinci (correct)\nC) Raphael\nD) Donatello', 'mcp-ai-wpoos-pro' ); ?>"
					></textarea>
					
					<div class="import-options">
						<label>
							<input type="checkbox" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create quizzes (recommended)', 'mcp-ai-wpoos-pro' ); ?>
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
		// Get quiz statistics.
		$total_quizzes   = wp_count_posts( 'mcp_ai_quiz' );
		$published_count = isset( $total_quizzes->publish ) ? $total_quizzes->publish : 0;

		// Calculate data quality metrics.
		$quizzes = get_posts(
			array(
				'post_type'      => 'mcp_ai_quiz',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count = 0;
		$with_questions = 0;
		$with_settings  = 0;

		foreach ( $quizzes as $quiz ) {
			$questions     = get_post_meta( $quiz->ID, 'questions', true );
			$time_limit    = get_post_meta( $quiz->ID, 'time_limit', true );
			$passing_score = get_post_meta( $quiz->ID, 'passing_score', true );
			$has_desc      = ! empty( $quiz->post_content );

			if ( is_array( $questions ) && count( $questions ) >= 5 ) {
				++$with_questions;
			}
			if ( ! empty( $time_limit ) && ! empty( $passing_score ) ) {
				++$with_settings;
			}
			if ( is_array( $questions ) && count( $questions ) >= 5 && ! empty( $time_limit ) && $has_desc ) {
				++$complete_count;
			}
		}

		$completeness = $published_count > 0 ? round( ( $complete_count / $published_count ) * 100 ) : 0;

		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Quiz Quality Dashboard', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="quality-dashboard">
				<h3><?php esc_html_e( 'Overall Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Quizzes', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_questions ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With 5+ Questions', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_settings ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Settings', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Quiz completeness is %d%%. Ensure all quizzes have sufficient questions, descriptions, and proper time/score settings.', 'mcp-ai-wpoos-pro' ),
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
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_quiz' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Quizzes', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_quiz' ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Quiz', 'mcp-ai-wpoos-pro' ); ?>
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
}

// Initialize.
WP_MCP_AI_Quiz_Research_Page::init();
