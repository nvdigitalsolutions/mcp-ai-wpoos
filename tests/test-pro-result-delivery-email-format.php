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
 * - send_email(): multi-recipient delivery via a comma-separated 'to' list.
 * - sanitize_email_recipients(): list normalization across every input shape.
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

	/**
	 * Test that numbered entries with indented continuation lines stay in a
	 * single ordered list.
	 *
	 * Workflow digests ship each listing URL as an indented line under its
	 * numbered title. If the converter closes the list at those lines, every
	 * entry becomes its own <ol> and the whole digest renders as "1.".
	 */
	public function test_converter_keeps_indented_continuations_in_one_ordered_list() {
		$md = "## Search for new matching jobs\n\n10 results found.\n\n"
			. "1. Job Alpha — Hourly\n   https://upwork.example/jobs/alpha\n"
			. "2. Job Beta — Hourly\n   https://upwork.example/jobs/beta\n"
			. "3. Job Gamma — Fixed-price\n   https://upwork.example/jobs/gamma";

		$html = WP_MCP_AI_Markdown_Converter::to_html( $md );

		$this->assertSame( 1, substr_count( $html, '<ol' ), 'All entries must share one ordered list.' );
		$this->assertSame( 3, substr_count( $html, '<li' ), 'Each entry must remain its own list item.' );
		$this->assertStringContainsString( 'Job Alpha', $html );
		$this->assertStringContainsString( 'Job Gamma', $html );
		$this->assertStringContainsString( 'https://upwork.example/jobs/alpha', $html );
		$this->assertStringContainsString( '<br>', $html );
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
	 * The full email template must not print the summary when the response
	 * already opens with it — assistant-run envelopes derive the summary
	 * from the response's first words, which previously duplicated the
	 * header inside the delivered email.
	 */
	public function test_format_email_full_skips_summary_prefix_of_response() {
		$response = "Here's your 6-hour email review 👀\n\nWindow reviewed: 13:52 – 19:52 UTC (Wed, Sep 9).\nResult: 2 actionable emails landed in the window, both unread, with the rest being Pinterest promos or earlier messages.";
		$summary  = wp_trim_words( wp_strip_all_tags( $response ), 25, '…' );
		$shared   = array(
			'schedule_name' => 'Inbox Digest',
			'summary'       => $summary,
			'response'      => $response,
			'status'        => 'success',
			'is_success'    => true,
			'generated_at'  => time(),
			'schedule_type' => 'assistant_run',
		);
		$envelope = array(
			'summary'      => $summary,
			'response'     => $response,
			'status'       => 'success',
			'generated_at' => time(),
			'data'         => array( 'tool_calls' => 2 ),
		);

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_email',
			array( $shared, $envelope, 'full' )
		);

		// The duplicated summary is skipped — the body opens with the response.
		$this->assertStringStartsWith( 'Results:', $payload['plain'] );
		// The header line appears exactly once.
		$this->assertSame( 1, substr_count( $payload['plain'], '6-hour email review' ) );
		$this->assertStringContainsString( 'Pinterest promos', $payload['plain'] );
		// The structured data section still follows.
		$this->assertStringContainsString( 'tool_calls: 2', $payload['plain'] );
	}

	/**
	 * The full email template must not render keys that duplicate the response
	 * or expose internal execution metadata (`response`, `assistant_id`,
	 * `is_agentic`) from the envelope data section — they previously produced
	 * a duplicated response and a raw metadata footer at the end of the email.
	 */
	public function test_format_email_full_redacts_duplicate_and_metadata_keys() {
		$response = "Here's your inbox rundown for the last 6 hours. Four messages landed and three of them carry real actions.";
		$summary  = wp_trim_words( wp_strip_all_tags( $response ), 25, '…' );
		$shared   = array(
			'schedule_name' => 'Inbox Digest',
			'summary'       => $summary,
			'response'      => $response,
			'status'        => 'success',
			'is_success'    => true,
			'generated_at'  => time(),
			'schedule_type' => 'assistant_run',
		);
		$envelope = array(
			'summary'      => $summary,
			'response'     => $response,
			'status'       => 'success',
			'generated_at' => time(),
			'data'         => array(
				'response'      => $response,
				'assistant_id'  => 953,
				'is_agentic'    => 1,
				'posts_created' => 3,
			),
		);

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_email',
			array( $shared, $envelope, 'full' )
		);

		// The duplicate response copy and internal flags are stripped.
		$this->assertStringNotContainsString( 'assistant_id', $payload['plain'] );
		$this->assertStringNotContainsString( 'is_agentic', $payload['plain'] );
		$this->assertSame( 1, substr_count( $payload['plain'], 'inbox rundown' ) );
		// Legitimate data keys still render.
		$this->assertStringContainsString( 'posts_created: 3', $payload['plain'] );
	}

	/**
	 * A summary that is not derived from the response must still be prepended
	 * in the full template (workflow/task summaries carry standalone context).
	 */
	public function test_format_email_full_keeps_non_prefix_summary() {
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

		$this->assertStringStartsWith( 'Here is **your** digest.', $payload['plain'] );
		$this->assertStringContainsString( 'Results:', $payload['plain'] );
	}

	/**
	 * The action_items email template must deliver only the actionable section
	 * of the response — the roundup intro and informational sections are dropped.
	 */
	public function test_format_email_action_items_extracts_section() {
		$response = "Inbox roundup.\n\n"
			. "## Needs your attention\n"
			. "1. Call supplier\n"
			. "2. Pay invoice\n\n"
			. "## Informational\n"
			. 'Nothing else.';
		$shared   = array(
			'schedule_name' => 'Inbox Digest',
			'summary'       => wp_trim_words( wp_strip_all_tags( $response ), 25, '…' ),
			'response'      => $response,
			'status'        => 'success',
			'is_success'    => true,
			'generated_at'  => time(),
			'schedule_type' => 'assistant_run',
		);
		$envelope = array(
			'summary'      => $shared['summary'],
			'response'     => $response,
			'status'       => 'success',
			'generated_at' => time(),
		);

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_email',
			array( $shared, $envelope, 'action_items' )
		);

		$this->assertStringContainsString( 'Action items', $payload['plain'] );
		$this->assertStringContainsString( 'Call supplier', $payload['plain'] );
		$this->assertStringContainsString( 'Pay invoice', $payload['plain'] );
		$this->assertStringNotContainsString( 'Inbox roundup', $payload['plain'] );
		$this->assertStringNotContainsString( 'Nothing else', $payload['plain'] );
	}

	/**
	 * The action_items email template must fall back to the substantive response
	 * when the response carries no action section (never the trimmed summary).
	 */
	public function test_format_email_action_items_falls_back_to_response() {
		$shared   = array(
			'schedule_name' => 'Inbox Digest',
			'summary'       => 'All clear.',
			'response'      => 'All quiet on the inbox front.',
			'status'        => 'success',
			'is_success'    => true,
			'generated_at'  => time(),
			'schedule_type' => 'assistant_run',
		);
		$envelope = array(
			'summary'      => 'All clear.',
			'response'     => 'All quiet on the inbox front.',
			'status'       => 'success',
			'generated_at' => time(),
		);

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_email',
			array( $shared, $envelope, 'action_items' )
		);

		$this->assertStringNotContainsString( 'Action items', $payload['plain'] );
		$this->assertStringContainsString( 'All quiet on the inbox front.', $payload['plain'] );
	}

	/**
	 * The summary email template must include a relevant excerpt of the
	 * response (the distillation section, not the roundup intro or trailing
	 * detail sections).
	 */
	public function test_format_email_summary_includes_relevant_excerpt() {
		$response = "Here's the roundup.\n\n"
			. "## Summary\n"
			. "Two orders need attention.\n\n"
			. "## Details\n"
			. 'Order 4412 delayed.';
		$shared   = array(
			'schedule_name' => 'Inbox Digest',
			'summary'       => wp_trim_words( wp_strip_all_tags( $response ), 25, '…' ),
			'response'      => $response,
			'status'        => 'success',
			'is_success'    => true,
			'generated_at'  => time(),
			'schedule_type' => 'assistant_run',
		);
		$envelope = array(
			'summary'      => $shared['summary'],
			'response'     => $response,
			'status'       => 'success',
			'generated_at' => time(),
		);

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_email',
			array( $shared, $envelope, 'summary' )
		);

		$this->assertStringContainsString( 'Two orders need attention', $payload['plain'] );
		$this->assertStringNotContainsString( 'Order 4412 delayed', $payload['plain'] );
		$this->assertStringNotContainsString( 'roundup', $payload['plain'] );
	}

	/**
	 * The summary email template must not print the summary line when the
	 * response already opens with it — assistant-run summaries are a trim of
	 * the response's first words.
	 */
	public function test_format_email_summary_skips_summary_prefix_of_response() {
		$response = "Here's your 6-hour email review 👀\n\nWindow reviewed: 13:52 – 19:52 UTC (Wed, Sep 9).\nResult: 2 actionable emails landed in the window, both unread, with the rest being Pinterest promos or earlier messages.";
		$summary  = wp_trim_words( wp_strip_all_tags( $response ), 25, '…' );
		$shared   = array(
			'schedule_name' => 'Inbox Digest',
			'summary'       => $summary,
			'response'      => $response,
			'status'        => 'success',
			'is_success'    => true,
			'generated_at'  => time(),
			'schedule_type' => 'assistant_run',
		);
		$envelope = array(
			'summary'      => $summary,
			'response'     => $response,
			'status'       => 'success',
			'generated_at' => time(),
		);

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_email',
			array( $shared, $envelope, 'summary' )
		);

		$this->assertStringNotContainsString( '6-hour email review', $payload['plain'] );
		$this->assertStringNotContainsString( 'Window reviewed', $payload['plain'] );
		$this->assertStringContainsString( '2 actionable emails', $payload['plain'] );
		$this->assertStringContainsString( 'Pinterest promos', $payload['plain'] );
	}

	/**
	 * A summary that is not derived from the response must still lead the
	 * summary email, followed by the relevant excerpt.
	 */
	public function test_format_email_summary_keeps_non_prefix_summary_line() {
		$shared   = array(
			'schedule_name' => 'Inbox Digest',
			'summary'       => 'Generated 5 posts.',
			'response'      => 'All tasks completed successfully.',
			'status'        => 'success',
			'is_success'    => true,
			'generated_at'  => time(),
			'schedule_type' => 'workflow',
		);
		$envelope = array(
			'summary'      => 'Generated 5 posts.',
			'response'     => 'All tasks completed successfully.',
			'status'       => 'success',
			'generated_at' => time(),
		);

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_email',
			array( $shared, $envelope, 'summary' )
		);

		$this->assertStringStartsWith( 'Generated 5 posts.', $payload['plain'] );
		$this->assertStringContainsString( 'All tasks completed successfully.', $payload['plain'] );
		$this->assertStringContainsString( '---', $payload['plain'] );
	}

	/**
	 * Workflow envelopes store the step log under `data.steps`; the full email
	 * template must render it as a compact execution log — never as a
	 * flattened dot-notation dump of the nested step results.
	 */
	public function test_format_email_full_renders_workflow_steps_as_compact_log() {
		$shared   = array(
			'schedule_name' => 'Upwork Job Discovery Scan',
			'summary'       => '1 workflow step completed.',
			'response'      => "Search for new matching jobs\n10 results found.",
			'status'        => 'success',
			'is_success'    => true,
			'generated_at'  => time(),
			'schedule_type' => 'workflow',
		);
		$envelope = array(
			'data' => array(
				'steps' => array(
					array(
						'tool_slug' => 'search_upwork_jobs',
						'label'     => 'Search for new matching jobs',
						'result'    => array(
							'success' => true,
							'jobs'    => array(
								array(
									'id'    => 'web_1',
									'title' => 'WordPress Developer needed for agency',
								),
							),
						),
						'duration'  => 0.942,
					),
				),
			),
		);

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_email',
			array( $shared, $envelope, 'full' )
		);

		$this->assertStringContainsString( '1. Search for new matching jobs (search_upwork_jobs)', $payload['plain'] );
		$this->assertStringContainsString( 'completed · 0.94s', $payload['plain'] );
		$this->assertStringNotContainsString( 'steps.0', $payload['plain'] );
		$this->assertStringNotContainsString( 'tool_slug:', $payload['plain'] );
		$this->assertStringNotContainsString( 'web_1', $payload['plain'] );
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

	// -------------------------------------------------------------------------
	// Multiple email recipients
	// -------------------------------------------------------------------------

	/**
	 * Test sanitize_email_recipients() normalizes every supported input shape
	 * into a comma-separated list of individually sanitized addresses.
	 */
	public function test_sanitize_email_recipients_normalizes_lists() {
		$sanitize = function ( $value ) {
			return WP_MCP_AI_Result_Delivery_Service::sanitize_email_recipients( $value );
		};

		// Single address passes through unchanged.
		$this->assertSame( 'a@x.com', $sanitize( 'a@x.com' ) );

		// Comma, semicolon, and whitespace separators all normalize.
		$this->assertSame( 'a@x.com, b@y.com', $sanitize( 'a@x.com, b@y.com' ) );
		$this->assertSame( 'a@x.com, b@y.com', $sanitize( 'a@x.com;b@y.com' ) );
		$this->assertSame( 'a@x.com, b@y.com', $sanitize( '  a@x.com  b@y.com  ' ) );

		// Empty tokens and duplicates are dropped.
		$this->assertSame( 'a@x.com', $sanitize( 'a@x.com,, , a@x.com' ) );

		// Array shapes are tolerated.
		$this->assertSame( 'a@x.com', $sanitize( array( 'a@x.com' ) ) );
		$this->assertSame( 'a@x.com, b@y.com', $sanitize( array( 'a@x.com', 'b@y.com' ) ) );

		// Nothing valid remains → empty string.
		$this->assertSame( '', $sanitize( '' ) );
		$this->assertSame( '', $sanitize( '   ,,,   ' ) );
		$this->assertSame( '', $sanitize( array() ) );
	}

	/**
	 * Test that send_email() delivers to every address in a comma-separated
	 * recipient list — through both Nodemailer and the wp_mail fallback.
	 */
	public function test_send_email_delivers_to_multiple_recipients() {
		$payload = $this->email_payload();
		$config  = array(
			'to'     => 'first@example.com, second@example.com',
			'format' => 'both',
		);

		$result = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'send_email',
			array( $payload, $config )
		);

		$this->assertTrue( $result );
		$this->assertNotNull( $this->captured_mail );
		$this->assertSame( 'first@example.com, second@example.com', $this->captured_mail['to'] );

		// When Nodemailer is in play its recipient list must match as well.
		if ( null !== $this->captured_nodemailer ) {
			$this->assertSame( 'first@example.com, second@example.com', $this->captured_nodemailer['to'] );
		}
	}

	/**
	 * Test that send_email() rejects configs whose recipient list contains no
	 * usable address.
	 */
	public function test_send_email_rejects_empty_recipient_list() {
		$result = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'send_email',
			array(
				$this->email_payload(),
				array( 'to' => ' ,,, ' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_email_recipient', $result->get_error_code() );
	}

	/**
	 * Test that sanitize_result_delivery() stores multiple email recipients as
	 * a comma-separated list of individually sanitized addresses, while legacy
	 * single-address configs pass through unchanged.
	 */
	public function test_sanitize_result_delivery_supports_multiple_email_recipients() {
		$delivery = array(
			'on_success' => array(
				'channels' => array(
					'email' => array(
						'enabled'  => true,
						'to'       => 'primary@example.com, cc@example.com; ops@example.com',
						'template' => 'full',
						'format'   => 'both',
					),
				),
			),
			'on_failure' => array(
				'channels' => array(),
			),
		);

		$sanitized = WP_MCP_AI_Pro_Schedule_Manager::sanitize_result_delivery( $delivery );
		$this->assertSame(
			'primary@example.com, cc@example.com, ops@example.com',
			$sanitized['on_success']['channels']['email']['to']
		);

		// Single-address configs keep their existing behavior.
		$delivery['on_success']['channels']['email']['to'] = 'solo@example.com';
		$sanitized = WP_MCP_AI_Pro_Schedule_Manager::sanitize_result_delivery( $delivery );
		$this->assertSame( 'solo@example.com', $sanitized['on_success']['channels']['email']['to'] );
	}
}
