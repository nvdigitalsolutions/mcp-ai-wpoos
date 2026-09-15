<?php
/**
 * Security Monitor Admin Interface
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Security_Monitor_Admin' ) ) {
	/**
	 * Handles admin interface for security monitoring.
	 */
	class WP_MCP_AI_Security_Monitor_Admin {
		/**
		 * Initialize the admin interface.
		 */
		public static function init() {
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'admin_post_wp_mcp_ai_clear_shutdown', array( __CLASS__, 'handle_clear_shutdown' ) );
			add_action( 'admin_post_wp_mcp_ai_clear_violations', array( __CLASS__, 'handle_clear_violations' ) );
			add_action( 'admin_post_wp_mcp_ai_verify_root_key', array( __CLASS__, 'handle_verify_root_key' ) );
			// Register admin_notices on init to avoid early translation loading (WordPress 6.7.0+).
			add_action( 'init', array( __CLASS__, 'register_admin_notices' ) );
			add_filter( 'wp_mcp_ai_admin_settings_sanitize', array( __CLASS__, 'sanitize_monitor_settings' ), 10, 2 );
		}

		/**
		 * Register admin notices on init action.
		 *
		 * WordPress 6.7.0+ requires translations to be loaded at init or later.
		 */
		public static function register_admin_notices() {
			add_action( 'admin_notices', array( __CLASS__, 'display_root_key_notices' ) );
		}

		/**
		 * Register settings fields.
		 */
		public static function register_settings() {
			// Settings are registered via the main settings class hooks.
		}

		/**
		 * Sanitize monitor settings.
		 *
		 * @param array $settings Sanitized settings.
		 * @param array $input    Raw input settings.
		 * @return array
		 */
		public static function sanitize_monitor_settings( $settings, $input ) {
			$monitor = WP_MCP_AI_Nefarious_Usage_Monitor::get_instance();

			// Only update keys that were actually submitted. Saves from other
			// sub-tabs (or other sections) do not include these fields, and a
			// naive merge would silently flip 'enabled' to false on every
			// unrelated settings save.
			$monitor_settings = array();

			if ( isset( $input['wp_mcp_ai_security_monitor_enabled'] ) ) {
				$monitor_settings['enabled'] = ! empty( $input['wp_mcp_ai_security_monitor_enabled'] );
			}

			if ( isset( $input['wp_mcp_ai_security_monitor_auto_shutdown'] ) ) {
				$monitor_settings['auto_shutdown_enabled'] = ! empty( $input['wp_mcp_ai_security_monitor_auto_shutdown'] );
			}

			if ( isset( $input['wp_mcp_ai_security_monitor_max_requests_per_minute'] ) ) {
				$max_requests                                = absint( $input['wp_mcp_ai_security_monitor_max_requests_per_minute'] );
				$monitor_settings['max_requests_per_minute'] = $max_requests > 0 ?
					$max_requests :
					WP_MCP_AI_Nefarious_Usage_Monitor::DEFAULT_MAX_REQUESTS_PER_MINUTE;
			}

			if ( isset( $input['wp_mcp_ai_security_monitor_max_tools_per_hour'] ) ) {
				$max_tools                              = absint( $input['wp_mcp_ai_security_monitor_max_tools_per_hour'] );
				$monitor_settings['max_tools_per_hour'] = $max_tools > 0 ?
					$max_tools :
					WP_MCP_AI_Nefarious_Usage_Monitor::DEFAULT_MAX_TOOLS_PER_HOUR;
			}

			if ( isset( $input['wp_mcp_ai_security_monitor_violation_threshold'] ) ) {
				$threshold                               = absint( $input['wp_mcp_ai_security_monitor_violation_threshold'] );
				$monitor_settings['violation_threshold'] = $threshold > 0 ? $threshold : 5;
			}

			if ( isset( $input['wp_mcp_ai_security_monitor_patterns'] ) ) {
				$monitor_settings['suspicious_patterns'] = self::sanitize_pattern_lines( $input['wp_mcp_ai_security_monitor_patterns'] );
			}

			if ( ! empty( $monitor_settings ) ) {
				$monitor->update_settings( $monitor_settings );
			}

			return $settings;
		}

		/**
		 * Sanitize a newline-separated list of regex patterns.
		 *
		 * Invalid regexes are dropped rather than stored, because a malformed
		 * pattern would later be interpolated into the scan loop. An empty
		 * result falls back to the default pattern set.
		 *
		 * @param string $raw Raw textarea value (one pattern per line).
		 * @return array Valid patterns, or defaults when none survive.
		 */
		public static function sanitize_pattern_lines( $raw ) {
			$monitor  = WP_MCP_AI_Nefarious_Usage_Monitor::get_instance();
			$defaults = $monitor->get_default_suspicious_patterns();

			if ( ! is_string( $raw ) ) {
				return $defaults;
			}

			$lines = preg_split( '/\r\n|\r|\n/', $raw );
			$clean = array();

			foreach ( (array) $lines as $line ) {
				$line = trim( str_replace( "\0", '', (string) $line ) );
				if ( '' === $line ) {
					continue;
				}

				// Probe with the same delimiter the scan loop uses. Returns
				// false for malformed patterns, which must never be stored.
				if ( false === @preg_match( '/' . $line . '/', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Validation probe: result ignored, malformed patterns skipped.
					continue;
				}

				$clean[] = $line;
			}

			return ! empty( $clean ) ? $clean : $defaults;
		}

		/**
		 * Handle clear emergency shutdown request.
		 */
		public static function handle_clear_shutdown() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'mcp-ai-wpoos' ) );
			}

			check_admin_referer( 'wp_mcp_ai_clear_shutdown', 'wp_mcp_ai_clear_shutdown_nonce' );

			$monitor = WP_MCP_AI_Nefarious_Usage_Monitor::get_instance();
			$monitor->clear_emergency_shutdown();

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'wp-mcp-ai-dashboard',
						'tab'     => 'security',
						'cleared' => 'shutdown',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		/**
		 * Handle clear violations request.
		 */
		public static function handle_clear_violations() {
			check_admin_referer( 'wp_mcp_ai_clear_violations', 'wp_mcp_ai_clear_violations_nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'mcp-ai-wpoos' ) );
			}

			$monitor = WP_MCP_AI_Nefarious_Usage_Monitor::get_instance();
			$monitor->clear_violations();

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'wp-mcp-ai-dashboard',
						'tab'     => 'security',
						'cleared' => 'violations',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		/**
		 * Handle root security key verification request.
		 */
		public static function handle_verify_root_key() {
			check_admin_referer( 'wp_mcp_ai_verify_root_key', 'wp_mcp_ai_verify_root_key_nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'mcp-ai-wpoos' ) );
			}

			$security_key = WP_MCP_AI_Root_Security_Key::get_instance();

			// Get the provided key from POST.
			$provided_key = isset( $_POST['wp_mcp_ai_root_key'] ) ? sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_root_key'] ) ) : '';

			if ( empty( $provided_key ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'     => 'wp-mcp-ai-dashboard',
							'tab'      => 'security',
							'root_key' => 'empty',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			// Attempt to disable key requirement.
			$result = $security_key->disable_key_requirement( $provided_key );

			if ( is_wp_error( $result ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'     => 'wp-mcp-ai-dashboard',
							'tab'      => 'security',
							'root_key' => 'invalid',
							'error'    => urlencode( $result->get_error_message() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- urlencode() required for URL parameter encoding in API request.
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'     => 'wp-mcp-ai-dashboard',
						'tab'      => 'security',
						'root_key' => 'verified',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		/**
		 * Display admin notices for root security key status.
		 */
		public static function display_root_key_notices() {
			$security_key = WP_MCP_AI_Root_Security_Key::get_instance();

			// Display root key requirement notice.
			if ( $security_key->is_key_required() ) {
				$status = $security_key->get_status();
				?>
				<div class="notice notice-error is-dismissible">
					<h3><?php esc_html_e( 'NV oOS Root Security Key Required', 'mcp-ai-wpoos' ); ?></h3>
					<p><strong><?php esc_html_e( 'Plugin initialization has been blocked. A root security key is required to unlock the plugin.', 'mcp-ai-wpoos' ); ?></strong></p>

					<?php if ( ! empty( $status['reason'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Reason:', 'mcp-ai-wpoos' ); ?></strong> <?php echo esc_html( $status['reason'] ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $status['enabled_at'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Enabled at:', 'mcp-ai-wpoos' ); ?></strong> <?php echo esc_html( $status['enabled_at'] ); ?></p>
					<?php endif; ?>

					<?php if ( $status['locked_out'] ) : ?>
						<p class="description" style="color: #d63638;">
							<?php esc_html_e( 'Too many failed verification attempts. Please wait 15 minutes before trying again.', 'mcp-ai-wpoos' ); ?>
						</p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'wp_mcp_ai_verify_root_key', 'wp_mcp_ai_verify_root_key_nonce' ); ?>
							<input type="hidden" name="action" value="wp_mcp_ai_verify_root_key" />
							<p>
								<label for="wp_mcp_ai_root_key">
									<?php esc_html_e( 'Enter Root Security Key:', 'mcp-ai-wpoos' ); ?>
								</label><br>
								<input
									type="password"
									id="wp_mcp_ai_root_key"
									name="wp_mcp_ai_root_key"
									class="regular-text"
									autocomplete="off"
									required
								/>
							</p>
							<p>
								<?php submit_button( __( 'Verify and Unlock', 'mcp-ai-wpoos' ), 'primary', 'submit', false ); ?>
							</p>
						</form>
						<p class="description">
							<?php
							printf(
								/* translators: %s: Code snippet */
								esc_html__( 'The root security key is defined in wp-config.php using: %s', 'mcp-ai-wpoos' ),
								'<code>define( \'WP_MCP_AI_ROOT_SECURITY_KEY\', \'your-secure-key\' );</code>'
							);
							?>
						</p>
					<?php endif; ?>
				</div>
				<?php
			}

			// Display verification result messages.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just displaying messages based on GET parameters.
			if ( isset( $_GET['root_key'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just displaying messages based on GET parameters.
				$result = sanitize_text_field( wp_unslash( $_GET['root_key'] ) );

				switch ( $result ) {
					case 'verified':
						?>
						<div class="notice notice-success is-dismissible">
							<p><strong><?php esc_html_e( 'Success!', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Root security key verified. Plugin has been unlocked.', 'mcp-ai-wpoos' ); ?></p>
						</div>
						<?php
						break;

					case 'invalid':
						?>
						<div class="notice notice-error is-dismissible">
							<p><strong><?php esc_html_e( 'Error!', 'mcp-ai-wpoos' ); ?></strong>
							<?php
							// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just displaying error message from previous request.
							if ( isset( $_GET['error'] ) ) {
							// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just displaying error message from previous request.
								echo esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) );
							} else {
								esc_html_e( 'Invalid root security key provided.', 'mcp-ai-wpoos' );
							}
							?>
							</p>
						</div>
						<?php
						break;

					case 'empty':
						?>
						<div class="notice notice-warning is-dismissible">
							<p><?php esc_html_e( 'Please enter a root security key.', 'mcp-ai-wpoos' ); ?></p>
						</div>
						<?php
						break;
				}
			}
		}
	}
}
