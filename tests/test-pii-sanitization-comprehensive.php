<?php
/**
 * Comprehensive tests for PII sanitization across all affected tools.
 *
 * @package WP_MCP_AI
 */

/**
 * Test PII sanitization for tools that return personally identifiable information.
 */
class WP_MCP_AI_PII_Sanitization_Comprehensive_Test extends WP_UnitTestCase {

	/**
	 * Test that get_woo_recent_orders sanitizes customer PII.
	 */
	public function test_woo_orders_sanitizes_customer_pii() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_woo_recent_orders' );
		if ( ! $tool ) {
			$this->markTestSkipped( 'WooCommerce orders tool not available' );
		}

		$this->assertInstanceOf( WP_MCP_AI_Tool_LLM_Sanitizer_Interface::class, $tool, 'Tool should implement LLM sanitizer interface.' );

		// Mock order data with customer PII.
		$orders = array(
			array(
				'id'            => 123,
				'order_number'  => 'ORD-123',
				'status'        => 'completed',
				'total'         => 99.99,
				'currency'      => 'USD',
				'created_at'    => '2024-01-01T00:00:00+00:00',
				'billing_name'  => 'John Doe',
				'billing_email' => 'customer@example.com',
			),
			array(
				'id'            => 124,
				'order_number'  => 'ORD-124',
				'status'        => 'processing',
				'total'         => 149.99,
				'currency'      => 'USD',
				'created_at'    => '2024-01-02T00:00:00+00:00',
				'billing_name'  => 'Jane Smith',
				'billing_email' => 'jane@example.com',
			),
		);

		// Sanitize for LLM.
		$sanitized = $tool->sanitize_for_llm( $orders );

		// Verify PII is removed.
		$this->assertIsArray( $sanitized );
		$this->assertCount( 2, $sanitized );

		foreach ( $sanitized as $order ) {
			$this->assertArrayNotHasKey( 'billing_email', $order, 'Customer email should be removed.' );
			$this->assertArrayNotHasKey( 'billing_name', $order, 'Customer name should be removed.' );
			// Essential fields should remain.
			$this->assertArrayHasKey( 'id', $order );
			$this->assertArrayHasKey( 'order_number', $order );
			$this->assertArrayHasKey( 'status', $order );
			$this->assertArrayHasKey( 'total', $order );
		}
	}

	/**
	 * Test that get_site_summary sanitizes admin email.
	 */
	public function test_site_summary_sanitizes_admin_email() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_site_summary' );
		$this->assertNotNull( $tool );
		$this->assertInstanceOf( WP_MCP_AI_Tool_LLM_Sanitizer_Interface::class, $tool, 'Tool should implement LLM sanitizer interface.' );

		// Mock site summary data.
		$summary = array(
			'summary'          => 'Site: Test Site',
			'site_name'        => 'Test Site',
			'site_description' => 'A test site',
			'site_url'         => 'https://example.com',
			'admin_email'      => 'admin@example.com',
			'posts_published'  => 10,
			'pages_published'  => 5,
			'total_users'      => 3,
		);

		// Sanitize for LLM.
		$sanitized = $tool->sanitize_for_llm( $summary );

		// Verify admin email is removed.
		$this->assertArrayNotHasKey( 'admin_email', $sanitized, 'Admin email should be removed.' );

		// Verify essential fields remain.
		$this->assertArrayHasKey( 'summary', $sanitized );
		$this->assertArrayHasKey( 'site_name', $sanitized );
		$this->assertArrayHasKey( 'site_description', $sanitized );
		$this->assertArrayHasKey( 'site_url', $sanitized );
		$this->assertArrayHasKey( 'posts_published', $sanitized );
		$this->assertArrayHasKey( 'pages_published', $sanitized );
		$this->assertArrayHasKey( 'total_users', $sanitized );
	}

	/**
	 * Test that search_gmail sanitizes email addresses.
	 */
	public function test_search_gmail_sanitizes_email_addresses() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'search_gmail' );
		$this->assertNotNull( $tool );
		$this->assertInstanceOf( WP_MCP_AI_Tool_LLM_Sanitizer_Interface::class, $tool, 'Tool should implement LLM sanitizer interface.' );

		// Mock Gmail search results.
		$messages = array(
			array(
				'id'        => 'msg_123',
				'labels'    => array( 'INBOX', 'UNREAD' ),
				'subject'   => 'Important Meeting',
				'from'      => 'sender@example.com',
				'to'        => 'recipient@example.com',
				'date'      => 'Mon, 1 Jan 2024 10:00:00 +0000',
				'timestamp' => 1704103200,
				'snippet'   => 'Meeting scheduled for next week...',
				'permalink' => 'https://mail.google.com/mail/u/0/#all/msg_123',
			),
			array(
				'id'        => 'msg_124',
				'labels'    => array( 'INBOX' ),
				'subject'   => 'Project Update',
				'from'      => 'colleague@example.com',
				'to'        => 'team@example.com',
				'date'      => 'Tue, 2 Jan 2024 14:00:00 +0000',
				'timestamp' => 1704204000,
				'snippet'   => 'Project is on track...',
				'permalink' => 'https://mail.google.com/mail/u/0/#all/msg_124',
			),
		);

		// Sanitize for LLM.
		$sanitized = $tool->sanitize_for_llm( $messages );

		// Verify email addresses are removed.
		$this->assertIsArray( $sanitized );
		$this->assertCount( 2, $sanitized );

		foreach ( $sanitized as $message ) {
			$this->assertArrayNotHasKey( 'from', $message, 'Sender email should be removed.' );
			$this->assertArrayNotHasKey( 'to', $message, 'Recipient email should be removed.' );
			// Essential fields should remain.
			$this->assertArrayHasKey( 'id', $message );
			$this->assertArrayHasKey( 'subject', $message );
			$this->assertArrayHasKey( 'snippet', $message );
			$this->assertArrayHasKey( 'date', $message );
			$this->assertArrayHasKey( 'permalink', $message );
		}
	}

	/**
	 * Test that all PII tools handle non-array input gracefully.
	 */
	public function test_pii_tools_handle_non_array_input() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tools = array(
			'get_user_info',
			'get_site_summary',
			'search_gmail',
		);

		// Add WooCommerce tool if available.
		if ( class_exists( 'WooCommerce' ) ) {
			$tools[] = 'get_woo_recent_orders';
		}

		foreach ( $tools as $tool_slug ) {
			$tool = $registry->get_tool( $tool_slug );
			if ( ! $tool ) {
				continue;
			}

			// Test with string input.
			$result = 'Error message';
			$sanitized = $tool->sanitize_for_llm( $result );
			$this->assertSame( 'Error message', $sanitized, sprintf( '%s should pass through string input unchanged.', $tool_slug ) );

			// Test with null input.
			$result = null;
			$sanitized = $tool->sanitize_for_llm( $result );
			$this->assertNull( $sanitized, sprintf( '%s should pass through null input unchanged.', $tool_slug ) );
		}
	}

	/**
	 * Test that all PII tools implement the sanitizer interface.
	 */
	public function test_all_pii_tools_implement_sanitizer() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$pii_tools = array(
			'get_user_info'         => 'User information',
			'get_site_summary'      => 'Site admin email',
			'search_gmail'          => 'Email addresses',
			'get_woo_recent_orders' => 'Customer information',
		);

		foreach ( $pii_tools as $tool_slug => $pii_type ) {
			$tool = $registry->get_tool( $tool_slug );

			// Skip if tool isn't available (e.g., WooCommerce not installed).
			if ( ! $tool ) {
				continue;
			}

			$this->assertInstanceOf(
				WP_MCP_AI_Tool_LLM_Sanitizer_Interface::class,
				$tool,
				sprintf( 'Tool "%s" handles %s and must implement LLM sanitizer interface.', $tool_slug, $pii_type )
			);
		}
	}
}
