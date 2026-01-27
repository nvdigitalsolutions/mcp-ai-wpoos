<?php
/**
 * Research & Add admin page for ECA CPT.
 *
 * Provides a dedicated page for researching extra-curricular activities before adding them,
 * with full chat interface for AI assistance.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';

/**
 * ECA Research Admin Page
 *
 * Adds a submenu page under ECAs menu for AI-powered activity research.
 */
class WP_MCP_AI_ECA_Research_Page {
	use WP_MCP_AI_Research_Page_Featured_Image;

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'research-eca';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_eca_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_eca', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * Add submenu page under ECAs menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_eca',
			__( 'Research & Add ECA', 'mcp-ai-wpoos-pro' ),
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
		if ( 'mcp_ai_eca_page_' . self::PAGE_SLUG !== $hook ) {
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
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'wp_mcp_ai_research_eca' ),
				'addNewUrl'    => admin_url( 'post-new.php?post_type=mcp_ai_eca' ),
				'researchTool' => 'research_eca',
				'strings'      => array(
					'researching'   => __( 'Researching...', 'mcp-ai-wpoos-pro' ),
					'error'         => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'creating'      => __( 'Creating ECA...', 'mcp-ai-wpoos-pro' ),
					'created'       => __( 'ECA created successfully!', 'mcp-ai-wpoos-pro' ),
					'confirmCreate' => __( 'Create an ECA with the researched information?', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_eca_settings', array() );
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
				<?php esc_html_e( 'Research & Add ECA', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<div class="wp-mcp-ai-research-container">
				<div class="wp-mcp-ai-research-sidebar">
					<div class="wp-mcp-ai-research-intro">
						<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos-pro' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Search existing ECAs or research new activities on the web', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Use calendar view to check schedules and conflicts', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create ECAs with schedules and enrollment options', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Enroll students directly from the chat interface', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search first:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'List existing ECAs to avoid duplicates', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Check calendar:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'View schedule conflicts before creating', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Web research:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Find curriculum ideas and best practices', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Enroll students:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Add students directly after creating ECA', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research a robotics club for high school students with curriculum and schedule">
								<?php esc_html_e( '"Research a robotics club for high school..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Find information about starting a debate team including format, topics, and practice schedule">
								<?php esc_html_e( '"Find information about starting a debate team..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research an art club program with projects, materials list, and session plans">
								<?php esc_html_e( '"Research an art club program..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_eca' ) ); ?>" class="button">
								<?php esc_html_e( 'View All ECAs', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_eca' ) ); ?>" class="button">
								<?php esc_html_e( 'Add ECA Manually', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
					</div>
				</div>

				<div class="wp-mcp-ai-research-main">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive ECA tools.
							// Includes research, creation, enrollment, calendar, and management tools.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="research_eca,create_eca,list_ecas,get_eca,enroll_student_eca,get_calendar_view,web_search,search_content"]'
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
	 * Handle AJAX request to create ECA from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_eca', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create ECAs.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$research_data = isset( $_POST['research_data'] ) ? json_decode( wp_unslash( $_POST['research_data'] ), true ) : array();

		if ( empty( $research_data ) || empty( $research_data['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid research data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process featured image generation request.
		$research_data = self::process_featured_image_request( $research_data, $research_data['title'], 'an extra-curricular activity' );

		// Use the create_eca tool to create the ECA.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_ECA' ) ) {
			wp_send_json_error( array( 'message' => __( 'Create ECA tool not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$tool   = new WP_MCP_AI_Tool_Create_ECA();
		$result = $tool->execute(
			$research_data,
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Return success with ECA ID and edit URL.
		$eca_id   = isset( $result['eca_id'] ) ? $result['eca_id'] : 0;
		$edit_url = $eca_id > 0 ? admin_url( 'post.php?post=' . $eca_id . '&action=edit' ) : '';

		wp_send_json_success(
			array(
				'message'  => __( 'ECA created successfully!', 'mcp-ai-wpoos-pro' ),
				'eca_id'   => $eca_id,
				'edit_url' => $edit_url,
			)
		);
	}

	/**
	 * Get supported import formats.
	 *
	 * @return array Array of format slug => label pairs.
	 */
	protected static function get_import_formats() {
		return array(
			'pdf'  => 'PDF',
			'docx' => 'DOCX',
			'csv'  => 'CSV',
			'json' => 'JSON',
		);
	}

	/**
	 * Process import data.
	 *
	 * @param mixed  $data   Data to import.
	 * @param string $format File format.
	 * @return array|WP_Error Array of processed items or WP_Error on failure.
	 */
	protected static function process_import_data( $data, $format ) {
		return new WP_Error( 'not_implemented', __( 'ECA import processing coming soon', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Get validation schema for ECA data.
	 *
	 * @return array Validation schema configuration.
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'title'   => __( 'ECA Title', 'mcp-ai-wpoos-pro' ),
				'content' => __( 'ECA Content', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'category'    => __( 'Category', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'tags'        => __( 'Tags', 'mcp-ai-wpoos-pro' ),
				'date'        => __( 'Date', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'date' => array( 'type' => 'datetime' ),
			),
			'quality_dimensions' => array(
				'completeness',
				'accuracy',
				'accessibility',
				'organization',
			),
		);
	}

	/**
	 * Calculate completeness score for ECAs.
	 *
	 * @return array Completeness data with percentage and suggestions.
	 */
	protected static function calculate_completeness() {
		$ecas = get_posts(
			array(
				'post_type'      => 'mcp_ai_eca',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total    = count( $ecas );
		$complete = 0;

		foreach ( $ecas as $eca ) {
			$category = get_post_meta( $eca->ID, 'category', true );
			if ( ! empty( $category ) && ! empty( $eca->post_content ) ) {
				++$complete;
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		return array(
			'percentage'  => $percentage,
			'missing'     => array(),
			'suggestions' => array(
				__( 'Categorize all ECA items', 'mcp-ai-wpoos-pro' ),
				__( 'Add detailed descriptions', 'mcp-ai-wpoos-pro' ),
				__( 'Include relevant tags', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Array of ECA items for review.
	 */
	protected static function get_items_for_review() {
		$ecas = get_posts(
			array(
				'post_type'      => 'mcp_ai_eca',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $ecas as $eca ) {
			$items[] = array(
				'id'    => $eca->ID,
				'title' => $eca->post_title,
				'meta'  => array(
					'category' => get_post_meta( $eca->ID, 'category', true ),
					'date'     => get_post_meta( $eca->ID, 'date', true ),
				),
			);
		}

		return $items;
	}

	/**
	 * Calculate quality score for an item.
	 *
	 * @param array $item Item data to score.
	 * @return array Quality score data with score, level, status, and issues.
	 */
	protected static function calculate_quality_score( $item ) {
		$score  = 0;
		$issues = array();

		if ( ! empty( $item['meta']['category'] ) ) {
			$score += 40;
		} else {
			$issues[] = __( 'Missing category', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['date'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing date', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['title'] ) && strlen( $item['title'] ) > 10 ) {
			$score += 30;
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
	 * Handle import AJAX request.
	 */
	public static function handle_import() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_eca', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import ECAs.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get import data from request.
		$format = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized in process_import_data.
		$data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';

		if ( empty( $format ) || empty( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid import data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process import.
		$result = self::process_import_data( $data, $format );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}
}

// Initialize.
WP_MCP_AI_ECA_Research_Page::init();
