<?php
/**
 * Research & Add admin page for Comics.
 *
 * Provides a dedicated page for researching and creating comics
 * with full chat interface for AI assistance.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * Comic Research Admin Page
 *
 * Adds a submenu page under Comics menu for AI-powered comic research.
 */
class WP_MCP_AI_Comic_Research_Page {
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
	const PAGE_SLUG = 'research-comic';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_comic_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_comic', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add submenu page under Comics menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_comic',
			__( 'Research & Add Comic', 'mcp-ai-wpoos-pro' ),
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
		if ( 'mcp_ai_comic_page_' . self::PAGE_SLUG !== $hook ) {
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
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_comic' ),
				'entityType' => 'comic',
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_comic_creation_settings', array() );
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
				<?php esc_html_e( 'Research & Add Comic', 'mcp-ai-wpoos-pro' ); ?>
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
							<li><?php esc_html_e( 'Research comic ideas, genres, and art styles', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Generate scripts and panel breakdowns', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create character sheets and design pages', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Build comic pages directly in your library', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Comic Creation Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Styles:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Manga, Western, Noir, Webtoon', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Formats:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Single page, strip, full issue', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Direction:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'LTR (Western) or RTL (Manga)', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Tools:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Script, panel, ink, color, letter', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create a superhero comic script with 4 panels">
								<?php esc_html_e( '"Create superhero comic script..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Generate a manga-style character sheet for a warrior">
								<?php esc_html_e( '"Generate manga character sheet..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Break down a comic page into 6 panels with speech bubbles">
								<?php esc_html_e( '"Break down comic page..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create a noir-style detective comic strip">
								<?php esc_html_e( '"Create noir detective strip..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-preview" id="wp-mcp-ai-comic-preview" style="display: none;">
						<h3><?php esc_html_e( 'Comic Preview', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="wp-mcp-ai-preview-content">
							<div class="wp-mcp-ai-preview-loading">
								<span class="spinner is-active"></span>
								<p><?php esc_html_e( 'Building comic preview...', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<div class="wp-mcp-ai-preview-data" style="display: none;">
								<div class="wp-mcp-ai-preview-header">
									<h4 class="wp-mcp-ai-preview-title"></h4>
									<p class="wp-mcp-ai-preview-meta"></p>
								</div>
								<div class="wp-mcp-ai-preview-details"></div>
							</div>
						</div>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_comic' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Comics', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_comic' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Comic Manually', 'mcp-ai-wpoos-pro' ); ?>
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
								<p><?php esc_html_e( 'Research and create comics with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import comic scripts and data', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View comic quality and completeness', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive comic creation tools.
							$comic_tools = array(
								// Script & writing tools.
								'generate_comic_script',
								'breakdown_comic_panels',
								'generate_character_sheet',
								// Panel & art generation.
								'generate_comic_panel',
								'create_comic_layout',
								'add_speech_bubbles',
								// Export & finishing.
								'export_comic_cbz',
								'colorize_comic_panel',
								'ink_comic_panel',
								'letter_comic_panel',
								// Enhancement tools.
								'upscale_comic_page',
								'apply_comic_style',
								// Image generation.
								'generate_image_ai',
								// Research → Paper Store pipeline.
								'generate_research_report',
								'create_post_from_research',
								// Research tools.
								'web_search',
								'search_content',
							);
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="' . esc_attr( implode( ',', $comic_tools ) ) . '"]'
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
	 * Handle AJAX request to create comic from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_comic', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create comics.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$research_data = isset( $_POST['research_data'] ) ? json_decode( wp_unslash( $_POST['research_data'] ), true ) : array();

		if ( empty( $research_data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid research data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Create comic post.
		$post_data = array(
			'post_type'    => 'mcp_ai_comic',
			'post_title'   => isset( $research_data['title'] ) ? sanitize_text_field( $research_data['title'] ) : __( 'New Comic', 'mcp-ai-wpoos-pro' ),
			'post_content' => isset( $research_data['content'] ) ? wp_kses_post( $research_data['content'] ) : '',
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
		);

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// Save comic metadata.
		if ( isset( $research_data['comic_style'] ) ) {
			update_post_meta( $post_id, '_comic_style', sanitize_text_field( $research_data['comic_style'] ) );
		}
		if ( isset( $research_data['reading_direction'] ) ) {
			update_post_meta( $post_id, '_reading_direction', sanitize_text_field( $research_data['reading_direction'] ) );
		}
		if ( isset( $research_data['page_layout'] ) ) {
			update_post_meta( $post_id, '_page_layout', sanitize_text_field( $research_data['page_layout'] ) );
		}
		if ( isset( $research_data['series_name'] ) ) {
			update_post_meta( $post_id, '_series_name', sanitize_text_field( $research_data['series_name'] ) );
		}
		if ( isset( $research_data['issue_number'] ) ) {
			update_post_meta( $post_id, '_issue_number', sanitize_text_field( $research_data['issue_number'] ) );
		}

		// Return success with comic ID and edit URL.
		$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

		wp_send_json_success(
			array(
				'message'  => __( 'Comic created successfully!', 'mcp-ai-wpoos-pro' ),
				'comic_id' => $post_id,
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
			'json' => 'JSON',
			'csv'  => 'CSV',
		);
	}

	/**
	 * Process imported data.
	 *
	 * @param string $data   Import data.
	 * @param string $format Data format.
	 * @return array|WP_Error Result or error.
	 */
	protected static function process_import_data( $data, $format ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by trait interface.
		return new WP_Error( 'not_implemented', __( 'Comic import processing coming soon', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Get validation schema.
	 *
	 * @return array Validation schema.
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'title'       => __( 'Comic Title', 'mcp-ai-wpoos-pro' ),
				'comic_style' => __( 'Comic Style', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'content'           => __( 'Comic Script / Content', 'mcp-ai-wpoos-pro' ),
				'series_name'       => __( 'Series Name', 'mcp-ai-wpoos-pro' ),
				'reading_direction' => __( 'Reading Direction', 'mcp-ai-wpoos-pro' ),
				'page_layout'       => __( 'Page Layout', 'mcp-ai-wpoos-pro' ),
				'issue_number'      => __( 'Issue Number', 'mcp-ai-wpoos-pro' ),
			),
			'quality_dimensions' => array(
				'completeness',
				'consistency',
				'art_quality',
			),
		);
	}

	/**
	 * Calculate completeness.
	 *
	 * @return array Completeness data.
	 */
	protected static function calculate_completeness() {
		$comics = get_posts(
			array(
				'post_type'      => 'mcp_ai_comic',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total    = count( $comics );
		$complete = 0;

		foreach ( $comics as $comic ) {
			$style     = get_post_meta( $comic->ID, '_comic_style', true );
			$series    = get_post_meta( $comic->ID, '_series_name', true );
			$has_title = ! empty( $comic->post_title );

			if ( $style && $has_title && $series ) {
				++$complete;
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		return array(
			'percentage'  => $percentage,
			'missing'     => array(),
			'suggestions' => array(
				__( 'Ensure all comics have a style specified', 'mcp-ai-wpoos-pro' ),
				__( 'Add comic scripts and panel breakdowns', 'mcp-ai-wpoos-pro' ),
				__( 'Set reading direction and page layout', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Items.
	 */
	protected static function get_items_for_review() {
		$comics = get_posts(
			array(
				'post_type'      => 'mcp_ai_comic',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $comics as $comic ) {
			$items[] = array(
				'id'    => $comic->ID,
				'title' => $comic->post_title,
				'meta'  => array(
					'comic_style'       => get_post_meta( $comic->ID, '_comic_style', true ),
					'reading_direction' => get_post_meta( $comic->ID, '_reading_direction', true ),
					'series_name'       => get_post_meta( $comic->ID, '_series_name', true ),
					'issue_number'      => get_post_meta( $comic->ID, '_issue_number', true ),
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

		// Check comic style (weight: 25).
		if ( ! empty( $item['meta']['comic_style'] ) ) {
			$score += 25;
		} else {
			$issues[] = __( 'Missing comic style', 'mcp-ai-wpoos-pro' );
		}

		// Check reading direction (weight: 25).
		if ( ! empty( $item['meta']['reading_direction'] ) ) {
			$score += 25;
		} else {
			$issues[] = __( 'Missing reading direction', 'mcp-ai-wpoos-pro' );
		}

		// Check series name (weight: 25).
		if ( ! empty( $item['meta']['series_name'] ) ) {
			$score += 25;
		} else {
			$issues[] = __( 'Missing series name', 'mcp-ai-wpoos-pro' );
		}

		// Check title quality (weight: 25).
		if ( ! empty( $item['title'] ) && strlen( $item['title'] ) > 5 ) {
			$score += 25;
		} else {
			$issues[] = __( 'Title needs improvement', 'mcp-ai-wpoos-pro' );
		}

		$level = $score >= 80 ? 'high' : ( $score >= 50 ? 'medium' : 'low' );

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
			<h2><?php esc_html_e( 'Import Comic Data', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import comics from JSON, CSV, or paste structured data. The AI will automatically parse and organize the comic information including scripts, panels, and character sheets.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			
			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include comic title, style, and series name', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Specify reading direction and page layout', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Add panel breakdowns and scripts', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Include character names and descriptions', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_import_comics', 'import_nonce' ); ?>
					
					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-import-file-input" name="import_file" accept=".json,.csv,.txt" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Choose File', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Supported: JSON, CSV, TXT', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<p><strong><?php esc_html_e( 'OR', 'mcp-ai-wpoos-pro' ); ?></strong></p>

					<textarea 
						id="wp-mcp-ai-import-text" 
						name="import_data" 
						class="widefat" 
						rows="12" 
						placeholder="<?php esc_attr_e( 'Example:\n\nTitle: The Cosmic Adventures\nStyle: Manga\nReading Direction: RTL\nSeries: Cosmic Saga\nIssue: 1\nPanel 1: Hero stands on rooftop\nPanel 2: Villain appears in sky\n\nTitle: Noir Detective\nStyle: Western Noir\nReading Direction: LTR\nSeries: City Shadows', 'mcp-ai-wpoos-pro' ); ?>"
					></textarea>
					
					<div class="import-options">
						<label for="auto-create-comics">
							<input type="checkbox" id="auto-create-comics" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create comics (recommended)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<label for="validate-comic-data">
							<input type="checkbox" id="validate-comic-data" name="validate_data" value="1" checked>
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
		// Get comic statistics.
		$total_comics    = wp_count_posts( 'mcp_ai_comic' );
		$published_count = isset( $total_comics->publish ) ? $total_comics->publish : 0;

		// Calculate data quality metrics.
		$comics = get_posts(
			array(
				'post_type'      => 'mcp_ai_comic',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count = 0;
		$with_style     = 0;
		$with_series    = 0;

		foreach ( $comics as $comic ) {
			$style     = get_post_meta( $comic->ID, '_comic_style', true );
			$series    = get_post_meta( $comic->ID, '_series_name', true );
			$has_title = ! empty( $comic->post_title );

			if ( $style ) {
				++$with_style;
			}
			if ( $series ) {
				++$with_series;
			}
			if ( $style && $series && $has_title ) {
				++$complete_count;
			}
		}

		$completeness = $published_count > 0 ? round( ( $complete_count / $published_count ) * 100 ) : 0;

		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Comic Quality Dashboard', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="quality-dashboard">
				<h3><?php esc_html_e( 'Overall Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Comics', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_style ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Style', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_series ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Series', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Comic completeness is %d%%. Ensure all comics have a style, series name, and script for best results.', 'mcp-ai-wpoos-pro' ),
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
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_comic' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Comics', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_comic' ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Comic', 'mcp-ai-wpoos-pro' ); ?>
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
