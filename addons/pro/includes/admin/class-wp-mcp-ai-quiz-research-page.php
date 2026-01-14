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

/**
 * Quiz Research Admin Page
 *
 * Adds a submenu page under Quizzes menu for AI-powered topic research.
 */
class WP_MCP_AI_Quiz_Research_Page {

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

		// Enqueue research page specific styles.
		wp_enqueue_style(
			'wp-mcp-ai-research-page',
			WP_MCP_AI_PRO_URL . 'assets/css/research-page.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);

		// Enqueue research page script.
		wp_enqueue_script(
			'wp-mcp-ai-research-page',
			WP_MCP_AI_PRO_URL . 'assets/js/research-page.js',
			array( 'jquery', 'wp-api', WP_MCP_AI_Shortcode::SCRIPT_HANDLE ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'wp-mcp-ai-research-page',
			'wpMcpAiResearchPage',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'wp_mcp_ai_research_quiz' ),
				'addNewUrl'     => admin_url( 'post-new.php?post_type=mcp_ai_quiz' ),
				'researchTool'  => 'research_quiz_topic',
				'strings'       => array(
					'researching'       => __( 'Researching...', 'mcp-ai-wpoos-pro' ),
					'error'             => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'creating'          => __( 'Creating quiz...', 'mcp-ai-wpoos-pro' ),
					'created'           => __( 'Quiz created successfully!', 'mcp-ai-wpoos-pro' ),
					'confirmCreate'     => __( 'Create a quiz with the researched information?', 'mcp-ai-wpoos-pro' ),
				),
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

			<div class="wp-mcp-ai-research-container">
				<div class="wp-mcp-ai-research-sidebar">
					<div class="wp-mcp-ai-research-intro">
						<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos-pro' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Use the AI assistant to research a quiz topic', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Ask for quiz questions like "Create quiz questions about World War II"', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Review the generated questions and answers', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Click "Create Quiz from Research" to add it to your database', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Be specific:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Specify difficulty level and question count', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Set parameters:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Request time limits and passing scores', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Review content:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Verify accuracy of questions and answers', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create 10 intermediate level questions about World War II">
								<?php esc_html_e( '"Create 10 intermediate questions about WWII"', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Generate basic algebra quiz with 15 questions">
								<?php esc_html_e( '"Generate basic algebra quiz..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Make a challenging quiz about Shakespeare's works">
								<?php esc_html_e( '"Make quiz about Shakespeare..."', 'mcp-ai-wpoos-pro' ); ?>
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
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with research tool.
							// Ensure research_quiz_topic tool is always available on this page.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="research_quiz_topic"]'
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
		$quiz_id = isset( $result['quiz_id'] ) ? $result['quiz_id'] : 0;
		$edit_url = $quiz_id > 0 ? admin_url( 'post.php?post=' . $quiz_id . '&action=edit' ) : '';

		wp_send_json_success(
			array(
				'message'  => __( 'Quiz created successfully!', 'mcp-ai-wpoos-pro' ),
				'quiz_id'  => $quiz_id,
				'edit_url' => $edit_url,
			)
		);
	}
}

// Initialize.
WP_MCP_AI_Quiz_Research_Page::init();
