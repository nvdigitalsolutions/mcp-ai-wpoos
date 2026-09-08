<?php
/**
 * Tests for email presentation formats in the Pro Result Delivery Service.
 *
 * Covers:
 * - WP_MCP_AI_Markdown_Converter: headings, emphasis, lists, GFM tables,
 *   fenced code, blockquotes, horizontal rules, links, and escaping of
 *   untrusted HTML.
 * - format_email(): derives an html_body from Markdown and keeps the plain
 *   Markdown source as the text/plain multipart fallback.
 * - build_email_html(): embeds the converted HTML body.
 * - send_email(): format routing — markdown sends text/plain only, html and
 *   both send text/html, and both supplies html + text to Nodemailer.
 * - sanitize_result_delivery(): email format allowlist with 'both' default.
 * - normalize_channel_credentials(): JSON-string credential coercion.
 * - resolve_channel_credentials(): Remote Sites connection token mapping
 *   (api_key → token) with per-schedule destination field merge.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   GPL-3.0-or-later
 */

// Guard: only run if Pro addon is present.
if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-markdown-converter.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-result-delivery-service.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-media-worker-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-nodemailer-service.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

/**
 * Test suite for email presentation formats in result delivery.
 */
class Test_Pro_Result_Delivery_Email_Format extends WP_UnitTestCase {

	/**
	 * Captured wp_mail() attributes (to, subject, message, headers).
	 *
	 * @var array|null
	 */
	private $captured_mail;

	/**
	 * Captured Nodemailer parameters (html / text presence).
	 *
	 * @var array|null
	 */
	private $captured_nodemailer;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->captured_mail       = null;
		$this->captured_nodemailer = null;

		// Short-circuit wp_mail() and capture the arguments.
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->captured_mail = $atts;
				return true;
			},
			10,
			2
		);

		// Capture Nodemailer parameters and force the wp_mail fallback so the
		// routing assertions are deterministic regardless of transport.
		add_filter(
			'wp_mcp_ai_nodemailer_send_email',
			function ( $result, $params ) {
				$this->captured_nodemailer = $params;
				return false;
			},
			10,
			2
		);

		// Ensure the Media Worker sidecar is never consulted.
		delete_option( 'wp_mcp_ai_media_worker_url' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'wp_mcp_ai_nodemailer_send_email' );
		parent::tearDown();
	}

	/**
	 * Invoke a protected static method via reflection.
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @param string $method     Method name.
	 * @param array  $args       Method arguments.
	 * @return mixed Method return value.
	 */
	private function invoke_static( $class_name, $method, array $args ) {
		$reflection = new ReflectionMethod( $class_name, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	// -------------------------------------------------------------------------
	// Markdown converter
	// -------------------------------------------------------------------------

	/**
	 * Test that a digest-style Markdown document renders to structured HTML.
	 */
	public function test_converter_renders_digest_markdown() {
		$md = "## Received\n\n**Bold** and *italic* and `code`.\n\n- item one\n- item two\n\n"
			. "| # | Action |\n|---|--------|\n| 1 | Do it |\n\n"
			. "```\necho '<script>';\n```\n\n[Link](https://example.com)";

		$html = WP_MCP_AI_Markdown_Converter::to_html( $md );

		$this->assertStringContainsString( '<h2', $html );
		$this->assertStringContainsString( '<strong>Bold</strong>', $html );
		$this->assertStringContainsString( '<em>italic</em>', $html );
		$this->assertStringContainsString( '<code', $html );
		$this->assertStringContainsString( '<ul', $html );
		$this->assertStringContainsString( '<li', $html );
		$this->assertStringContainsString( '<table', $html );
		$this->assertStringContainsString( '<th', $html );
		$this->assertStringContainsString( '<td', $html );
		$this->assertStringContainsString( '<pre', $html );
		$this->assertStringContainsString( '<a href="https://example.com"', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '**Bold**', $html );
	}

	/**
	 * Test that untrusted HTML in the source is neutralized.
	 */
	public function test_converter_neutralizes_unsafe_html() {
		$html = WP_MCP_AI_Markdown_Converter::to_html( 'Hello <script>alert(1)</script> **world**' );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( '<strong>world</strong>', $html );
	}

	/**
	 * Test that links with unsafe protocols are not turned into anchors.
	 */
	public function test_converter_rejects_unsafe_link_protocols() {
		$html = WP_MCP_AI_Markdown_Converter::to_html( '[x](javascript:alert(1)) and [y](https://ok.com)' );

		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringContainsString( '<a href="https://ok.com"', $html );
	}

	/**
	 * Test horizontal rules and blockquotes render as expected.
	 */
	public function test_converter_renders_rules_and_blockquotes() {
		$html = WP_MCP_AI_Markdown_Converter::to_html( "Before\n\n---\n\n> Quoted text" );

		$this->assertStringContainsString( '<hr', $html );
		$this->assertStringContainsString( '<blockquote', $html );
		$this->assertStringContainsString( 'Quoted text', $html );
	}

	/**
	 * Test empty input yields an empty fragment.
	 */
	public function test_converter_empty_input() {
		$this->assertSame( '', WP_MCP_AI_Markdown_Converter::to_html( '' ) );
		$this->assertSame( '', WP_MCP_AI_Markdown_Converter::to_html( " \n\n " ) );
	}

	// -------------------------------------------------------------------------
	// format_email() / build_email_html()
	// -------------------------------------------------------------------------

	/**
	 * Test that format_email() derives html_body from Markdown and keeps plain.
	 */
	public function test_format_email_full_includes_html_body() {
		$shared   = array(
			'schedule_name' => 'Inbox Digest',
			'summary'       => 'Here is **your** digest.',
			'response'      => "## Results\n\n**Item** 1\n\n| A | B |\n|---|---|\n| x | y |",
			'status'        => 'success',
			'is_success'    => true,
			'generated_at'  => time(),
			'schedule_type' => 'assistant_run',
		);
		$envelope = array(
			'summary'      => 'Here is **your** digest.',
			'response'     => "## Results\n\n**Item** 1\n\n| A | B |\n|---|---|\n| x | y |",
			'status'       => 'success',
			'generated_at' => time(),
			'data'         => array( 'items' => array( 'a', 'b' ) ),
		);

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_email',
			array( $shared, $envelope, 'full' )
		);

		$this->assertArrayHasKey( 'plain', $payload );
		$this->assertArrayHasKey( 'html_body', $payload );
		// The plain Markdown source is preserved for the text/plain fallback.
		$this->assertStringContainsString( '**', $payload['plain'] );
		// The HTML body carries the rendered structure, not raw Markdown.
		$this->assertStringContainsString( '<strong>', $payload['html_body'] );
		$this->assertStringContainsString( '<h2', $payload['html_body'] );
		$this->assertStringContainsString( '<table', $payload['html_body'] );
		$this->assertStringNotContainsString( '**', $payload['html_body'] );
	}

	/**
	 * Test that build_email_html() embeds the converted body.
	 */
	public function test_build_email_html_embeds_converted_body() {
		$payload = array(
			'subject'    => 'Test',
			'plain'      => '**Bold** markdown',
			'html_body'  => '<p><strong>Bold</strong> markdown</p>',
			'is_error'   => false,
			'site_name'  => 'Site',
			'manage_url' => admin_url(),
		);

		$html = $this->invoke_static( 'WP_MCP_AI_Result_Delivery_Service', 'build_email_html', array( $payload ) );

		$this->assertStringContainsString( '<strong>Bold</strong>', $html );
		$this->assertStringNotContainsString( '**Bold**', $html );
	}

	/**
	 * Test that build_email_html() keeps the legacy nl2br fallback without html_body.
	 */
	public function test_build_email_html_falls_back_without_html_body() {
		$payload = array(
			'subject'    => 'Test',
			'plain'      => "Line one\nLine two",
			'is_error'   => false,
			'site_name'  => 'Site',
			'manage_url' => admin_url(),
		);

		$html = $this->invoke_static( 'WP_MCP_AI_Result_Delivery_Service', 'build_email_html', array( $payload ) );

		$this->assertStringContainsString( 'Line one<br', $html );
		$this->assertStringContainsString( 'Line two', $html );
	}

	// -------------------------------------------------------------------------
	// send_email() format routing
	// -------------------------------------------------------------------------

	/**
	 * Build a minimal formatted email payload.
	 *
	 * @return array Email payload.
	 */
	private function email_payload() {
		return array(
			'subject'    => 'Schedule Result',
			'plain'      => '# Digest' . "\n\n" . '**Bold** markdown body',
			'html_body'  => '<h2>Digest</h2><p><strong>Bold</strong> markdown body</p>',
			'is_error'   => false,
			'site_name'  => 'Test Site',
			'manage_url' => admin_url(),
		);
	}

	/**
	 * Join captured wp_mail headers into a single string.
	 *
	 * @return string Headers as a newline-joined string.
	 */
	private function captured_headers() {
		if ( null === $this->captured_mail ) {
			return '';
		}
		return implode( "\n", (array) $this->captured_mail['headers'] );
	}

	/**
	 * Test that the markdown format sends text/plain only.
	 */
	public function test_send_email_markdown_format_sends_plain_text_only() {
		$payload = $this->email_payload();
		$config  = array(
			'to'     => 'recipient@example.com',
			'format' => 'markdown',
		);

		$result = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'send_email',
			array( $payload, $config )
		);

		$this->assertTrue( $result );
		$this->assertNotNull( $this->captured_mail );
		$this->assertStringContainsString( 'text/plain', $this->captured_headers() );
		$this->assertSame( $payload['plain'], $this->captured_mail['message'] );

		// When Nodemailer is in play it must not receive an html part.
		if ( null !== $this->captured_nodemailer ) {
			$this->assertArrayNotHasKey( 'html', $this->captured_nodemailer );
			$this->assertArrayHasKey( 'text', $this->captured_nodemailer );
		}
	}

	/**
	 * Test that the html format sends text/html only.
	 */
	public function test_send_email_html_format_sends_html_only() {
		$payload = $this->email_payload();
		$config  = array(
			'to'     => 'recipient@example.com',
			'format' => 'html',
		);

		$result = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'send_email',
			array( $payload, $config )
		);

		$this->assertTrue( $result );
		$this->assertNotNull( $this->captured_mail );
		$this->assertStringContainsString( 'text/html', $this->captured_headers() );
		$this->assertStringContainsString( '<strong>Bold</strong>', $this->captured_mail['message'] );

		// When Nodemailer is in play it must not receive a text part.
		if ( null !== $this->captured_nodemailer ) {
			$this->assertArrayHasKey( 'html', $this->captured_nodemailer );
			$this->assertArrayNotHasKey( 'text', $this->captured_nodemailer );
		}
	}

	/**
	 * Test that the both format supplies html + text to Nodemailer.
	 */
	public function test_send_email_both_format_supplies_html_and_text() {
		$payload = $this->email_payload();
		$config  = array(
			'to'     => 'recipient@example.com',
			'format' => 'both',
		);

		$result = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'send_email',
			array( $payload, $config )
		);

		$this->assertTrue( $result );

		// The Nodemailer capture only exists when Nodemailer is available;
		// when it is, the multipart/alternative pair must be complete.
		if ( null !== $this->captured_nodemailer ) {
			$this->assertArrayHasKey( 'html', $this->captured_nodemailer );
			$this->assertArrayHasKey( 'text', $this->captured_nodemailer );
			$this->assertSame( $payload['plain'], $this->captured_nodemailer['text'] );
		}

		// The wp_mail fallback carries the HTML body.
		$this->assertNotNull( $this->captured_mail );
		$this->assertStringContainsString( 'text/html', $this->captured_headers() );
		$this->assertStringContainsString( '<strong>Bold</strong>', $this->captured_mail['message'] );
	}

	/**
	 * Test that an unknown format falls back to 'both' behaviour.
	 */
	public function test_send_email_unknown_format_falls_back_to_both() {
		$payload = $this->email_payload();
		$config  = array(
			'to'     => 'recipient@example.com',
			'format' => 'nonsense',
		);

		$result = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'send_email',
			array( $payload, $config )
		);

		$this->assertTrue( $result );
		$this->assertNotNull( $this->captured_mail );
		$this->assertStringContainsString( 'text/html', $this->captured_headers() );
	}

	/**
	 * Test resolve_email_format() defaulting.
	 */
	public function test_resolve_email_format_defaults_to_both() {
		$resolve = function ( array $config ) {
			return $this->invoke_static(
				'WP_MCP_AI_Result_Delivery_Service',
				'resolve_email_format',
				array( $config )
			);
		};

		$this->assertSame( 'both', $resolve( array() ) );
		$this->assertSame( 'both', $resolve( array( 'format' => 'invalid' ) ) );
		$this->assertSame( 'markdown', $resolve( array( 'format' => 'markdown' ) ) );
		$this->assertSame( 'html', $resolve( array( 'format' => 'html' ) ) );
		$this->assertSame( 'both', $resolve( array( 'format' => 'both' ) ) );
	}

	// -------------------------------------------------------------------------
	// Sanitizer
	// -------------------------------------------------------------------------

	/**
	 * Test that sanitize_result_delivery() allowlists the email format.
	 */
	public function test_sanitize_result_delivery_email_format_allowlist() {
		$delivery = array(
			'on_success' => array(
				'channels' => array(
					'email' => array(
						'enabled'  => true,
						'to'       => 'a@b.com',
						'template' => 'full',
						'format'   => 'markdown',
					),
				),
			),
			'on_failure' => array(
				'channels' => array(),
			),
		);

		$sanitized = WP_MCP_AI_Pro_Schedule_Manager::sanitize_result_delivery( $delivery );
		$this->assertSame( 'markdown', $sanitized['on_success']['channels']['email']['format'] );

		// Invalid value → 'both'.
		$delivery['on_success']['channels']['email']['format'] = 'nonsense';
		$sanitized = WP_MCP_AI_Pro_Schedule_Manager::sanitize_result_delivery( $delivery );
		$this->assertSame( 'both', $sanitized['on_success']['channels']['email']['format'] );

		// Absent → 'both' (backward compatibility).
		unset( $delivery['on_success']['channels']['email']['format'] );
		$sanitized = WP_MCP_AI_Pro_Schedule_Manager::sanitize_result_delivery( $delivery );
		$this->assertSame( 'both', $sanitized['on_success']['channels']['email']['format'] );
	}

	/**
	 * Inline chat-channel credentials stored as a JSON string (the shape the
	 * Schedule Manager edit modal previously saved) must be coerced back into
	 * a sanitized array at the sanitize_result_delivery() boundary.
	 */
	public function test_sanitize_result_delivery_coerces_string_credentials() {
		$delivery = array(
			'on_success' => array(
				'channels' => array(
					'telegram' => array(
						'enabled'              => true,
						'template'             => 'summary',
						'telegram_credentials' => '{"token":"123:ABC","chat_id":"-1001"}',
					),
				),
			),
			'on_failure' => array(
				'channels' => array(),
			),
		);

		$sanitized = WP_MCP_AI_Pro_Schedule_Manager::sanitize_result_delivery( $delivery );
		$telegram  = $sanitized['on_success']['channels']['telegram'];

		$this->assertArrayHasKey( 'telegram_credentials', $telegram );
		$this->assertIsArray( $telegram['telegram_credentials'] );
		$this->assertSame( '123:ABC', $telegram['telegram_credentials']['token'] );
		$this->assertSame( '-1001', $telegram['telegram_credentials']['chat_id'] );
	}

	/**
	 * Normalize_channel_credentials() must decode JSON-string credentials and
	 * return an empty array for any other non-array value so send_chat()
	 * fails with a WP_Error instead of a TypeError.
	 */
	public function test_normalize_channel_credentials_decodes_json_strings() {
		$normalize = function ( $raw ) {
			return $this->invoke_static(
				'WP_MCP_AI_Result_Delivery_Service',
				'normalize_channel_credentials',
				array( $raw )
			);
		};

		// JSON string → array.
		$decoded = $normalize( '{"token":"123:ABC","chat_id":"-1001"}' );
		$this->assertSame(
			array(
				'token'   => '123:ABC',
				'chat_id' => '-1001',
			),
			$decoded
		);

		// Array passes through untouched.
		$array = array( 'token' => '456:DEF' );
		$this->assertSame( $array, $normalize( $array ) );

		// Bare non-JSON string → empty array.
		$this->assertSame( array(), $normalize( '123:ABC' ) );

		// Scalar garbage → empty array.
		$this->assertSame( array(), $normalize( 42 ) );
		$this->assertSame( array(), $normalize( null ) );
	}

	/**
	 * Connection-ID resolution must read the real Remote Sites storage
	 * schema (encrypted token under api_key) and merge the destination
	 * chat_id from the schedule's channel config.
	 */
	public function test_resolve_channel_credentials_from_connection_merges_destination() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		update_option(
			WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME,
			array(
				'conn_testbot123' => array(
					'id'              => 'conn_testbot123',
					'name'            => 'Telegram Bot',
					'connection_type' => 'telegram',
					'api_key'         => '1234567890:ABCdefGHIjklMNOpqrsTUVwxyz',
				),
			)
		);

		$creds = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'resolve_channel_credentials',
			array(
				'telegram',
				array(
					'connection_id' => 'conn_testbot123',
					'chat_id'       => '-1001234567890',
				),
			)
		);

		$this->assertSame( '1234567890:ABCdefGHIjklMNOpqrsTUVwxyz', $creds['token'] );
		$this->assertSame( '-1001234567890', $creds['chat_id'] );

		// Without a destination in the config the connection still resolves
		// the token alone; the broadcast tool reports the missing chat_id.
		$token_only = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'resolve_channel_credentials',
			array(
				'telegram',
				array( 'connection_id' => 'conn_testbot123' ),
			)
		);
		$this->assertSame( '1234567890:ABCdefGHIjklMNOpqrsTUVwxyz', $token_only['token'] );
		$this->assertArrayNotHasKey( 'chat_id', $token_only );

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}

	/**
	 * When a channel config carries no connection_id and no inline credentials,
	 * resolve_channel_credentials() must fall back to the first enabled Remote
	 * Sites connection of the channel's type and merge the destination field
	 * (chat_id) from the schedule config.
	 */
	public function test_resolve_channel_credentials_falls_back_to_enabled_connection() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		update_option(
			WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME,
			array(
				'conn_fallbackbot' => array(
					'id'              => 'conn_fallbackbot',
					'name'            => 'Fallback Bot',
					'connection_type' => 'telegram',
					'enabled'         => true,
					'api_key'         => '111111:TOKEN_ONE',
				),
			)
		);

		$creds = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'resolve_channel_credentials',
			array(
				'telegram',
				array( 'chat_id' => '-100987654321' ),
				array(),
			)
		);

		$this->assertSame( '111111:TOKEN_ONE', $creds['token'] );
		$this->assertSame( '-100987654321', $creds['chat_id'] );

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}

	/**
	 * The fallback must prefer a connection the schedule's assistant is
	 * assigned to over other enabled connections of the same type.
	 */
	public function test_resolve_channel_credentials_prefers_assistant_assigned_connection() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		update_option(
			WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME,
			array(
				'conn_firstbot'    => array(
					'id'              => 'conn_firstbot',
					'name'            => 'First Bot',
					'connection_type' => 'telegram',
					'enabled'         => true,
					'api_key'         => '222222:TOKEN_FIRST',
				),
				'conn_assignedbot' => array(
					'id'                     => 'conn_assignedbot',
					'name'                   => 'Assigned Bot',
					'connection_type'        => 'telegram',
					'enabled'                => true,
					'api_key'                => '333333:TOKEN_ASSIGNED',
					'assigned_assistant_ids' => array( 42 ),
				),
			)
		);

		$creds = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'resolve_channel_credentials',
			array(
				'telegram',
				array( 'chat_id' => '-100111' ),
				array( 'assistant_config' => array( 'assistant_id' => 42 ) ),
			)
		);

		$this->assertSame( '333333:TOKEN_ASSIGNED', $creds['token'] );

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}

	/**
	 * Disabled Remote Sites connections must be ignored by the fallback, and a
	 * channel with no usable connection at all must resolve to an empty array.
	 */
	public function test_resolve_channel_credentials_skips_disabled_connections() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		update_option(
			WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME,
			array(
				'conn_offbot' => array(
					'id'              => 'conn_offbot',
					'name'            => 'Disabled Bot',
					'connection_type' => 'telegram',
					'enabled'         => false,
					'api_key'         => '444444:TOKEN_OFF',
				),
			)
		);

		$creds = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'resolve_channel_credentials',
			array(
				'telegram',
				array( 'chat_id' => '-100222' ),
				array(),
			)
		);

		$this->assertSame( array(), $creds );

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}

	/**
	 * Verify build_credential_diagnostics() describes the resolution failure
	 * without ever leaking secret material.
	 */
	public function test_build_credential_diagnostics_never_contains_secrets() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		update_option(
			WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME,
			array(
				'conn_diagbot' => array(
					'id'              => 'conn_diagbot',
					'name'            => 'Diag Bot',
					'connection_type' => 'telegram',
					'enabled'         => true,
					'api_key'         => '555555:SUPER_SECRET',
				),
			)
		);

		$diagnostics = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'build_credential_diagnostics',
			array(
				'telegram',
				array(
					'enabled' => true,
					'chat_id' => '-100333',
				),
			)
		);

		$this->assertSame( 1, $diagnostics['matching_connections'] );
		$this->assertSame( 1, $diagnostics['enabled_matching_connections'] );
		$this->assertTrue( $diagnostics['has_destination_field'] );
		$this->assertFalse( $diagnostics['has_inline_credentials'] );

		$serialized = wp_json_encode( $diagnostics );
		$this->assertStringNotContainsString( 'SUPER_SECRET', $serialized );

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}
}
