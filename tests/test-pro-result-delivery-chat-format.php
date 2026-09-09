<?php
/**
 * Tests for chat presentation formats and templates in the Pro Result
 * Delivery Service.
 *
 * Covers:
 * - resolve_chat_format(): per-channel allowlists and defaults.
 * - telegram_parse_mode(): format → Telegram parse_mode mapping.
 * - escape_markdown_v2(): reserved-character escaping.
 * - format_chat(): format-aware markup, full template (response + data),
 *   plain template (no markup), and parse_mode payload key.
 * - sanitize_result_delivery(): chat format allowlist and full template
 *   acceptance.
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
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

/**
 * Test suite for chat presentation formats in result delivery.
 */
class Test_Pro_Result_Delivery_Chat_Format extends WP_UnitTestCase {

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

	/**
	 * Shared shared-fields fixture for format_chat().
	 *
	 * @return array
	 */
	private function chat_shared() {
		return array(
			'schedule_name' => 'Daily Report',
			'summary'       => 'Generated 5 posts.',
			'response'      => 'All tasks completed successfully.',
			'status'        => 'success',
			'is_success'    => true,
			'generated_at'  => time(),
			'schedule_type' => 'assistant_run',
		);
	}

	// -------------------------------------------------------------------------
	// Format resolution
	// -------------------------------------------------------------------------

	/**
	 * Resolve_chat_format() must apply per-channel defaults when the config
	 * carries no format.
	 */
	public function test_resolve_chat_format_defaults_per_channel() {
		$resolve = function ( $channel ) {
			return $this->invoke_static(
				'WP_MCP_AI_Result_Delivery_Service',
				'resolve_chat_format',
				array( $channel, array() )
			);
		};

		$this->assertSame( 'html', $resolve( 'telegram' ) );
		$this->assertSame( 'markdown', $resolve( 'whatsapp' ) );
		$this->assertSame( 'markdown', $resolve( 'slack' ) );
		$this->assertSame( 'markdown', $resolve( 'discord' ) );
		$this->assertSame( 'markdown', $resolve( 'teams' ) );
		$this->assertSame( 'plain', $resolve( 'messenger' ) );
		$this->assertSame( 'plain', $resolve( 'google_chat' ) );
	}

	/**
	 * Resolve_chat_format() must honour valid configured formats and fall
	 * back to the channel default for invalid or unavailable ones.
	 */
	public function test_resolve_chat_format_allowlist_per_channel() {
		$resolve = function ( $channel, $format ) {
			return $this->invoke_static(
				'WP_MCP_AI_Result_Delivery_Service',
				'resolve_chat_format',
				array( $channel, array( 'format' => $format ) )
			);
		};

		// Telegram supports all four formats.
		$this->assertSame( 'markdown_v2', $resolve( 'telegram', 'markdown_v2' ) );
		$this->assertSame( 'plain', $resolve( 'telegram', 'plain' ) );
		$this->assertSame( 'markdown', $resolve( 'telegram', 'markdown' ) );

		// Invalid value → channel default.
		$this->assertSame( 'html', $resolve( 'telegram', 'nonsense' ) );

		// WhatsApp: markdown_v2 is not available → default.
		$this->assertSame( 'markdown', $resolve( 'whatsapp', 'markdown_v2' ) );
		$this->assertSame( 'plain', $resolve( 'whatsapp', 'plain' ) );

		// Messenger: only plain.
		$this->assertSame( 'plain', $resolve( 'messenger', 'markdown' ) );

		// Unknown channel → generic default.
		$this->assertSame( 'markdown', $resolve( 'carrier_pigeon', 'html' ) );
	}

	/**
	 * Telegram_parse_mode() must map chat formats to Telegram parse modes.
	 */
	public function test_telegram_parse_mode_mapping() {
		$map = function ( $format ) {
			return $this->invoke_static(
				'WP_MCP_AI_Result_Delivery_Service',
				'telegram_parse_mode',
				array( $format )
			);
		};

		$this->assertSame( 'HTML', $map( 'html' ) );
		$this->assertSame( 'Markdown', $map( 'markdown' ) );
		$this->assertSame( 'MarkdownV2', $map( 'markdown_v2' ) );
		$this->assertSame( '', $map( 'plain' ) );
		$this->assertSame( '', $map( 'bogus' ) );
	}

	/**
	 * Escape_markdown_v2() must backslash-escape Telegram reserved characters.
	 */
	public function test_escape_markdown_v2_escapes_reserved_characters() {
		$escape = function ( $text ) {
			return $this->invoke_static(
				'WP_MCP_AI_Result_Delivery_Service',
				'escape_markdown_v2',
				array( $text )
			);
		};

		$this->assertSame( 'a\\_b\\*c\\.d', $escape( 'a_b*c.d' ) );
		$this->assertSame( '\\[link\\]\\(url\\)', $escape( '[link](url)' ) );
		$this->assertSame( 'plain', $escape( 'plain' ) );
	}

	// -------------------------------------------------------------------------
	// format_chat() output
	// -------------------------------------------------------------------------

	/**
	 * The html format must wrap the schedule name in <b> tags and carry
	 * parse_mode HTML.
	 */
	public function test_format_chat_html_uses_bold_tags() {
		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_chat',
			array( $this->chat_shared(), 'summary', 'html', array() )
		);

		$this->assertStringContainsString( '<b>Daily Report</b>', $payload['message'] );
		$this->assertSame( 'HTML', $payload['parse_mode'] );
	}

	/**
	 * The markdown format must use *bold* markers.
	 */
	public function test_format_chat_markdown_uses_asterisks() {
		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_chat',
			array( $this->chat_shared(), 'summary', 'markdown', array() )
		);

		$this->assertStringContainsString( '*Daily Report*', $payload['message'] );
		$this->assertSame( 'Markdown', $payload['parse_mode'] );
	}

	/**
	 * The markdown_v2 format must escape reserved characters in the content
	 * and map to MarkdownV2 parse mode.
	 */
	public function test_format_chat_markdown_v2_escapes_content() {
		$shared                  = $this->chat_shared();
		$shared['summary']       = 'Check the report (final).';
		$shared['schedule_name'] = 'Weekly_Report';

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_chat',
			array( $shared, 'summary', 'markdown_v2', array() )
		);

		$this->assertStringContainsString( 'Weekly\\_Report', $payload['message'] );
		$this->assertStringContainsString( 'Check the report \\(final\\)\\.', $payload['message'] );
		$this->assertSame( 'MarkdownV2', $payload['parse_mode'] );
	}

	/**
	 * The plain format must add no markup and no parse mode.
	 */
	public function test_format_chat_plain_has_no_markup() {
		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_chat',
			array( $this->chat_shared(), 'summary', 'plain', array() )
		);

		$this->assertStringNotContainsString( '*', $payload['message'] );
		$this->assertStringNotContainsString( '<b>', $payload['message'] );
		$this->assertSame( '', $payload['parse_mode'] );
	}

	/**
	 * The full template must include the complete response and the structured
	 * envelope data, mirroring the email full template.
	 */
	public function test_format_chat_full_includes_response_and_data() {
		$envelope = array(
			'data' => array(
				'posts_created' => 5,
				'tags'          => array( 'seo', 'news' ),
			),
		);

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_chat',
			array( $this->chat_shared(), 'full', 'plain', $envelope )
		);

		$this->assertStringContainsString( 'Generated 5 posts.', $payload['message'] );
		$this->assertStringContainsString( 'All tasks completed successfully.', $payload['message'] );
		$this->assertStringContainsString( 'posts_created: 5', $payload['message'] );
		$this->assertStringContainsString( 'tags: seo, news', $payload['message'] );
		$this->assertSame( '', $payload['parse_mode'] );
	}

	// -------------------------------------------------------------------------
	// Sanitization
	// -------------------------------------------------------------------------

	/**
	 * Sanitize_result_delivery() must allowlist chat formats per channel and
	 * drop invalid values so the delivery service resolves the default.
	 */
	public function test_sanitize_result_delivery_chat_format_allowlist() {
		$delivery = array(
			'on_success' => array(
				'channels' => array(
					'telegram' => array(
						'enabled'  => true,
						'template' => 'full',
						'format'   => 'markdown_v2',
					),
					'whatsapp' => array(
						'enabled'  => true,
						'template' => 'summary',
						'format'   => 'plain',
					),
				),
			),
			'on_failure' => array(
				'channels' => array(),
			),
		);

		$sanitized = WP_MCP_AI_Pro_Schedule_Manager::sanitize_result_delivery( $delivery );

		$telegram = $sanitized['on_success']['channels']['telegram'];
		$this->assertSame( 'full', $telegram['template'] );
		$this->assertSame( 'markdown_v2', $telegram['format'] );

		$whatsapp = $sanitized['on_success']['channels']['whatsapp'];
		$this->assertSame( 'summary', $whatsapp['template'] );
		$this->assertSame( 'plain', $whatsapp['format'] );

		// Invalid format → dropped (delivery resolves the channel default).
		$delivery['on_success']['channels']['telegram']['format'] = 'html_markdown_bogus';
		$sanitized = WP_MCP_AI_Pro_Schedule_Manager::sanitize_result_delivery( $delivery );
		$this->assertArrayNotHasKey( 'format', $sanitized['on_success']['channels']['telegram'] );

		// Format unavailable for the channel → dropped.
		$delivery['on_success']['channels']['telegram']['format'] = 'markdown_v2';
		$delivery['on_success']['channels']['whatsapp']['format'] = 'markdown_v2';
		$sanitized = WP_MCP_AI_Pro_Schedule_Manager::sanitize_result_delivery( $delivery );
		$this->assertArrayNotHasKey( 'format', $sanitized['on_success']['channels']['whatsapp'] );
	}

	/**
	 * Sanitize_result_delivery() must accept the full template for chat
	 * channels (previously limited to summary/error/response_only).
	 */
	public function test_sanitize_result_delivery_accepts_chat_full_template() {
		$delivery = array(
			'on_success' => array(
				'channels' => array(
					'slack' => array(
						'enabled'  => true,
						'template' => 'full',
					),
				),
			),
			'on_failure' => array(
				'channels' => array(),
			),
		);

		$sanitized = WP_MCP_AI_Pro_Schedule_Manager::sanitize_result_delivery( $delivery );
		$this->assertSame( 'full', $sanitized['on_success']['channels']['slack']['template'] );
	}
}
