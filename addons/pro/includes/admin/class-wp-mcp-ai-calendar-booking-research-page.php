<?php
/**
 * Research & Add admin page for Calendar Booking Toolkit.
 *
 * Provides a dedicated page for researching appointments and availability
 * before creating bookings, with full chat interface for AI assistance.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * Calendar Booking Research Admin Page
 *
 * Adds a submenu page under Appointments menu for AI-powered booking research.
 */
class WP_MCP_AI_Calendar_Booking_Research_Page {
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
	const PAGE_SLUG = 'research-appointment';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_appointment_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_appointment', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add submenu page under Appointments menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_appointment',
			__( 'Research & Add Appointment', 'mcp-ai-wpoos-pro' ),
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
		if ( 'mcp_appointment_page_' . self::PAGE_SLUG !== $hook ) {
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
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_appointment' ),
				'entityType' => 'appointment',
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_calendar_booking_toolkit_settings', array() );
		$assistant_id = isset( $settings['research_assistant_id'] ) ? absint( $settings['research_assistant_id'] ) : 0;

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
				<?php esc_html_e( 'Research & Add Appointment', 'mcp-ai-wpoos-pro' ); ?>
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
							<li><?php esc_html_e( 'Search for available time slots and resources', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Use AI to research appointment types and scheduling', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Review and refine the generated content', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create the appointment directly or save for later', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Check availability:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Find open time slots before booking', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Be specific:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Specify date, time, and duration needed', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Set details:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Include client information and appointment type', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Review booking:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Verify details before creating appointment', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="List all appointments for today">
								<?php esc_html_e( '"List today\'s appointments"', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Check availability for next Monday afternoon">
								<?php esc_html_e( '"Check Monday availability..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create a 1-hour appointment for client meeting">
								<?php esc_html_e( '"Create client meeting..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Find open slots this week">
								<?php esc_html_e( '"Find open slots this week..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-preview" id="wp-mcp-ai-appointment-preview" style="display: none;">
						<h3><?php esc_html_e( 'Appointment Preview', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="wp-mcp-ai-preview-content">
							<div class="wp-mcp-ai-preview-loading">
								<span class="spinner is-active"></span>
								<p><?php esc_html_e( 'Building appointment...', 'mcp-ai-wpoos-pro' ); ?></p>
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
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_appointment' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Appointments', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_appointment' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Appointment Manually', 'mcp-ai-wpoos-pro' ); ?>
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
								<p><?php esc_html_e( 'Research and create appointments with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Import appointments from CSV or JSON files', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- Research Mode (AI Chat) -->
					<div class="workflow-content" id="research-mode">
						<?php
						if ( ! $assistant_id ) {
							?>
							<div class="notice notice-warning">
								<p>
									<?php
									echo wp_kses_post(
										sprintf(
											/* translators: %s: Link to settings page */
											__( 'No assistant configured. <a href="%s">Configure an assistant</a> to use AI research.', 'mcp-ai-wpoos-pro' ),
											admin_url( 'admin.php?page=wp-mcp-ai-calendar-booking-toolkit-settings&tab=configuration' )
										)
									);
									?>
								</p>
							</div>
							<?php
						} else {
							echo do_shortcode( '[wp_mcp_ai_chat assistant="' . esc_attr( $assistant_id ) . '"]' );
						}
						?>
					</div>

					<!-- Import Mode -->
					<div class="workflow-content" id="import-mode" style="display: none;">
						<?php self::render_import_interface(); ?>
					</div>
				</div>
			</div>
		<?php
	}

	/**
	 * Render import interface.
	 */
	protected static function render_import_interface() {
		?>
		<div class="wp-mcp-ai-import-interface">
			<h2><?php esc_html_e( 'Import Appointments', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Upload a CSV or JSON file containing appointment data to import multiple appointments at once.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<div class="wp-mcp-ai-import-form">
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_import_appointment', 'wp_mcp_ai_import_nonce' ); ?>
					
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="import-file"><?php esc_html_e( 'Import File', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<input type="file" id="import-file" name="import_file" accept=".csv,.json" required />
								<p class="description">
									<?php esc_html_e( 'Supported formats: CSV, JSON', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Import Appointments', 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</p>
				</form>

				<div id="import-results" style="display: none;">
					<h3><?php esc_html_e( 'Import Results', 'mcp-ai-wpoos-pro' ); ?></h3>
					<div id="import-results-content"></div>
				</div>
			</div>

			<div class="wp-mcp-ai-import-help">
				<h3><?php esc_html_e( 'Import Format', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p><?php esc_html_e( 'Your import file should contain the following fields:', 'mcp-ai-wpoos-pro' ); ?></p>
				<ul>
					<li><code>title</code> - <?php esc_html_e( 'Appointment title/description', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><code>start_date</code> - <?php esc_html_e( 'Start date and time (Y-m-d H:i:s)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><code>end_date</code> - <?php esc_html_e( 'End date and time (Y-m-d H:i:s)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><code>client_name</code> - <?php esc_html_e( 'Client name (optional)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><code>client_email</code> - <?php esc_html_e( 'Client email (optional)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><code>status</code> - <?php esc_html_e( 'Appointment status (pending, confirmed, cancelled)', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle create from research AJAX request.
	 */
	public static function handle_create_from_research() {
		check_ajax_referer( 'wp_mcp_ai_research_appointment', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get appointment data from request.
		$title      = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';

		if ( empty( $title ) || empty( $start_date ) ) {
			wp_send_json_error( array( 'message' => __( 'Title and start date are required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Create appointment post.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_appointment',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// Save appointment meta.
		update_post_meta( $post_id, '_appointment_start_date', $start_date );
		update_post_meta( $post_id, '_appointment_end_date', $end_date );

		wp_send_json_success(
			array(
				'message'  => __( 'Appointment created successfully.', 'mcp-ai-wpoos-pro' ),
				'post_id'  => $post_id,
				'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			)
		);
	}
}

// Initialize.
WP_MCP_AI_Calendar_Booking_Research_Page::init();
