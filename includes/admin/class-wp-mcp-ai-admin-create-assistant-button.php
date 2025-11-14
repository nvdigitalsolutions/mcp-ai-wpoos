<?php
/**
 * Admin UI for Create Assistant button and modal.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "Create Assistant" button to the assistant post type page and handles the modal UI.
 */
class WP_MCP_AI_Admin_Create_Assistant_Button {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_modal' ) );
		add_filter( 'views_edit-mcp_ai_assistant', array( __CLASS__, 'add_create_button' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_assistant_from_modal', array( __CLASS__, 'handle_ajax_create' ) );
	}

	/**
	 * Enqueue scripts and styles for the create assistant modal.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_scripts( $hook ) {
		// Only load on the assistant list page.
		if ( 'edit.php' !== $hook || ! isset( $_GET['post_type'] ) || 'mcp_ai_assistant' !== $_GET['post_type'] ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-ai-create-assistant-modal',
			WP_MCP_AI_URL . 'assets/css/admin-create-assistant-modal.css',
			array(),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-ai-create-assistant-modal',
			WP_MCP_AI_URL . 'assets/js/admin-create-assistant-modal.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-create-assistant-modal',
			'wpMcpAiCreateAssistant',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_create_assistant' ),
				'strings'     => array(
					'creating'       => __( 'Creating assistant...', 'wp-mcp-ai' ),
					'success'        => __( 'Assistant created successfully!', 'wp-mcp-ai' ),
					'error'          => __( 'Error creating assistant. Please try again.', 'wp-mcp-ai' ),
					'required'       => __( 'This field is required.', 'wp-mcp-ai' ),
					'maxProfessions' => __( 'You can select up to 3 professions.', 'wp-mcp-ai' ),
					'maxRegions'     => __( 'You can select up to 2 regions.', 'wp-mcp-ai' ),
				),
				'professions' => self::get_professions(),
				'regions'     => self::get_regions(),
			)
		);
	}

	/**
	 * Add create assistant button to the post type page.
	 *
	 * @param array $views Views array.
	 * @return array Modified views.
	 */
	public static function add_create_button( $views ) {
		?>
		<style>
			.wp-mcp-ai-create-assistant-btn {
				margin-left: 10px;
				vertical-align: middle;
			}
		</style>
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				// Add button after the page title
				var button = '<button type="button" class="page-title-action wp-mcp-ai-create-assistant-btn" id="wp-mcp-ai-open-create-modal"><?php echo esc_js( __( 'Create AI Assistant', 'wp-mcp-ai' ) ); ?></button>';
				$('.wrap h1.wp-heading-inline').after(button);
			});
		</script>
		<?php
		return $views;
	}

	/**
	 * Render the create assistant modal HTML.
	 */
	public static function render_modal() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-mcp_ai_assistant' !== $screen->id ) {
			return;
		}

		?>
		<div id="wp-mcp-ai-create-assistant-modal" class="wp-mcp-ai-modal" style="display:none;">
			<div class="wp-mcp-ai-modal-overlay"></div>
			<div class="wp-mcp-ai-modal-content">
				<div class="wp-mcp-ai-modal-header">
					<h2><?php esc_html_e( 'Create AI Assistant', 'wp-mcp-ai' ); ?></h2>
					<button type="button" class="wp-mcp-ai-modal-close">&times;</button>
				</div>
				<div class="wp-mcp-ai-modal-body">
					<form id="wp-mcp-ai-create-assistant-form">
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row">
										<label for="assistant-title">
											<?php esc_html_e( 'Assistant Title', 'wp-mcp-ai' ); ?> <span class="required">*</span>
										</label>
									</th>
									<td>
										<input type="text" id="assistant-title" name="title" class="regular-text" required>
										<p class="description">
											<?php esc_html_e( 'E.g., "Jamaica Tax Assistant", "Sri Lanka Customs Broker - Perfumes"', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="assistant-professions">
											<?php esc_html_e( 'Professions', 'wp-mcp-ai' ); ?> <span class="required">*</span>
										</label>
									</th>
									<td>
										<select id="assistant-professions" name="professions[]" multiple class="regular-text" required style="height: 150px;">
											<?php foreach ( self::get_professions() as $key => $label ) : ?>
												<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
										<p class="description">
											<?php esc_html_e( 'Select up to 3 professions. Hold Ctrl/Cmd to select multiple.', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="assistant-regions">
											<?php esc_html_e( 'Regions', 'wp-mcp-ai' ); ?> <span class="required">*</span>
										</label>
									</th>
									<td>
										<select id="assistant-regions" name="regions[]" multiple class="regular-text" required style="height: 150px;">
											<?php foreach ( self::get_regions() as $key => $label ) : ?>
												<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
										<p class="description">
											<?php esc_html_e( 'Select up to 2 regions. Hold Ctrl/Cmd to select multiple.', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="assistant-industry">
											<?php esc_html_e( 'Industry Focus', 'wp-mcp-ai' ); ?>
										</label>
									</th>
									<td>
										<input type="text" id="assistant-industry" name="industry_focus" class="regular-text">
										<p class="description">
											<?php esc_html_e( 'Optional: E.g., "perfumes", "technology", "restaurants"', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="assistant-provider">
											<?php esc_html_e( 'AI Provider', 'wp-mcp-ai' ); ?>
										</label>
									</th>
									<td>
										<select id="assistant-provider" name="provider" class="regular-text">
											<option value="openai" selected><?php esc_html_e( 'OpenAI (Default)', 'wp-mcp-ai' ); ?></option>
											<option value="gemini"><?php esc_html_e( 'Google Gemini', 'wp-mcp-ai' ); ?></option>
											<option value="anthropic"><?php esc_html_e( 'Anthropic Claude', 'wp-mcp-ai' ); ?></option>
											<option value="ollama"><?php esc_html_e( 'Ollama (Local)', 'wp-mcp-ai' ); ?></option>
											<option value="lm_studio"><?php esc_html_e( 'LM Studio', 'wp-mcp-ai' ); ?></option>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="assistant-model">
											<?php esc_html_e( 'Model', 'wp-mcp-ai' ); ?>
										</label>
									</th>
									<td>
										<input type="text" id="assistant-model" name="model" class="regular-text" value="gpt-4">
										<p class="description">
											<?php esc_html_e( 'E.g., "gpt-4", "gpt-4-turbo", "gemini-pro"', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="assistant-temperature">
											<?php esc_html_e( 'Temperature', 'wp-mcp-ai' ); ?>
										</label>
									</th>
									<td>
										<input type="number" id="assistant-temperature" name="temperature" class="small-text" min="0" max="2" step="0.1" value="0.7">
										<p class="description">
											<?php esc_html_e( '0-2. Lower is more deterministic, higher is more creative.', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="assistant-async">
											<input type="checkbox" id="assistant-async" name="async" value="1">
											<?php esc_html_e( 'Create in Background', 'wp-mcp-ai' ); ?>
										</label>
									</th>
									<td>
										<p class="description">
											<?php esc_html_e( 'For complex assistants, create asynchronously via cron. You will be notified when complete.', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>
							</tbody>
						</table>
					</form>
				</div>
				<div class="wp-mcp-ai-modal-footer">
					<button type="button" class="button button-secondary wp-mcp-ai-modal-close">
						<?php esc_html_e( 'Cancel', 'wp-mcp-ai' ); ?>
					</button>
					<button type="submit" form="wp-mcp-ai-create-assistant-form" class="button button-primary" id="wp-mcp-ai-submit-create">
						<?php esc_html_e( 'Create Assistant', 'wp-mcp-ai' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to create assistant.
	 */
	public static function handle_ajax_create() {
		check_ajax_referer( 'wp_mcp_ai_create_assistant', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
		}

		// Get form data.
		$title          = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$professions    = isset( $_POST['professions'] ) && is_array( $_POST['professions'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['professions'] ) ) : array();
		$regions        = isset( $_POST['regions'] ) && is_array( $_POST['regions'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['regions'] ) ) : array();
		$industry_focus = isset( $_POST['industry_focus'] ) ? sanitize_text_field( wp_unslash( $_POST['industry_focus'] ) ) : '';
		$provider       = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'openai';
		$model          = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : 'gpt-4';
		$temperature    = isset( $_POST['temperature'] ) ? floatval( $_POST['temperature'] ) : 0.7;
		$async          = isset( $_POST['async'] ) && '1' === $_POST['async'];

		// Validate required fields.
		if ( empty( $title ) || empty( $professions ) || empty( $regions ) ) {
			wp_send_json_error( array( 'message' => __( 'Title, professions, and regions are required.', 'wp-mcp-ai' ) ) );
		}

		// Use the create_assistant tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'create_assistant' );
		if ( ! $tool ) {
			wp_send_json_error( array( 'message' => __( 'Create assistant tool not available.', 'wp-mcp-ai' ) ) );
		}

		$arguments = array(
			'title'          => $title,
			'professions'    => $professions,
			'regions'        => $regions,
			'industry_focus' => $industry_focus,
			'provider'       => $provider,
			'model'          => $model,
			'temperature'    => $temperature,
			'async'          => $async,
		);

		$context = array(
			'user_id' => get_current_user_id(),
		);

		$result = $tool->execute( $arguments, $context );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Get profession options.
	 *
	 * Now integrates with profession CPT system.
	 * Falls back to hardcoded list for backward compatibility.
	 *
	 * @return array Profession key => label pairs.
	 */
	protected static function get_professions() {
		// Try to get professions from CPT system.
		if ( function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			$profession_service = wp_mcp_ai_get_profession_service();
			$professions        = $profession_service->get_professions_for_dropdown();

			// If we have professions from CPT, use them.
			if ( ! empty( $professions ) ) {
				return $professions;
			}
		}

		// Fallback to hardcoded list for backward compatibility.
		return array(
			'tax_advisor'              => __( 'Tax Advisor', 'wp-mcp-ai' ),
			'accountant'               => __( 'Accountant', 'wp-mcp-ai' ),
			'bookkeeper'               => __( 'Bookkeeper', 'wp-mcp-ai' ),
			'lawyer'                   => __( 'Lawyer', 'wp-mcp-ai' ),
			'legal_advisor'            => __( 'Legal Advisor', 'wp-mcp-ai' ),
			'customs_broker'           => __( 'Customs Broker', 'wp-mcp-ai' ),
			'import_export_specialist' => __( 'Import/Export Specialist', 'wp-mcp-ai' ),
			'financial_advisor'        => __( 'Financial Advisor', 'wp-mcp-ai' ),
			'business_consultant'      => __( 'Business Consultant', 'wp-mcp-ai' ),
			'real_estate_agent'        => __( 'Real Estate Agent', 'wp-mcp-ai' ),
			'healthcare_advisor'       => __( 'Healthcare Advisor', 'wp-mcp-ai' ),
			'marketing_consultant'     => __( 'Marketing Consultant', 'wp-mcp-ai' ),
			'hr_consultant'            => __( 'HR Consultant', 'wp-mcp-ai' ),
			'it_consultant'            => __( 'IT Consultant', 'wp-mcp-ai' ),
			'restaurant_consultant'    => __( 'Restaurant Consultant', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Get region options.
	 *
	 * @return array Region key => label pairs.
	 */
	protected static function get_regions() {
		return array(
			'united_states'        => __( 'United States', 'wp-mcp-ai' ),
			'canada'               => __( 'Canada', 'wp-mcp-ai' ),
			'united_kingdom'       => __( 'United Kingdom', 'wp-mcp-ai' ),
			'australia'            => __( 'Australia', 'wp-mcp-ai' ),
			'jamaica'              => __( 'Jamaica', 'wp-mcp-ai' ),
			'sri_lanka'            => __( 'Sri Lanka', 'wp-mcp-ai' ),
			'india'                => __( 'India', 'wp-mcp-ai' ),
			'singapore'            => __( 'Singapore', 'wp-mcp-ai' ),
			'united_arab_emirates' => __( 'United Arab Emirates', 'wp-mcp-ai' ),
			'germany'              => __( 'Germany', 'wp-mcp-ai' ),
			'france'               => __( 'France', 'wp-mcp-ai' ),
			'spain'                => __( 'Spain', 'wp-mcp-ai' ),
			'italy'                => __( 'Italy', 'wp-mcp-ai' ),
			'netherlands'          => __( 'Netherlands', 'wp-mcp-ai' ),
			'brazil'               => __( 'Brazil', 'wp-mcp-ai' ),
			'mexico'               => __( 'Mexico', 'wp-mcp-ai' ),
			'south_africa'         => __( 'South Africa', 'wp-mcp-ai' ),
			'new_zealand'          => __( 'New Zealand', 'wp-mcp-ai' ),
			'ireland'              => __( 'Ireland', 'wp-mcp-ai' ),
			'japan'                => __( 'Japan', 'wp-mcp-ai' ),
			'china'                => __( 'China', 'wp-mcp-ai' ),
			'global'               => __( 'Global', 'wp-mcp-ai' ),
		);
	}
}
