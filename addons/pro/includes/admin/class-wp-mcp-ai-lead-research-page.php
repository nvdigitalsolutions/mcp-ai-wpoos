<?php
/**
 * Research & Add admin page for Lead CPT.
 *
 * Provides a dedicated page for researching and qualifying leads before adding them,
 * with AI-powered chat interface, lead scoring, and qualification tools.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 1.1.24
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
 * Lead Research Admin Page
 *
 * Adds a submenu page under NV CRM menu for AI-powered lead research and qualification.
 */
class WP_MCP_AI_Lead_Research_Page {
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
	const PAGE_SLUG = 'crm-lead-research';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 26 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_lead_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_lead', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add submenu page under NV CRM menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			WP_MCP_AI_CRM_Admin_Menu::PARENT_SLUG,
			__( 'Research & Add Lead', 'mcp-ai-wpoos-pro' ),
			__( 'Lead Research & Add', 'mcp-ai-wpoos-pro' ),
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
		if ( WP_MCP_AI_CRM_Admin_Menu::PARENT_SLUG . '_page_' . self::PAGE_SLUG !== $hook ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page slug check for asset enqueue.
			if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
				return;
			}
		}

		// Enqueue chat assets.
		if ( function_exists( 'wp_mcp_ai_enqueue_chat_assets' ) ) {
			wp_mcp_ai_enqueue_chat_assets();
		}
	}

	/**
	 * Render the research & add page.
	 */
	public static function render_page() {
		$current_mode = self::get_current_mode();
		?>
		<div class="wrap wp-mcp-ai-research-page">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Research & Add Lead', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<?php self::render_mode_tabs( $current_mode ); ?>

			<?php if ( 'import' === $current_mode ) : ?>
				<?php self::render_import_section(); ?>
			<?php elseif ( 'consolidate' === $current_mode ) : ?>
				<?php self::render_consolidation_dashboard(); ?>
			<?php else : ?>
				<div class="wp-mcp-ai-research-container">
					<div class="wp-mcp-ai-research-chat">
						<h2><?php esc_html_e( 'AI Lead Research Assistant', 'mcp-ai-wpoos-pro' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Use the AI assistant to research leads, qualify prospects (BANT/MEDDIC/CHAMP), identify buying signals, and draft outreach messages.', 'mcp-ai-wpoos-pro' ); ?>
						</p>

						<ul class="wp-mcp-ai-feature-list" style="margin-bottom: 15px;">
							<li><strong><?php esc_html_e( 'Lead Scoring:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Score leads based on fit, intent, and engagement', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Qualification:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'BANT, MEDDIC & CHAMP framework analysis', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Web Research:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Find and research potential leads online', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Outreach Drafting:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Draft personalized outreach messages', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>

						<div class="wp-mcp-ai-research-chat-container">
							<?php
							$crm_settings       = class_exists( 'WP_MCP_AI_CRM_Engine' ) ? WP_MCP_AI_CRM_Engine::get_toolkit_settings() : array();
							$assigned_assistant = isset( $crm_settings['research_assistant'] ) ? $crm_settings['research_assistant'] : 'default';
							if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
								$shortcode_instance = new WP_MCP_AI_Shortcode();
								$shortcode          = sprintf(
									'[nvoos_chat assistant="%s" placeholder="%s"]',
									esc_attr( $assigned_assistant ),
									esc_attr__( 'Ask me to research and qualify leads, or help identify prospects in target industries...', 'mcp-ai-wpoos-pro' )
								);
								echo do_shortcode( $shortcode );
							} else {
								echo '<p>' . esc_html__( 'Chat interface not available. Please ensure the plugin is properly installed.', 'mcp-ai-wpoos-pro' ) . '</p>';
							}
							?>
						</div>
					</div>

					<div class="wp-mcp-ai-research-form">
						<h2><?php esc_html_e( 'Lead Details', 'mcp-ai-wpoos-pro' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Fill in lead information below, or ask the AI assistant to help you research and populate these fields.', 'mcp-ai-wpoos-pro' ); ?>
						</p>

						<form id="wp-mcp-ai-lead-research-form" class="wp-mcp-ai-research-form-fields">
							<div class="form-field required">
								<label for="lead_name"><?php esc_html_e( 'Lead Name', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="text" id="lead_name" name="lead_name" required>
							</div>

							<div class="form-row">
								<div class="form-field required">
									<label for="email"><?php esc_html_e( 'Email Address', 'mcp-ai-wpoos-pro' ); ?></label>
									<input type="email" id="email" name="email" required>
								</div>

								<div class="form-field">
									<label for="phone"><?php esc_html_e( 'Phone Number', 'mcp-ai-wpoos-pro' ); ?></label>
									<input type="tel" id="phone" name="phone">
								</div>
							</div>

							<div class="form-field">
								<label for="company"><?php esc_html_e( 'Company', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="text" id="company" name="company">
							</div>

							<div class="form-row">
								<div class="form-field">
									<label for="source"><?php esc_html_e( 'Lead Source', 'mcp-ai-wpoos-pro' ); ?></label>
									<select id="source" name="source">
										<option value=""><?php esc_html_e( 'Select source...', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="website"><?php esc_html_e( 'Website', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="referral"><?php esc_html_e( 'Referral', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="social_media"><?php esc_html_e( 'Social Media', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="email_campaign"><?php esc_html_e( 'Email Campaign', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="paid_ad"><?php esc_html_e( 'Paid Ad', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="event"><?php esc_html_e( 'Event / Trade Show', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="other"><?php esc_html_e( 'Other', 'mcp-ai-wpoos-pro' ); ?></option>
									</select>
								</div>

								<div class="form-field">
									<label for="score"><?php esc_html_e( 'Lead Score (0-100)', 'mcp-ai-wpoos-pro' ); ?></label>
									<input type="number" id="score" name="score" min="0" max="100" placeholder="50">
								</div>
							</div>

							<div class="form-row">
								<div class="form-field">
									<label for="status"><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></label>
									<select id="status" name="status">
										<option value="new" selected><?php esc_html_e( 'New', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="contacted"><?php esc_html_e( 'Contacted', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="qualified"><?php esc_html_e( 'Qualified', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="nurturing"><?php esc_html_e( 'Nurturing', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="converted"><?php esc_html_e( 'Converted', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="lost"><?php esc_html_e( 'Lost', 'mcp-ai-wpoos-pro' ); ?></option>
									</select>
								</div>

								<div class="form-field">
									<label for="value"><?php esc_html_e( 'Estimated Value', 'mcp-ai-wpoos-pro' ); ?></label>
									<input type="number" id="value" name="value" placeholder="0">
								</div>
							</div>

							<div class="form-field">
								<label for="assigned_to"><?php esc_html_e( 'Assigned To (User ID)', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="text" id="assigned_to" name="assigned_to" placeholder="<?php esc_attr_e( 'e.g. user ID or name', 'mcp-ai-wpoos-pro' ); ?>">
							</div>

							<div class="form-field">
								<label for="next_action"><?php esc_html_e( 'Next Action', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="text" id="next_action" name="next_action" placeholder="<?php esc_attr_e( 'e.g. Send follow-up email, Schedule demo call...', 'mcp-ai-wpoos-pro' ); ?>">
							</div>

							<div class="form-field">
								<label for="notes"><?php esc_html_e( 'Notes', 'mcp-ai-wpoos-pro' ); ?></label>
								<textarea id="notes" name="notes" rows="4" placeholder="<?php esc_attr_e( 'Add any research findings, qualification notes, or key insights about this lead...', 'mcp-ai-wpoos-pro' ); ?>"></textarea>
							</div>

							<div class="form-actions">
								<button type="submit" class="button button-primary button-large">
									<?php esc_html_e( 'Create Lead', 'mcp-ai-wpoos-pro' ); ?>
								</button>
								<button type="button" class="button button-secondary button-large" id="wp-mcp-ai-clear-form">
									<?php esc_html_e( 'Clear Form', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</div>
						</form>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<style>
			.wp-mcp-ai-research-page {
				margin: 0 0 0 -20px;
			}
			.wp-mcp-ai-research-page h1 {
				background: #fff;
				padding: 16px 24px;
				margin: 0;
			}
			.wp-mcp-ai-research-page .wp-header-end {
				margin: 0;
			}
			.wp-mcp-ai-mode-tabs {
				background: #fff;
				padding: 0 24px;
				border-bottom: 1px solid #c3c4c7;
				display: flex;
				gap: 0;
			}
			.wp-mcp-ai-mode-tabs .mode-tab {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				padding: 10px 16px;
				text-decoration: none;
				color: #50575e;
				border-bottom: 3px solid transparent;
				font-size: 13px;
				font-weight: 500;
			}
			.wp-mcp-ai-mode-tabs .mode-tab:hover {
				color: #2271b1;
				background: #f0f6fc;
			}
			.wp-mcp-ai-mode-tabs .mode-tab.active {
				color: #2271b1;
				border-bottom-color: #2271b1;
				font-weight: 600;
			}
			.wp-mcp-ai-feature-list {
				list-style: none;
				padding: 0;
			}
			.wp-mcp-ai-feature-list li {
				margin: 6px 0;
				padding-left: 22px;
				position: relative;
				font-size: 13px;
			}
			.wp-mcp-ai-feature-list li:before {
				content: "✓";
				color: #00a32a;
				font-weight: bold;
				position: absolute;
				left: 0;
			}
			.wp-mcp-ai-research-container {
				display: grid;
				grid-template-columns: 1fr 1fr;
				gap: 24px;
				padding: 24px;
			}
			@media (max-width: 1024px) {
				.wp-mcp-ai-research-container {
					grid-template-columns: 1fr;
				}
			}
			.wp-mcp-ai-research-chat,
			.wp-mcp-ai-research-form {
				background: #fff;
				padding: 24px;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
			}
			.wp-mcp-ai-research-form-fields .form-field {
				margin-bottom: 15px;
			}
			.wp-mcp-ai-research-form-fields .form-row {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
				gap: 15px;
				margin-bottom: 15px;
			}
			.wp-mcp-ai-research-form-fields label {
				display: block;
				margin-bottom: 5px;
				font-weight: 600;
			}
			.wp-mcp-ai-research-form-fields .required label:after {
				content: " *";
				color: #d63638;
			}
			.wp-mcp-ai-research-form-fields input[type="text"],
			.wp-mcp-ai-research-form-fields input[type="email"],
			.wp-mcp-ai-research-form-fields input[type="url"],
			.wp-mcp-ai-research-form-fields input[type="tel"],
			.wp-mcp-ai-research-form-fields input[type="number"],
			.wp-mcp-ai-research-form-fields select,
			.wp-mcp-ai-research-form-fields textarea {
				width: 100%;
			}
			.form-actions {
				margin-top: 20px;
				display: flex;
				gap: 10px;
			}
		</style>


		<?php
	}

	/**
	 * Handle AJAX request to create lead from research.
	 */
	public static function handle_create_from_research() {
		check_ajax_referer( 'wp_mcp_ai_research_page', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$lead_name = isset( $_POST['lead_name'] ) ? sanitize_text_field( wp_unslash( $_POST['lead_name'] ) ) : '';
		$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( empty( $lead_name ) || empty( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Lead name and email are required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Create the lead post.
		$post_data = array(
			'post_title'  => $lead_name,
			'post_type'   => 'mcp_ai_lead',
			'post_status' => 'publish',
		);

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// Save lead metadata.
		$meta_fields = array(
			'lead_status'   => 'status',
			'email'         => 'email',
			'phone'         => 'phone',
			'company'       => 'company',
			'source'        => 'source',
			'lead_score'    => 'score',
			'estimated_value' => 'value',
			'contact_owner' => 'assigned_to',
			'next_action'   => 'next_action',
			'notes'         => 'notes',
		);

		foreach ( $meta_fields as $meta_key => $post_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				if ( 'notes' === $post_key ) {
					$value = sanitize_textarea_field( wp_unslash( $_POST[ $post_key ] ) );
				} elseif ( 'email' === $post_key ) {
					$value = sanitize_email( wp_unslash( $_POST[ $post_key ] ) );
				} elseif ( 'lead_score' === $meta_key || 'estimated_value' === $meta_key ) {
					$value = floatval( $_POST[ $post_key ] );
				} else {
					$value = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
				}
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		// Set default lifecycle stage if not already set.
		if ( ! get_post_meta( $post_id, 'lifecycle_stage', true ) ) {
			update_post_meta( $post_id, 'lifecycle_stage', 'lead' );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Lead created successfully!', 'mcp-ai-wpoos-pro' ),
				'post_id'  => $post_id,
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			)
		);
	}

	/**
	 * Handle AJAX import (placeholder for future bulk import functionality).
	 */
	public static function ajax_handle_import() {
		check_ajax_referer( 'wp_mcp_ai_research_page', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Import functionality coming soon.', 'mcp-ai-wpoos-pro' ) ) );
	}
}
