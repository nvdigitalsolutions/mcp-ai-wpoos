<?php
/**
 * Password Vault Manager Admin Page
 *
 * Provides admin interface for password vault management with dedicated sections for:
 * - Vault Items Management
 * - Password Generator & Authenticator
 * - Security Settings
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
		add_action( 'admin_post_vault_generate_password', array( $this, 'handle_generate_password' ) );
		add_action( 'admin_post_vault_generate_totp_secret', array( $this, 'handle_generate_totp_secret' ) );
	}

	/**
	 * Add admin menu page under NV oOS Pro menu.
	 *
	 * @since 1.3.0
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Password Vault', 'mcp-ai-wpoos-pro' ),
			__( 'Password Vault', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'wp-mcp-ai-password-vault',
			array( $this, 'render_admin_page' )
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
		if ( 'nv-oos-pro_page_wp-mcp-ai-password-vault' !== $hook ) {
			return;
		}

		// Enqueue WordPress color picker.
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		// Enqueue custom styles.
		wp_enqueue_style(
			'wp-mcp-ai-password-vault',
			WP_MCP_AI_PRO_URL . 'assets/css/password-vault-admin.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);

		// Enqueue custom scripts.
		wp_enqueue_script(
			'wp-mcp-ai-password-vault',
			WP_MCP_AI_PRO_URL . 'assets/js/password-vault-admin.js',
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
					'copy_success'         => __( 'Copied to clipboard!', 'mcp-ai-wpoos-pro' ),
					'copy_failed'          => __( 'Failed to copy.', 'mcp-ai-wpoos-pro' ),
					'password_generated'   => __( 'Password generated successfully!', 'mcp-ai-wpoos-pro' ),
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
				'secret' => $secret,
				'qr_uri' => $qr_uri,
			)
		);
	}

	/**
	 * Render admin page.
	 *
	 * @since 1.3.0
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'vault';
		$settings   = get_option( 'wp_mcp_ai_vault_settings', array() );

		?>
		<div class="wrap wp-mcp-ai-vault-admin">
			<h1><?php esc_html_e( 'Password Vault Manager', 'mcp-ai-wpoos-pro' ); ?></h1>

			<nav class="nav-tab-wrapper wp-clearfix">
				<a href="?page=wp-mcp-ai-password-vault&tab=vault" class="nav-tab <?php echo 'vault' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Vault Items', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="?page=wp-mcp-ai-password-vault&tab=generator" class="nav-tab <?php echo 'generator' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Password Generator & Authenticator', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="?page=wp-mcp-ai-password-vault&tab=settings" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Security Settings', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</nav>

			<div class="tab-content">
				<?php
				switch ( $active_tab ) {
					case 'generator':
						$this->render_generator_tab( $settings );
						break;
					case 'settings':
						$this->render_settings_tab( $settings );
						break;
					case 'vault':
					default:
						$this->render_vault_tab();
						break;
				}
				?>
			</div>
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
}
