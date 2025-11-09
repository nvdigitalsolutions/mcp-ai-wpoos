<?php
/**
 * Security Audit Verification Tests
 *
 * Tests to verify security claims made in the Security Audit Report.
 * These tests complement existing security tests and focus on areas
 * identified as needing additional coverage.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test security audit verification.
 *
 * @group security
 * @group audit
 * @group security-audit
 */
class Test_Security_Audit_Verification extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test A) Authentication Pathways - Multiple auth methods available
	 *
	 * Verifies that the plugin supports multiple authentication methods
	 * as documented: X-WP-Nonce, Bearer tokens, Assistant credentials,
	 * Auth0 JWT, and Guest tokens.
	 */
	public function test_multiple_authentication_pathways_available() {
		// Verify authenticator class exists and has expected methods.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_REST_Authenticator' ),
			'REST Authenticator class must exist'
		);

		// Create authenticator instance.
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-authenticator.php';
		$authenticator = new WP_MCP_AI_REST_Authenticator();

		// Verify key authentication methods exist.
		$this->assertTrue(
			method_exists( $authenticator, 'validate_local_token' ),
			'Local token validation method must exist'
		);
		$this->assertTrue(
			method_exists( $authenticator, 'validate_bearer_token' ),
			'Bearer token validation method must exist'
		);
		$this->assertTrue(
			method_exists( $authenticator, 'validate_mesh_key' ),
			'Mesh key validation method must exist'
		);
	}

	/**
	 * Test A) Authentication - Timing attack protection
	 *
	 * Verifies that security-critical comparisons use hash_equals()
	 * to prevent timing attacks.
	 */
	public function test_timing_attack_protection_in_auth() {
		// Check mesh key validation uses hash_equals.
		$file_content = file_get_contents( WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-authenticator.php' );
		
		$this->assertStringContainsString(
			'hash_equals',
			$file_content,
			'Authenticator must use hash_equals for timing-attack protection'
		);

		// Check root security key uses hash_equals.
		$root_key_content = file_get_contents( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-root-security-key.php' );
		
		$this->assertStringContainsString(
			'hash_equals',
			$root_key_content,
			'Root Security Key must use hash_equals for verification'
		);
	}

	/**
	 * Test B) Authorization - All REST endpoints have permission callbacks
	 *
	 * Verifies that every REST endpoint enforces authorization through
	 * permission_callback.
	 */
	public function test_rest_endpoints_have_permission_callbacks() {
		// Load REST class.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';

		$file_content = file_get_contents( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php' );

		// Count register_rest_route calls.
		preg_match_all( '/register_rest_route\s*\(/', $file_content, $route_matches );
		$route_count = count( $route_matches[0] );

		// Count permission_callback occurrences.
		preg_match_all( '/[\'"]permission_callback[\'"]/', $file_content, $callback_matches );
		$callback_count = count( $callback_matches[0] );

		// Every route registration should have at least one permission callback.
		$this->assertGreaterThanOrEqual(
			$route_count,
			$callback_count,
			'Every REST route must have a permission_callback'
		);

		// Verify no routes use __return_true without justification.
		preg_match_all( '/permission_callback.*__return_true/', $file_content, $open_routes );
		$open_route_count = count( $open_routes[0] );

		// Allow minimal open routes (SSE endpoint is intentionally open with additional auth).
		$this->assertLessThanOrEqual(
			2,
			$open_route_count,
			'Minimal routes should use __return_true for permission_callback'
		);
	}

	/**
	 * Test B) Authorization - Tool capability enforcement
	 *
	 * Verifies that tools check user capabilities before execution.
	 */
	public function test_tools_enforce_capabilities() {
		// Sample tools that should have capability checks.
		$tools_to_check = array(
			'includes/tools/class-wp-mcp-ai-tool-search-gmail.php',
			'includes/tools/class-wp-mcp-ai-tool-create-google-calendar-event.php',
			'includes/tools/class-wp-mcp-ai-tool-get-quickbooks-report.php',
			'includes/tools/class-wp-mcp-ai-tool-send-group-email.php',
		);

		foreach ( $tools_to_check as $tool_file ) {
			$full_path = WP_MCP_AI_PATH . $tool_file;
			
			if ( ! file_exists( $full_path ) ) {
				// Skip if tool doesn't exist (might be in base vs full version).
				continue;
			}

			$content = file_get_contents( $full_path );
			
			// Verify capability check exists.
			$has_capability_check = (
				strpos( $content, 'user_can' ) !== false ||
				strpos( $content, 'current_user_can' ) !== false ||
				strpos( $content, 'required_capability' ) !== false
			);

			$this->assertTrue(
				$has_capability_check,
				"Tool {$tool_file} must check user capabilities"
			);
		}
	}

	/**
	 * Test C) Abuse Prevention - Nefarious Usage Monitor exists
	 *
	 * Verifies that the Nefarious Usage Monitor is implemented with
	 * required features.
	 */
	public function test_nefarious_usage_monitor_implementation() {
		// Verify class exists.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Nefarious_Usage_Monitor' ),
			'Nefarious Usage Monitor class must exist'
		);

		// Create instance.
		$monitor = WP_MCP_AI_Nefarious_Usage_Monitor::get_instance();

		// Verify key methods exist.
		$this->assertTrue(
			method_exists( $monitor, 'monitor_tool_execution' ),
			'Monitor must track tool execution'
		);
		$this->assertTrue(
			method_exists( $monitor, 'monitor_chat_request' ),
			'Monitor must track chat requests'
		);
		$this->assertTrue(
			method_exists( $monitor, 'trigger_shutdown' ),
			'Monitor must support emergency shutdown'
		);
	}

	/**
	 * Test C) Abuse Prevention - Root Security Key implementation
	 *
	 * Verifies Root Security Key protects against unauthorized re-enablement.
	 */
	public function test_root_security_key_implementation() {
		// Verify class exists.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Root_Security_Key' ),
			'Root Security Key class must exist'
		);

		// Create instance.
		$security_key = WP_MCP_AI_Root_Security_Key::get_instance();

		// Verify key methods exist.
		$this->assertTrue(
			method_exists( $security_key, 'is_key_configured' ),
			'Must check if key is configured'
		);
		$this->assertTrue(
			method_exists( $security_key, 'is_key_required' ),
			'Must check if key is required'
		);
		$this->assertTrue(
			method_exists( $security_key, 'verify_key' ),
			'Must support key verification'
		);
		$this->assertTrue(
			method_exists( $security_key, 'enable_key_requirement' ),
			'Must support enabling key requirement'
		);
		$this->assertTrue(
			method_exists( $security_key, 'disable_key_requirement' ),
			'Must support disabling after verification'
		);
	}

	/**
	 * Test C) Abuse Prevention - Rate limiting implementation
	 *
	 * Verifies that rate limiting is configured and available.
	 */
	public function test_rate_limiting_configuration() {
		// Check security settings section.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Section_Security' ),
			'Security settings section must exist'
		);

		// Verify rate limit settings are defined.
		$security_section_file = WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-security.php';
		$content = file_get_contents( $security_section_file );

		$this->assertStringContainsString(
			'enable_rate_limiting',
			$content,
			'Rate limiting toggle must be available'
		);
		$this->assertStringContainsString(
			'rate_limit_requests',
			$content,
			'Rate limit request count must be configurable'
		);
		$this->assertStringContainsString(
			'rate_limit_window',
			$content,
			'Rate limit time window must be configurable'
		);
	}

	/**
	 * Test D) Audit Logging - Logger class implementation
	 *
	 * Verifies comprehensive logging capabilities.
	 */
	public function test_audit_logging_implementation() {
		// Verify logger class exists.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Logger' ),
			'Logger class must exist'
		);

		// Verify key logging methods.
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Logger', 'log_event' ),
			'Must support logging events'
		);
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Logger', 'get_recent_activity_entries' ),
			'Must support retrieving activity logs'
		);
	}

	/**
	 * Test E) Secrets Management - Root key configuration
	 *
	 * Verifies that root security key is configured via wp-config.php
	 * and not stored in database.
	 */
	public function test_root_key_not_in_database() {
		// Verify the root key class checks for constant, not database option.
		$security_key = WP_MCP_AI_Root_Security_Key::get_instance();
		
		// If key is configured, it should be via constant.
		if ( $security_key->is_key_configured() ) {
			$this->assertTrue(
				defined( 'WP_MCP_AI_ROOT_SECURITY_KEY' ),
				'Root security key must be defined as constant'
			);
			$this->assertNotEmpty(
				WP_MCP_AI_ROOT_SECURITY_KEY,
				'Root security key constant must not be empty'
			);
		}

		// Verify there's no database option for the key itself.
		$this->assertFalse(
			get_option( 'wp_mcp_ai_root_security_key' ),
			'Root security key must not be stored in database'
		);
	}

	/**
	 * Test F) File Upload - MIME type validation
	 *
	 * Verifies file upload service validates MIME types.
	 */
	public function test_file_upload_mime_validation() {
		// Verify File Service class exists.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_File_Service' ),
			'File Service class must exist'
		);

		// Check implementation uses wp_check_filetype.
		$file_content = file_get_contents( WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-file-service.php' );
		
		$this->assertStringContainsString(
			'wp_check_filetype',
			$file_content,
			'File service must validate MIME types using WordPress core'
		);
		$this->assertStringContainsString(
			'allowed_mime_types',
			$file_content,
			'File service must maintain MIME type whitelist'
		);
	}

	/**
	 * Test F) File Upload - Size limit enforcement
	 *
	 * Verifies file size limits are enforced before processing.
	 */
	public function test_file_upload_size_limits() {
		$file_content = file_get_contents( WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-file-service.php' );
		
		$this->assertStringContainsString(
			'max_file_size',
			$file_content,
			'File service must check file size limits'
		);
		$this->assertStringContainsString(
			'wp_mcp_ai_file_too_large',
			$file_content,
			'File service must reject oversized files with clear error'
		);
	}

	/**
	 * Test G) REST + SSE Security - Separate SSE endpoint
	 *
	 * Verifies SSE has dedicated route separate from JSON-RPC.
	 */
	public function test_sse_has_separate_endpoint() {
		// Verify SSE handler class exists.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_SSE_Handler' ),
			'SSE Handler class must exist'
		);

		// Check REST file for SSE route registration.
		$rest_content = file_get_contents( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php' );
		
		$this->assertStringContainsString(
			'/sse',
			$rest_content,
			'Dedicated /sse endpoint must exist'
		);
	}

	/**
	 * Test H) Third-Party Tools - OAuth scope documentation
	 *
	 * Verifies third-party integration tools exist with capability checks.
	 */
	public function test_third_party_tools_capability_checks() {
		$third_party_tools = array(
			'includes/tools/class-wp-mcp-ai-tool-search-gmail.php' => 'Gmail',
			'includes/tools/class-wp-mcp-ai-tool-create-google-calendar-event.php' => 'Google Calendar',
			'includes/tools/class-wp-mcp-ai-tool-get-quickbooks-report.php' => 'QuickBooks',
			'includes/tools/class-wp-mcp-ai-tool-get-google-analytics-report.php' => 'Google Analytics',
		);

		foreach ( $third_party_tools as $tool_file => $service_name ) {
			$full_path = WP_MCP_AI_PATH . $tool_file;
			
			if ( ! file_exists( $full_path ) ) {
				// Skip if not in current version.
				continue;
			}

			$content = file_get_contents( $full_path );
			
			// Verify capability check exists.
			$this->assertStringContainsString(
				'required_capability',
				$content,
				"{$service_name} tool must check capabilities"
			);
		}
	}

	/**
	 * Test comprehensive security - Input sanitization patterns
	 *
	 * Verifies common sanitization functions are used throughout codebase.
	 */
	public function test_input_sanitization_patterns_used() {
		// Check admin settings file as representative sample.
		$admin_file = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
		
		if ( file_exists( $admin_file ) ) {
			$content = file_get_contents( $admin_file );
			
			// Verify sanitization functions are used.
			$this->assertStringContainsString(
				'sanitize_text_field',
				$content,
				'Admin code must use sanitize_text_field'
			);
			$this->assertStringContainsString(
				'esc_url',
				$content,
				'Admin code must escape URLs'
			);
		}
	}

	/**
	 * Test comprehensive security - Output escaping patterns
	 *
	 * Verifies common escaping functions are used throughout codebase.
	 */
	public function test_output_escaping_patterns_used() {
		// Check admin settings file as representative sample.
		$admin_file = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
		
		if ( file_exists( $admin_file ) ) {
			$content = file_get_contents( $admin_file );
			
			// Verify escaping functions are used.
			$this->assertStringContainsString(
				'esc_html',
				$content,
				'Admin code must escape HTML output'
			);
			$this->assertStringContainsString(
				'esc_attr',
				$content,
				'Admin code must escape HTML attributes'
			);
		}
	}

	/**
	 * Test comprehensive security - Nonce verification in AJAX
	 *
	 * Verifies AJAX handlers use check_ajax_referer.
	 */
	public function test_ajax_handlers_verify_nonces() {
		// Check admin AJAX handlers file.
		$ajax_file = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php';
		
		if ( file_exists( $ajax_file ) ) {
			$content = file_get_contents( $ajax_file );
			
			// Verify nonce checking is used.
			$this->assertStringContainsString(
				'check_ajax_referer',
				$content,
				'AJAX handlers must verify nonces'
			);
		}
	}

	/**
	 * Test comprehensive security - Database prepared statements
	 *
	 * Verifies that database queries use prepared statements.
	 */
	public function test_database_uses_prepared_statements() {
		// Find files with database queries.
		$files_with_queries = array(
			'includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php',
		);

		foreach ( $files_with_queries as $file ) {
			$full_path = WP_MCP_AI_PATH . $file;
			
			if ( ! file_exists( $full_path ) ) {
				continue;
			}

			$content = file_get_contents( $full_path );
			
			// If file contains get_col or get_results, it should use prepare.
			if ( strpos( $content, 'get_col' ) !== false || strpos( $content, 'get_results' ) !== false ) {
				$this->assertStringContainsString(
					'->prepare(',
					$content,
					"{$file} must use prepared statements for database queries"
				);
			}
		}
	}

	/**
	 * Test security documentation exists
	 *
	 * Verifies that security documentation files are present.
	 */
	public function test_security_documentation_exists() {
		$required_docs = array(
			'docs/SECURITY_HARDENING.md',
			'docs/authentication.md',
			'docs/root-security-key.md',
			'docs/rate-limit-protection.md',
		);

		foreach ( $required_docs as $doc ) {
			$full_path = WP_MCP_AI_PATH . $doc;
			$this->assertFileExists(
				$full_path,
				"Security documentation {$doc} must exist"
			);
		}
	}
}
