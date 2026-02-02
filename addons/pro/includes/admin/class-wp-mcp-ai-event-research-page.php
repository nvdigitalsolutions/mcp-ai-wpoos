<?php
/**
 * Research & Add admin page for Event CPT.
 *
 * Provides a dedicated page for researching events before adding them,
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
 * Event Research Admin Page
 *
 * Adds a submenu page under Events menu for AI-powered event research.
 */
class WP_MCP_AI_Event_Research_Page {
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
	const PAGE_SLUG = 'research-event';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_event_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_event', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add submenu page under Events menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_event',
			__( 'Research & Add Event', 'mcp-ai-wpoos-pro' ),
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
		if ( 'mcp_ai_event_page_' . self::PAGE_SLUG !== $hook ) {
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
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_event' ),
				'entityType' => 'event',
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_event_settings', array() );
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
				<?php esc_html_e( 'Research & Add Event', 'mcp-ai-wpoos-pro' ); ?>
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
							<li><?php esc_html_e( 'Search existing events or research ideas on the web', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Use calendar view to check for scheduling conflicts', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create events with proper timing and details', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Link events to projects and invite attendees', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search first:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'List existing events to avoid conflicts', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Check calendar:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Review calendar before scheduling', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Web research:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Find venue information and best practices', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Link to projects:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Associate events with relevant projects', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research planning a team kickoff meeting with agenda, attendees, and materials needed">
								<?php esc_html_e( '"Research planning a team kickoff meeting..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Find information about organizing a product launch event including venue, catering, and timeline">
								<?php esc_html_e( '"Find information about organizing a product launch..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research scheduling a quarterly review meeting with preparation requirements and key topics">
								<?php esc_html_e( '"Research scheduling a quarterly review..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_event' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Events', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_event' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Event Manually', 'mcp-ai-wpoos-pro' ); ?>
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
								<p><?php esc_html_e( 'Research and create events with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import event data', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View event quality and completeness', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive event tools.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="create_event,list_events,get_calendar_view,list_projects,web_search,search_content"]'
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
	 * Handle AJAX request to create event from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_event', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create events.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$research_data_raw = isset( $_POST['research_data'] ) ? wp_unslash( $_POST['research_data'] ) : '';

		if ( empty( $research_data_raw ) ) {
			wp_send_json_error( array( 'message' => __( 'No research data provided.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$research_data = json_decode( $research_data_raw, true );

		// Validate JSON decoding.
		if ( null === $research_data || JSON_ERROR_NONE !== json_last_error() ) {
			wp_send_json_error( array( 'message' => __( 'Invalid JSON data format.', 'mcp-ai-wpoos-pro' ) ) );
		}

		if ( empty( $research_data['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Event title is required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process featured image generation request.
		$research_data = self::process_featured_image_request( $research_data, $research_data['title'], 'an event' );

		// Use the create_event tool to create the event.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Event' ) ) {
			wp_send_json_error( array( 'message' => __( 'Create Event tool not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$tool   = new WP_MCP_AI_Tool_Create_Event();
		$result = $tool->execute(
			$research_data,
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Return success with event ID and edit URL.
		$event_id = isset( $result['event_id'] ) ? $result['event_id'] : 0;
		$edit_url = $event_id > 0 ? admin_url( 'post.php?post=' . $event_id . '&action=edit' ) : '';

		wp_send_json_success(
			array(
				'message'  => __( 'Event created successfully!', 'mcp-ai-wpoos-pro' ),
				'event_id' => $event_id,
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
			'ics'  => 'iCalendar (ICS)',
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
	protected static function process_import_data( $data, $format ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by trait interface.
		return new WP_Error( 'not_implemented', __( 'Event import processing coming soon', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Get validation schema (RFC 5545).
	 *
	 * @return array Validation schema.
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'uid'     => __( 'UID', 'mcp-ai-wpoos-pro' ),
				'summary' => __( 'Summary/Title', 'mcp-ai-wpoos-pro' ),
				'dtstart' => __( 'Start Date/Time', 'mcp-ai-wpoos-pro' ),
				'dtstamp' => __( 'Timestamp', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'dtend'       => __( 'End Date/Time', 'mcp-ai-wpoos-pro' ),
				'location'    => __( 'Location', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'organizer'   => __( 'Organizer', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'dtstart' => array( 'type' => 'datetime' ),
				'dtend'   => array( 'type' => 'datetime' ),
			),
			'quality_dimensions' => array(
				'rfc_5545_compliance',
				'completeness',
				'accuracy',
				'uniqueness',
			),
		);
	}

	/**
	 * Calculate completeness.
	 *
	 * @return array Completeness data.
	 */
	protected static function calculate_completeness() {
		$events = get_posts(
			array(
				'post_type'      => 'mcp_ai_event',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total    = count( $events );
		$complete = 0;
		$missing  = array();

		foreach ( $events as $event ) {
			$start_date = get_post_meta( $event->ID, 'start_date', true );
			$location   = get_post_meta( $event->ID, 'location', true );
			if ( ! empty( $start_date ) && ! empty( $location ) && ! empty( $event->post_content ) ) {
				++$complete;
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		if ( $complete < $total ) {
			$missing[] = sprintf(
				/* translators: %d: Number of incomplete events */
				__( '%d events missing required data', 'mcp-ai-wpoos-pro' ),
				$total - $complete
			);
		}

		return array(
			'percentage'  => $percentage,
			'missing'     => $missing,
			'suggestions' => array(
				__( 'Add locations to all events', 'mcp-ai-wpoos-pro' ),
				__( 'Include detailed event descriptions', 'mcp-ai-wpoos-pro' ),
				__( 'Ensure all events have start/end times', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Items.
	 */
	protected static function get_items_for_review() {
		$events = get_posts(
			array(
				'post_type'      => 'mcp_ai_event',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $events as $event ) {
			$items[] = array(
				'id'    => $event->ID,
				'title' => $event->post_title,
				'meta'  => array(
					'start_date' => get_post_meta( $event->ID, 'start_date', true ),
					'end_date'   => get_post_meta( $event->ID, 'end_date', true ),
					'location'   => get_post_meta( $event->ID, 'location', true ),
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

		// Check start date (30 points).
		if ( ! empty( $item['meta']['start_date'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing start date', 'mcp-ai-wpoos-pro' );
		}

		// Check end date (20 points).
		if ( ! empty( $item['meta']['end_date'] ) ) {
			$score += 20;
		} else {
			$issues[] = __( 'Missing end date', 'mcp-ai-wpoos-pro' );
		}

		// Check location (30 points).
		if ( ! empty( $item['meta']['location'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing location', 'mcp-ai-wpoos-pro' );
		}

		// Check title (20 points).
		if ( ! empty( $item['title'] ) && strlen( $item['title'] ) > 10 ) {
			$score += 20;
		} else {
			$issues[] = __( 'Title needs improvement', 'mcp-ai-wpoos-pro' );
		}

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
			<h2><?php esc_html_e( 'Import Event Data', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import events from iCalendar (ICS), CSV, JSON, or paste structured data. The AI will automatically parse and organize the event information.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			
			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include event title, start/end dates and times', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Specify location details and organizer information', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Add event descriptions and attendee lists', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Include project associations if applicable', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_import_events', 'import_nonce' ); ?>
					
					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-import-file-input" name="import_file" accept=".ics,.csv,.json,.txt" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Choose File', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Supported: ICS (iCalendar), CSV, JSON, TXT', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<p><strong><?php esc_html_e( 'OR', 'mcp-ai-wpoos-pro' ); ?></strong></p>

					<textarea 
						id="wp-mcp-ai-import-text" 
						name="import_data" 
						class="widefat" 
						rows="12" 
						placeholder="<?php esc_attr_e( 'Example:\n\nEvent: Team Kickoff Meeting\nStart: 2024-01-15 09:00 AM\nEnd: 2024-01-15 10:30 AM\nLocation: Conference Room A\nDescription: Quarterly kickoff meeting for the development team\nAttendees: john@example.com, jane@example.com\n\nEvent: Product Launch\nStart: 2024-02-01 02:00 PM\nEnd: 2024-02-01 05:00 PM\nLocation: Main Auditorium\nDescription: Official launch event for new product line', 'mcp-ai-wpoos-pro' ); ?>"
					></textarea>
					
					<div class="import-options">
						<label>
							<input type="checkbox" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create events (recommended)', 'mcp-ai-wpoos-pro' ); ?>
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
		// Get event statistics.
		$total_events    = wp_count_posts( 'mcp_ai_event' );
		$published_count = isset( $total_events->publish ) ? $total_events->publish : 0;

		// Calculate data quality metrics.
		$events = get_posts(
			array(
				'post_type'      => 'mcp_ai_event',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count = 0;
		$with_dates     = 0;
		$with_location  = 0;

		foreach ( $events as $event ) {
			$start_date = get_post_meta( $event->ID, 'start_date', true );
			$end_date   = get_post_meta( $event->ID, 'end_date', true );
			$location   = get_post_meta( $event->ID, 'location', true );
			$has_desc   = ! empty( $event->post_content );

			if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
				++$with_dates;
			}
			if ( ! empty( $location ) ) {
				++$with_location;
			}
			if ( ! empty( $start_date ) && ! empty( $location ) && $has_desc ) {
				++$complete_count;
			}
		}

		$completeness = $published_count > 0 ? round( ( $complete_count / $published_count ) * 100 ) : 0;

		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Event Quality Dashboard', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="quality-dashboard">
				<h3><?php esc_html_e( 'Overall Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Events', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_dates ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Start/End Dates', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_location ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Location', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Event completeness is %d%%. Ensure all events have start/end dates, locations, and descriptions.', 'mcp-ai-wpoos-pro' ),
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
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_event' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Events', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_event' ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Event', 'mcp-ai-wpoos-pro' ); ?>
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
WP_MCP_AI_Event_Research_Page::init();
