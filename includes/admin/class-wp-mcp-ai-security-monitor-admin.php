<?php
/**
 * Security Monitor Admin Interface
 *
 *
 * @package WP_MCP_AI
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

			// Update monitor settings from form input.
			$monitor_settings = array(
				'enabled'                 => ! empty( $input['wp_mcp_ai_security_monitor_enabled'] ),
				'auto_shutdown_enabled'   => ! empty( $input['wp_mcp_ai_security_monitor_auto_shutdown'] ),
				'max_requests_per_minute' => isset( $input['wp_mcp_ai_security_monitor_max_requests_per_minute'] ) ?
					absint( $input['wp_mcp_ai_security_monitor_max_requests_per_minute'] ) :
					WP_MCP_AI_Nefarious_Usage_Monitor::DEFAULT_MAX_REQUESTS_PER_MINUTE,
				'max_tools_per_hour'      => isset( $input['wp_mcp_ai_security_monitor_max_tools_per_hour'] ) ?
					absint( $input['wp_mcp_ai_security_monitor_max_tools_per_hour'] ) :
					WP_MCP_AI_Nefarious_Usage_Monitor::DEFAULT_MAX_TOOLS_PER_HOUR,
				'violation_threshold'     => isset( $input['wp_mcp_ai_security_monitor_violation_threshold'] ) ?
					absint( $input['wp_mcp_ai_security_monitor_violation_threshold'] ) :
					5,
			);

			$monitor->update_settings( $monitor_settings );

			return $settings;
		}

		/**
		 * Handle clear emergency shutdown request.
		 */
		public static function handle_clear_shutdown() {
			check_admin_referer( 'wp_mcp_ai_clear_shutdown', 'wp_mcp_ai_clear_shutdown_nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-mcp-ai' ) );
			}

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
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-mcp-ai' ) );
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
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-mcp-ai' ) );
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
							'error'    => urlencode( $result->get_error_message() ),
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
					<h3><?php esc_html_e( 'WP oOS Root Security Key Required', 'wp-mcp-ai' ); ?></h3>
					<p><strong><?php esc_html_e( 'Plugin initialization has been blocked. A root security key is required to unlock the plugin.', 'wp-mcp-ai' ); ?></strong></p>
					
					<?php if ( ! empty( $status['reason'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Reason:', 'wp-mcp-ai' ); ?></strong> <?php echo esc_html( $status['reason'] ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $status['enabled_at'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Enabled at:', 'wp-mcp-ai' ); ?></strong> <?php echo esc_html( $status['enabled_at'] ); ?></p>
					<?php endif; ?>

					<?php if ( $status['locked_out'] ) : ?>
						<p class="description" style="color: #d63638;">
							<?php esc_html_e( 'Too many failed verification attempts. Please wait 15 minutes before trying again.', 'wp-mcp-ai' ); ?>
						</p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'wp_mcp_ai_verify_root_key', 'wp_mcp_ai_verify_root_key_nonce' ); ?>
							<input type="hidden" name="action" value="wp_mcp_ai_verify_root_key" />
							<p>
								<label for="wp_mcp_ai_root_key">
									<?php esc_html_e( 'Enter Root Security Key:', 'wp-mcp-ai' ); ?>
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
								<?php submit_button( __( 'Verify and Unlock', 'wp-mcp-ai' ), 'primary', 'submit', false ); ?>
							</p>
						</form>
						<p class="description">
							<?php
							printf(
								/* translators: %s: Code snippet */
								esc_html__( 'The root security key is defined in wp-config.php using: %s', 'wp-mcp-ai' ),
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
							<p><strong><?php esc_html_e( 'Success!', 'wp-mcp-ai' ); ?></strong> <?php esc_html_e( 'Root security key verified. Plugin has been unlocked.', 'wp-mcp-ai' ); ?></p>
						</div>
						<?php
						break;

					case 'invalid':
						?>
						<div class="notice notice-error is-dismissible">
							<p><strong><?php esc_html_e( 'Error!', 'wp-mcp-ai' ); ?></strong> 
							<?php
							// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just displaying error message from previous request.
							if ( isset( $_GET['error'] ) ) {
								echo esc_html( urldecode( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) );
							} else {
								esc_html_e( 'Invalid root security key provided.', 'wp-mcp-ai' );
							}
							?>
							</p>
						</div>
						<?php
						break;

					case 'empty':
						?>
						<div class="notice notice-warning is-dismissible">
							<p><?php esc_html_e( 'Please enter a root security key.', 'wp-mcp-ai' ); ?></p>
						</div>
						<?php
						break;
				}
			}
		}
	}
}
