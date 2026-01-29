<?php
/**
 * Enhanced Security Settings Section
 *
 * Comprehensive security controls based on OWASP, GDPR, SOC 2,
 * and WordPress security best practices (2024).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Security' ) ) {
	/**
	 * Enhanced security settings section with comprehensive controls.
	 */
	class WP_MCP_AI_Section_Security extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'security';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Security Settings', 'mcp-ai-wpoos' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'security';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure comprehensive access controls, authentication requirements, rate limiting, and advanced security features to protect your NV oOS installation. These settings follow OWASP, GDPR, and SOC 2 security best practices.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/features/security/SECURITY_HARDENING.md';
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			$roles = wp_roles()->get_names();

			return array(
				// ========================================
				// GLOBAL ACCESS CONTROL
				// ========================================
				'_heading_global_access'                   => array(
					'type'  => 'heading',
					'label' => __( 'Global Access Control', 'mcp-ai-wpoos' ),
				),
				'require_authentication_all'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Require Authentication for All Access', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Block all unauthenticated access to REST API and media files', 'mcp-ai-wpoos' ),
					'description'    => __( '<strong>Master Security Switch:</strong> When enabled, all REST API endpoints and uploaded media files require authentication. Only logged-in users or those with valid bearer tokens can access the system. <strong>Warning:</strong> This will block guest access and public integrations.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'allow_guest_access'                       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Guest Access (with Tokens)', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Permit guest tokens when authentication is required', 'mcp-ai-wpoos' ),
					'description'    => __( 'When global authentication is enabled, this allows guest tokens (X-WP-MCP-AI-Guest header) to access endpoints that explicitly allow guests.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'bypass_auth_for_logged_in'                => array(
					'type'           => 'checkbox',
					'label'          => __( 'Bypass Checks for Logged-in Users', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Automatically allow access for WordPress logged-in users', 'mcp-ai-wpoos' ),
					'description'    => __( 'Logged-in WordPress users bypass additional authentication checks. Disable this to require explicit bearer tokens even for logged-in users.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),

				// ========================================
				// REST API ENDPOINT PROTECTION
				// ========================================
				'_heading_rest_api'                        => array(
					'type'  => 'heading',
					'label' => __( 'REST API Endpoint Protection', 'mcp-ai-wpoos' ),
				),
				'require_auth_chat_endpoints'              => array(
					'type'           => 'checkbox',
					'label'          => __( 'Protect Chat Endpoints', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Require authentication for /chat and /chat-client endpoints', 'mcp-ai-wpoos' ),
					'description'    => __( 'Protects chat endpoints from unauthenticated access.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'require_auth_tool_execution'              => array(
					'type'           => 'checkbox',
					'label'          => __( 'Protect Tool Execution', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Require authentication for tool execution endpoints', 'mcp-ai-wpoos' ),
					'description'    => __( 'Prevents unauthenticated users from executing tools.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'require_auth_assistant_management'        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Protect Assistant Management', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Require authentication for assistant CRUD operations', 'mcp-ai-wpoos' ),
					'description'    => __( 'Protects assistant creation, reading, updating, and deletion.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'require_auth_transcripts'                 => array(
					'type'           => 'checkbox',
					'label'          => __( 'Protect Chat Transcripts', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Require authentication for transcript access', 'mcp-ai-wpoos' ),
					'description'    => __( 'Prevents unauthenticated access to chat history and transcripts.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'require_auth_file_operations'             => array(
					'type'           => 'checkbox',
					'label'          => __( 'Protect File Operations', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Require authentication for file upload and download endpoints', 'mcp-ai-wpoos' ),
					'description'    => __( 'Protects file upload and download REST endpoints.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// ========================================
				// MEDIA & FILE PROTECTION
				// ========================================
				'_heading_media_protection'                => array(
					'type'  => 'heading',
					'label' => __( 'Media & File Protection', 'mcp-ai-wpoos' ),
				),
				'protect_media_urls'                       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Protect Direct Media URLs', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Require authentication for direct wp-content/uploads access', 'mcp-ai-wpoos' ),
					'description'    => __( '<strong>Advanced:</strong> Intercepts direct media file requests and requires authentication. May impact performance. Requires rewrite rules or server configuration. See documentation for .htaccess configuration.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'protect_attachment_pages'                 => array(
					'type'           => 'checkbox',
					'label'          => __( 'Protect Attachment Pages', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Require authentication to view attachment pages', 'mcp-ai-wpoos' ),
					'description'    => __( 'Blocks unauthenticated access to WordPress attachment pages.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'allow_public_thumbnails'                  => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Public Thumbnails', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Allow unauthenticated access to thumbnail images', 'mcp-ai-wpoos' ),
					'description'    => __( 'When media protection is enabled, allows public access to thumbnails (thumbnail, medium, medium_large) while protecting full-size images.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'protected_file_extensions'                => array(
					'type'        => 'text',
					'label'       => __( 'Protected File Extensions', 'mcp-ai-wpoos' ),
					'description' => __( 'Comma-separated list of file extensions to protect (e.g., pdf,doc,docx,zip). Leave empty to protect all uploaded files.', 'mcp-ai-wpoos' ),
					'placeholder' => 'pdf,doc,docx,zip',
					'default'     => '',
				),

				// ========================================
				// ROLE & CAPABILITY CONTROLS
				// ========================================
				'_heading_role_controls'                   => array(
					'type'  => 'heading',
					'label' => __( 'Role & Capability Controls (RBAC)', 'mcp-ai-wpoos' ),
				),
				'restrict_to_roles'                        => array(
					'type'        => 'multiselect',
					'label'       => __( 'Restrict to Specific Roles', 'mcp-ai-wpoos' ),
					'description' => __( 'Only allow these user roles to access the system. Leave empty to allow all authenticated users. Hold Ctrl/Cmd to select multiple.', 'mcp-ai-wpoos' ),
					'options'     => $roles,
					'default'     => array(),
				),
				'minimum_capability'                       => array(
					'type'        => 'select',
					'label'       => __( 'Minimum Capability Required', 'mcp-ai-wpoos' ),
					'description' => __( 'Users must have at least this capability to access protected endpoints. Follows WordPress capability hierarchy.', 'mcp-ai-wpoos' ),
					'options'     => array(
						''                  => __( 'No requirement (any authenticated user)', 'mcp-ai-wpoos' ),
						'read'              => __( 'Read (Subscriber+)', 'mcp-ai-wpoos' ),
						'edit_posts'        => __( 'Edit Posts (Contributor+)', 'mcp-ai-wpoos' ),
						'publish_posts'     => __( 'Publish Posts (Author+)', 'mcp-ai-wpoos' ),
						'edit_others_posts' => __( 'Edit Others Posts (Editor+)', 'mcp-ai-wpoos' ),
						'manage_options'    => __( 'Manage Options (Administrator only)', 'mcp-ai-wpoos' ),
					),
					'default'     => '',
				),

				// ========================================
				// NETWORK SECURITY
				// ========================================
				'_heading_network_security'                => array(
					'type'  => 'heading',
					'label' => __( 'Network Security & IP Filtering', 'mcp-ai-wpoos' ),
				),
				'enable_ip_whitelist'                      => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable IP Whitelist', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Only allow access from whitelisted IP addresses', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, only requests from whitelisted IPs can access the system. Use with caution - can lock you out if misconfigured.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'ip_whitelist'                             => array(
					'type'        => 'textarea',
					'label'       => __( 'IP Whitelist', 'mcp-ai-wpoos' ),
					'description' => __( 'Enter IP addresses or CIDR ranges, one per line. Supports both IPv4 and IPv6. Example: 192.168.1.100 or 10.0.0.0/24', 'mcp-ai-wpoos' ),
					'placeholder' => "192.168.1.100\n10.0.0.0/24\n2001:db8::/32",
					'default'     => '',
				),
				'enable_ip_blacklist'                      => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable IP Blacklist', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Block access from blacklisted IP addresses', 'mcp-ai-wpoos' ),
					'description'    => __( 'Blocks requests from specified IP addresses. Useful for blocking known malicious IPs or scrapers.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'ip_blacklist'                             => array(
					'type'        => 'textarea',
					'label'       => __( 'IP Blacklist', 'mcp-ai-wpoos' ),
					'description' => __( 'Enter IP addresses or CIDR ranges to block, one per line. Example: 203.0.113.0 or 198.51.100.0/24', 'mcp-ai-wpoos' ),
					'placeholder' => "203.0.113.0\n198.51.100.0/24",
					'default'     => '',
				),
				'require_https'                            => array(
					'type'           => 'checkbox',
					'label'          => __( 'Require HTTPS for API Requests', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Block non-HTTPS API requests', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enforces HTTPS (TLS 1.2+) for all REST API requests. Highly recommended for production. HTTP requests will return 403 Forbidden.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// ========================================
				// RATE LIMITING & ABUSE PREVENTION
				// ========================================
				'_heading_rate_limiting'                   => array(
					'type'  => 'heading',
					'label' => __( 'Rate Limiting & Abuse Prevention', 'mcp-ai-wpoos' ),
				),
				'enable_rate_limiting'                     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Rate Limiting', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Limit request rates to prevent abuse and DDoS attacks', 'mcp-ai-wpoos' ),
					'description'    => __( 'Protects your installation from excessive API requests, brute-force attacks, and resource exhaustion. Returns HTTP 429 (Too Many Requests) when limit exceeded.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'rate_limit_requests'                      => array(
					'type'        => 'number',
					'label'       => __( 'Rate Limit (requests per window)', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum number of requests allowed per time window per user/IP. Recommended: 100-1000 for normal usage.', 'mcp-ai-wpoos' ),
					'default'     => 100,
					'placeholder' => '100',
				),
				'rate_limit_window'                        => array(
					'type'        => 'number',
					'label'       => __( 'Rate Limit Window (seconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'Time window for rate limiting in seconds. Common values: 60 (1 min), 300 (5 min), 3600 (1 hour).', 'mcp-ai-wpoos' ),
					'default'     => 3600,
					'placeholder' => '3600',
				),
				'rate_limit_by'                            => array(
					'type'        => 'select',
					'label'       => __( 'Rate Limit By', 'mcp-ai-wpoos' ),
					'description' => __( 'What to use for rate limit tracking. User ID is more accurate but IP-based works for unauthenticated requests.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'user_id' => __( 'User ID (recommended)', 'mcp-ai-wpoos' ),
						'ip'      => __( 'IP Address', 'mcp-ai-wpoos' ),
						'both'    => __( 'Both User ID and IP', 'mcp-ai-wpoos' ),
					),
					'default'     => 'user_id',
				),

				// ========================================
				// AUDIT LOGGING & COMPLIANCE
				// ========================================
				'_heading_audit_logging'                   => array(
					'type'  => 'heading',
					'label' => __( 'Audit Logging & Compliance', 'mcp-ai-wpoos' ),
				),
				'enable_security_audit_log'                => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Security Audit Log', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Log all authentication attempts and security events', 'mcp-ai-wpoos' ),
					'description'    => __( 'Records failed login attempts, IP blocks, authentication failures, and security violations. Required for SOC 2 and GDPR compliance. Useful for security monitoring and incident response.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'log_successful_auth'                      => array(
					'type'           => 'checkbox',
					'label'          => __( 'Log Successful Authentication', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Log successful authentication events', 'mcp-ai-wpoos' ),
					'description'    => __( 'Records all successful authentication attempts. Creates more log entries but useful for comprehensive audit trails and compliance requirements.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'log_file_access'                          => array(
					'type'           => 'checkbox',
					'label'          => __( 'Log File Access', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Log all file downloads and media access', 'mcp-ai-wpoos' ),
					'description'    => __( 'Records file downloads and media access including user ID, timestamp, and file path. Useful for data protection compliance.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'audit_log_retention_days'                 => array(
					'type'        => 'number',
					'label'       => __( 'Audit Log Retention (days)', 'mcp-ai-wpoos' ),
					'description' => __( 'Number of days to retain audit logs. Set to 0 for unlimited retention. GDPR recommends 90-180 days. SOC 2 typically requires 365 days.', 'mcp-ai-wpoos' ),
					'default'     => 90,
					'placeholder' => '90',
				),

				// ========================================
				// SECURITY HEADERS
				// ========================================
				'_heading_security_headers'                => array(
					'type'  => 'heading',
					'label' => __( 'Security Headers (OWASP Recommendations)', 'mcp-ai-wpoos' ),
				),
				'enable_security_headers'                  => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Security Headers', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Add security headers to all API responses', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables OWASP-recommended security headers: X-Content-Type-Options, X-Frame-Options, CSP, etc. Protects against XSS, clickjacking, and MIME-sniffing attacks.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'enable_hsts'                              => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable HSTS (HTTP Strict Transport Security)', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Force HTTPS connections', 'mcp-ai-wpoos' ),
					'description'    => __( 'Instructs browsers to only connect via HTTPS. <strong>Warning:</strong> Only enable if you have a valid SSL certificate and HTTPS is working correctly. Misconfiguration can lock users out.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'hsts_max_age'                             => array(
					'type'        => 'number',
					'label'       => __( 'HSTS Max Age (seconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'How long browsers should enforce HTTPS. Recommended: 31536000 (1 year). Start with a low value (e.g., 300) for testing.', 'mcp-ai-wpoos' ),
					'default'     => 31536000,
					'placeholder' => '31536000',
				),
				'csp_frame_ancestors'                      => array(
					'type'        => 'select',
					'label'       => __( 'CSP frame-ancestors (Clickjacking Protection)', 'mcp-ai-wpoos' ),
					'description' => __( 'Controls which sites can embed your content in frames/iframes. Modern replacement for X-Frame-Options.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'none' => __( "'none' - Block all framing", 'mcp-ai-wpoos' ),
						'self' => __( "'self' - Allow same-origin framing only", 'mcp-ai-wpoos' ),
						''     => __( 'Disabled', 'mcp-ai-wpoos' ),
					),
					'default'     => 'none',
				),

				// ========================================
				// ADVANCED SECURITY
				// ========================================
				'_heading_advanced_security'               => array(
					'type'  => 'heading',
					'label' => __( 'Advanced Security Features', 'mcp-ai-wpoos' ),
				),
				'enable_root_security_key'                 => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Root Security Key', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Require root security key for sensitive operations', 'mcp-ai-wpoos' ),
					'description'    => __( 'Adds an extra layer of security for administrative operations like deleting assistants, changing critical settings, or accessing sensitive data. Acts as a "second password" for high-risk actions.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'root_security_key'                        => array(
					'type'        => 'password',
					'label'       => __( 'Root Security Key', 'mcp-ai-wpoos' ),
					'description' => __( 'A secure key for sensitive operations (minimum 32 characters, recommended 64+). Store this securely - it cannot be recovered if lost. Use a password manager to generate a strong key.', 'mcp-ai-wpoos' ),
					'placeholder' => '',
				),
				'enable_2fa_requirement'                   => array(
					'type'           => 'checkbox',
					'label'          => __( 'Require Two-Factor Authentication (2FA)', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Require 2FA for administrators accessing the plugin', 'mcp-ai-wpoos' ),
					'description'    => __( 'Requires WordPress administrators to have 2FA enabled to access plugin settings. Requires a 2FA plugin like WP 2FA or similar to be installed.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'enable_loopback_ssl_bypass'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Loopback/Private Network SSL Bypass', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Automatically disable SSL verification for localhost and private networks', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, SSL verification is automatically disabled for requests to localhost (127.x.x.x), private IPv4 addresses (10.x.x.x, 172.16-31.x.x, 192.168.x.x), and private IPv6 addresses (fc00::/7). <strong>Necessary for local AI services</strong> like Ollama and LM Studio which typically do not have valid SSL certificates. Disable this if you have proper SSL certificates configured for your local services or want stricter security.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'enable_loopback_private_network_requests' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Private Network Requests', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Allow HTTP requests to private network addresses', 'mcp-ai-wpoos' ),
					'description'    => __( 'WordPress blocks requests to local and private IP addresses by default for security (SSRF protection). Enable this to allow connections to local AI services (LM Studio, Ollama, etc.) running on private network addresses like 192.168.2.222:11434. <strong>Required for local AI providers</strong> on your network.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
			);
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			// Display security overview notice.
			echo '<div class="notice notice-info inline" style="margin: 20px 0; padding: 12px;">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
			echo '<p><strong>' . esc_html__( '🔒 Security Overview:', 'mcp-ai-wpoos' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'This page provides comprehensive security controls following OWASP Top 10, GDPR, and SOC 2 compliance standards. Enable controls that match your security requirements. Start with conservative settings and adjust based on your needs.', 'mcp-ai-wpoos' ) . '</p>';
			echo '<p><em>' . esc_html__( '💡 Tip: Use "Require Authentication for All Access" as the master switch to lock down everything, then use granular controls to allow specific access patterns.', 'mcp-ai-wpoos' ) . '</em></p>';
			echo '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.

			$fields = $this->get_fields();

			foreach ( $fields as $key => $field ) {
				$this->render_field( $key, $field );
			}
		}

		/**
		 * Validate section input.
		 *
		 * @param array $input Raw input.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			$errors = array();

			// Validate root security key length.
			if ( isset( $input['root_security_key'] ) && ! empty( $input['root_security_key'] ) ) {
				if ( strlen( $input['root_security_key'] ) < 32 ) {
					$errors[] = __( 'Root Security Key must be at least 32 characters long.', 'mcp-ai-wpoos' );
				}
			}

			// Validate rate limit numbers.
			if ( isset( $input['rate_limit_requests'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['rate_limit_requests'],
					1,
					10000
				);
				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Rate Limit Requests: ', 'mcp-ai-wpoos' ) . $result->get_error_message();
				}
			}

			if ( isset( $input['rate_limit_window'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['rate_limit_window'],
					60,
					86400
				);
				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Rate Limit Window: ', 'mcp-ai-wpoos' ) . $result->get_error_message();
				}
			}

			// Validate audit log retention.
			if ( isset( $input['audit_log_retention_days'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['audit_log_retention_days'],
					0,
					3650
				);
				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Audit Log Retention: ', 'mcp-ai-wpoos' ) . $result->get_error_message();
				}
			}

			// Validate HSTS max age.
			if ( isset( $input['hsts_max_age'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['hsts_max_age'],
					300,
					63072000
				);
				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'HSTS Max Age: ', 'mcp-ai-wpoos' ) . $result->get_error_message();
				}
			}

			// Validate IP addresses in whitelist.
			if ( isset( $input['ip_whitelist'] ) && ! empty( $input['ip_whitelist'] ) ) {
				$validation_result = $this->validate_ip_list( $input['ip_whitelist'] );
				if ( is_wp_error( $validation_result ) ) {
					$errors[] = __( 'IP Whitelist: ', 'mcp-ai-wpoos' ) . $validation_result->get_error_message();
				}
			}

			// Validate IP addresses in blacklist.
			if ( isset( $input['ip_blacklist'] ) && ! empty( $input['ip_blacklist'] ) ) {
				$validation_result = $this->validate_ip_list( $input['ip_blacklist'] );
				if ( is_wp_error( $validation_result ) ) {
					$errors[] = __( 'IP Blacklist: ', 'mcp-ai-wpoos' ) . $validation_result->get_error_message();
				}
			}

			// Warn if enabling IP whitelist without entries.
			if ( ! empty( $input['enable_ip_whitelist'] ) && empty( $input['ip_whitelist'] ) ) {
				$errors[] = __( 'Warning: IP Whitelist is enabled but no IP addresses are configured. This will block all access!', 'mcp-ai-wpoos' );
			}

			// Warn if requiring HTTPS without SSL.
			if ( ! empty( $input['require_https'] ) && ! is_ssl() ) {
				$errors[] = __( 'Warning: You are enabling "Require HTTPS" but your site is currently not using HTTPS. This may cause issues. Please ensure SSL is properly configured first.', 'mcp-ai-wpoos' );
			}

			// Warn if enabling HSTS without HTTPS.
			if ( ! empty( $input['enable_hsts'] ) && ! is_ssl() ) {
				$errors[] = __( 'Warning: You are enabling HSTS but your site is currently not using HTTPS. HSTS will not work without a valid SSL certificate.', 'mcp-ai-wpoos' );
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( '<br>', $errors ) );
			}

			return $input;
		}

		/**
		 * Validate a list of IP addresses or CIDR ranges.
		 *
		 * @param string $ip_list Line-separated list of IPs/CIDR ranges.
		 * @return true|WP_Error True if valid, WP_Error otherwise.
		 */
		private function validate_ip_list( $ip_list ) {
			$ips = array_filter( array_map( 'trim', explode( "\n", $ip_list ) ) );

			foreach ( $ips as $entry ) {
				// Check if it's a CIDR range.
				if ( strpos( $entry, '/' ) !== false ) {
					// Validate CIDR format.
					$parts = explode( '/', $entry );
					if ( count( $parts ) !== 2 ) {
						return new WP_Error( 'invalid_cidr', sprintf( __( 'Invalid CIDR format: %s', 'mcp-ai-wpoos' ), $entry ) );
					}

					list($ip, $mask) = $parts;

					// Validate IP part.
					if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
						return new WP_Error( 'invalid_cidr_ip', sprintf( __( 'Invalid IP in CIDR: %s', 'mcp-ai-wpoos' ), $entry ) );
					}

					// Validate mask.
					$mask_int = intval( $mask );
					// Check for IPv4 or IPv6.
					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
						if ( $mask_int < 0 || $mask_int > 32 ) {
							return new WP_Error( 'invalid_cidr_mask', sprintf( __( 'Invalid IPv4 CIDR mask (must be 0-32): %s', 'mcp-ai-wpoos' ), $entry ) );
						}
					} elseif ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
						if ( $mask_int < 0 || $mask_int > 128 ) {
							return new WP_Error( 'invalid_cidr_mask', sprintf( __( 'Invalid IPv6 CIDR mask (must be 0-128): %s', 'mcp-ai-wpoos' ), $entry ) );
						}
					}
				} else {
					// Validate as plain IP address.
					if ( ! filter_var( $entry, FILTER_VALIDATE_IP ) ) {
						return new WP_Error( 'invalid_ip', sprintf( __( 'Invalid IP address: %s', 'mcp-ai-wpoos' ), $entry ) );
					}
				}
			}

			return true;
		}
	}
}
