<?php
/**
 * Password Vault Manager Admin Page
 *
 * Provides admin interface for password vault management with dedicated pages for:
 * - Vault Items Management
 * - Password Generator & Authenticator
 * - Import/Export & Sync
 * - Security Settings
 * - Auto Sync & Conflicts
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin interface for Password Vault Manager.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Password_Vault_Admin {

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		// Priority 30 ensures this runs after Pro Dashboard menu registration (priority 25).
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 30 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// AJAX handlers for password and TOTP generation.
		add_action( 'wp_ajax_vault_generate_password', array( $this, 'handle_generate_password' ) );
		add_action( 'wp_ajax_vault_generate_totp_secret', array( $this, 'handle_generate_totp_secret' ) );

		// Import/Export/Sync handlers (these use admin_post for form submissions with redirect).
		add_action( 'admin_post_wp_mcp_ai_vault_import_bitwarden', array( $this, 'handle_import_bitwarden' ) );
		add_action( 'admin_post_wp_mcp_ai_vault_export_bitwarden', array( $this, 'handle_export_bitwarden' ) );
		add_action( 'admin_post_wp_mcp_ai_vault_sync_bitwarden', array( $this, 'handle_sync_bitwarden' ) );
	}

	/**
	 * Add admin menu pages for Password Vault Manager.
	 *
	 * Creates a top-level menu with submenu pages for each section.
	 *
	 * @since 1.3.0
	 */
	public function add_admin_menu() {
		// Add top-level menu.
		add_menu_page(
			__( 'Password Vault Manager', 'mcp-ai-wpoos-pro' ),
			__( 'Password Vault', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'wp-mcp-ai-password-vault',
			array( $this, 'render_vault_items_page' ),
			'dashicons-lock',
			26
		);

		// Add submenu pages.
		add_submenu_page(
			'wp-mcp-ai-password-vault',
			__( 'Vault Items', 'mcp-ai-wpoos-pro' ),
			__( 'Vault Items', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'wp-mcp-ai-password-vault',
			array( $this, 'render_vault_items_page' )
		);

		add_submenu_page(
			'wp-mcp-ai-password-vault',
			__( 'Password Generator & Authenticator', 'mcp-ai-wpoos-pro' ),
			__( 'Generator & Auth', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'wp-mcp-ai-password-vault-generator',
			array( $this, 'render_generator_page' )
		);

		add_submenu_page(
			'wp-mcp-ai-password-vault',
			__( 'Import/Export & Sync', 'mcp-ai-wpoos-pro' ),
			__( 'Import/Export & Sync', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'wp-mcp-ai-password-vault-sync',
			array( $this, 'render_sync_page' )
		);

		add_submenu_page(
			'wp-mcp-ai-password-vault',
			__( 'Security Settings', 'mcp-ai-wpoos-pro' ),
			__( 'Security Settings', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'wp-mcp-ai-password-vault-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'wp-mcp-ai-password-vault',
			__( 'Auto Sync & Conflicts', 'mcp-ai-wpoos-pro' ),
			__( 'Auto Sync & Conflicts', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'wp-mcp-ai-password-vault-automation',
			array( $this, 'render_automation_page' )
		);
	}

	/**
	 * Register settings.
	 *
	 * @since 1.3.0
	 */
	public function register_settings() {
		register_setting(
			'wp_mcp_ai_vault_settings',
			'wp_mcp_ai_vault_settings',
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @since 1.3.0
	 *
	 * @param array $settings Settings to sanitize.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $settings ) {
		$sanitized = array();

		// Password generator settings.
		$sanitized['password_length']          = isset( $settings['password_length'] ) ? absint( $settings['password_length'] ) : 20;
		$sanitized['password_uppercase']       = ! empty( $settings['password_uppercase'] );
		$sanitized['password_lowercase']       = ! empty( $settings['password_lowercase'] );
		$sanitized['password_numbers']         = ! empty( $settings['password_numbers'] );
		$sanitized['password_symbols']         = ! empty( $settings['password_symbols'] );
		$sanitized['password_avoid_ambiguous'] = ! empty( $settings['password_avoid_ambiguous'] );

		// TOTP settings.
		$sanitized['totp_issuer'] = ! empty( $settings['totp_issuer'] ) ? sanitize_text_field( $settings['totp_issuer'] ) : get_bloginfo( 'name' );

		return $sanitized;
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @since 1.3.0
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// List of all password vault page hooks.
		$vault_pages = array(
			'toplevel_page_wp-mcp-ai-password-vault',
			'wp-mcp-ai-password-vault_page_wp-mcp-ai-password-vault-generator',
			'wp-mcp-ai-password-vault_page_wp-mcp-ai-password-vault-sync',
			'wp-mcp-ai-password-vault_page_wp-mcp-ai-password-vault-settings',
			'wp-mcp-ai-password-vault_page_wp-mcp-ai-password-vault-automation',
		);

		// Check if we're on any vault page.
		$is_vault_page = in_array( $hook, $vault_pages, true );

		// Also check via $_GET for additional safety.
		if ( ! $is_vault_page && isset( $_GET['page'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking page slug for script enqueue only.
			$page = sanitize_text_field( wp_unslash( $_GET['page'] ) );
			$is_vault_page = strpos( $page, 'wp-mcp-ai-password-vault' ) === 0;
		}

		if ( ! $is_vault_page ) {
			return;
		}

		// Verify Pro addon constants are defined before enqueueing.
		if ( ! defined( 'WP_MCP_AI_PRO_URL' ) || ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Critical error logging.
			error_log( 'Password Vault: Cannot enqueue scripts - WP_MCP_AI_PRO_URL or WP_MCP_AI_PRO_VERSION not defined' );
			return;
		}

		// Debug logging when WP_DEBUG is enabled.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$pro_url  = defined( 'WP_MCP_AI_PRO_URL' ) ? WP_MCP_AI_PRO_URL : 'undefined';
			$pro_path = defined( 'WP_MCP_AI_PRO_PATH' ) ? WP_MCP_AI_PRO_PATH : 'undefined';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is enabled.
			error_log( sprintf( 'Password Vault: Enqueuing scripts. WP_MCP_AI_PRO_URL: %s, WP_MCP_AI_PRO_PATH: %s', $pro_url, $pro_path ) );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is enabled.
			error_log( sprintf( 'Password Vault: CSS URL: %s', $css_url ) );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is enabled.
			error_log( sprintf( 'Password Vault: JS URL: %s', $js_url ) );
		}

		// Build asset URLs with validation.
		$css_url = trailingslashit( WP_MCP_AI_PRO_URL ) . 'assets/css/password-vault-admin.css';
		$js_url  = trailingslashit( WP_MCP_AI_PRO_URL ) . 'assets/js/password-vault-admin.js';

		// Enqueue WordPress color picker.
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		// Enqueue custom styles.
		wp_enqueue_style(
			'wp-mcp-ai-password-vault',
			$css_url,
			array(),
			WP_MCP_AI_PRO_VERSION
		);

		// Enqueue custom scripts.
		wp_enqueue_script(
			'wp-mcp-ai-password-vault',
			$js_url,
			array( 'jquery', 'wp-color-picker' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'wp-mcp-ai-password-vault',
			'wpMcpAiVault',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_vault_action' ),
				'strings'  => array(
					'copy_success'          => __( 'Copied to clipboard!', 'mcp-ai-wpoos-pro' ),
					'copy_failed'           => __( 'Failed to copy.', 'mcp-ai-wpoos-pro' ),
					'password_generated'    => __( 'Password generated successfully!', 'mcp-ai-wpoos-pro' ),
					'totp_secret_generated' => __( 'Authenticator secret generated successfully!', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Handle password generation AJAX request.
	 *
	 * @since 1.3.0
	 */
	public function handle_generate_password() {
		check_admin_referer( 'vault_generate_password' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
		}

		$settings = get_option( 'wp_mcp_ai_vault_settings', array() );

		$length          = ! empty( $_POST['length'] ) ? absint( $_POST['length'] ) : ( $settings['password_length'] ?? 20 );
		$uppercase       = isset( $_POST['uppercase'] ) ? (bool) $_POST['uppercase'] : ( $settings['password_uppercase'] ?? true );
		$lowercase       = isset( $_POST['lowercase'] ) ? (bool) $_POST['lowercase'] : ( $settings['password_lowercase'] ?? true );
		$numbers         = isset( $_POST['numbers'] ) ? (bool) $_POST['numbers'] : ( $settings['password_numbers'] ?? true );
		$symbols         = isset( $_POST['symbols'] ) ? (bool) $_POST['symbols'] : ( $settings['password_symbols'] ?? true );
		$avoid_ambiguous = isset( $_POST['avoid_ambiguous'] ) ? (bool) $_POST['avoid_ambiguous'] : ( $settings['password_avoid_ambiguous'] ?? true );

		$encryption_service = new WP_MCP_AI_Vault_Encryption_Service();
		$password           = $encryption_service->generate_password( $length, $uppercase, $lowercase, $numbers, $symbols, $avoid_ambiguous );

		if ( is_wp_error( $password ) ) {
			wp_die( esc_html( $password->get_error_message() ) );
		}

		$strength = $encryption_service->calculate_password_strength( $password );

		wp_send_json_success(
			array(
				'password' => $password,
				'strength' => $strength,
			)
		);
	}

	/**
	 * Handle TOTP secret generation AJAX request.
	 *
	 * @since 1.3.0
	 */
	public function handle_generate_totp_secret() {
		check_admin_referer( 'vault_generate_totp_secret' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
		}

		$encryption_service = new WP_MCP_AI_Vault_Encryption_Service();
		$secret             = $encryption_service->generate_totp_secret();

		if ( is_wp_error( $secret ) ) {
			wp_die( esc_html( $secret->get_error_message() ) );
		}

		$settings = get_option( 'wp_mcp_ai_vault_settings', array() );
		$issuer   = $settings['totp_issuer'] ?? get_bloginfo( 'name' );
		$user     = wp_get_current_user();
		$label    = $user->user_email;

		$qr_uri = $encryption_service->get_totp_qr_code_uri( $secret, $label, $issuer );

		wp_send_json_success(
			array(
				'secret'      => $secret,
				'qr_code_url' => $qr_uri,
			)
		);
	}

	/**
	 * Render Vault Items page.
	 *
	 * @since 1.3.0
	 */
	public function render_vault_items_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		?>
		<div class="wrap wp-mcp-ai-vault-admin">
			<h1><?php esc_html_e( 'Vault Items', 'mcp-ai-wpoos-pro' ); ?></h1>
			<?php $this->render_vault_tab(); ?>
		</div>
		<?php
	}

	/**
	 * Render Password Generator & Authenticator page.
	 *
	 * @since 1.3.0
	 */
	public function render_generator_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		$settings = get_option( 'wp_mcp_ai_vault_settings', array() );

		?>
		<div class="wrap wp-mcp-ai-vault-admin">
			<h1><?php esc_html_e( 'Password Generator & Authenticator', 'mcp-ai-wpoos-pro' ); ?></h1>
			<?php $this->render_generator_tab( $settings ); ?>
		</div>
		<?php
	}

	/**
	 * Render Import/Export & Sync page.
	 *
	 * @since 1.3.0
	 */
	public function render_sync_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		$settings = get_option( 'wp_mcp_ai_vault_settings', array() );

		?>
		<div class="wrap wp-mcp-ai-vault-admin">
			<h1><?php esc_html_e( 'Import/Export & Sync', 'mcp-ai-wpoos-pro' ); ?></h1>
			<?php $this->render_sync_tab( $settings ); ?>
		</div>
		<?php
	}

	/**
	 * Render Security Settings page.
	 *
	 * @since 1.3.0
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		$settings = get_option( 'wp_mcp_ai_vault_settings', array() );

		?>
		<div class="wrap wp-mcp-ai-vault-admin">
			<h1><?php esc_html_e( 'Security Settings', 'mcp-ai-wpoos-pro' ); ?></h1>
			<?php $this->render_settings_tab( $settings ); ?>
		</div>
		<?php
	}

	/**
	 * Render Auto Sync & Conflicts page.
	 *
	 * @since 1.3.0
	 */
	public function render_automation_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		$settings = get_option( 'wp_mcp_ai_vault_settings', array() );

		?>
		<div class="wrap wp-mcp-ai-vault-admin">
			<h1><?php esc_html_e( 'Auto Sync & Conflicts', 'mcp-ai-wpoos-pro' ); ?></h1>
			<?php $this->render_automation_tab( $settings ); ?>
		</div>
		<?php
	}

	/**
	 * Render vault items tab.
	 *
	 * @since 1.3.0
	 */
	private function render_vault_tab() {
		?>
		<div class="vault-items-container">
			<div class="vault-header">
				<h2><?php esc_html_e( 'Your Vault Items', 'mcp-ai-wpoos-pro' ); ?></h2>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_vault_item' ) ); ?>" class="button button-primary">
					<span class="dashicons dashicons-plus-alt"></span>
					<?php esc_html_e( 'Add New Item', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</div>

			<div class="vault-stats">
				<?php
				$user_id = get_current_user_id();
				$query   = new WP_Query(
					array(
						'post_type'      => 'mcp_vault_item',
						'author'         => $user_id,
						'post_status'    => 'private',
						'posts_per_page' => -1,
						'fields'         => 'ids',
					)
				);

				$total_items = $query->found_posts;
				?>
				<div class="stat-box">
					<span class="dashicons dashicons-lock"></span>
					<div class="stat-content">
						<div class="stat-number"><?php echo esc_html( $total_items ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Vault Items', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>

				<div class="stat-box">
					<span class="dashicons dashicons-shield"></span>
					<div class="stat-content">
						<div class="stat-number"><?php esc_html_e( 'AES-256-GCM', 'mcp-ai-wpoos-pro' ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Encryption', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>

				<div class="stat-box">
					<span class="dashicons dashicons-yes-alt"></span>
					<div class="stat-content">
						<div class="stat-number"><?php esc_html_e( 'OWASP', 'mcp-ai-wpoos-pro' ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Compliant', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>
			</div>

			<div class="vault-items-list">
				<h3><?php esc_html_e( 'Recent Items', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p><?php esc_html_e( 'Manage your vault items in the WordPress post editor or via the REST API.', 'mcp-ai-wpoos-pro' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_vault_item' ) ); ?>" class="button">
					<?php esc_html_e( 'View All Vault Items', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render password generator & authenticator tab.
	 *
	 * @since 1.3.0
	 *
	 * @param array $settings Current settings.
	 */
	private function render_generator_tab( $settings ) {
		$encryption_service = new WP_MCP_AI_Vault_Encryption_Service();

		?>
		<div class="generator-container">
			<div class="generator-section password-generator-section">
				<h2>
					<span class="dashicons dashicons-admin-network"></span>
					<?php esc_html_e( 'Password Generator', 'mcp-ai-wpoos-pro' ); ?>
				</h2>
				<p class="description"><?php esc_html_e( 'Generate cryptographically secure passwords for your vault items.', 'mcp-ai-wpoos-pro' ); ?></p>

				<form id="password-generator-form" method="post">
					<?php wp_nonce_field( 'vault_generate_password' ); ?>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="password_length"><?php esc_html_e( 'Length', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<input type="number" id="password_length" name="length" value="<?php echo esc_attr( $settings['password_length'] ?? 20 ); ?>" min="12" max="128" class="small-text" />
								<p class="description"><?php esc_html_e( 'Password length (12-128 characters)', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Character Sets', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="checkbox" name="uppercase" value="1" <?php checked( $settings['password_uppercase'] ?? true ); ?> />
										<?php esc_html_e( 'Uppercase Letters (A-Z)', 'mcp-ai-wpoos-pro' ); ?>
									</label><br />
									<label>
										<input type="checkbox" name="lowercase" value="1" <?php checked( $settings['password_lowercase'] ?? true ); ?> />
										<?php esc_html_e( 'Lowercase Letters (a-z)', 'mcp-ai-wpoos-pro' ); ?>
									</label><br />
									<label>
										<input type="checkbox" name="numbers" value="1" <?php checked( $settings['password_numbers'] ?? true ); ?> />
										<?php esc_html_e( 'Numbers (0-9)', 'mcp-ai-wpoos-pro' ); ?>
									</label><br />
									<label>
										<input type="checkbox" name="symbols" value="1" <?php checked( $settings['password_symbols'] ?? true ); ?> />
										<?php esc_html_e( 'Symbols (!@#$%^&*)', 'mcp-ai-wpoos-pro' ); ?>
									</label><br />
									<label>
										<input type="checkbox" name="avoid_ambiguous" value="1" <?php checked( $settings['password_avoid_ambiguous'] ?? true ); ?> />
										<?php esc_html_e( 'Avoid Ambiguous Characters (0, O, l, I)', 'mcp-ai-wpoos-pro' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary button-large">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Generate Password', 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</p>
				</form>

				<div id="password-result" class="password-result" style="display: none;">
					<h3><?php esc_html_e( 'Generated Password', 'mcp-ai-wpoos-pro' ); ?></h3>
					<div class="password-display">
						<input type="text" id="generated-password" readonly class="large-text" />
						<button type="button" id="copy-password" class="button">
							<span class="dashicons dashicons-clipboard"></span>
							<?php esc_html_e( 'Copy', 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</div>
					<div class="password-strength">
						<label><?php esc_html_e( 'Strength:', 'mcp-ai-wpoos-pro' ); ?></label>
						<div id="strength-indicator" class="strength-indicator">
							<span class="strength-bar"></span>
						</div>
						<span id="strength-text" class="strength-text"></span>
					</div>
				</div>
			</div>

			<hr />

			<div class="generator-section totp-generator-section">
				<h2>
					<span class="dashicons dashicons-smartphone"></span>
					<?php esc_html_e( 'TOTP Authenticator', 'mcp-ai-wpoos-pro' ); ?>
				</h2>
				<p class="description"><?php esc_html_e( 'Generate Time-based One-Time Password (TOTP) secrets for two-factor authentication. Compatible with Google Authenticator, Authy, Microsoft Authenticator, and other RFC 6238 authenticator apps.', 'mcp-ai-wpoos-pro' ); ?></p>

				<form id="totp-generator-form" method="post">
					<?php wp_nonce_field( 'vault_generate_totp_secret' ); ?>

					<p class="submit">
						<button type="submit" class="button button-primary button-large">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Generate Authenticator Secret', 'mcp-ai-wpoos-pro' ); ?>
						</button>
					</p>
				</form>

				<div id="totp-result" class="totp-result" style="display: none;">
					<h3><?php esc_html_e( 'Authenticator Setup', 'mcp-ai-wpoos-pro' ); ?></h3>
					
					<div class="totp-secret-display">
						<label><?php esc_html_e( 'Secret Key:', 'mcp-ai-wpoos-pro' ); ?></label>
						<div class="secret-box">
							<code id="totp-secret" class="totp-secret"></code>
							<button type="button" id="copy-totp-secret" class="button">
								<span class="dashicons dashicons-clipboard"></span>
								<?php esc_html_e( 'Copy', 'mcp-ai-wpoos-pro' ); ?>
							</button>
						</div>
						<p class="description"><?php esc_html_e( 'Save this secret securely. You can manually enter it into your authenticator app.', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<div class="totp-qr-code">
						<h4><?php esc_html_e( 'Scan QR Code', 'mcp-ai-wpoos-pro' ); ?></h4>
						<div id="qr-code-container" class="qr-code-container"></div>
						<p class="description"><?php esc_html_e( 'Scan this QR code with your authenticator app (Google Authenticator, Authy, etc.)', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<div class="totp-test">
						<h4><?php esc_html_e( 'Test Your Code', 'mcp-ai-wpoos-pro' ); ?></h4>
						<input type="text" id="totp-test-code" placeholder="<?php esc_attr_e( 'Enter 6-digit code', 'mcp-ai-wpoos-pro' ); ?>" maxlength="6" pattern="\d{6}" class="regular-text" />
						<button type="button" id="verify-totp-code" class="button">
							<?php esc_html_e( 'Verify Code', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span id="totp-verification-result"></span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render security settings tab.
	 *
	 * @since 1.3.0
	 *
	 * @param array $settings Current settings.
	 */
	private function render_settings_tab( $settings ) {
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'wp_mcp_ai_vault_settings' );
			?>

			<h2><?php esc_html_e( 'Security Settings', 'mcp-ai-wpoos-pro' ); ?></h2>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="totp_issuer"><?php esc_html_e( 'TOTP Issuer Name', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" id="totp_issuer" name="wp_mcp_ai_vault_settings[totp_issuer]" value="<?php echo esc_attr( $settings['totp_issuer'] ?? get_bloginfo( 'name' ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Name displayed in authenticator apps. Defaults to site name.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Default Password Generator Settings', 'mcp-ai-wpoos-pro' ); ?></h3>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="password_length"><?php esc_html_e( 'Default Length', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="number" id="password_length" name="wp_mcp_ai_vault_settings[password_length]" value="<?php echo esc_attr( $settings['password_length'] ?? 20 ); ?>" min="12" max="128" class="small-text" />
						<p class="description"><?php esc_html_e( 'Default password length (12-128 characters)', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Character Sets', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="checkbox" name="wp_mcp_ai_vault_settings[password_uppercase]" value="1" <?php checked( $settings['password_uppercase'] ?? true ); ?> />
								<?php esc_html_e( 'Uppercase Letters', 'mcp-ai-wpoos-pro' ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="wp_mcp_ai_vault_settings[password_lowercase]" value="1" <?php checked( $settings['password_lowercase'] ?? true ); ?> />
								<?php esc_html_e( 'Lowercase Letters', 'mcp-ai-wpoos-pro' ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="wp_mcp_ai_vault_settings[password_numbers]" value="1" <?php checked( $settings['password_numbers'] ?? true ); ?> />
								<?php esc_html_e( 'Numbers', 'mcp-ai-wpoos-pro' ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="wp_mcp_ai_vault_settings[password_symbols]" value="1" <?php checked( $settings['password_symbols'] ?? true ); ?> />
								<?php esc_html_e( 'Symbols', 'mcp-ai-wpoos-pro' ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="wp_mcp_ai_vault_settings[password_avoid_ambiguous]" value="1" <?php checked( $settings['password_avoid_ambiguous'] ?? true ); ?> />
								<?php esc_html_e( 'Avoid Ambiguous Characters', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Encryption Information', 'mcp-ai-wpoos-pro' ); ?></h3>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Encryption Algorithm', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<p><strong>AES-256-GCM</strong></p>
						<p class="description"><?php esc_html_e( 'Authenticated encryption providing both confidentiality and integrity. OWASP recommended.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Key Derivation', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<p><strong>PBKDF2-HMAC-SHA256 (100,000 iterations)</strong></p>
						<p class="description"><?php esc_html_e( 'Per-user encryption keys derived from WordPress AUTH_KEY + unique user salt.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'TOTP Algorithm', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<p><strong>RFC 6238 (TOTP) & RFC 4226 (HOTP)</strong></p>
						<p class="description"><?php esc_html_e( 'Time-based One-Time Password compatible with Google Authenticator and similar apps.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Handle Bitwarden import.
	 *
	 * @since 1.3.0
	 */
	public function handle_import_bitwarden() {
		check_admin_referer( 'vault_import_bitwarden', 'vault_import_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! isset( $_FILES['bitwarden_import_file'] ) || $_FILES['bitwarden_import_file']['error'] !== UPLOAD_ERR_OK ) {
			wp_die( esc_html__( 'No file uploaded or upload error.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate file type.
		$file_type = wp_check_filetype( $_FILES['bitwarden_import_file']['name'] );
		if ( $file_type['ext'] !== 'json' ) {
			wp_die( esc_html__( 'Invalid file type. Please upload a JSON file.', 'mcp-ai-wpoos-pro' ) );
		}

		// Read file content.
		$json_data = file_get_contents( $_FILES['bitwarden_import_file']['tmp_name'] );

		// Parse options.
		$options = array(
			'merge_folders'    => ! empty( $_POST['merge_folders'] ),
			'skip_duplicates'  => ! empty( $_POST['skip_duplicates'] ),
			'import_totp'      => ! empty( $_POST['import_totp'] ),
			'import_favorites' => ! empty( $_POST['import_favorites'] ),
		);

		// Import.
		$import_service = new WP_MCP_AI_Bitwarden_Import_Export();
		$result         = $import_service->import_bitwarden_json( $json_data, get_current_user_id(), $options );

		if ( $result['success'] ) {
			$message = sprintf(
				/* translators: 1: items imported, 2: folders imported, 3: items skipped */
				esc_html__( 'Import successful! Imported %1$d items, %2$d folders. Skipped %3$d items.', 'mcp-ai-wpoos-pro' ),
				$result['imported_count'],
				$result['folder_count'],
				$result['skipped_count']
			);

			if ( ! empty( $result['errors'] ) ) {
				$message .= '<br><br><strong>' . esc_html__( 'Errors:', 'mcp-ai-wpoos-pro' ) . '</strong><br>' . implode( '<br>', array_map( 'esc_html', $result['errors'] ) );
			}

			wp_die( $message, esc_html__( 'Import Complete', 'mcp-ai-wpoos-pro' ), array( 'back_link' => true ) );
		} else {
			$error_message = esc_html__( 'Import failed.', 'mcp-ai-wpoos-pro' );
			if ( ! empty( $result['errors'] ) ) {
				$error_message .= '<br>' . implode( '<br>', array_map( 'esc_html', $result['errors'] ) );
			}
			wp_die( $error_message, esc_html__( 'Import Failed', 'mcp-ai-wpoos-pro' ), array( 'back_link' => true ) );
		}
	}

	/**
	 * Handle Bitwarden export.
	 *
	 * @since 1.3.0
	 */
	public function handle_export_bitwarden() {
		check_admin_referer( 'vault_export_bitwarden', 'vault_export_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
		}

		// Parse options.
		$options = array(
			'include_folders'   => ! empty( $_POST['include_folders'] ),
			'include_totp'      => ! empty( $_POST['include_totp'] ),
			'include_history'   => ! empty( $_POST['include_history'] ),
			'include_favorites' => ! empty( $_POST['include_favorites'] ),
		);

		// Export.
		$import_export = new WP_MCP_AI_Bitwarden_Import_Export();
		$json_data     = $import_export->export_to_bitwarden_json( get_current_user_id(), $options );

		if ( is_wp_error( $json_data ) ) {
			wp_die( esc_html( $json_data->get_error_message() ), esc_html__( 'Export Failed', 'mcp-ai-wpoos-pro' ), array( 'back_link' => true ) );
		}

		// Send file download.
		$filename = 'bitwarden-export-' . date( 'Y-m-d-His' ) . '.json';

		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json_data ) );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		echo $json_data;
		exit;
	}

	/**
	 * Handle Bitwarden sync.
	 *
	 * @since 1.3.0
	 */
	public function handle_sync_bitwarden() {
		check_admin_referer( 'vault_sync_bitwarden', 'vault_sync_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
		}

		$server_url     = esc_url_raw( $_POST['bitwarden_server_url'] ?? '' );
		$email          = sanitize_text_field( $_POST['bitwarden_email'] ?? '' );
		$password       = $_POST['bitwarden_password'] ?? '';
		$auth_method    = sanitize_text_field( $_POST['auth_method'] ?? 'password' );
		$sync_direction = sanitize_text_field( $_POST['sync_direction'] ?? 'pull' );

		if ( empty( $server_url ) || empty( $email ) || empty( $password ) ) {
			wp_die( esc_html__( 'Please fill in all required fields.', 'mcp-ai-wpoos-pro' ), esc_html__( 'Sync Failed', 'mcp-ai-wpoos-pro' ), array( 'back_link' => true ) );
		}

		$sync_service = new WP_MCP_AI_Bitwarden_Sync_Service();

		// Authenticate.
		$auth_result = $sync_service->authenticate( $server_url, $email, $password, $auth_method );
		if ( is_wp_error( $auth_result ) ) {
			wp_die( esc_html( $auth_result->get_error_message() ), esc_html__( 'Authentication Failed', 'mcp-ai-wpoos-pro' ), array( 'back_link' => true ) );
		}

		$access_token = $auth_result['access_token'];

		// Perform sync.
		if ( $sync_direction === 'pull' ) {
			$result = $sync_service->sync_from_bitwarden( get_current_user_id(), $access_token );
		} else {
			$result = $sync_service->sync_to_bitwarden( get_current_user_id(), $access_token );
		}

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), esc_html__( 'Sync Failed', 'mcp-ai-wpoos-pro' ), array( 'back_link' => true ) );
		}

		if ( $result['success'] ) {
			if ( $sync_direction === 'pull' ) {
				$message = sprintf(
					/* translators: 1: items synced, 2: folders synced */
					esc_html__( 'Sync successful! Pulled %1$d items and %2$d folders from Bitwarden.', 'mcp-ai-wpoos-pro' ),
					$result['imported_count'],
					$result['folder_count']
				);
			} else {
				$message = sprintf(
					/* translators: 1: items synced, 2: folders synced */
					esc_html__( 'Sync successful! Pushed %1$d items and %2$d folders to Bitwarden.', 'mcp-ai-wpoos-pro' ),
					$result['items_synced'],
					$result['folders_synced']
				);
			}

			if ( ! empty( $result['errors'] ) ) {
				$message .= '<br><br><strong>' . esc_html__( 'Errors:', 'mcp-ai-wpoos-pro' ) . '</strong><br>' . implode( '<br>', array_map( 'esc_html', $result['errors'] ) );
			}

			wp_die( $message, esc_html__( 'Sync Complete', 'mcp-ai-wpoos-pro' ), array( 'back_link' => true ) );
		} else {
			wp_die( esc_html__( 'Sync failed.', 'mcp-ai-wpoos-pro' ), esc_html__( 'Sync Failed', 'mcp-ai-wpoos-pro' ), array( 'back_link' => true ) );
		}
	}

	/**
	 * Render Import/Export & Sync tab.
	 *
	 * @since 1.3.0
	 * @param array $settings Vault settings.
	 */
	private function render_sync_tab( $settings ) {
		$user_id = get_current_user_id();
		?>
		<div class="sync-container">
			<h2><?php esc_html_e( 'Import/Export & Bitwarden Sync', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import vault data from Bitwarden JSON export, export your WordPress vault to Bitwarden format, or sync bidirectionally with an external Bitwarden/Vaultwarden server.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<!-- Import Section -->
			<div class="vault-card">
				<h3><?php esc_html_e( 'Import from Bitwarden', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Import vault items from a Bitwarden JSON export file.', 'mcp-ai-wpoos-pro' ); ?></p>
				
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'vault_import_bitwarden', 'vault_import_nonce' ); ?>
					<input type="hidden" name="action" value="wp_mcp_ai_vault_import_bitwarden" />
					
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="bitwarden_import_file"><?php esc_html_e( 'Bitwarden Export File', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<input type="file" name="bitwarden_import_file" id="bitwarden_import_file" accept=".json" required />
								<p class="description"><?php esc_html_e( 'Select your Bitwarden JSON export file (unencrypted format).', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Import Options', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="checkbox" name="merge_folders" value="1" checked />
										<?php esc_html_e( 'Merge folders with existing (instead of creating duplicates)', 'mcp-ai-wpoos-pro' ); ?>
									</label><br />
									<label>
										<input type="checkbox" name="skip_duplicates" value="1" checked />
										<?php esc_html_e( 'Skip duplicate items', 'mcp-ai-wpoos-pro' ); ?>
									</label><br />
									<label>
										<input type="checkbox" name="import_totp" value="1" checked />
										<?php esc_html_e( 'Import TOTP secrets', 'mcp-ai-wpoos-pro' ); ?>
									</label><br />
									<label>
										<input type="checkbox" name="import_favorites" value="1" checked />
										<?php esc_html_e( 'Import favorite status', 'mcp-ai-wpoos-pro' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Import from Bitwarden', 'mcp-ai-wpoos-pro' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<!-- Export Section -->
			<div class="vault-card">
				<h3><?php esc_html_e( 'Export to Bitwarden Format', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Export your WordPress vault to Bitwarden-compatible JSON format.', 'mcp-ai-wpoos-pro' ); ?></p>
				
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'vault_export_bitwarden', 'vault_export_nonce' ); ?>
					<input type="hidden" name="action" value="wp_mcp_ai_vault_export_bitwarden" />
					
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Export Options', 'mcp-ai-wpoos-pro' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="checkbox" name="include_folders" value="1" checked />
										<?php esc_html_e( 'Include folders', 'mcp-ai-wpoos-pro' ); ?>
									</label><br />
									<label>
										<input type="checkbox" name="include_totp" value="1" checked />
										<?php esc_html_e( 'Include TOTP secrets', 'mcp-ai-wpoos-pro' ); ?>
									</label><br />
									<label>
										<input type="checkbox" name="include_history" value="1" checked />
										<?php esc_html_e( 'Include password history', 'mcp-ai-wpoos-pro' ); ?>
									</label><br />
									<label>
										<input type="checkbox" name="include_favorites" value="1" checked />
										<?php esc_html_e( 'Include favorite status', 'mcp-ai-wpoos-pro' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Export to Bitwarden JSON', 'mcp-ai-wpoos-pro' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<!-- Sync Section -->
			<div class="vault-card">
				<h3><?php esc_html_e( 'Sync with Bitwarden/Vaultwarden Server', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Bidirectional sync with an external Bitwarden or Vaultwarden server.', 'mcp-ai-wpoos-pro' ); ?></p>
				
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'vault_sync_bitwarden', 'vault_sync_nonce' ); ?>
					<input type="hidden" name="action" value="wp_mcp_ai_vault_sync_bitwarden" />
					
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="bitwarden_server_url"><?php esc_html_e( 'Server URL', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<input type="url" name="bitwarden_server_url" id="bitwarden_server_url" class="regular-text" 
									value="<?php echo esc_attr( $settings['bitwarden_server_url'] ?? 'https://vault.bitwarden.com' ); ?>" required />
								<p class="description"><?php esc_html_e( 'Bitwarden server URL (e.g., https://vault.bitwarden.com or your Vaultwarden server)', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="bitwarden_email"><?php esc_html_e( 'Email/Client ID', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<input type="text" name="bitwarden_email" id="bitwarden_email" class="regular-text" 
									value="<?php echo esc_attr( $settings['bitwarden_email'] ?? '' ); ?>" required />
								<p class="description"><?php esc_html_e( 'Your Bitwarden account email or API client ID', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="bitwarden_password"><?php esc_html_e( 'Password/API Secret', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<input type="password" name="bitwarden_password" id="bitwarden_password" class="regular-text" required />
								<p class="description"><?php esc_html_e( 'Your master password or API client secret (not stored, only used for this sync)', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="auth_method"><?php esc_html_e( 'Authentication Method', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<select name="auth_method" id="auth_method">
									<option value="password"><?php esc_html_e( 'Password', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="api_key"><?php esc_html_e( 'API Key', 'mcp-ai-wpoos-pro' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Choose authentication method', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="sync_direction"><?php esc_html_e( 'Sync Direction', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<select name="sync_direction" id="sync_direction">
									<option value="pull"><?php esc_html_e( 'Pull from Bitwarden to WordPress', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="push"><?php esc_html_e( 'Push from WordPress to Bitwarden', 'mcp-ai-wpoos-pro' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Choose sync direction (bidirectional sync coming in future update)', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
					</table>

					<p>
						<button type="button" class="button" id="test-bitwarden-connection"><?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos-pro' ); ?></button>
						<span id="connection-test-result"></span>
					</p>

					<?php submit_button( __( 'Sync Now', 'mcp-ai-wpoos-pro' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<!-- Help Section -->
			<div class="vault-card">
				<h3><?php esc_html_e( 'How to Export from Bitwarden', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ol>
					<li><?php esc_html_e( 'Open Bitwarden (web, desktop, or browser extension)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Go to Tools → Export Vault', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Select ".json" as the file format (not ".json (Encrypted)")', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Enter your master password to confirm', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Download the JSON file and upload it above', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>
				<p><strong><?php esc_html_e( 'Important:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Delete the export file after importing for security.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render automation tab (Phase 4)
	 *
	 * Handles automatic background sync and conflict resolution.
	 *
	 * @param array $settings Vault settings.
	 */
	private function render_automation_tab( $settings ) {
		$sync_service      = new WP_MCP_AI_Vault_Background_Sync();
		$conflict_resolver = new WP_MCP_AI_Vault_Conflict_Resolver();

		$sync_settings     = get_option( 'wp_mcp_ai_vault_sync_settings', array() );
		$last_sync         = $sync_service->get_last_sync_time();
		$next_sync         = $sync_service->get_next_sync_time();
		$sync_logs         = $sync_service->get_sync_logs( 10 );
		$pending_conflicts = $conflict_resolver->get_pending_conflicts();
		?>
		<div class="vault-tabs-content">
			<h2><?php esc_html_e( 'Automatic Background Sync', 'mcp-ai-wpoos-pro' ); ?></h2>

			<!-- Sync Status Card -->
			<div class="vault-card">
				<h3><?php esc_html_e( 'Sync Status', 'mcp-ai-wpoos-pro' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Auto Sync:', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<strong><?php echo ! empty( $sync_settings['auto_sync_enabled'] ) ? esc_html__( 'Enabled', 'mcp-ai-wpoos-pro' ) : esc_html__( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></strong>
						</td>
					</tr>
					<?php if ( $last_sync ) : ?>
					<tr>
						<th><?php esc_html_e( 'Last Sync:', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( human_time_diff( $last_sync ) . ' ago' ); ?></td>
					</tr>
					<?php endif; ?>
					<?php if ( $next_sync ) : ?>
					<tr>
						<th><?php esc_html_e( 'Next Sync:', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( human_time_diff( $next_sync ) ); ?></td>
					</tr>
					<?php endif; ?>
					<tr>
						<th><?php esc_html_e( 'Pending Conflicts:', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<strong><?php echo esc_html( count( $pending_conflicts ) ); ?></strong>
							<?php if ( count( $pending_conflicts ) > 0 ) : ?>
								<a href="#conflicts" class="button button-small"><?php esc_html_e( 'View Conflicts', 'mcp-ai-wpoos-pro' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</div>

			<!-- Configure Auto Sync -->
			<div class="vault-card">
				<h3><?php esc_html_e( 'Configure Automatic Sync', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wp_mcp_ai_configure_auto_sync', 'wp_mcp_ai_auto_sync_nonce' ); ?>
					<input type="hidden" name="action" value="wp_mcp_ai_configure_auto_sync" />

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="auto_sync_enabled"><?php esc_html_e( 'Enable Auto Sync', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" name="auto_sync_enabled" id="auto_sync_enabled" value="1" <?php checked( ! empty( $sync_settings['auto_sync_enabled'] ) ); ?> />
									<?php esc_html_e( 'Automatically synchronize vault with Bitwarden server', 'mcp-ai-wpoos-pro' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="sync_interval"><?php esc_html_e( 'Sync Interval', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<select name="sync_interval" id="sync_interval">
									<option value="every_15_minutes" <?php selected( $sync_settings['sync_interval'] ?? '', 'every_15_minutes' ); ?>><?php esc_html_e( 'Every 15 Minutes', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="every_30_minutes" <?php selected( $sync_settings['sync_interval'] ?? '', 'every_30_minutes' ); ?>><?php esc_html_e( 'Every 30 Minutes', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="hourly" <?php selected( $sync_settings['sync_interval'] ?? 'hourly', 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="every_2_hours" <?php selected( $sync_settings['sync_interval'] ?? '', 'every_2_hours' ); ?>><?php esc_html_e( 'Every 2 Hours', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="every_6_hours" <?php selected( $sync_settings['sync_interval'] ?? '', 'every_6_hours' ); ?>><?php esc_html_e( 'Every 6 Hours', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="twicedaily" <?php selected( $sync_settings['sync_interval'] ?? '', 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="daily" <?php selected( $sync_settings['sync_interval'] ?? '', 'daily' ); ?>><?php esc_html_e( 'Daily', 'mcp-ai-wpoos-pro' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="conflict_resolution"><?php esc_html_e( 'Conflict Resolution', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<select name="conflict_resolution" id="conflict_resolution">
									<option value="newest_wins" <?php selected( $sync_settings['conflict_resolution'] ?? 'newest_wins', 'newest_wins' ); ?>><?php esc_html_e( 'Newest Wins (Recommended)', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="local_wins" <?php selected( $sync_settings['conflict_resolution'] ?? '', 'local_wins' ); ?>><?php esc_html_e( 'Local Wins', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="remote_wins" <?php selected( $sync_settings['conflict_resolution'] ?? '', 'remote_wins' ); ?>><?php esc_html_e( 'Remote Wins', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="merge" <?php selected( $sync_settings['conflict_resolution'] ?? '', 'merge' ); ?>><?php esc_html_e( 'Merge (Combine Data)', 'mcp-ai-wpoos-pro' ); ?></option>
									<option value="manual" <?php selected( $sync_settings['conflict_resolution'] ?? '', 'manual' ); ?>><?php esc_html_e( 'Manual (Review Each)', 'mcp-ai-wpoos-pro' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'How to handle conflicts when both local and remote items have been modified', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save Auto Sync Settings', 'mcp-ai-wpoos-pro' ) ); ?>
				</form>
			</div>

			<!-- Recent Sync Logs -->
			<div class="vault-card">
				<h3><?php esc_html_e( 'Recent Sync Activity', 'mcp-ai-wpoos-pro' ); ?></h3>
				<?php if ( ! empty( $sync_logs ) ) : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Time', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Message', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Level', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $sync_logs as $log ) : ?>
								<tr>
									<td><?php echo esc_html( $log['timestamp'] ); ?></td>
									<td><?php echo esc_html( $log['message'] ); ?></td>
									<td>
										<span class="badge badge-<?php echo esc_attr( $log['level'] ); ?>">
											<?php echo esc_html( ucfirst( $log['level'] ) ); ?>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><?php esc_html_e( 'No sync activity yet.', 'mcp-ai-wpoos-pro' ); ?></p>
				<?php endif; ?>
			</div>

			<!-- Pending Conflicts -->
			<?php if ( ! empty( $pending_conflicts ) ) : ?>
				<div class="vault-card" id="conflicts">
					<h3><?php esc_html_e( 'Pending Conflicts', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p><?php esc_html_e( 'The following items have conflicting changes that need to be resolved:', 'mcp-ai-wpoos-pro' ); ?></p>

					<?php foreach ( $pending_conflicts as $conflict ) : ?>
						<div class="conflict-item">
							<h4><?php echo esc_html( $conflict['local_item']['name'] ?? 'Unknown Item' ); ?></h4>
							<p><strong><?php esc_html_e( 'Detected:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php echo esc_html( $conflict['timestamp'] ); ?></p>

							<div class="conflict-comparison">
								<div class="conflict-local">
									<h5><?php esc_html_e( 'Local Version', 'mcp-ai-wpoos-pro' ); ?></h5>
									<p><?php esc_html_e( 'Modified:', 'mcp-ai-wpoos-pro' ); ?> <?php echo esc_html( $conflict['local_item']['modified'] ?? 'Unknown' ); ?></p>
								</div>
								<div class="conflict-remote">
									<h5><?php esc_html_e( 'Remote Version', 'mcp-ai-wpoos-pro' ); ?></h5>
									<p><?php esc_html_e( 'Modified:', 'mcp-ai-wpoos-pro' ); ?> <?php echo esc_html( $conflict['remote_item']['modified'] ?? 'Unknown' ); ?></p>
								</div>
							</div>

							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 10px;">
								<?php wp_nonce_field( 'wp_mcp_ai_resolve_conflict_' . $conflict['id'], 'wp_mcp_ai_conflict_nonce' ); ?>
								<input type="hidden" name="action" value="wp_mcp_ai_resolve_conflict" />
								<input type="hidden" name="conflict_id" value="<?php echo esc_attr( $conflict['id'] ); ?>" />

								<button type="submit" name="resolution" value="local" class="button"><?php esc_html_e( 'Use Local', 'mcp-ai-wpoos-pro' ); ?></button>
								<button type="submit" name="resolution" value="remote" class="button"><?php esc_html_e( 'Use Remote', 'mcp-ai-wpoos-pro' ); ?></button>
								<button type="submit" name="resolution" value="merge" class="button button-primary"><?php esc_html_e( 'Merge Both', 'mcp-ai-wpoos-pro' ); ?></button>
							</form>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- Help Section -->
			<div class="vault-card">
				<h3><?php esc_html_e( 'About Automatic Sync', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p><?php esc_html_e( 'Automatic background sync keeps your WordPress vault synchronized with your external Bitwarden server at regular intervals using WP-Cron.', 'mcp-ai-wpoos-pro' ); ?></p>
				<h4><?php esc_html_e( 'Conflict Resolution Strategies:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><strong><?php esc_html_e( 'Newest Wins:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'The most recently modified version is kept (recommended for most users)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Local Wins:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Always keep the WordPress version', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Remote Wins:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Always keep the Bitwarden server version', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Merge:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Intelligently combines data from both versions', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Manual:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Review and resolve each conflict individually', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}
