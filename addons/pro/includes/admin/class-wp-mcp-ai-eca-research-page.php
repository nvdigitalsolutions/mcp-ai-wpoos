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

/**
 * ECA Research Admin Page
 *
 * Adds a submenu page under ECAs menu for AI-powered activity research.
 */
class WP_MCP_AI_ECA_Research_Page {

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
			array( 'jquery', 'wp-api' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'wp-mcp-ai-research-page',
			'wpMcpAiResearchPage',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'wp_mcp_ai_research_eca' ),
				'addNewUrl'     => admin_url( 'post-new.php?post_type=mcp_ai_eca' ),
				'researchTool'  => 'research_eca',
				'strings'       => array(
					'researching'       => __( 'Researching...', 'mcp-ai-wpoos-pro' ),
					'error'             => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'creating'          => __( 'Creating ECA...', 'mcp-ai-wpoos-pro' ),
					'created'           => __( 'ECA created successfully!', 'mcp-ai-wpoos-pro' ),
					'confirmCreate'     => __( 'Create an ECA with the researched information?', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get the first available assistant for chat.
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
							<li><?php esc_html_e( 'Use the AI assistant to research an extra-curricular activity or program', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Ask questions like "Research a coding club curriculum for middle school"', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Review the research results including schedule, materials, and objectives', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Click "Create ECA from Research" to add it to your database', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Be specific:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Include age group and activity focus', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Ask for details:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Request schedule, materials, and learning objectives', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Consider logistics:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Ask about space, equipment, and instructor requirements', 'mcp-ai-wpoos-pro' ); ?></li>
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
							// Render chat interface using shortcode.
							echo do_shortcode( '[mcp_ai_chat assistant="' . absint( $assistant_id ) . '"]' );
							?>
						</div>

						<div class="wp-mcp-ai-research-create-section" style="display: none;">
							<div class="wp-mcp-ai-research-data-preview">
								<h3><?php esc_html_e( 'Researched Activity Data', 'mcp-ai-wpoos-pro' ); ?></h3>
								<div id="wp-mcp-ai-research-data-content"></div>
							</div>
							<div class="wp-mcp-ai-research-create-actions">
								<button type="button" class="button button-primary button-hero wp-mcp-ai-create-eca-btn">
									<span class="dashicons dashicons-plus-alt" style="margin-top: 8px;"></span>
									<?php esc_html_e( 'Create ECA from Research', 'mcp-ai-wpoos-pro' ); ?>
								</button>
								<p class="description">
									<?php esc_html_e( 'This will create a new ECA using the information from your research conversation.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							</div>
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
}

// Initialize.
WP_MCP_AI_ECA_Research_Page::init();
