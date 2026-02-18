<?php
/**
 * Research & Add admin page for Company CPT.
 *
 * Provides a dedicated page for researching companies before adding them,
 * with full chat interface for AI assistance and web search capabilities.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * Company Research Admin Page
 *
 * Adds a submenu page under Companies menu for AI-powered company research.
 */
class WP_MCP_AI_Company_Research_Page {
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
	const PAGE_SLUG = 'research-company';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_company_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_company', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add submenu page under Companies menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_company',
			__( 'Research & Add Company', 'mcp-ai-wpoos-pro' ),
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
		if ( 'mcp_ai_company_page_' . self::PAGE_SLUG !== $hook ) {
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
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'wp_mcp_ai_research_page' ),
				'entityType'     => 'company',
				'createAction'   => 'wp_mcp_ai_create_company_from_research',
				'importAction'   => 'wp_mcp_ai_import_company',
				'i18n'           => array(
					'creating'       => __( 'Creating company...', 'mcp-ai-wpoos-pro' ),
					'created'        => __( 'Company created successfully!', 'mcp-ai-wpoos-pro' ),
					'createError'    => __( 'Error creating company.', 'mcp-ai-wpoos-pro' ),
					'importing'      => __( 'Importing company...', 'mcp-ai-wpoos-pro' ),
					'imported'       => __( 'Company imported successfully!', 'mcp-ai-wpoos-pro' ),
					'importError'    => __( 'Error importing company.', 'mcp-ai-wpoos-pro' ),
					'requiredFields' => __( 'Please fill in all required fields.', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		?>
		<div class="wrap wp-mcp-ai-research-page">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Research & Add Company', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<div class="wp-mcp-ai-research-info-box">
				<h3><?php esc_html_e( 'AI-Powered Company Research', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<?php esc_html_e( 'Use AI to research companies, identify target prospects, and analyze industry best practices before adding them to your CRM.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<ul class="wp-mcp-ai-feature-list">
					<li><strong><?php esc_html_e( 'Web Search Integration:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Ask the AI to search for companies in specific industries or locations', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Industry Standards:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Research best practices and trends in target industries', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Company Intelligence:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Get company size, revenue, and contact information', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Target Identification:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Identify which companies to target for your services', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
				<p class="description">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: 1: Newsletter plugin link, 2: WP Mail SMTP plugin link */
							__( 'This toolkit works with <a href="%1$s" target="_blank">Newsletter</a> and <a href="%2$s" target="_blank">WP Mail SMTP</a> plugins for email marketing integration.', 'mcp-ai-wpoos-pro' ),
							'https://wordpress.org/plugins/newsletter/',
							'https://wordpress.org/plugins/wp-mail-smtp/'
						)
					);
					?>
				</p>
			</div>

			<div class="wp-mcp-ai-research-container">
				<div class="wp-mcp-ai-research-chat">
					<h2><?php esc_html_e( 'AI Research Assistant', 'mcp-ai-wpoos-pro' ); ?></h2>
					<div class="wp-mcp-ai-research-chat-container">
						<?php
						// Get the assigned assistant for CRM research.
						$assigned_assistant = get_option( 'wp_mcp_ai_crm_research_assistant', 'default' );
						
						// Render the AI chat interface with the assigned assistant.
						if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
							$shortcode_instance = new WP_MCP_AI_Shortcode();
							$shortcode          = sprintf(
								'[nvoos_chat assistant="%s" placeholder="%s"]',
								esc_attr( $assigned_assistant ),
								esc_attr__( 'Ask me to research companies, industries, or help identify target prospects...', 'mcp-ai-wpoos-pro' )
							);
							echo do_shortcode( $shortcode );
						} else {
							echo '<p>' . esc_html__( 'Chat interface not available. Please ensure the plugin is properly installed.', 'mcp-ai-wpoos-pro' ) . '</p>';
						}
						?>
					</div>
				</div>

				<div class="wp-mcp-ai-research-form">
					<h2><?php esc_html_e( 'Company Details', 'mcp-ai-wpoos-pro' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Fill in company information below, or ask the AI assistant to help you research and populate these fields.', 'mcp-ai-wpoos-pro' ); ?>
					</p>

					<form id="wp-mcp-ai-company-research-form" class="wp-mcp-ai-research-form-fields">
						<div class="form-field required">
							<label for="company_name"><?php esc_html_e( 'Company Name', 'mcp-ai-wpoos-pro' ); ?></label>
							<input type="text" id="company_name" name="company_name" required>
						</div>

						<div class="form-row">
							<div class="form-field required">
								<label for="industry"><?php esc_html_e( 'Industry', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="text" id="industry" name="industry" required>
							</div>

							<div class="form-field">
								<label for="company_size"><?php esc_html_e( 'Company Size', 'mcp-ai-wpoos-pro' ); ?></label>
								<select id="company_size" name="company_size">
									<option value=""><?php esc_html_e( 'Select size...', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="1-10"><?php esc_html_e( '1-10 employees', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="11-50"><?php esc_html_e( '11-50 employees', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="51-200"><?php esc_html_e( '51-200 employees', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="201-500"><?php esc_html_e( '201-500 employees', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="501-1000"><?php esc_html_e( '501-1,000 employees', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="1001-5000"><?php esc_html_e( '1,001-5,000 employees', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="5001+"><?php esc_html_e( '5,001+ employees', 'mcp-ai-wpoos-pro' ); ?></option>
								</select>
							</div>
						</div>

						<div class="form-field">
							<label for="website"><?php esc_html_e( 'Website', 'mcp-ai-wpoos-pro' ); ?></label>
							<input type="url" id="website" name="website" placeholder="https://">
						</div>

						<div class="form-field">
							<label for="description"><?php esc_html_e( 'Company Description', 'mcp-ai-wpoos-pro' ); ?></label>
							<textarea id="description" name="description" rows="4"></textarea>
						</div>

						<div class="form-row">
							<div class="form-field">
								<label for="city"><?php esc_html_e( 'City', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="text" id="city" name="city">
							</div>

							<div class="form-field">
								<label for="state"><?php esc_html_e( 'State/Province', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="text" id="state" name="state">
							</div>

							<div class="form-field">
								<label for="country"><?php esc_html_e( 'Country', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="text" id="country" name="country">
							</div>
						</div>

						<div class="form-row">
							<div class="form-field">
								<label for="phone"><?php esc_html_e( 'Phone', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="tel" id="phone" name="phone">
							</div>

							<div class="form-field">
								<label for="revenue"><?php esc_html_e( 'Annual Revenue', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="number" id="revenue" name="revenue" placeholder="0">
							</div>
						</div>

						<div class="form-field">
							<label for="target_status"><?php esc_html_e( 'Target Status', 'mcp-ai-wpoos-pro' ); ?></label>
							<select id="target_status" name="target_status">
								<option value="prospect"><?php esc_html_e( 'Prospect', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="target" selected><?php esc_html_e( 'Target', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="in_discussion"><?php esc_html_e( 'In Discussion', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="client"><?php esc_html_e( 'Client', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="not_interested"><?php esc_html_e( 'Not Interested', 'mcp-ai-wpoos-pro' ); ?></option>
							</select>
						</div>

						<div class="form-row">
							<div class="form-field">
								<label for="linkedin"><?php esc_html_e( 'LinkedIn URL', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="url" id="linkedin" name="linkedin" placeholder="https://linkedin.com/company/">
							</div>

							<div class="form-field">
								<label for="twitter"><?php esc_html_e( 'Twitter/X Handle', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="text" id="twitter" name="twitter" placeholder="@company">
							</div>
						</div>

						<div class="form-field">
							<label for="notes"><?php esc_html_e( 'Research Notes', 'mcp-ai-wpoos-pro' ); ?></label>
							<textarea id="notes" name="notes" rows="4" placeholder="<?php esc_attr_e( 'Add any research findings, target strategy notes, or key insights about this company...', 'mcp-ai-wpoos-pro' ); ?>"></textarea>
						</div>

						<div class="form-actions">
							<button type="submit" class="button button-primary button-large">
								<?php esc_html_e( 'Create Company', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<button type="button" class="button button-secondary button-large" id="wp-mcp-ai-clear-form">
								<?php esc_html_e( 'Clear Form', 'mcp-ai-wpoos-pro' ); ?>
							</button>
						</div>
					</form>
				</div>
			</div>
		</div>

		<style>
			.wp-mcp-ai-research-info-box {
				background: #f0f6fc;
				border-left: 4px solid #2271b1;
				padding: 15px 20px;
				margin: 20px 0;
			}
			.wp-mcp-ai-research-info-box h3 {
				margin-top: 0;
			}
			.wp-mcp-ai-feature-list {
				list-style: none;
				padding: 0;
			}
			.wp-mcp-ai-feature-list li {
				margin: 8px 0;
				padding-left: 25px;
				position: relative;
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
				gap: 20px;
				margin-top: 20px;
			}
			.wp-mcp-ai-research-chat,
			.wp-mcp-ai-research-form {
				background: #fff;
				padding: 20px;
				border: 1px solid #c3c4c7;
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
	 * Handle AJAX request to create company from research.
	 */
	public static function handle_create_from_research() {
		check_ajax_referer( 'wp_mcp_ai_research_page', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$company_name = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
		$industry     = isset( $_POST['industry'] ) ? sanitize_text_field( wp_unslash( $_POST['industry'] ) ) : '';

		if ( empty( $company_name ) || empty( $industry ) ) {
			wp_send_json_error( array( 'message' => __( 'Company name and industry are required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Create the company post.
		$post_data = array(
			'post_title'   => $company_name,
			'post_content' => isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '',
			'post_type'    => 'mcp_ai_company',
			'post_status'  => 'publish',
		);

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// Save company metadata.
		$meta_fields = array(
			'_company_industry'       => 'industry',
			'_company_size'           => 'company_size',
			'_company_website'        => 'website',
			'_company_city'           => 'city',
			'_company_state'          => 'state',
			'_company_country'        => 'country',
			'_company_phone'          => 'phone',
			'_company_revenue'        => 'revenue',
			'_company_target_status'  => 'target_status',
			'_company_linkedin'       => 'linkedin',
			'_company_twitter'        => 'twitter',
			'_company_notes'          => 'notes',
		);

		foreach ( $meta_fields as $meta_key => $post_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
				if ( 'notes' === $post_key || 'description' === $post_key ) {
					$value = wp_kses_post( wp_unslash( $_POST[ $post_key ] ) );
				}
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		wp_send_json_success(
			array(
				'message' => __( 'Company created successfully!', 'mcp-ai-wpoos-pro' ),
				'post_id' => $post_id,
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

		// Placeholder for future import functionality.
		wp_send_json_error( array( 'message' => __( 'Import functionality coming soon.', 'mcp-ai-wpoos-pro' ) ) );
	}
}
