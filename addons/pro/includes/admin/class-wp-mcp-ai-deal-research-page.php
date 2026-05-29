<?php
/**
 * Research & Add admin page for Deal CPT.
 *
 * Provides a dedicated page for researching and creating deals/opportunities,
 * with AI-powered pipeline stage analysis, forecasting, and deal structuring.
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
 * Deal Research Admin Page
 *
 * Adds a submenu page under NV CRM menu for AI-powered deal research and creation.
 */
class WP_MCP_AI_Deal_Research_Page {
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
	const PAGE_SLUG = 'crm-deal-research';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 26 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_deal_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_deal', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add submenu page under NV CRM menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			WP_MCP_AI_CRM_Admin_Menu::PARENT_SLUG,
			__( 'Research & Add Deal', 'mcp-ai-wpoos-pro' ),
			__( 'Deal Research & Add', 'mcp-ai-wpoos-pro' ),
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
				<?php esc_html_e( 'Research & Add Deal', 'mcp-ai-wpoos-pro' ); ?>
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
						<h2><?php esc_html_e( 'AI Deal Research Assistant', 'mcp-ai-wpoos-pro' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Use the AI assistant to research deals, analyze pipeline stages, forecast revenue, and structure opportunity details.', 'mcp-ai-wpoos-pro' ); ?>
						</p>

						<ul class="wp-mcp-ai-feature-list" style="margin-bottom: 15px;">
							<li><strong><?php esc_html_e( 'Pipeline Analysis:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Analyze pipeline health and forecasting accuracy', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Deal Structuring:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Structure deal details with stage, amount, and probability', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Revenue Forecasting:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Weighted and unweighted pipeline forecasts', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Conversion Analytics:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Track win/loss rates and conversion metrics', 'mcp-ai-wpoos-pro' ); ?></li>
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
									esc_attr__( 'Ask me to analyze your pipeline, forecast deal outcomes, or help structure new opportunities...', 'mcp-ai-wpoos-pro' )
								);
								echo do_shortcode( $shortcode );
							} else {
								echo '<p>' . esc_html__( 'Chat interface not available. Please ensure the plugin is properly installed.', 'mcp-ai-wpoos-pro' ) . '</p>';
							}
							?>
						</div>
					</div>

					<div class="wp-mcp-ai-research-form">
						<h2><?php esc_html_e( 'Deal Details', 'mcp-ai-wpoos-pro' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Fill in deal information below, or ask the AI assistant to help you structure and populate these fields.', 'mcp-ai-wpoos-pro' ); ?>
						</p>

						<form id="wp-mcp-ai-deal-research-form" class="wp-mcp-ai-research-form-fields">
							<div class="form-field required">
								<label for="deal_name"><?php esc_html_e( 'Deal Name', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="text" id="deal_name" name="deal_name" required>
							</div>

							<div class="form-row">
								<div class="form-field">
									<label for="contact_id"><?php esc_html_e( 'Contact / Lead', 'mcp-ai-wpoos-pro' ); ?></label>
									<input type="text" id="contact_id" name="contact_id" placeholder="<?php esc_attr_e( 'e.g. lead name or ID', 'mcp-ai-wpoos-pro' ); ?>">
								</div>

								<div class="form-field">
									<label for="company_id"><?php esc_html_e( 'Company', 'mcp-ai-wpoos-pro' ); ?></label>
									<input type="text" id="company_id" name="company_id" placeholder="<?php esc_attr_e( 'e.g. company name or ID', 'mcp-ai-wpoos-pro' ); ?>">
								</div>
							</div>

							<div class="form-row">
								<div class="form-field required">
									<label for="deal_amount"><?php esc_html_e( 'Deal Amount', 'mcp-ai-wpoos-pro' ); ?></label>
									<input type="number" id="deal_amount" name="deal_amount" required placeholder="0.00" step="0.01">
								</div>

								<div class="form-field">
									<label for="deal_currency"><?php esc_html_e( 'Currency', 'mcp-ai-wpoos-pro' ); ?></label>
									<select id="deal_currency" name="deal_currency">
										<option value="USD">USD ($)</option>
										<option value="EUR">EUR (€)</option>
										<option value="GBP">GBP (£)</option>
										<option value="AED">AED (د.إ)</option>
										<option value="CAD">CAD</option>
										<option value="AUD">AUD</option>
									</select>
								</div>
							</div>

							<div class="form-row">
								<div class="form-field">
									<label for="deal_stage"><?php esc_html_e( 'Pipeline Stage', 'mcp-ai-wpoos-pro' ); ?></label>
									<select id="deal_stage" name="deal_stage">
										<option value="prospecting"><?php esc_html_e( '1. Prospecting', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="qualification"><?php esc_html_e( '2. Qualification', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="needs_analysis"><?php esc_html_e( '3. Needs Analysis', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="value_proposition"><?php esc_html_e( '4. Value Proposition', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="decision_makers"><?php esc_html_e( '5. Decision Makers', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="perception_analysis"><?php esc_html_e( '6. Perception Analysis', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="proposal"><?php esc_html_e( '7. Proposal', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="negotiation"><?php esc_html_e( '8. Negotiation', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="closed_won"><?php esc_html_e( '9. Closed Won', 'mcp-ai-wpoos-pro' ); ?></option>
										<option value="closed_lost"><?php esc_html_e( '10. Closed Lost', 'mcp-ai-wpoos-pro' ); ?></option>
									</select>
								</div>

								<div class="form-field">
									<label for="deal_probability"><?php esc_html_e( 'Probability (%)', 'mcp-ai-wpoos-pro' ); ?></label>
									<input type="number" id="deal_probability" name="deal_probability" min="0" max="100" placeholder="50">
								</div>
							</div>

							<div class="form-row">
								<div class="form-field">
									<label for="close_date"><?php esc_html_e( 'Close Date', 'mcp-ai-wpoos-pro' ); ?></label>
									<input type="date" id="close_date" name="close_date">
								</div>

								<div class="form-field">
									<label for="deal_owner"><?php esc_html_e( 'Deal Owner (User ID)', 'mcp-ai-wpoos-pro' ); ?></label>
									<input type="text" id="deal_owner" name="deal_owner" placeholder="<?php esc_attr_e( 'e.g. user ID or name', 'mcp-ai-wpoos-pro' ); ?>">
								</div>
							</div>

							<div class="form-field">
								<label for="deal_source"><?php esc_html_e( 'Deal Source', 'mcp-ai-wpoos-pro' ); ?></label>
								<select id="deal_source" name="deal_source">
									<option value=""><?php esc_html_e( 'Select source...', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="inbound"><?php esc_html_e( 'Inbound', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="outbound"><?php esc_html_e( 'Outbound', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="referral"><?php esc_html_e( 'Referral', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="partner"><?php esc_html_e( 'Partner', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="upsell"><?php esc_html_e( 'Upsell / Cross-sell', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="other"><?php esc_html_e( 'Other', 'mcp-ai-wpoos-pro' ); ?></option>
								</select>
							</div>

							<div class="form-field">
								<label for="deal_type"><?php esc_html_e( 'Deal Type', 'mcp-ai-wpoos-pro' ); ?></label>
								<select id="deal_type" name="deal_type">
									<option value="new_business"><?php esc_html_e( 'New Business', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="existing_business"><?php esc_html_e( 'Existing Business', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="renewal"><?php esc_html_e( 'Renewal', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="expansion"><?php esc_html_e( 'Expansion', 'mcp-ai-wpoos-pro' ); ?></option>
								</select>
							</div>

							<div class="form-field">
								<label for="deal_notes"><?php esc_html_e( 'Notes', 'mcp-ai-wpoos-pro' ); ?></label>
								<textarea id="deal_notes" name="deal_notes" rows="4" placeholder="<?php esc_attr_e( 'Add deal details, next steps, competitor intel, or key conditions...', 'mcp-ai-wpoos-pro' ); ?>"></textarea>
							</div>

							<div class="form-actions">
								<button type="submit" class="button button-primary button-large">
									<?php esc_html_e( 'Create Deal', 'mcp-ai-wpoos-pro' ); ?>
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
			.wp-mcp-ai-research-form-fields input[type="date"],
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
	 * Handle AJAX request to create deal from research.
	 */
	public static function handle_create_from_research() {
		check_ajax_referer( 'wp_mcp_ai_research_page', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$deal_name   = isset( $_POST['deal_name'] ) ? sanitize_text_field( wp_unslash( $_POST['deal_name'] ) ) : '';
		$deal_amount = isset( $_POST['deal_amount'] ) ? floatval( $_POST['deal_amount'] ) : 0;

		if ( empty( $deal_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Deal name is required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Create the deal post.
		$post_data = array(
			'post_title'  => $deal_name,
			'post_type'   => 'mcp_ai_deal',
			'post_status' => 'publish',
		);

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// Save deal metadata.
		$deal_stage       = isset( $_POST['deal_stage'] ) ? sanitize_text_field( wp_unslash( $_POST['deal_stage'] ) ) : 'prospecting';
		$deal_probability = isset( $_POST['deal_probability'] ) ? floatval( $_POST['deal_probability'] ) / 100 : 0.5;

		$meta_fields = array(
			'deal_amount'      => 'deal_amount',
			'deal_currency'    => 'deal_currency',
			'deal_stage'       => 'deal_stage',
			'deal_probability' => 'deal_probability',
			'close_date'       => 'close_date',
			'deal_owner'       => 'deal_owner',
			'deal_source'      => 'deal_source',
			'deal_type'        => 'deal_type',
			'contact_id'       => 'contact_id',
			'company_id'       => 'company_id',
			'deal_notes'       => 'deal_notes',
		);

		foreach ( $meta_fields as $meta_key => $post_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				if ( 'deal_amount' === $meta_key ) {
					$value = floatval( $_POST[ $post_key ] );
				} elseif ( 'deal_probability' === $meta_key ) {
					$value = floatval( $_POST[ $post_key ] ) / 100;
				} elseif ( 'deal_notes' === $post_key ) {
					$value = sanitize_textarea_field( wp_unslash( $_POST[ $post_key ] ) );
				} else {
					$value = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
				}
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Deal created successfully!', 'mcp-ai-wpoos-pro' ),
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
