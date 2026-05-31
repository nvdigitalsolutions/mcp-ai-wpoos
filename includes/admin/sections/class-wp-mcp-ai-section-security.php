<?php
/**
 * Security Center Settings Section
 *
 * Five-subtab Security Center built on top of the existing security settings
 * fields. Each sub-tab is independently saveable; existing option keys are
 * 100 % preserved so no database migration is needed.
 *
 * Sub-tabs:
 *   overview   – Read-only posture score card, quick wins, recent events.
 *   access     – Global access control, REST/media protection, RBAC, guest
 *                token policy, A2A auth, capability fence table.
 *   network    – IP filtering, rate limiting, security headers + IP dry-run
 *                and header-preview AJAX tools.
 *   ai_safety  – Prompt-injection detector, PII filter, HITL approvals,
 *                sandbox mode.
 *   audit      – Audit logging, compliance, snapshot/restore, recovery docs,
 *                self-test (OTel config gated behind Pro).
 *
 * @package WP_MCP_AI
 * @since   1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Security' ) ) {
	/**
	 * Security Center — five-subtab settings section.
	 */
	class WP_MCP_AI_Section_Security extends WP_MCP_AI_Settings_Section {

		// ---------------------------------------------------------------- //
		// Identity                                                          //
		// ---------------------------------------------------------------- //

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
			return __( 'Security Center', 'mcp-ai-wpoos' );
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
			return __( 'Comprehensive security controls following OWASP Top 10, GDPR, and SOC 2 standards. Use the sub-tabs to navigate between posture overview, access controls, network settings, AI safety, and audit/compliance.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/features/security/SECURITY_HARDENING.md';
		}

		// ---------------------------------------------------------------- //
		// Sub-tab routing                                                   //
		// ---------------------------------------------------------------- //

		/**
		 * Return the subtab group definitions used by the sanitize_with_subtabs
		 * base-class logic and by render_wrapper().
		 *
		 * The 'overview' subtab has an empty fields list because it is
		 * read-only (posture score card) — no settings are saved from it.
		 *
		 * @return array
		 */
		protected function get_subtab_groups() {
			return array(
				'overview'  => array(
					'id'     => 'overview',
					'label'  => __( '🔍 Overview', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-shield',
					'fields' => array(), // Read-only — nothing saved.
				),
				'access'    => array(
					'id'     => 'access',
					'label'  => __( '🔑 Access & Identity', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-admin-users',
					'fields' => array(
						// Global Access Control.
						'require_authentication_all',
						'allow_guest_access',
						'bypass_auth_for_logged_in',
						// REST API protection.
						'require_auth_chat_endpoints',
						'require_auth_tool_execution',
						'require_auth_assistant_management',
						'require_auth_transcripts',
						'require_auth_file_operations',
						// Media protection.
						'protect_media_urls',
						'protect_attachment_pages',
						'allow_public_thumbnails',
						'protected_file_extensions',
						// RBAC.
						'restrict_to_roles',
						'minimum_capability',
						// Guest token policy (new).
						'guest_token_ttl_hours',
						// A2A authentication (new).
						'enable_a2a_jwt_validation',
						'require_capability_on_delegate',
						// Advanced security.
						'enable_root_security_key',
						'root_security_key',
						'enable_2fa_requirement',
						'enable_loopback_ssl_bypass',
						'enable_loopback_private_network_requests',
					),
				),
				'network'   => array(
					'id'     => 'network',
					'label'  => __( '🌐 Network & Headers', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-networking',
					'fields' => array(
						// IP filtering.
						'enable_ip_whitelist',
						'ip_whitelist',
						'enable_ip_blacklist',
						'ip_blacklist',
						'require_https',
						// Rate limiting.
						'enable_rate_limiting',
						'rate_limit_requests',
						'rate_limit_window',
						'rate_limit_by',
						// Security headers.
						'enable_security_headers',
						'enable_hsts',
						'hsts_max_age',
						'csp_frame_ancestors',
					),
				),
				'ai_safety' => array(
					'id'     => 'ai_safety',
					'label'  => __( '🤖 AI Safety', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-warning',
					'fields' => array(
						'enable_prompt_injection_detector',
						'prompt_injection_sensitivity',
						'prompt_injection_mode',
						'enable_pii_filter',
						'pii_filter_patterns',
						'pii_filter_side',
						'pii_filter_mode',
						'enable_hitl_for_write_tools',
						'hitl_write_tool_threshold',
						'enable_sandbox_mode',
					),
				),
				'audit'     => array(
					'id'     => 'audit',
					'label'  => __( '📋 Audit & Compliance', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-clipboard',
					'fields' => array_merge(
						array(
							'enable_security_audit_log',
							'log_successful_auth',
							'log_file_access',
							'audit_log_retention_days',
						),
						// OTel config is Pro-only.
						defined( 'WP_MCP_AI_PRO_VERSION' ) ? array(
							'enable_otel_security_export',
							'otel_security_endpoint',
							'otel_security_bearer_token',
							'otel_security_sampling_percent',
						) : array()
					),
				),
			);
		}

		/**
		 * Return the currently active sub-tab slug.
		 *
		 * Reads (in priority order): POST subtab_security → POST subtab →
		 * GET subtab → default 'overview'.
		 *
		 * @return string
		 */
		protected function get_active_subtab() {
			$groups = $this->get_subtab_groups();
			$subtab = '';

			// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
			if ( isset( $_POST['subtab_security'] ) ) {
				$subtab = sanitize_key( wp_unslash( $_POST['subtab_security'] ) );
			} elseif ( isset( $_POST['subtab'] ) ) {
				$subtab = sanitize_key( wp_unslash( $_POST['subtab'] ) );
			} elseif ( isset( $_GET['subtab'] ) ) {
				$subtab = sanitize_key( wp_unslash( $_GET['subtab'] ) );
			}
			// phpcs:enable

			if ( empty( $subtab ) || ! isset( $groups[ $subtab ] ) ) {
				$subtab = 'overview';
			}

			return $subtab;
		}

		// ---------------------------------------------------------------- //
		// Fields                                                            //
		// ---------------------------------------------------------------- //

		/**
		 * Get field definitions.
		 *
		 * All historical option keys are included so that sanitize() can
		 * handle them. New AI-Safety and OTel fields are appended at the end.
		 *
		 * @return array
		 */
		public function get_fields() {
			$roles  = wp_roles()->get_names();

			$fields = array(
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

				// ========================================
				// GUEST TOKEN POLICY (new — Access subtab)
				// ========================================
				'_heading_guest_policy'                    => array(
					'type'  => 'heading',
					'label' => __( 'Guest Token Policy', 'mcp-ai-wpoos' ),
				),
				'guest_token_ttl_hours'                    => array(
					'type'        => 'number',
					'label'       => __( 'Guest Token TTL (hours)', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum lifetime for guest tokens in hours. Tokens older than this are revoked on the next request. Set 0 for the session default (24 h).', 'mcp-ai-wpoos' ),
					'default'     => 24,
					'min'         => 0,
					'max'         => 8760,
				),

				// ========================================
				// A2A / SUB-AGENT AUTHENTICATION (new)
				// ========================================
				'_heading_a2a_auth'                        => array(
					'type'  => 'heading',
					'label' => __( 'Sub-Agent / A2A Authentication', 'mcp-ai-wpoos' ),
				),
				'enable_a2a_jwt_validation'                => array(
					'type'           => 'checkbox',
					'label'          => __( 'Require JWT Validation for A2A Calls', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Validate inter-agent JWT tokens on every delegate_to_agent call', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, the A2A dispatcher verifies a signed JWT on every sub-agent delegation. Disable only in local/dev environments.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'require_capability_on_delegate'           => array(
					'type'           => 'checkbox',
					'label'          => __( 'Require Capability Check on delegate_to_agent', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enforce capability_required before delegating to a sub-agent', 'mcp-ai-wpoos' ),
					'description'    => __( 'Ensures that the initiating user holds the declared capability of the target agent before the delegation is granted.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// ========================================
				// AI SAFETY (new — AI Safety subtab)
				// ========================================
				'_heading_ai_safety'                       => array(
					'type'  => 'heading',
					'label' => __( 'Prompt-Injection Detector', 'mcp-ai-wpoos' ),
				),
				'enable_prompt_injection_detector'         => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Prompt-Injection Detector', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Scan incoming messages for prompt-injection patterns', 'mcp-ai-wpoos' ),
					'description'    => __( 'Fires the <code>wp_mcp_ai_prompt_injection_detected</code> action when a suspicious pattern is found and optionally blocks the request. Tune sensitivity and action below.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'prompt_injection_sensitivity'             => array(
					'type'    => 'select',
					'label'   => __( 'Detection Sensitivity', 'mcp-ai-wpoos' ),
					'options' => array(
						'low'    => __( 'Low — flag obvious jailbreak patterns only', 'mcp-ai-wpoos' ),
						'medium' => __( 'Medium — balance precision and recall (recommended)', 'mcp-ai-wpoos' ),
						'high'   => __( 'High — strict, may produce false positives', 'mcp-ai-wpoos' ),
					),
					'default' => 'medium',
				),
				'prompt_injection_mode'                    => array(
					'type'    => 'select',
					'label'   => __( 'Action on Detection', 'mcp-ai-wpoos' ),
					'options' => array(
						'flag'  => __( 'Flag only — log event, allow request to proceed', 'mcp-ai-wpoos' ),
						'block' => __( 'Block — return 403 and log event', 'mcp-ai-wpoos' ),
					),
					'default' => 'flag',
				),

				'_heading_pii_filter'                      => array(
					'type'  => 'heading',
					'label' => __( 'PII Filter', 'mcp-ai-wpoos' ),
				),
				'enable_pii_filter'                        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable PII Filter', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Redact personal data before it reaches model or storage', 'mcp-ai-wpoos' ),
					'description'    => __( 'Uses <code>WP_MCP_AI_Pii_Filter::scrub()</code> to find and redact emails, phone numbers, SSNs, credit-card numbers, and API key patterns.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'pii_filter_patterns'                      => array(
					'type'        => 'multiselect',
					'label'       => __( 'Patterns to Detect', 'mcp-ai-wpoos' ),
					'description' => __( 'Which PII categories to redact. All patterns are enabled by default when the filter is on.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'email'       => __( 'Email addresses', 'mcp-ai-wpoos' ),
						'phone'       => __( 'Phone numbers (US/E.164)', 'mcp-ai-wpoos' ),
						'ssn'         => __( 'US Social Security Numbers', 'mcp-ai-wpoos' ),
						'credit_card' => __( 'Credit card numbers', 'mcp-ai-wpoos' ),
						'api_keys'    => __( 'API key patterns (sk-*, Bearer, ghp_*, etc.)', 'mcp-ai-wpoos' ),
					),
					'default'     => array( 'email', 'phone', 'ssn', 'credit_card', 'api_keys' ),
				),
				'pii_filter_side'                          => array(
					'type'    => 'select',
					'label'   => __( 'Filter Side', 'mcp-ai-wpoos' ),
					'options' => array(
						'request'  => __( 'Request only — scrub user messages before sending to model', 'mcp-ai-wpoos' ),
						'response' => __( 'Response only — scrub model output before returning to client', 'mcp-ai-wpoos' ),
						'both'     => __( 'Both — scrub request and response', 'mcp-ai-wpoos' ),
					),
					'default' => 'both',
				),
				'pii_filter_mode'                          => array(
					'type'    => 'select',
					'label'   => __( 'Redaction Mode', 'mcp-ai-wpoos' ),
					'options' => array(
						'redact' => __( 'Redact — replace with [REDACTED_*] token', 'mcp-ai-wpoos' ),
						'block'  => __( 'Block — return error if PII is detected', 'mcp-ai-wpoos' ),
					),
					'default' => 'redact',
				),

				'_heading_hitl'                            => array(
					'type'  => 'heading',
					'label' => __( 'HITL Approvals for Write Tools', 'mcp-ai-wpoos' ),
				),
				'enable_hitl_for_write_tools'              => array(
					'type'           => 'checkbox',
					'label'          => __( 'Require Human Approval for Write Tools', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Route write-flag tool calls through the HITL approval queue', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, any tool marked with the <code>write</code> or <code>state-changing</code> capability flag is held in the approval queue before execution. Configure the approval admin at <em>NV oOS Pro → Approvals</em>.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'hitl_write_tool_threshold'                => array(
					'type'    => 'select',
					'label'   => __( 'Approval Threshold', 'mcp-ai-wpoos' ),
					'options' => array(
						'none'          => __( 'None — no automatic HITL (per-tool overrides still apply)', 'mcp-ai-wpoos' ),
						'any_write'     => __( 'Any write-flag tool', 'mcp-ai-wpoos' ),
						'state_changing' => __( 'State-changing tools only', 'mcp-ai-wpoos' ),
						'destructive'   => __( 'Destructive tools only (highest risk)', 'mcp-ai-wpoos' ),
					),
					'default' => 'state_changing',
				),

				'_heading_sandbox'                         => array(
					'type'  => 'heading',
					'label' => __( 'Sandbox Mode', 'mcp-ai-wpoos' ),
				),
				'enable_sandbox_mode'                      => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Sandbox (Dry-Run) Mode', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Force all write-flag tools through the approval queue globally', 'mcp-ai-wpoos' ),
					'description'    => __( 'Useful for staging or testing. Every tool that carries the <code>write</code> capability flag is queued for manual approval regardless of per-tool settings. This overrides the HITL threshold above.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
			);

			// ====================================================
			// PRO-ONLY: OTel security export field definitions.
			// These keys are referenced by the 'audit' subtab
			// group only when Pro is active (see get_subtab_groups).
			// ====================================================
			if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
				$fields['_heading_otel_security']         = array(
					'type'  => 'heading',
					'label' => __( 'OTel Security Export (Pro)', 'mcp-ai-wpoos' ),
				);
				$fields['enable_otel_security_export']    = array(
					'type'           => 'checkbox',
					'label'          => __( 'Export Security Spans to OTel', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Send auth-fail, IP-block, and injection events as OTLP spans', 'mcp-ai-wpoos' ),
					'description'    => __( 'Reuses the existing OTel exporter. Endpoint and token are shared with <em>Tools → Connections → OTel</em>. Override below for security spans only.', 'mcp-ai-wpoos' ),
					'default'        => false,
				);
				$fields['otel_security_endpoint']         = array(
					'type'        => 'text',
					'label'       => __( 'OTel Endpoint Override (Security)', 'mcp-ai-wpoos' ),
					'description' => __( 'Optional: override the global OTel endpoint for security spans only. Leave blank to use <code>otel_endpoint</code> from Tools → Connections.', 'mcp-ai-wpoos' ),
					'placeholder' => 'https://otel-collector.example.com:4318/v1/traces',
					'default'     => '',
				);
				$fields['otel_security_bearer_token']     = array(
					'type'        => 'password',
					'label'       => __( 'OTel Bearer Token Override (Security)', 'mcp-ai-wpoos' ),
					'description' => __( 'Optional: override the global OTel bearer token for security spans only.', 'mcp-ai-wpoos' ),
					'default'     => '',
				);
				$fields['otel_security_sampling_percent'] = array(
					'type'        => 'number',
					'label'       => __( 'Security Span Sampling (%)', 'mcp-ai-wpoos' ),
					'description' => __( 'Percentage of security spans to export (0–100). 100 = export all.', 'mcp-ai-wpoos' ),
					'default'     => 100,
					'min'         => 0,
					'max'         => 100,
				);
			}

			return $fields;
		}

		/**
		 * Override render_wrapper to support the five-subtab Security Center layout.
		 *
		 * For the 'overview' subtab this renders the posture score card instead of a
		 * form table.  For all other subtabs it falls back to the standard form-table
		 * flow, wrapping only the active subtab's fields.
		 */
		public function render_wrapper() {
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();
			?>
<div class="settings-section" id="section-security">
<h2><?php esc_html_e( 'Security Center', 'mcp-ai-wpoos' ); ?></h2>
<p class="section-documentation">
	<span class="dashicons dashicons-book-alt" style="color: #2271b1;"></span>
	<a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/features/security/SECURITY_HARDENING.md"
		target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'View Security Hardening Docs', 'mcp-ai-wpoos' ); ?>
		<span class="dashicons dashicons-external" style="font-size: 14px;"></span>
	</a>
</p>

<!-- Security Center sub-tab navigation -->
<div class="wp-mcp-ai-provider-subtabs">
<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'Security Center sub-tabs', 'mcp-ai-wpoos' ); ?>">
			<?php foreach ( $subtab_groups as $group ) : ?>
				<?php
				$subtab_url = add_query_arg(
					array(
						'page'   => 'wp-mcp-ai-dashboard',
						'tab'    => 'security',
						'subtab' => $group['id'],
					),
					admin_url( 'admin.php' )
				);
				$is_active  = ( $group['id'] === $active_subtab );
				?>
<a href="<?php echo esc_url( $subtab_url ); ?>"
	class="wp-mcp-ai-subtab <?php echo $is_active ? 'wp-mcp-ai-subtab-active' : ''; ?>"
	data-subtab="<?php echo esc_attr( $group['id'] ); ?>">
				<?php echo esc_html( $group['label'] ); ?>
</a>
		<?php endforeach; ?>
</nav>

<!-- Preserve active subtab on save -->
<input type="hidden" name="subtab_security" value="<?php echo esc_attr( $active_subtab ); ?>" />

<div class="wp-mcp-ai-subtab-content">
			<?php if ( 'overview' === $active_subtab ) : ?>
				<?php $this->render_overview_subtab(); ?>
			<?php else : ?>
<table class="form-table" role="presentation">
				<?php $this->render(); ?>
</table>
			<?php endif; ?>
</div>
</div>
</div>
			<?php
		}

		/**
		 * Render the Overview & Posture sub-tab (read-only, no form fields).
		 */
		private function render_overview_subtab() {
			if ( ! class_exists( 'WP_MCP_AI_Security_Posture' ) ) {
				$posture_file = WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-security-posture.php';
				if ( file_exists( $posture_file ) ) {
					require_once $posture_file;
				}
			}

			$report     = array();
			$score      = 0;
			$grade      = 'F';
			$signals    = array();
			$quick_wins = array();

			if ( class_exists( 'WP_MCP_AI_Security_Posture' ) ) {
				$posture    = new WP_MCP_AI_Security_Posture();
				$report     = $posture->get_report();
				$score      = (int) $report['score'];
				$grade      = $report['grade'];
				$signals    = $report['signals'];
				$quick_wins = $report['quick_wins'];
			}

			$grade_color = array(
				'A' => '#46b450',
				'B' => '#72aee6',
				'C' => '#dba617',
				'D' => '#d63638',
				'F' => '#8c1c1c',
			);
			$color = $grade_color[ $grade ] ?? '#d63638';

			// Recent security events.
			$recent_events = array_slice( array_reverse( get_option( 'wp_mcp_ai_security_audit_log', array() ) ), 0, 10 );

			// Sibling-page cross-links.
			$sibling_links = array(
				array(
					'label' => __( 'Security Audit Dashboard', 'mcp-ai-wpoos' ),
					'url'   => admin_url( 'admin.php?page=nvoos-pro-dashboard-audits' ),
					'icon'  => 'dashicons-clipboard',
				),
				array(
					'label' => __( 'Approvals Queue', 'mcp-ai-wpoos' ),
					'url'   => admin_url( 'admin.php?page=nvoos-pro-approvals' ),
					'icon'  => 'dashicons-yes-alt',
				),
				array(
					'label' => __( 'Security Monitor', 'mcp-ai-wpoos' ),
					'url'   => admin_url( 'admin.php?page=nvoos-pro-security-monitor' ),
					'icon'  => 'dashicons-visibility',
				),
				array(
					'label' => __( 'Security Training', 'mcp-ai-wpoos' ),
					'url'   => admin_url( 'admin.php?page=nvoos-pro-security-training' ),
					'icon'  => 'dashicons-welcome-learn-more',
				),
				array(
					'label' => __( 'Supplier Security', 'mcp-ai-wpoos' ),
					'url'   => admin_url( 'admin.php?page=nvoos-pro-supplier-security' ),
					'icon'  => 'dashicons-shield-alt',
				),
			);
			?>
<div class="wp-mcp-ai-security-overview">

	<!-- Posture score card -->
	<div class="wp-mcp-ai-security-score-card" style="background:#fff;border:2px solid <?php echo esc_attr( $color ); ?>;border-radius:6px;padding:24px;display:flex;align-items:center;gap:24px;margin-bottom:24px;">
		<div class="wp-mcp-ai-score-circle" style="width:90px;height:90px;border-radius:50%;background:<?php echo esc_attr( $color ); ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
			<span style="color:#fff;font-size:26px;font-weight:700;line-height:1;"><?php echo esc_html( $grade ); ?></span>
		</div>
		<div>
			<?php /* translators: %d: security posture score (0-100) */ ?>
			<h3 style="margin:0 0 4px;"><?php echo esc_html( sprintf( __( 'Security Posture: %d / 100', 'mcp-ai-wpoos' ), $score ) ); ?></h3>
			<p style="margin:0;color:#646970;"><?php esc_html_e( 'Computed from 17 weighted signals. Refreshes every 5 minutes.', 'mcp-ai-wpoos' ); ?></p>
			<button type="button" class="button button-small wp-mcp-ai-refresh-posture" style="margin-top:8px;">
				<span class="dashicons dashicons-update" style="vertical-align:middle;"></span>
				<?php esc_html_e( 'Refresh Now', 'mcp-ai-wpoos' ); ?>
			</button>
		</div>
	</div>

	<!-- Quick wins -->
			<?php if ( ! empty( $quick_wins ) ) : ?>
	<div class="wp-mcp-ai-quick-wins" style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:16px;margin-bottom:24px;">
		<h4 style="margin-top:0;"><?php esc_html_e( '⚡ Quick Wins (highest-impact unmet signals)', 'mcp-ai-wpoos' ); ?></h4>
		<ul style="margin:0;padding-left:20px;">
				<?php foreach ( $quick_wins as $win ) : ?>
			<li style="margin-bottom:8px;">
				<strong><?php echo esc_html( $win['label'] ); ?></strong>
					<?php if ( ! empty( $win['detail'] ) ) : ?>
				<span style="color:#646970;"> — <?php echo esc_html( $win['detail'] ); ?></span>
				<?php endif; ?>
					<?php if ( ! empty( $win['subtab'] ) ) : ?>
				<a href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page' => 'wp-mcp-ai-dashboard',
									'tab' => 'security',
									'subtab' => $win['subtab'],
								),
								admin_url( 'admin.php' )
							) . ( ! empty( $win['anchor'] ) ? '#' . $win['anchor'] : '' )
						);
						?>
							"
					style="margin-left:8px;" class="button button-small">
						<?php esc_html_e( 'Fix →', 'mcp-ai-wpoos' ); ?>
				</a>
				<?php endif; ?>
			</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>

	<!-- Signal checklist -->
	<div class="wp-mcp-ai-signals" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:24px;">
		<h4 style="margin-top:0;"><?php esc_html_e( 'Signal Checklist', 'mcp-ai-wpoos' ); ?></h4>
		<table class="widefat striped" style="margin:0;">
			<thead>
				<tr>
					<th style="width:30px;"></th>
					<th><?php esc_html_e( 'Signal', 'mcp-ai-wpoos' ); ?></th>
					<th style="width:60px;"><?php esc_html_e( 'Weight', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Detail', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $signals as $signal ) : ?>
				<tr>
					<td style="text-align:center;font-size:18px;"><?php echo (bool) $signal['passed'] ? '✅' : '❌'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- emoji literals, bool-cast prevents unexpected output ?></td>
					<td><?php echo esc_html( $signal['label'] ); ?></td>
					<td><?php echo esc_html( $signal['weight'] ); ?></td>
					<td style="color:<?php echo esc_attr( (bool) $signal['passed'] ? '#46b450' : '#d63638' ); ?>;"><?php echo esc_html( $signal['detail'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<!-- Recent security events -->
	<div class="wp-mcp-ai-recent-events" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:24px;">
		<h4 style="margin-top:0;"><?php esc_html_e( 'Recent Security Events (last 10)', 'mcp-ai-wpoos' ); ?></h4>
			<?php if ( empty( $recent_events ) ) : ?>
			<p style="color:#646970;margin:0;"><?php esc_html_e( 'No security events logged yet. Enable the audit log under Audit & Compliance to start recording events.', 'mcp-ai-wpoos' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="margin:0;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Event', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'IP', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'User', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $recent_events as $event ) : ?>
					<tr>
						<td><?php echo esc_html( isset( $event['timestamp'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event['timestamp'] ) ) : '—' ); ?></td>
						<td><?php echo esc_html( $event['event'] ?? $event['type'] ?? '—' ); ?></td>
						<td><?php echo esc_html( $event['ip'] ?? '—' ); ?></td>
						<td><?php echo esc_html( $event['user_id'] ?? '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- Sibling page cross-links -->
	<div class="wp-mcp-ai-security-links" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px;">
		<h4 style="margin-top:0;"><?php esc_html_e( 'Security Administration Pages', 'mcp-ai-wpoos' ); ?></h4>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
			<?php foreach ( $sibling_links as $link ) : ?>
			<a href="<?php echo esc_url( $link['url'] ); ?>" class="button button-secondary" style="display:flex;align-items:center;gap:6px;justify-content:center;padding:8px 12px;">
				<span class="dashicons <?php echo esc_attr( $link['icon'] ); ?>"></span>
				<?php echo esc_html( $link['label'] ); ?>
			</a>
		<?php endforeach; ?>
		</div>
	</div>

</div><!-- /.wp-mcp-ai-security-overview -->

			<?php
			$refresh_text    = wp_json_encode( __( 'Refreshing…', 'mcp-ai-wpoos' ) );
			$refresh_now_text = wp_json_encode( __( 'Refresh Now', 'mcp-ai-wpoos' ) );
			ob_start();
			?>
		jQuery(function($){
			$('.wp-mcp-ai-refresh-posture').on('click', function(){
				var $btn = $(this).prop('disabled', true).text(<?php echo wp_json_encode( __( 'Refreshing…', 'mcp-ai-wpoos' ) ); ?>);
				wp.apiRequest({
					path: '/mcp-ai/v1/security/posture?refresh=1',
					method: 'GET'
				}).done(function(){
					window.location.reload();
				}).fail(function(){
					$btn.prop('disabled', false).text(<?php echo wp_json_encode( __( 'Refresh Now', 'mcp-ai-wpoos' ) ); ?>);
				});
			});
		});
			<?php
			$js = ob_get_clean();
			wp_print_inline_script_tag( $js );
			?>
			<?php
		}

		/**
		 * Render section fields for the active (non-overview) sub-tab.
		 *
		 * The base class sanitize_with_subtabs() uses get_subtab_groups() to know
		 * which fields to sanitize, so we only need to render the active group here.
		 */
		public function render() {
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();
			$all_fields    = $this->get_fields();

			if ( 'overview' === $active_subtab ) {
				return; // Handled by render_wrapper().
			}

			if ( ! isset( $subtab_groups[ $active_subtab ] ) ) {
				return;
			}

			$field_keys = $subtab_groups[ $active_subtab ]['fields'];

			foreach ( $field_keys as $key ) {
				if ( isset( $all_fields[ $key ] ) ) {
					$this->render_field( $key, $all_fields[ $key ] );
				}
			}

			// Render tool-specific extra UI for Network and Audit subtabs.
			if ( 'network' === $active_subtab ) {
				$this->render_network_tools();
			} elseif ( 'audit' === $active_subtab ) {
				$this->render_audit_tools();
			} elseif ( 'ai_safety' === $active_subtab ) {
				$this->render_capability_fence_hint();
				$this->render_deprecated_alias_telemetry();
				$this->render_mcp_token_inventory();
			}
		}

		/**
		 * Render IP dry-run tool and header preview for the Network sub-tab.
		 */
		private function render_network_tools() {
			$posture_nonce = wp_create_nonce( 'wp_rest' );
			?>
			</table><!-- close form-table before custom UI -->

			<!-- IP Dry-Run Tool -->
			<div class="wp-mcp-ai-network-tool" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px;margin:16px 0;">
				<h3 style="margin-top:0;"><?php esc_html_e( '🔍 Test IP Against Rules', 'mcp-ai-wpoos' ); ?></h3>
				<p><?php esc_html_e( 'Enter any IP address to instantly see whether it would be allowed or blocked by the current whitelist/blacklist rules.', 'mcp-ai-wpoos' ); ?></p>
				<div style="display:flex;gap:8px;align-items:center;">
					<input type="text" id="wp-mcp-ai-test-ip-input" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. 203.0.113.5', 'mcp-ai-wpoos' ); ?>" style="max-width:260px;" />
					<button type="button" id="wp-mcp-ai-test-ip-btn" class="button button-secondary">
						<?php esc_html_e( 'Test IP', 'mcp-ai-wpoos' ); ?>
					</button>
				</div>
				<div id="wp-mcp-ai-test-ip-result" style="margin-top:12px;display:none;"></div>
			</div>

			<!-- Header Preview Tool -->
			<div class="wp-mcp-ai-network-tool" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px;margin:16px 0;">
				<h3 style="margin-top:0;"><?php esc_html_e( '🌐 Effective Security Headers Preview', 'mcp-ai-wpoos' ); ?></h3>
				<p><?php esc_html_e( 'Shows which security headers the plugin would emit based on current settings.', 'mcp-ai-wpoos' ); ?></p>
				<button type="button" id="wp-mcp-ai-preview-headers-btn" class="button button-secondary">
					<?php esc_html_e( 'Preview Headers', 'mcp-ai-wpoos' ); ?>
				</button>
				<div id="wp-mcp-ai-preview-headers-result" style="margin-top:12px;display:none;"></div>
			</div>

			<table class="form-table" role="presentation"><!-- reopen form-table for remaining fields -->

			<?php
				$posture_nonce_js = wp_json_encode( $posture_nonce );
				ob_start();
			?>
				jQuery(function($){
					var nonce = <?php echo wp_json_encode( $posture_nonce ); ?>;

					$('#wp-mcp-ai-test-ip-btn').on('click', function(){
						var ip = $('#wp-mcp-ai-test-ip-input').val().trim();
						if (!ip) return;
						$(this).prop('disabled', true);
						var $result = $('#wp-mcp-ai-test-ip-result').show().html('<em>' + <?php echo wp_json_encode( __( 'Testing…', 'mcp-ai-wpoos' ) ); ?> + '</em>');
						wp.apiRequest({
							path: '/mcp-ai/v1/security/test-ip',
							method: 'POST',
							data: { ip: ip },
							beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', nonce); }
						}).done(function(res){
							var icon  = res.allowed ? '✅' : '🚫';
							var color = res.allowed ? '#46b450' : '#d63638';
							$result.html('<p style="color:' + color + ';font-weight:600;">' + icon + ' ' + $('<span>').text(res.reason).html() + '</p>');
						}).fail(function(xhr){
							var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : <?php echo wp_json_encode( __( 'Request failed.', 'mcp-ai-wpoos' ) ); ?>;
							$result.html('<p style="color:#d63638;">' + $('<span>').text(msg).html() + '</p>');
						}).always(function(){ $('#wp-mcp-ai-test-ip-btn').prop('disabled', false); });
					});

					$('#wp-mcp-ai-preview-headers-btn').on('click', function(){
						$(this).prop('disabled', true);
						var $result = $('#wp-mcp-ai-preview-headers-result').show().html('<em>' + <?php echo wp_json_encode( __( 'Loading…', 'mcp-ai-wpoos' ) ); ?> + '</em>');
						wp.apiRequest({
							path: '/mcp-ai/v1/security/preview-headers',
							method: 'GET',
							beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', nonce); }
						}).done(function(res){
							var html = '<table class="widefat striped" style="max-width:700px;"><thead><tr><th>' + <?php echo wp_json_encode( __( 'Header', 'mcp-ai-wpoos' ) ); ?> + '</th><th>' + <?php echo wp_json_encode( __( 'Value', 'mcp-ai-wpoos' ) ); ?> + '</th></tr></thead><tbody>';
							if (res.headers && Object.keys(res.headers).length > 0) {
								$.each(res.headers, function(name, val){
									html += '<tr><td><code>' + $('<span>').text(name).html() + '</code></td><td><code>' + $('<span>').text(val).html() + '</code></td></tr>';
								});
							} else {
								html += '<tr><td colspan="2" style="color:#646970;">' + <?php echo wp_json_encode( __( 'No security headers are currently configured. Enable "OWASP security headers" above.', 'mcp-ai-wpoos' ) ); ?> + '</td></tr>';
							}
							html += '</tbody></table>';
							$result.html(html);
						}).fail(function(){
							$result.html('<p style="color:#d63638;">' + <?php echo wp_json_encode( __( 'Could not retrieve headers.', 'mcp-ai-wpoos' ) ); ?> + '</p>');
						}).always(function(){ $('#wp-mcp-ai-preview-headers-btn').prop('disabled', false); });
					});
				});
				<?php
				$js = ob_get_clean();
				wp_print_inline_script_tag( $js );
				?>
			<?php
		}

		/**
		 * Render snapshot/restore and self-test tools for the Audit sub-tab.
		 */
		private function render_audit_tools() {
			$posture_nonce = wp_create_nonce( 'wp_rest' );
			?>
			</table><!-- close form-table before custom UI -->

			<!-- Snapshot / Restore -->
			<div class="wp-mcp-ai-audit-tool" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px;margin:16px 0;">
				<h3 style="margin-top:0;"><?php esc_html_e( '💾 Security Settings Snapshot', 'mcp-ai-wpoos' ); ?></h3>
				<p><?php esc_html_e( 'Take a versioned snapshot of all current security settings before making changes. You can restore any snapshot at any time.', 'mcp-ai-wpoos' ); ?></p>
				<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
					<input type="text" id="wp-mcp-ai-snapshot-label" class="regular-text" placeholder="<?php esc_attr_e( 'Optional label…', 'mcp-ai-wpoos' ); ?>" style="max-width:260px;" />
					<button type="button" id="wp-mcp-ai-save-snapshot-btn" class="button button-secondary">
						<?php esc_html_e( 'Save Snapshot', 'mcp-ai-wpoos' ); ?>
					</button>
					<button type="button" id="wp-mcp-ai-load-snapshots-btn" class="button">
						<?php esc_html_e( 'List Snapshots', 'mcp-ai-wpoos' ); ?>
					</button>
				</div>
				<div id="wp-mcp-ai-snapshot-result" style="margin-top:12px;display:none;"></div>
				<div id="wp-mcp-ai-snapshot-list"   style="margin-top:12px;display:none;"></div>
			</div>


			<!-- Compliance Report Builder -->
			<div class="wp-mcp-ai-audit-tool" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px;margin:16px 0;">
				<h3 style="margin-top:0;"><?php esc_html_e( '📋 Compliance Report Builder', 'mcp-ai-wpoos' ); ?></h3>
				<p><?php esc_html_e( 'Export a CSV evidence pack showing current control status, posture score, and recent security events for your chosen compliance framework.', 'mcp-ai-wpoos' ); ?></p>
				<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
					<select id="wp-mcp-ai-compliance-framework" class="regular-text" style="max-width:200px;">
						<option value="owasp"><?php esc_html_e( 'OWASP Top 10', 'mcp-ai-wpoos' ); ?></option>
						<option value="gdpr"><?php esc_html_e( 'GDPR', 'mcp-ai-wpoos' ); ?></option>
						<option value="soc2"><?php esc_html_e( 'SOC 2', 'mcp-ai-wpoos' ); ?></option>
						<option value="hipaa"><?php esc_html_e( 'HIPAA', 'mcp-ai-wpoos' ); ?></option>
					</select>
					<button type="button" id="wp-mcp-ai-export-report-btn" class="button button-primary">
						<span class="dashicons dashicons-download" style="vertical-align:middle;"></span>
						<?php esc_html_e( 'Export CSV', 'mcp-ai-wpoos' ); ?>
					</button>
				</div>
				<div id="wp-mcp-ai-compliance-result" style="margin-top:12px;display:none;"></div>
			</div>

			<!-- Self-test runner -->
			<div class="wp-mcp-ai-audit-tool" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px;margin:16px 0;">
				<h3 style="margin-top:0;"><?php esc_html_e( '🧪 Security Self-Test', 'mcp-ai-wpoos' ); ?></h3>
				<p><?php esc_html_e( 'Fires synthetic auth-fail, IP-block, and prompt-injection events to verify that audit logging, OTel spans, and admin email notifications are wired end-to-end.', 'mcp-ai-wpoos' ); ?></p>
				<button type="button" id="wp-mcp-ai-self-test-btn" class="button button-secondary">
					<?php esc_html_e( 'Run Self-Test', 'mcp-ai-wpoos' ); ?>
				</button>
				<div id="wp-mcp-ai-self-test-result" style="margin-top:12px;display:none;"></div>
			</div>

			<!-- Recovery / safe-mode documentation -->
			<div class="wp-mcp-ai-audit-tool" style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:16px;margin:16px 0;">
				<h3 style="margin-top:0;"><?php esc_html_e( '🛟 Recovery / Safe-Mode', 'mcp-ai-wpoos' ); ?></h3>
				<p><?php esc_html_e( 'If you lock yourself out by enabling HTTPS enforcement or an IP whitelist, add the following constant to your wp-config.php to temporarily bypass all security checks and regain access:', 'mcp-ai-wpoos' ); ?></p>
				<pre style="background:#f6f7f7;padding:12px;border-radius:4px;overflow:auto;"><code>define( 'WP_MCP_AI_SECURITY_SAFE_MODE', true );</code></pre>
				<p><?php esc_html_e( 'Remove the constant once you have corrected the settings. Never leave safe-mode active on a production site.', 'mcp-ai-wpoos' ); ?></p>
				<p>
					<strong><?php esc_html_e( 'WP-CLI rescue command for this site:', 'mcp-ai-wpoos' ); ?></strong>
				</p>
				<pre style="background:#f6f7f7;padding:12px;border-radius:4px;overflow:auto;"><code>wp option patch delete wp_mcp_ai_settings require_authentication_all --url=<?php echo esc_html( site_url() ); ?></code></pre>
			</div>

			<table class="form-table" role="presentation"><!-- reopen form-table -->

			<?php
				$posture_nonce_js = wp_json_encode( $posture_nonce );
				ob_start();
			?>
				jQuery(function($){
					var nonce = <?php echo wp_json_encode( $posture_nonce ); ?>;

					$('#wp-mcp-ai-save-snapshot-btn').on('click', function(){
						var label = $('#wp-mcp-ai-snapshot-label').val().trim();
						$(this).prop('disabled', true);
						var $result = $('#wp-mcp-ai-snapshot-result').show().html('<em>' + <?php echo wp_json_encode( __( 'Saving…', 'mcp-ai-wpoos' ) ); ?> + '</em>');
						wp.apiRequest({
							path: '/mcp-ai/v1/security/snapshot',
							method: 'POST',
							data: { label: label },
							beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', nonce); }
						}).done(function(res){
							$result.html('<p style="color:#46b450;">✅ ' + <?php echo wp_json_encode( __( 'Snapshot saved:', 'mcp-ai-wpoos' ) ); ?> + ' ' + $('<span>').text(res.label).html() + ' (' + $('<span>').text(res.snapshot_id).html() + ')</p>');
						}).fail(function(){
							$result.html('<p style="color:#d63638;">' + <?php echo wp_json_encode( __( 'Save failed.', 'mcp-ai-wpoos' ) ); ?> + '</p>');
						}).always(function(){ $('#wp-mcp-ai-save-snapshot-btn').prop('disabled', false); });
					});

					$('#wp-mcp-ai-load-snapshots-btn').on('click', function(){
						$(this).prop('disabled', true);
						var $list = $('#wp-mcp-ai-snapshot-list').show().html('<em>' + <?php echo wp_json_encode( __( 'Loading…', 'mcp-ai-wpoos' ) ); ?> + '</em>');
						wp.apiRequest({
							path: '/mcp-ai/v1/security/snapshots',
							method: 'GET',
							beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', nonce); }
						}).done(function(res){
							if (!res.snapshots || !res.snapshots.length) {
								$list.html('<p style="color:#646970;">' + <?php echo wp_json_encode( __( 'No snapshots yet.', 'mcp-ai-wpoos' ) ); ?> + '</p>');
								return;
							}
							var html = '<table class="widefat striped" style="max-width:700px;"><thead><tr><th>' + <?php echo wp_json_encode( __( 'Label', 'mcp-ai-wpoos' ) ); ?> + '</th><th>' + <?php echo wp_json_encode( __( 'Created', 'mcp-ai-wpoos' ) ); ?> + '</th><th></th></tr></thead><tbody>';
							$.each(res.snapshots, function(i, snap){
								html += '<tr><td>' + $('<span>').text(snap.label).html() + '</td><td>' + $('<span>').text(snap.created_at).html() + '</td>';
								html += '<td><button type="button" class="button button-small wp-mcp-ai-restore-btn" data-id="' + $('<span>').text(snap.id).html() + '">' + <?php echo wp_json_encode( __( 'Restore', 'mcp-ai-wpoos' ) ); ?> + '</button></td></tr>';
							});
							html += '</tbody></table>';
							$list.html(html);

							$list.on('click', '.wp-mcp-ai-restore-btn', function(){
								var id = $(this).data('id');
								if (!confirm(<?php echo wp_json_encode( __( 'Restore this snapshot? A backup of current settings will be saved automatically.', 'mcp-ai-wpoos' ) ); ?>)) return;
								$(this).prop('disabled', true);
								wp.apiRequest({
									path: '/mcp-ai/v1/security/restore',
									method: 'POST',
									data: { snapshot_id: id },
									beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', nonce); }
								}).done(function(res){
									$list.html('<p style="color:#46b450;">✅ ' + <?php echo wp_json_encode( __( 'Restored. Reloading page…', 'mcp-ai-wpoos' ) ); ?> + '</p>');
									setTimeout(function(){ window.location.reload(); }, 1500);
								}).fail(function(){
									$list.html('<p style="color:#d63638;">' + <?php echo wp_json_encode( __( 'Restore failed.', 'mcp-ai-wpoos' ) ); ?> + '</p>');
								});
							});
						}).fail(function(){
							$list.html('<p style="color:#d63638;">' + <?php echo wp_json_encode( __( 'Could not load snapshots.', 'mcp-ai-wpoos' ) ); ?> + '</p>');
						}).always(function(){ $('#wp-mcp-ai-load-snapshots-btn').prop('disabled', false); });
					});


					// Compliance report builder.
					$('#wp-mcp-ai-export-report-btn').on('click', function(){
						var fw = $('#wp-mcp-ai-compliance-framework').val();
						$(this).prop('disabled', true);
						var $result = $('#wp-mcp-ai-compliance-result').show().html('<em>' + <?php echo wp_json_encode( __( 'Generating report…', 'mcp-ai-wpoos' ) ); ?> + '</em>');
						wp.apiRequest({
							path: '/mcp-ai/v1/security/compliance-report',
							method: 'POST',
							data: { framework: fw },
							beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', nonce); }
						}).done(function(res){
							// Trigger CSV download.
							var blob = new Blob([res.csv], { type: 'text/csv' });
							var url  = URL.createObjectURL(blob);
							var a    = document.createElement('a');
							a.href     = url;
							a.download = 'security-compliance-' + fw + '-' + res.generated_at.replace(/[^0-9]/g, '') + '.csv';
							document.body.appendChild(a);
							a.click();
							document.body.removeChild(a);
							URL.revokeObjectURL(url);
							$result.html('<p style="color:#46b450;">✅ ' + <?php echo wp_json_encode( __( 'Report downloaded.', 'mcp-ai-wpoos' ) ); ?> + ' (' + $('<span>').text(res.title).html() + ')</p>');
						}).fail(function(){
							$result.html('<p style="color:#d63638;">' + <?php echo wp_json_encode( __( 'Report generation failed.', 'mcp-ai-wpoos' ) ); ?> + '</p>');
						}).always(function(){ $('#wp-mcp-ai-export-report-btn').prop('disabled', false); });
					});
					$('#wp-mcp-ai-self-test-btn').on('click', function(){
						$(this).prop('disabled', true);
						var $result = $('#wp-mcp-ai-self-test-result').show().html('<em>' + <?php echo wp_json_encode( __( 'Running…', 'mcp-ai-wpoos' ) ); ?> + '</em>');
						wp.apiRequest({
							path: '/mcp-ai/v1/security/self-test',
							method: 'POST',
							beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', nonce); }
						}).done(function(res){
							var html = '<table class="widefat striped" style="max-width:600px;"><thead><tr><th>' + <?php echo wp_json_encode( __( 'Test', 'mcp-ai-wpoos' ) ); ?> + '</th><th>' + <?php echo wp_json_encode( __( 'Result', 'mcp-ai-wpoos' ) ); ?> + '</th></tr></thead><tbody>';
							$.each(res.results, function(key, r){
								var icon = r.passed ? '✅' : '❌';
								html += '<tr><td><code>' + $('<span>').text(key).html() + '</code></td><td>' + icon + ' ' + $('<span>').text(r.message).html() + '</td></tr>';
							});
							html += '</tbody></table>';
							$result.html(html);
						}).fail(function(){
							$result.html('<p style="color:#d63638;">' + <?php echo wp_json_encode( __( 'Self-test failed.', 'mcp-ai-wpoos' ) ); ?> + '</p>');
						}).always(function(){ $('#wp-mcp-ai-self-test-btn').prop('disabled', false); });
					});
				});
				<?php
				$js = ob_get_clean();
				wp_print_inline_script_tag( $js );
				?>
			<?php
		}

		/**
		 * Render a read-only capability fence table on the AI Safety sub-tab.
		 *
		 * Shows tool slug, required_capability, and capability flags for every
		 * registered tool.  Flags tools that are missing capability declarations.
		 */
		private function render_capability_fence_hint() {
			if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				return;
			}
			?>
			</table>

			<div class="wp-mcp-ai-cap-fence" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px;margin:16px 0;">
				<h3 style="margin-top:0;"><?php esc_html_e( '🔒 Capability Fence Audit (read-only)', 'mcp-ai-wpoos' ); ?></h3>
				<p><?php esc_html_e( 'Every registered tool with its required capability and capability flags. Tools missing a capability declaration are highlighted.', 'mcp-ai-wpoos' ); ?></p>
				<?php
				$registry = WP_MCP_AI_Tool_Registry::get_instance();
				// Use get_all_tools() so the array is keyed by slug rather than numerically.
				$tools    = $registry->get_all_tools();
				if ( empty( $tools ) ) {
					echo '<p style="color:#646970;">' . esc_html__( 'No tools registered.', 'mcp-ai-wpoos' ) . '</p>';
				} else {
					echo '<div style="max-height:400px;overflow:auto;">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static HTML
					echo '<table class="widefat striped">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static HTML
					echo '<thead><tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static HTML
					echo '<th>' . esc_html__( 'Slug', 'mcp-ai-wpoos' ) . '</th>';
					echo '<th>' . esc_html__( 'Required Capability', 'mcp-ai-wpoos' ) . '</th>';
					echo '<th>' . esc_html__( 'Flags', 'mcp-ai-wpoos' ) . '</th>';
					echo '</tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static HTML
					foreach ( $tools as $slug => $tool ) {
						$def = method_exists( $tool, 'get_definition' ) ? (array) $tool->get_definition() : array();

						// Capability can live either in the definition array or behind a dedicated getter.
						$cap = '';
						if ( ! empty( $def['required_capability'] ) && is_string( $def['required_capability'] ) ) {
							$cap = $def['required_capability'];
						} elseif ( method_exists( $tool, 'get_required_capability' ) ) {
							$resolved = $tool->get_required_capability();
							if ( is_string( $resolved ) ) {
								$cap = $resolved;
							}
						}
						// Normalize capability flags: tolerate both indexed arrays of strings
						// (interface contract) and legacy associative `flag => bool` shapes.
						$flags     = array();
						$raw_flags = array();
						if ( method_exists( $tool, 'get_capability_flags' ) ) {
							$raw_flags = (array) $tool->get_capability_flags();
						}
						foreach ( $raw_flags as $flag_key => $flag_value ) {
							if ( is_string( $flag_key ) ) {
								// Associative form: include the key when the value is truthy.
								if ( $flag_value ) {
									$flags[] = $flag_key;
								}
							} elseif ( is_string( $flag_value ) && '' !== $flag_value ) {
								// Indexed form: include the string value as-is.
								$flags[] = $flag_value;
							}
						}

						$missing   = ( '' === $cap );
						$row_class = $missing ? 'wp-mcp-ai-cap-fence-missing' : '';
						echo '<tr class="' . esc_attr( $row_class ) . '">';
						echo '<td><code>' . esc_html( (string) $slug ) . '</code></td>';
						echo '<td>' . ( $missing ? '<span style="color:#d63638;">' . esc_html__( 'Missing!', 'mcp-ai-wpoos' ) . '</span>' : '<code>' . esc_html( $cap ) . '</code>' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- conditional of esc_html outputs
						echo '<td>' . esc_html( implode( ', ', $flags ) ) . '</td>';
						echo '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static HTML
					}
					echo '</tbody></table></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static HTML
				}
				?>
			</div>
			<?php
			wp_add_inline_style(
				'wp-mcp-ai-section-security',
				'.wp-mcp-ai-cap-fence-missing{background:#fef9f9;}'
			);
			?>
			<table class="form-table" role="presentation">
			<?php
		}

		/**
		 * Render a deprecated-alias telemetry table on the AI Safety sub-tab.
		 *
		 * Shows every registered deprecated alias (from P5 Part 2 infrastructure) so
		 * admins can see which legacy slugs are still being called and plan migrations.
		 */
		private function render_deprecated_alias_telemetry() {
			if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				return;
			}

			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			if ( ! method_exists( $registry, 'get_deprecated_aliases' ) ) {
				return;
			}

			$aliases = $registry->get_deprecated_aliases();
			?>
</table>

<div class="wp-mcp-ai-cap-fence" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px;margin:16px 0;">
<h3 style="margin-top:0;"><?php esc_html_e( '⚠️ Deprecated Tool-Alias Telemetry (read-only)', 'mcp-ai-wpoos' ); ?></h3>
<p><?php esc_html_e( 'Tool slugs that have been renamed. Legacy callers are still routed to the replacement but should be updated. Each alias fires the', 'mcp-ai-wpoos' ); ?>
<code>wp_mcp_ai_tool_deprecated_alias_invoked</code>
			<?php esc_html_e( 'action once per request.', 'mcp-ai-wpoos' ); ?>
</p>
			<?php if ( empty( $aliases ) ) : ?>
<p style="color:#46b450;"><?php esc_html_e( '✅ No deprecated aliases registered.', 'mcp-ai-wpoos' ); ?></p>
<?php else : ?>
<table class="widefat striped">
<thead>
<tr>
<th><?php esc_html_e( 'Legacy Slug', 'mcp-ai-wpoos' ); ?></th>
<th><?php esc_html_e( 'Replaced By', 'mcp-ai-wpoos' ); ?></th>
<th><?php esc_html_e( 'Deprecated Since', 'mcp-ai-wpoos' ); ?></th>
<th><?php esc_html_e( 'Remove In', 'mcp-ai-wpoos' ); ?></th>
<th><?php esc_html_e( 'Notes', 'mcp-ai-wpoos' ); ?></th>
</tr>
</thead>
<tbody>
	<?php foreach ( $aliases as $old_slug => $entry ) : ?>
<tr>
<td><code><?php echo esc_html( $old_slug ); ?></code></td>
<td><code><?php echo esc_html( $entry['new_slug'] ); ?></code></td>
<td><?php echo esc_html( $entry['since'] ?: '—' ); ?></td>
<td><?php echo esc_html( $entry['remove'] ?: '—' ); ?></td>
<td style="color:#646970;"><?php echo esc_html( $entry['message'] ?: '—' ); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<table class="form-table" role="presentation">
			<?php
		}

		/**
		 * Render a Pro-only MCP-server token inventory panel on the AI Safety sub-tab.
		 *
		 * Shows configured MCP server names and their last-rotated timestamps.
		 * Only rendered when WP_MCP_AI_PRO_VERSION is defined.
		 */
		private function render_mcp_token_inventory() {
			if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
				return;
			}

			$settings    = get_option( 'wp_mcp_ai_settings', array() );
			$mcp_servers = isset( $settings['mcp_servers'] ) && is_array( $settings['mcp_servers'] )
			? $settings['mcp_servers']
			: array();

			// Also check dedicated MCP connections option.
			$connections = get_option( 'wp_mcp_ai_mcp_connections', array() );
			if ( is_array( $connections ) ) {
				foreach ( $connections as $conn ) {
					$mcp_servers[] = $conn;
				}
			}
			?>
</table>

<div class="wp-mcp-ai-cap-fence" style="background:#fff;border:1px solid #e0e0e0;border-radius:4px;padding:16px;margin:16px 0;">
<h3 style="margin-top:0;"><?php esc_html_e( '🔌 MCP Server Token Inventory (Pro)', 'mcp-ai-wpoos' ); ?></h3>
<p><?php esc_html_e( 'Connected MCP servers and their bearer-token rotation status. Tokens should be rotated every 90 days.', 'mcp-ai-wpoos' ); ?></p>
			<?php if ( empty( $mcp_servers ) ) : ?>
<p style="color:#646970;"><?php esc_html_e( 'No MCP servers configured. Add servers under Tools → Connections.', 'mcp-ai-wpoos' ); ?></p>
<?php else : ?>
<table class="widefat striped">
<thead>
<tr>
<th><?php esc_html_e( 'Server', 'mcp-ai-wpoos' ); ?></th>
<th><?php esc_html_e( 'URL', 'mcp-ai-wpoos' ); ?></th>
<th><?php esc_html_e( 'Token Last Rotated', 'mcp-ai-wpoos' ); ?></th>
<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
</tr>
</thead>
<tbody>
	<?php foreach ( $mcp_servers as $server ) : ?>
		<?php
		$name          = esc_html( $server['name'] ?? $server['url'] ?? __( '(unnamed)', 'mcp-ai-wpoos' ) );
		$url           = esc_url( $server['url'] ?? '' );
		$rotated_raw   = $server['token_rotated_at'] ?? '';
		$rotated_ts    = $rotated_raw ? strtotime( $rotated_raw ) : 0;
		$days_since    = $rotated_ts ? (int) ( ( time() - $rotated_ts ) / DAY_IN_SECONDS ) : PHP_INT_MAX;
		$needs_rotation = $days_since > 90;
		$rotated_label = $rotated_ts
		? wp_date( get_option( 'date_format' ), $rotated_ts )
		: __( 'Unknown', 'mcp-ai-wpoos' );
		$status_html   = $needs_rotation
		? '<span style="color:#d63638;">⚠️ ' . esc_html__( 'Rotation overdue', 'mcp-ai-wpoos' ) . '</span>'
		: '<span style="color:#46b450;">✅ ' . esc_html__( 'OK', 'mcp-ai-wpoos' ) . '</span>';
		?>
<tr>
<td><?php echo esc_html( $name ); ?></td>
<td><?php echo esc_html( $url ); ?></td>
<td><?php echo esc_html( $rotated_label ); ?></td>
<td><?php echo $status_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- composed of esc_html() calls above ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<table class="form-table" role="presentation">
			<?php
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

			// Validate guest token TTL.
			if ( isset( $input['guest_token_ttl_hours'] ) ) {
				$ttl = (int) $input['guest_token_ttl_hours'];
				if ( $ttl < 0 || $ttl > 8760 ) {
					$errors[] = __( 'Guest Token TTL must be between 0 and 8760 hours (1 year).', 'mcp-ai-wpoos' );
				}
			}

			// Validate prompt injection sensitivity.
			if ( isset( $input['prompt_injection_sensitivity'] ) ) {
				if ( ! in_array( $input['prompt_injection_sensitivity'], array( 'low', 'medium', 'high' ), true ) ) {
					$errors[] = __( 'Invalid prompt-injection sensitivity value.', 'mcp-ai-wpoos' );
				}
			}

			// Validate HITL threshold.
			if ( isset( $input['hitl_write_tool_threshold'] ) ) {
				if ( ! in_array( $input['hitl_write_tool_threshold'], array( 'none', 'any_write', 'state_changing', 'destructive' ), true ) ) {
					$errors[] = __( 'Invalid HITL write-tool threshold value.', 'mcp-ai-wpoos' );
				}
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
						/* translators: %s: CIDR entry */
						return new WP_Error( 'invalid_cidr', sprintf( __( 'Invalid CIDR format: %s', 'mcp-ai-wpoos' ), $entry ) );
					}

					list($ip, $mask) = $parts;

					// Validate IP part.
					if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
						/* translators: %s: CIDR entry */
						return new WP_Error( 'invalid_cidr_ip', sprintf( __( 'Invalid IP in CIDR: %s', 'mcp-ai-wpoos' ), $entry ) );
					}

					// Validate mask.
					$mask_int = intval( $mask );
					// Check for IPv4 or IPv6.
					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
						if ( $mask_int < 0 || $mask_int > 32 ) {
							/* translators: %s: CIDR entry */
							return new WP_Error( 'invalid_cidr_mask', sprintf( __( 'Invalid IPv4 CIDR mask (must be 0-32): %s', 'mcp-ai-wpoos' ), $entry ) );
						}
					} elseif ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
						if ( $mask_int < 0 || $mask_int > 128 ) {
							/* translators: %s: CIDR entry */
							return new WP_Error( 'invalid_cidr_mask', sprintf( __( 'Invalid IPv6 CIDR mask (must be 0-128): %s', 'mcp-ai-wpoos' ), $entry ) );
						}
					}
				} elseif ( ! filter_var( $entry, FILTER_VALIDATE_IP ) ) {
					// Validate as plain IP address.
					/* translators: %s: IP address */
					return new WP_Error( 'invalid_ip', sprintf( __( 'Invalid IP address: %s', 'mcp-ai-wpoos' ), $entry ) );
				}
			}

			return true;
		}
	}
}
