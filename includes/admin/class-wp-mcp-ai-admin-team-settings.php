<?php
/**
 * Team Settings Admin Page
 *
 * Provides a dedicated settings page for AI Teams configuration.
 * Allows administrators to set global defaults for provider, model, and temperature
 * that cascade to all teams (overridable at individual team level).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Team Settings admin page handler.
 */
class WP_MCP_AI_Admin_Team_Settings {

	/**
	 * Page hook suffix.
	 *
	 * @var string|false
	 */
	protected $page_hook;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_submenu_page' ), 25 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register the submenu page under Teams CPT.
	 */
	public function register_submenu_page() {
		$post_type = class_exists( 'WP_MCP_AI_Team_CPT' ) ? WP_MCP_AI_Team_CPT::POST_TYPE : 'mcp_ai_team';

		$this->page_hook = add_submenu_page(
			'edit.php?post_type=' . $post_type,
			__( 'Team Settings', 'mcp-ai-wpoos' ),
			__( 'Settings', 'mcp-ai-wpoos' ),
			'manage_options',
			'wp-mcp-ai-team-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting(
			'wp_mcp_ai_team_settings_group',
			'wp_mcp_ai_team_default_provider',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);
		register_setting(
			'wp_mcp_ai_team_settings_group',
			'wp_mcp_ai_team_default_model',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);
		register_setting(
			'wp_mcp_ai_team_settings_group',
			'wp_mcp_ai_team_default_temperature',
			array( 'sanitize_callback' => 'floatval' )
		);
		register_setting(
			'wp_mcp_ai_team_settings_group',
			'wp_mcp_ai_team_default_driver_assistant',
			array( 'sanitize_callback' => 'absint' )
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
		}

		// Handle form submission.
		if ( isset( $_POST['wp_mcp_ai_team_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_team_settings_nonce'] ) ), 'wp_mcp_ai_team_settings' ) ) {
			$this->save_settings();
		}

		// Get current settings.
		$default_provider    = get_option( 'wp_mcp_ai_team_default_provider', '' );
		$default_model       = get_option( 'wp_mcp_ai_team_default_model', '' );
		$default_temperature = get_option( 'wp_mcp_ai_team_default_temperature', 0.7 );

		// Get available providers.
		$available_providers = array();
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$available_providers = WP_MCP_AI_Admin_Settings::get_available_providers();
		}

		// Get post type for links.
		$post_type = class_exists( 'WP_MCP_AI_Team_CPT' ) ? WP_MCP_AI_Team_CPT::POST_TYPE : 'mcp_ai_team';

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Team Settings', 'mcp-ai-wpoos' ); ?></h1>
			
			<p class="description">
				<?php esc_html_e( 'Configure global default settings for all AI Teams. These settings provide baseline values that cascade to all team members, but can be overridden at the individual team level via metaboxes.', 'mcp-ai-wpoos' ); ?>
			</p>

			<?php if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved successfully.', 'mcp-ai-wpoos' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'wp_mcp_ai_team_settings', 'wp_mcp_ai_team_settings_nonce' ); ?>
				
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_team_default_provider">
									<?php esc_html_e( 'Default AI Provider', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<select name="wp_mcp_ai_team_default_provider" id="wp_mcp_ai_team_default_provider" class="regular-text">
									<option value=""><?php esc_html_e( '-- Use Global Default --', 'mcp-ai-wpoos' ); ?></option>
									<?php foreach ( $available_providers as $provider_slug => $provider_label ) : ?>
										<option value="<?php echo esc_attr( $provider_slug ); ?>" <?php selected( $default_provider, $provider_slug ); ?>>
											<?php echo esc_html( $provider_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Default AI provider for all team members. Individual teams can override this in their metabox settings.', 'mcp-ai-wpoos' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_team_default_model">
									<?php esc_html_e( 'Default Model', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<input type="text" name="wp_mcp_ai_team_default_model" id="wp_mcp_ai_team_default_model" value="<?php echo esc_attr( $default_model ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., gpt-4o, claude-3-5-sonnet-20241022', 'mcp-ai-wpoos' ); ?>">
								<p class="description">
									<?php esc_html_e( 'Default AI model for all team members. Leave empty to use the provider\'s default model. Individual teams can override this setting.', 'mcp-ai-wpoos' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_team_default_temperature">
									<?php esc_html_e( 'Default Temperature', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<input type="number" name="wp_mcp_ai_team_default_temperature" id="wp_mcp_ai_team_default_temperature" value="<?php echo esc_attr( $default_temperature ); ?>" class="small-text" min="0" max="1" step="0.1">
								<p class="description">
									<?php esc_html_e( 'Default creativity/randomness setting (0.0 = deterministic, 1.0 = creative). Individual teams can override this setting.', 'mcp-ai-wpoos' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'mcp-ai-wpoos' ); ?>">
				</p>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Settings Hierarchy', 'mcp-ai-wpoos' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Settings cascade in the following order (higher priority overrides lower):', 'mcp-ai-wpoos' ); ?>
			</p>
			<ol>
				<li><strong><?php esc_html_e( 'Individual Team Settings', 'mcp-ai-wpoos' ); ?></strong> - <?php esc_html_e( 'Configured in each team\'s metabox (highest priority)', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Team Global Defaults', 'mcp-ai-wpoos' ); ?></strong> - <?php esc_html_e( 'This page (medium priority)', 'mcp-ai-wpoos' ); ?></li>
				<li><strong><?php esc_html_e( 'Global Plugin Settings', 'mcp-ai-wpoos' ); ?></strong> - <?php esc_html_e( 'Site-wide defaults (lowest priority)', 'mcp-ai-wpoos' ); ?></li>
			</ol>

			<hr>

			<h2><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos' ); ?></h2>
			<p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>" class="button">
					<?php esc_html_e( 'View All Teams', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>" class="button">
					<?php esc_html_e( 'Create New Team', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type . '&page=wp-mcp-ai-test-team' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Test Team', 'mcp-ai-wpoos' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Save settings.
	 */
	private function save_settings() {
		// Sanitize and save provider.
		if ( isset( $_POST['wp_mcp_ai_team_default_provider'] ) ) {
			update_option( 'wp_mcp_ai_team_default_provider', sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_team_default_provider'] ) ) );
		}

		// Sanitize and save model.
		if ( isset( $_POST['wp_mcp_ai_team_default_model'] ) ) {
			update_option( 'wp_mcp_ai_team_default_model', sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_team_default_model'] ) ) );
		}

		// Sanitize and save temperature.
		if ( isset( $_POST['wp_mcp_ai_team_default_temperature'] ) ) {
			$temperature = floatval( wp_unslash( $_POST['wp_mcp_ai_team_default_temperature'] ) );
			$temperature = max( 0, min( 1, $temperature ) ); // Clamp between 0 and 1.
			update_option( 'wp_mcp_ai_team_default_temperature', $temperature );
		}

		// Redirect with success message.
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', admin_url( 'edit.php?post_type=mcp_ai_team&page=wp-mcp-ai-team-settings' ) ) );
		exit;
	}
}
