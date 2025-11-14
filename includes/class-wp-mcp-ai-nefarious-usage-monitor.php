<?php
/**
 * Nefarious Usage Monitor for WP oOS.
 *
 * Detects suspicious usage patterns and automatically disables tools to protect the site.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Nefarious_Usage_Monitor' ) ) {
	/**
	 * Monitors plugin usage for suspicious patterns and can auto-disable tools.
	 */
	class WP_MCP_AI_Nefarious_Usage_Monitor {
		/**
		 * Option key for storing monitor settings.
		 */
		const SETTINGS_OPTION = 'wp_mcp_ai_nefarious_monitor_settings';

		/**
		 * Option key for storing detected violations.
		 */
		const VIOLATIONS_OPTION = 'wp_mcp_ai_nefarious_violations';

		/**
		 * Option key for emergency shutdown state.
		 */
		const SHUTDOWN_OPTION = 'wp_mcp_ai_emergency_shutdown';

		/**
		 * Transient key for rate limiting tracking.
		 */
		const RATE_LIMIT_TRANSIENT = 'wp_mcp_ai_rate_limit_';

		/**
		 * Maximum allowed requests per minute (default threshold).
		 */
		const DEFAULT_MAX_REQUESTS_PER_MINUTE = 60;

		/**
		 * Maximum allowed tool executions per hour (default threshold).
		 */
		const DEFAULT_MAX_TOOLS_PER_HOUR = 500;

		/**
		 * Singleton instance.
		 *
		 * @var WP_MCP_AI_Nefarious_Usage_Monitor|null
		 */
		private static $instance = null;

		/**
		 * Whether monitoring is enabled.
		 *
		 * @var bool
		 */
		private $enabled = true;

		/**
		 * Whether auto-shutdown is enabled.
		 *
		 * @var bool
		 */
		private $auto_shutdown_enabled = true;

		/**
		 * Current settings.
		 *
		 * @var array
		 */
		private $settings = array();

		/**
		 * Get singleton instance.
		 *
		 * @return WP_MCP_AI_Nefarious_Usage_Monitor
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor.
		 */
		private function __construct() {
			$this->load_settings();
			$this->enabled               = ! empty( $this->settings['enabled'] );
			$this->auto_shutdown_enabled = ! empty( $this->settings['auto_shutdown_enabled'] );
		}

		/**
		 * Initialize the monitor.
		 */
		public function init() {
			if ( ! $this->enabled ) {
				return;
			}

			// Hook early into tool execution.
			add_filter( 'wp_mcp_ai_can_execute_tool', array( $this, 'check_tool_execution' ), 1, 3 );
			add_action( 'wp_mcp_ai_tool_executed', array( $this, 'monitor_tool_execution' ), 10, 4 );

			// Hook into chat requests.
			add_action( 'wp_mcp_ai_before_chat_request', array( $this, 'monitor_chat_request' ), 1, 2 );

			// Register admin_notices on init to avoid early translation loading (WordPress 6.7.0+).
			add_action( 'init', array( $this, 'register_admin_notices' ) );
		}

		/**
		 * Register admin notices on init action.
		 *
		 * WordPress 6.7.0+ requires translations to be loaded at init or later.
		 */
		public function register_admin_notices() {
			add_action( 'admin_notices', array( $this, 'display_violation_notices' ) );
		}

		/**
		 * Load monitor settings.
		 */
		private function load_settings() {
			$defaults = array(
				'enabled'                 => true,
				'auto_shutdown_enabled'   => true,
				'max_requests_per_minute' => self::DEFAULT_MAX_REQUESTS_PER_MINUTE,
				'max_tools_per_hour'      => self::DEFAULT_MAX_TOOLS_PER_HOUR,
				'suspicious_patterns'     => $this->get_default_suspicious_patterns(),
				'violation_threshold'     => 5, // Auto-shutdown after this many violations.
				'notify_admin_email'      => true,
			);

			$saved_settings = get_option( self::SETTINGS_OPTION, array() );
			$this->settings = wp_parse_args( $saved_settings, $defaults );
		}

		/**
		 * Get default suspicious content patterns.
		 *
		 * @return array
		 */
		private function get_default_suspicious_patterns() {
			return array(
				// Phishing patterns.
				'verify.*account.*immediately',
				'suspended.*account.*click',
				'urgent.*action.*required',
				'confirm.*identity.*now',

				// Credential harvesting.
				'enter.*password.*below',
				'provide.*credit.*card',
				'social.*security.*number',

				// Malware/injection patterns.
				'<script[^>]*>',
				'eval\s*\(',
				'base64_decode',
				'system\s*\(',
				'exec\s*\(',
				'shell_exec',

				// Spam patterns.
				'buy.*now.*limited.*time',
				'click.*here.*free.*money',
				'congratulations.*won',

				// SQL injection attempts.
				'union.*select.*from',
				'drop.*table',
				'delete.*from.*where',
			);
		}

		/**
		 * Check if tool execution should be allowed.
		 *
		 * @param bool   $can_execute Whether tool can execute.
		 * @param string $tool_slug   Tool slug.
		 * @param array  $context     Execution context.
		 * @return bool
		 */
		public function check_tool_execution( $can_execute, $tool_slug, $context ) {
			if ( ! $can_execute || ! $this->enabled ) {
				return $can_execute;
			}

			// Check if emergency shutdown is active.
			if ( $this->is_emergency_shutdown_active() ) {
				WP_MCP_AI_Logger::log_event(
					'security_blocked',
					sprintf( 'Tool execution blocked due to emergency shutdown: %s', $tool_slug ),
					array( 'tool' => $tool_slug )
				);
				return false;
			}

			// Check rate limits.
			if ( ! $this->check_rate_limits( $context ) ) {
				$this->record_violation(
					'rate_limit_exceeded',
					sprintf( 'Rate limit exceeded for tool: %s', $tool_slug ),
					array(
						'tool'    => $tool_slug,
						'context' => $context,
					)
				);
				return false;
			}

			return $can_execute;
		}

		/**
		 * Monitor tool execution for suspicious patterns.
		 *
		 * @param string $tool_slug Tool slug.
		 * @param array  $arguments Tool arguments.
		 * @param mixed  $result    Execution result.
		 * @param array  $context   Execution context.
		 */
		public function monitor_tool_execution( $tool_slug, $arguments, $result, $context ) {
			if ( ! $this->enabled ) {
				return;
			}

			// Track tool usage.
			$this->increment_tool_usage_counter();

			// Check for suspicious content in arguments.
			$suspicious_content = $this->scan_for_suspicious_content( $arguments );
			if ( ! empty( $suspicious_content ) ) {
				$this->record_violation(
					'suspicious_content',
					sprintf( 'Suspicious content detected in tool arguments: %s', $tool_slug ),
					array(
						'tool'     => $tool_slug,
						'patterns' => $suspicious_content,
						'context'  => $context,
					)
				);
			}

			// Check for suspicious tool combinations (e.g., rapid fire email sending).
			if ( in_array( $tool_slug, array( 'send_group_email', 'send_mailjet_email', 'send_whatsapp_message' ), true ) ) {
				if ( ! $this->check_messaging_tool_limits() ) {
					$this->record_violation(
						'messaging_abuse',
						sprintf( 'Excessive messaging tool usage detected: %s', $tool_slug ),
						array( 'tool' => $tool_slug )
					);
				}
			}
		}

		/**
		 * Monitor chat requests for suspicious patterns.
		 *
		 * @param array $messages     Chat messages.
		 * @param array $request_data Request data.
		 */
		public function monitor_chat_request( $messages, $request_data ) {
			if ( ! $this->enabled ) {
				return;
			}

			// Check if emergency shutdown is active.
			if ( $this->is_emergency_shutdown_active() ) {
				wp_die(
					esc_html__( 'AI Assistant services have been temporarily disabled due to suspicious activity. Please contact the site administrator.', 'wp-mcp-ai' ),
					esc_html__( 'Service Unavailable', 'wp-mcp-ai' ),
					array( 'response' => 503 )
				);
			}

			// Scan chat messages for suspicious content.
			foreach ( $messages as $message ) {
				if ( ! empty( $message['content'] ) ) {
					$suspicious_content = $this->scan_for_suspicious_content( $message['content'] );
					if ( ! empty( $suspicious_content ) ) {
						$this->record_violation(
							'suspicious_chat_content',
							'Suspicious content detected in chat message',
							array(
								'patterns'     => $suspicious_content,
								'message_role' => isset( $message['role'] ) ? $message['role'] : 'unknown',
							)
						);
					}
				}
			}

			// Track request rate.
			$this->increment_request_counter();
		}

		/**
		 * Scan content for suspicious patterns.
		 *
		 * @param mixed $content Content to scan (string, array, or object).
		 * @return array Matched patterns.
		 */
		private function scan_for_suspicious_content( $content ) {
			$matched_patterns = array();
			$patterns         = $this->settings['suspicious_patterns'];

			// Convert content to searchable text.
			$text = '';
			if ( is_string( $content ) ) {
				$text = $content;
			} elseif ( is_array( $content ) || is_object( $content ) ) {
				$text = wp_json_encode( $content );
			}

			if ( empty( $text ) ) {
				return $matched_patterns;
			}

			// Check against patterns.
			foreach ( $patterns as $pattern ) {
				if ( preg_match( '/' . $pattern . '/i', $text ) ) {
					$matched_patterns[] = $pattern;
				}
			}

			return $matched_patterns;
		}

		/**
		 * Check rate limits.
		 *
		 * @param array $context Execution context.
		 * @return bool Whether rate limits are satisfied.
		 */
		private function check_rate_limits( $context ) {
			$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
			$key     = $user_id > 0 ? 'user_' . $user_id : 'guest';

			$transient_key = self::RATE_LIMIT_TRANSIENT . $key;
			$request_count = get_transient( $transient_key );

			if ( false === $request_count ) {
				$request_count = 0;
			}

			$max_requests = absint( $this->settings['max_requests_per_minute'] );

			return $request_count < $max_requests;
		}

		/**
		 * Check messaging tool limits.
		 *
		 * @return bool Whether messaging limits are satisfied.
		 */
		private function check_messaging_tool_limits() {
			$transient_key = 'wp_mcp_ai_messaging_count';
			$count         = get_transient( $transient_key );

			if ( false === $count ) {
				$count = 0;
			}

			// Allow max 10 messaging tool calls per minute.
			return $count < 10;
		}

		/**
		 * Increment request counter.
		 */
		private function increment_request_counter() {
			$user_id = get_current_user_id();
			$key     = $user_id > 0 ? 'user_' . $user_id : 'guest';

			$transient_key = self::RATE_LIMIT_TRANSIENT . $key;
			$count         = get_transient( $transient_key );

			if ( false === $count ) {
				$count = 0;
			}

			set_transient( $transient_key, $count + 1, MINUTE_IN_SECONDS );
		}

		/**
		 * Increment tool usage counter.
		 */
		private function increment_tool_usage_counter() {
			$transient_key = 'wp_mcp_ai_tool_usage_count';
			$count         = get_transient( $transient_key );

			if ( false === $count ) {
				$count = 0;
			}

			set_transient( $transient_key, $count + 1, HOUR_IN_SECONDS );

			// Check if exceeded hourly limit.
			$max_tools = absint( $this->settings['max_tools_per_hour'] );
			if ( $count >= $max_tools ) {
				$this->record_violation(
					'tool_usage_limit_exceeded',
					'Hourly tool usage limit exceeded',
					array( 'count' => $count )
				);
			}
		}

		/**
		 * Record a security violation.
		 *
		 * @param string $type        Violation type.
		 * @param string $message     Violation message.
		 * @param array  $details     Additional details.
		 */
		private function record_violation( $type, $message, $details = array() ) {
			$violations = get_option( self::VIOLATIONS_OPTION, array() );

			$violation = array(
				'type'      => sanitize_key( $type ),
				'message'   => sanitize_text_field( $message ),
				'details'   => $details,
				'timestamp' => current_time( 'mysql', true ),
				'user_id'   => get_current_user_id(),
				'ip'        => $this->get_client_ip(),
			);

			$violations[] = $violation;

			// Keep only last 100 violations.
			if ( count( $violations ) > 100 ) {
				$violations = array_slice( $violations, -100 );
			}

			update_option( self::VIOLATIONS_OPTION, $violations, false );

			// Log the violation.
			WP_MCP_AI_Logger::log_event(
				'security_violation',
				$message,
				array_merge( $details, array( 'violation_type' => $type ) )
			);

			// Check if we should trigger auto-shutdown.
			if ( $this->auto_shutdown_enabled ) {
				$recent_violations = $this->count_recent_violations( 5 * MINUTE_IN_SECONDS );
				$threshold         = absint( $this->settings['violation_threshold'] );

				if ( $recent_violations >= $threshold ) {
					$this->trigger_emergency_shutdown( $violation );
				}
			}
		}

		/**
		 * Count recent violations within a time window.
		 *
		 * @param int $seconds Time window in seconds.
		 * @return int Number of violations.
		 */
		private function count_recent_violations( $seconds ) {
			$violations = get_option( self::VIOLATIONS_OPTION, array() );
			$cutoff     = time() - $seconds;
			$count      = 0;

			foreach ( $violations as $violation ) {
				$timestamp = strtotime( $violation['timestamp'] );
				if ( $timestamp >= $cutoff ) {
					++$count;
				}
			}

			return $count;
		}

		/**
		 * Trigger emergency shutdown.
		 *
		 * @param array $triggering_violation The violation that triggered shutdown.
		 */
		private function trigger_emergency_shutdown( $triggering_violation ) {
			update_option(
				self::SHUTDOWN_OPTION,
				array(
					'active'               => true,
					'triggered_at'         => current_time( 'mysql', true ),
					'triggering_violation' => $triggering_violation,
				),
				false
			);

			// Enable root security key requirement if configured.
			$security_key = WP_MCP_AI_Root_Security_Key::get_instance();
			if ( $security_key->is_key_configured() ) {
				$security_key->enable_key_requirement( 'Emergency shutdown triggered by security monitor' );
			}

			WP_MCP_AI_Logger::log_event(
				'emergency_shutdown',
				'Emergency shutdown triggered due to excessive security violations',
				array( 'violation' => $triggering_violation )
			);

			// Send admin notification if enabled.
			if ( ! empty( $this->settings['notify_admin_email'] ) ) {
				$this->send_admin_notification( $triggering_violation );
			}
		}

		/**
		 * Check if emergency shutdown is active.
		 *
		 * @return bool
		 */
		public function is_emergency_shutdown_active() {
			$shutdown = get_option( self::SHUTDOWN_OPTION, array() );
			return ! empty( $shutdown['active'] );
		}

		/**
		 * Clear emergency shutdown.
		 */
		public function clear_emergency_shutdown() {
			delete_option( self::SHUTDOWN_OPTION );
			WP_MCP_AI_Logger::log_event(
				'emergency_shutdown_cleared',
				'Emergency shutdown cleared by administrator',
				array( 'user_id' => get_current_user_id() )
			);
		}

		/**
		 * Get client IP address.
		 *
		 * @return string
		 */
		private function get_client_ip() {
			$ip = '';

			if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			}

			return $ip;
		}

		/**
		 * Send admin notification email.
		 *
		 * @param array $violation Violation details.
		 */
		private function send_admin_notification( $violation ) {
			$admin_email = get_option( 'admin_email' );
			$site_name   = get_bloginfo( 'name' );

			$subject = sprintf(
				/* translators: %s: Site name */
				__( '[%s] WP oOS Emergency Shutdown Activated', 'wp-mcp-ai' ),
				$site_name
			);

			$message = sprintf(
				/* translators: 1: Site URL, 2: Violation type, 3: Violation message */
				__(
					"The WP Open Operator System has been automatically shut down due to suspicious activity.\n\nSite: %1\$s\nViolation Type: %2\$s\nMessage: %3\$s\n\nPlease review the security logs and clear the shutdown from the plugin settings page if this was a false positive.",
					'wp-mcp-ai'
				),
				home_url(),
				$violation['type'],
				$violation['message']
			);

			wp_mail( $admin_email, $subject, $message );
		}

		/**
		 * Display admin notices for violations.
		 */
		public function display_violation_notices() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( $this->is_emergency_shutdown_active() ) {
				$shutdown = get_option( self::SHUTDOWN_OPTION, array() );
				?>
				<div class="notice notice-error is-dismissible">
					<p>
						<strong><?php esc_html_e( 'WP Open Operator System: Emergency Shutdown Active', 'wp-mcp-ai' ); ?></strong>
					</p>
					<p>
						<?php
						printf(
							/* translators: %s: Settings page URL */
							esc_html__( 'The AI Assistant has been automatically disabled due to suspicious activity. %s', 'wp-mcp-ai' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=security' ) ) . '">' . esc_html__( 'Review and clear shutdown', 'wp-mcp-ai' ) . '</a>'
						);
						?>
					</p>
					<?php if ( ! empty( $shutdown['triggering_violation']['message'] ) ) : ?>
						<p>
							<em><?php echo esc_html( $shutdown['triggering_violation']['message'] ); ?></em>
						</p>
					<?php endif; ?>
				</div>
				<?php
			} else {
				// Show warning for recent violations.
				$recent_violations = $this->count_recent_violations( HOUR_IN_SECONDS );
				if ( $recent_violations > 0 ) {
					?>
					<div class="notice notice-warning">
						<p>
							<strong><?php esc_html_e( 'WP Open Operator System: Security Violations Detected', 'wp-mcp-ai' ); ?></strong>
						</p>
						<p>
							<?php
							printf(
								/* translators: 1: Number of violations, 2: Settings page URL */
								esc_html( _n( '%1$d security violation detected in the past hour. %2$s', '%1$d security violations detected in the past hour. %2$s', $recent_violations, 'wp-mcp-ai' ) ),
								absint( $recent_violations ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=security' ) ) . '">' . esc_html__( 'View details', 'wp-mcp-ai' ) . '</a>'
							);
							?>
						</p>
					</div>
					<?php
				}
			}
		}

		/**
		 * Get all recorded violations.
		 *
		 * @return array
		 */
		public function get_violations() {
			return get_option( self::VIOLATIONS_OPTION, array() );
		}

		/**
		 * Clear all recorded violations.
		 */
		public function clear_violations() {
			delete_option( self::VIOLATIONS_OPTION );
			WP_MCP_AI_Logger::log_event(
				'violations_cleared',
				'Security violations cleared by administrator',
				array( 'user_id' => get_current_user_id() )
			);
		}

		/**
		 * Get current settings.
		 *
		 * @return array
		 */
		public function get_settings() {
			return $this->settings;
		}

		/**
		 * Update settings.
		 *
		 * @param array $settings New settings.
		 */
		public function update_settings( $settings ) {
			$this->settings = wp_parse_args( $settings, $this->settings );
			update_option( self::SETTINGS_OPTION, $this->settings, false );
			$this->enabled               = ! empty( $this->settings['enabled'] );
			$this->auto_shutdown_enabled = ! empty( $this->settings['auto_shutdown_enabled'] );
		}
	}
}
