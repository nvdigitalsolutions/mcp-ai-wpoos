<?php
/**
 * Pro License Management System
 *
 * Handles Pro license validation, activation, and feature gating.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro License Class
 */
class WP_MCP_AI_Pro_License {

	/**
	 * License server URL
	 *
	 * @var string
	 */
	private $license_server_url;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->license_server_url = apply_filters( 'wp_mcp_ai_license_server_url', 'https://nvdigitalsolutions.com/api/licenses' );

		add_action( 'admin_init', array( $this, 'handle_license_activation' ) );
		add_action( 'admin_notices', array( $this, 'license_notices' ) );
		add_action( 'wp_mcp_ai_check_license', array( $this, 'check_license_validity' ) );

		// Schedule daily license check.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_check_license' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_check_license' );
		}
	}

	/**
	 * Check if Pro is active
	 *
	 * @return bool
	 */
	public static function is_pro_active() {
		// Check for wp-config.php constant first (recommended method).
		if ( defined( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED' ) && WP_MCP_AI_PRO_DASHBOARD_ENABLED ) {
			return true;
		}

		// Allow override via filter for testing/development (backward compatibility).
		$force_pro = apply_filters( 'wp_mcp_ai_pro_dashboard_available', false );
		if ( $force_pro ) {
			return true;
		}

		// Check license status.
		$license_status = get_option( 'wp_mcp_ai_pro_license_status', '' );
		$license_key    = get_option( 'wp_mcp_ai_pro_license_key', '' );

		return 'valid' === $license_status && ! empty( $license_key );
	}

	/**
	 * Get Pro plan
	 *
	 * @return string
	 */
	public static function get_pro_plan() {
		return get_option( 'wp_mcp_ai_pro_plan', 'compliance' );
	}

	/**
	 * Check if feature is available
	 *
	 * @param string $feature Feature name.
	 * @return bool
	 */
	public static function has_feature( $feature ) {
		if ( ! self::is_pro_active() ) {
			return false;
		}

		$plan     = self::get_pro_plan();
		$features = array(
			'compliance'   => array(
				'dashboard',
				'controls_management',
				'reports_basic',
				'audit_trail',
				'evidence_collection',
			),
			'professional' => array(
				'dashboard',
				'controls_management',
				'reports_basic',
				'reports_advanced',
				'audit_trail',
				'evidence_collection',
				'risk_matrix',
				'multi_framework',
				'siem_integration',
			),
			'enterprise'   => array(
				'dashboard',
				'controls_management',
				'reports_basic',
				'reports_advanced',
				'audit_trail',
				'evidence_collection',
				'risk_matrix',
				'multi_framework',
				'siem_integration',
				'white_label',
				'priority_support',
				'custom_development',
			),
		);

		$plan_features = $features[ $plan ] ?? array();

		return in_array( $feature, $plan_features, true );
	}

	/**
	 * Handle license activation
	 *
	 * @return void
	 */
	public function handle_license_activation() {
		if ( ! isset( $_POST['wp_mcp_ai_activate_license'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'wp_mcp_ai_license_activation', 'wp_mcp_ai_license_nonce' );

		$license_key = isset( $_POST['wp_mcp_ai_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_license_key'] ) ) : '';

		if ( empty( $license_key ) ) {
			add_settings_error(
				'wp_mcp_ai_license',
				'empty_license',
				__( 'Please enter a license key.', 'mcp-ai-wpoos' ),
				'error'
			);
			return;
		}

		$result = $this->activate_license( $license_key );

		if ( $result['success'] ) {
			update_option( 'wp_mcp_ai_pro_license_key', $license_key );
			update_option( 'wp_mcp_ai_pro_license_status', 'valid' );
			update_option( 'wp_mcp_ai_pro_plan', $result['plan'] ?? 'compliance' );
			update_option( 'wp_mcp_ai_pro_license_expires', $result['expires'] ?? '' );

			add_settings_error(
				'wp_mcp_ai_license',
				'license_activated',
				__( 'License activated successfully!', 'mcp-ai-wpoos' ),
				'success'
			);
		} else {
			add_settings_error(
				'wp_mcp_ai_license',
				'activation_failed',
				$result['message'] ?? __( 'License activation failed.', 'mcp-ai-wpoos' ),
				'error'
			);
		}
	}

	/**
	 * Activate license with server
	 *
	 * @param string $license_key License key.
	 * @return array
	 */
	private function activate_license( $license_key ) {
		$site_url = get_site_url();

		$response = wp_remote_post(
			$this->license_server_url . '/activate',
			array(
				'timeout' => 15,
				'body'    => array(
					'license_key' => $license_key,
					'site_url'    => $site_url,
					'product'     => 'mcp-ai-wpoos-pro',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! $data || ! isset( $data['success'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid response from license server.', 'mcp-ai-wpoos' ),
			);
		}

		return $data;
	}

	/**
	 * Check license validity
	 *
	 * @return void
	 */
	public function check_license_validity() {
		$license_key = get_option( 'wp_mcp_ai_pro_license_key', '' );

		if ( empty( $license_key ) ) {
			return;
		}

		$site_url = get_site_url();

		$response = wp_remote_post(
			$this->license_server_url . '/check',
			array(
				'timeout' => 15,
				'body'    => array(
					'license_key' => $license_key,
					'site_url'    => $site_url,
					'product'     => 'mcp-ai-wpoos-pro',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $data && isset( $data['status'] ) ) {
			update_option( 'wp_mcp_ai_pro_license_status', $data['status'] );

			if ( 'valid' !== $data['status'] ) {
				update_option( 'wp_mcp_ai_pro_license_expires', '' );
			} elseif ( isset( $data['expires'] ) ) {
				update_option( 'wp_mcp_ai_pro_license_expires', $data['expires'] );
			}
		}
	}

	/**
	 * Deactivate license
	 *
	 * @return array
	 */
	public function deactivate_license() {
		$license_key = get_option( 'wp_mcp_ai_pro_license_key', '' );
		$site_url    = get_site_url();

		$response = wp_remote_post(
			$this->license_server_url . '/deactivate',
			array(
				'timeout' => 15,
				'body'    => array(
					'license_key' => $license_key,
					'site_url'    => $site_url,
					'product'     => 'mcp-ai-wpoos-pro',
				),
			)
		);

		delete_option( 'wp_mcp_ai_pro_license_key' );
		delete_option( 'wp_mcp_ai_pro_license_status' );
		delete_option( 'wp_mcp_ai_pro_plan' );
		delete_option( 'wp_mcp_ai_pro_license_expires' );

		return array( 'success' => true );
	}

	/**
	 * Display license notices
	 *
	 * @return void
	 */
	public function license_notices() {
		$license_status  = get_option( 'wp_mcp_ai_pro_license_status', '' );
		$license_expires = get_option( 'wp_mcp_ai_pro_license_expires', '' );

		// Expiring soon notice.
		if ( 'valid' === $license_status && ! empty( $license_expires ) ) {
			$expires_timestamp = strtotime( $license_expires );
			$days_until_expiry = floor( ( $expires_timestamp - time() ) / DAY_IN_SECONDS );

			if ( $days_until_expiry > 0 && $days_until_expiry <= 30 ) {
				?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %d: number of days */
							esc_html__( 'Your NV oOS Pro license expires in %d days. Please renew to continue using Pro features.', 'mcp-ai-wpoos' ),
							(int) $days_until_expiry
						);
						?>
					</p>
				</div>
				<?php
			}
		}

		// Expired license notice.
		if ( 'expired' === $license_status ) {
			?>
			<div class="notice notice-error">
				<p>
					<?php esc_html_e( 'Your NV oOS Pro license has expired. Pro features are disabled. Please renew your license.', 'mcp-ai-wpoos' ); ?>
					<a href="<?php echo esc_url( apply_filters( 'wp_mcp_ai_pro_upgrade_url', 'https://nvdigitalsolutions.com/renew' ) ); ?>" class="button button-primary" style="margin-left: 10px;">
						<?php esc_html_e( 'Renew License', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>
			</div>
			<?php
		}

		// Invalid license notice.
		if ( 'invalid' === $license_status ) {
			?>
			<div class="notice notice-error">
				<p>
					<?php esc_html_e( 'Your NV oOS Pro license is invalid. Pro features are disabled.', 'mcp-ai-wpoos' ); ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Render license activation form
	 *
	 * @return void
	 */
	public static function render_license_form() {
		$license_key    = get_option( 'wp_mcp_ai_pro_license_key', '' );
		$license_status = get_option( 'wp_mcp_ai_pro_license_status', '' );
		$plan           = self::get_pro_plan();
		$expires        = get_option( 'wp_mcp_ai_pro_license_expires', '' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'NV oOS Pro License', 'mcp-ai-wpoos' ); ?></h1>

			<?php settings_errors( 'wp_mcp_ai_license' ); ?>

			<?php if ( 'valid' === $license_status ) : ?>
				<div class="notice notice-success inline">
					<p>
						<strong><?php esc_html_e( 'Pro License Active', 'mcp-ai-wpoos' ); ?></strong><br>
						<?php
						printf(
							/* translators: %s: plan name */
							esc_html__( 'Plan: %s', 'mcp-ai-wpoos' ),
							'<strong>' . esc_html( ucfirst( $plan ) ) . '</strong>'
						);
						?>
						<br>
						<?php
						if ( ! empty( $expires ) ) {
							printf(
								/* translators: %s: expiration date */
								esc_html__( 'Expires: %s', 'mcp-ai-wpoos' ),
								esc_html( date_i18n( get_option( 'date_format' ), strtotime( $expires ) ) )
							);
						}
						?>
					</p>
				</div>

				<form method="post" action="">
					<?php wp_nonce_field( 'wp_mcp_ai_license_deactivation', 'wp_mcp_ai_license_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'License Key', 'mcp-ai-wpoos' ); ?></th>
							<td>
								<code><?php echo esc_html( str_repeat( '*', strlen( $license_key ) - 8 ) . substr( $license_key, -8 ) ); ?></code>
							</td>
						</tr>
					</table>
					<p class="submit">
						<button type="submit" name="wp_mcp_ai_deactivate_license" class="button button-secondary">
							<?php esc_html_e( 'Deactivate License', 'mcp-ai-wpoos' ); ?>
						</button>
					</p>
				</form>
			<?php else : ?>
				<form method="post" action="">
					<?php wp_nonce_field( 'wp_mcp_ai_license_activation', 'wp_mcp_ai_license_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_license_key"><?php esc_html_e( 'License Key', 'mcp-ai-wpoos' ); ?></label>
							</th>
							<td>
								<input type="text"
									id="wp_mcp_ai_license_key"
									name="wp_mcp_ai_license_key"
									class="regular-text"
									value="<?php echo esc_attr( $license_key ); ?>"
									placeholder="XXXX-XXXX-XXXX-XXXX" />
								<p class="description">
									<?php
									printf(
										/* translators: %s: purchase URL */
										wp_kses_post( __( 'Enter your license key. Don\'t have one? <a href="%s" target="_blank">Purchase a license</a>', 'mcp-ai-wpoos' ) ),
										esc_url( apply_filters( 'wp_mcp_ai_pro_upgrade_url', 'https://nvdigitalsolutions.com/pricing' ) )
									);
									?>
								</p>
							</td>
						</tr>
					</table>
					<p class="submit">
						<button type="submit" name="wp_mcp_ai_activate_license" class="button button-primary">
							<?php esc_html_e( 'Activate License', 'mcp-ai-wpoos' ); ?>
						</button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}

// Initialize license system.
new WP_MCP_AI_Pro_License();
