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

	/**
	 * The full chat template must not repeat the summary when the response
	 * already begins with it (assistant-run summaries are a trim of the
	 * response's first words).
	 */
	public function test_format_chat_full_skips_summary_prefix_of_response() {
		$response           = "Here's your 6-hour email review 👀\n\nWindow reviewed: 13:52 – 19:52 UTC (Wed, Sep 9).\nResult: 2 actionable emails landed in the window, both unread, with the rest being Pinterest promos or earlier messages.";
		$shared             = $this->chat_shared();
		$shared['summary']  = wp_trim_words( wp_strip_all_tags( $response ), 25, '…' );
		$shared['response'] = $response;

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_chat',
			array( $shared, 'full', 'plain', array() )
		);

		// The header line appears exactly once — only inside the response.
		$this->assertSame( 1, substr_count( $payload['message'], '6-hour email review' ) );
		$this->assertStringContainsString( 'Results:', $payload['message'] );
		$this->assertStringContainsString( 'Pinterest promos', $payload['message'] );
	}

	/**
	 * The summary chat template must not print the summary line when the
	 * response already opens with it — assistant-run summaries are a trim of
	 * the response's first words. The excerpt now carries the substantive
	 * "Result" distillation block instead of the roundup intro.
	 */
	public function test_format_chat_summary_skips_summary_prefix_of_response() {
		$response           = "Here's your 6-hour email review 👀\n\nWindow reviewed: 13:52 – 19:52 UTC (Wed, Sep 9).\nResult: 2 actionable emails landed in the window, both unread, with the rest being Pinterest promos or earlier messages.";
		$shared             = $this->chat_shared();
		$shared['summary']  = wp_trim_words( wp_strip_all_tags( $response ), 25, '…' );
		$shared['response'] = $response;

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_chat',
			array( $shared, 'summary', 'plain', array() )
		);

		// The roundup intro is gone (deduped summary line + relevant excerpt).
		$this->assertStringNotContainsString( '6-hour email review', $payload['message'] );
		$this->assertStringNotContainsString( 'Window reviewed', $payload['message'] );
		$this->assertStringContainsString( '📋', $payload['message'] );
		$this->assertStringContainsString( 'Pinterest promos', $payload['message'] );
	}

	/**
	 * A summary that is not derived from the response must still be prepended
	 * in the summary template (workflow/task summaries carry standalone
	 * context), with the excerpt following.
	 */
	public function test_format_chat_summary_keeps_non_prefix_summary() {
		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_chat',
			array( $this->chat_shared(), 'summary', 'plain', array() )
		);

		$this->assertStringContainsString( 'Generated 5 posts.', $payload['message'] );
		$this->assertStringContainsString( 'All tasks completed successfully.', $payload['message'] );
		// The summary line appears exactly once — the excerpt does not repeat it.
		$this->assertSame( 1, substr_count( $payload['message'], 'Generated 5 posts.' ) );
	}

	// -------------------------------------------------------------------------
	// Action-items template
	// -------------------------------------------------------------------------

	/**
	 * Must return the block under the first action-signalling heading and stop
	 * at the next section heading.
	 */
	public function test_extract_action_items_returns_needs_attention_block() {
		$response = "Here's your inbox roundup for the last 6 hours.\n\n"
			. "## 📥 Inbound — needs your attention\n"
			. "**1. \"Men's eau de toilette\" — Fri, 25 Sep**\n"
			. "- From: The Parfumerie Store\n"
			. "- Snippet: Brand new perfume for sale.\n\n"
			. "## 📤 Sent — informational\n"
			. "1. Order confirmation\n\n"
			. "That's the roundup!";

		$block = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'extract_action_items',
			array( $response )
		);

		$this->assertStringContainsString( "Men's eau de toilette", $block );
		$this->assertStringContainsString( 'The Parfumerie Store', $block );
		$this->assertStringNotContainsString( 'Order confirmation', $block );
		$this->assertStringNotContainsString( 'inbox roundup', $block );
		$this->assertStringNotContainsString( 'roundup!', $block );
	}

	/**
	 * Must recognise bold and bare action headings, including items carried on
	 * the heading line after a colon.
	 */
	public function test_extract_action_items_supports_bold_and_inline_headings() {
		$response = "Digest complete.\n\n"
			. "**Action Items**: Reply to the supplier\n\n"
			. "To-do\n"
			. "- Restock men's eau de toilette\n\n"
			. "### Notes\n"
			. 'Traffic was up.';

		$block = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'extract_action_items',
			array( $response )
		);

		$this->assertStringContainsString( 'Reply to the supplier', $block );
		$this->assertStringContainsString( "Restock men's eau de toilette", $block );
		$this->assertStringNotContainsString( 'Digest complete', $block );
		$this->assertStringNotContainsString( 'Traffic was up', $block );
	}

	/**
	 * Must return an empty string when the response carries no action section,
	 * and must ignore negative phrasings.
	 */
	public function test_extract_action_items_returns_empty_without_action_section() {
		$quiet  = "All quiet on the inbox front.\n\nNothing to report today.";
		$no_act = "All quiet.\n\nNo action needed from you.\n\nNext: nothing.";

		foreach ( array( $quiet, $no_act ) as $response ) {
			$block = $this->invoke_static(
				'WP_MCP_AI_Result_Delivery_Service',
				'extract_action_items',
				array( $response )
			);
			$this->assertSame( '', $block );
		}
	}

	/**
	 * The action_items chat template must forward only the action block — the
	 * roundup intro, informational sections, and the summary excerpt are all
	 * noise the template exists to drop.
	 */
	public function test_format_chat_action_items_sends_only_actions() {
		$response           = "Here's your inbox roundup for the last 6 hours.\n\n"
			. "**Action Items**\n"
			. "- Reply to the perfume supplier\n"
			. "- Restock men's eau de toilette\n\n"
			. "### Notes\n"
			. 'Traffic was up this week.';
		$shared             = $this->chat_shared();
		$shared['summary']  = wp_trim_words( wp_strip_all_tags( $response ), 25, '…' );
		$shared['response'] = $response;

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_chat',
			array( $shared, 'action_items', 'html', array() )
		);

		$this->assertStringContainsString( 'Action items', $payload['message'] );
		$this->assertStringContainsString( 'Reply to the perfume supplier', $payload['message'] );
		// esc_html() in the html format escapes the apostrophe.
		$this->assertStringContainsString( 'Restock men&#039;s eau de toilette', $payload['message'] );
		$this->assertStringNotContainsString( 'inbox roundup', $payload['message'] );
		$this->assertStringNotContainsString( 'Traffic was up', $payload['message'] );
		// No response excerpt is appended on top of the action block.
		$this->assertStringNotContainsString( '📋', $payload['message'] );
	}

	/**
	 * The action_items chat template must fall back to the summary rendering
	 * (summary line + response excerpt) when the response has no action section.
	 */
	public function test_format_chat_action_items_falls_back_to_summary() {
		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_chat',
			array( $this->chat_shared(), 'action_items', 'plain', array() )
		);

		$this->assertStringNotContainsString( 'Action items', $payload['message'] );
		$this->assertStringContainsString( 'Generated 5 posts.', $payload['message'] );
		$this->assertStringContainsString( '📋', $payload['message'] );
		$this->assertStringContainsString( 'All tasks completed successfully.', $payload['message'] );
	}

	// -------------------------------------------------------------------------
	// Representative excerpt selection
	// -------------------------------------------------------------------------

	/**
	 * Must prefer the response's own distillation section ("Summary",
	 * "TL;DR", "Key points", "Results", …) over the roundup intro and
	 * trailing detail sections.
	 */
	public function test_select_representative_excerpt_prefers_summary_section() {
		$response = "Intro text here.\n\n"
			. "## Summary\n"
			. "Orders up 12%, two flagged for review.\n\n"
			. "## Details\n"
			. 'Order 4412 delayed.';

		$excerpt = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'select_representative_excerpt',
			array( $response, 80 )
		);

		$this->assertStringContainsString( 'Orders up 12%', $excerpt );
		$this->assertStringNotContainsString( 'Intro text', $excerpt );
		$this->assertStringNotContainsString( 'Order 4412', $excerpt );
	}

	/**
	 * Must prefer the actionable section when no distillation section exists.
	 */
	public function test_select_representative_excerpt_prefers_action_block() {
		$response = "Roundup intro here.\n\n"
			. "**Action Items**\n"
			. "- Do the thing\n\n"
			. "### Notes\n"
			. 'Extra info.';

		$excerpt = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'select_representative_excerpt',
			array( $response, 80 )
		);

		$this->assertStringContainsString( 'Do the thing', $excerpt );
		$this->assertStringNotContainsString( 'Roundup intro', $excerpt );
		$this->assertStringNotContainsString( 'Extra info', $excerpt );
	}

	/**
	 * Must fall back to centroid extraction: sentences are ranked by
	 * content-word centrality with a positional lead bias, keeping the
	 * top-ranked sentences in original order within the word budget.
	 */
	public function test_select_representative_excerpt_centroid_ranking() {
		$response = 'Here is the roundup. Shipment delay confirmed for order 4412 and refund initiated by finance. Signing off.';

		$excerpt = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'select_representative_excerpt',
			array( $response, 15 )
		);

		// Lead sentence kept, high-value sentence in, trailing boilerplate out.
		$this->assertStringContainsString( 'Here is the roundup', $excerpt );
		$this->assertStringContainsString( 'Shipment delay confirmed', $excerpt );
		$this->assertStringNotContainsString( 'Signing off', $excerpt );
	}

	/**
	 * Must fall back to a plain lead-word trim for short, unstructured
	 * responses (the classic extractive baseline).
	 */
	public function test_select_representative_excerpt_falls_back_to_lead_trim() {
		$response = 'Single line with no sections at all.';

		$excerpt = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'select_representative_excerpt',
			array( $response, 80 )
		);

		$this->assertStringContainsString( 'Single line with no sections', $excerpt );
	}

	/**
	 * The summary chat template must excerpt the relevant distillation block,
	 * not the roundup intro or the trailing detail sections.
	 */
	public function test_format_chat_summary_excerpts_relevant_block() {
		$response           = "Here's the roundup.\n\n"
			. "**Summary**\n"
			. "Two orders need attention and one invoice is due.\n\n"
			. "## Details\n"
			. 'Order 4412 delayed.';
		$shared             = $this->chat_shared();
		$shared['summary']  = wp_trim_words( wp_strip_all_tags( $response ), 25, '…' );
		$shared['response'] = $response;

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_chat',
			array( $shared, 'summary', 'plain', array() )
		);

		$this->assertStringContainsString( 'Two orders need attention', $payload['message'] );
		$this->assertStringNotContainsString( 'Order 4412 delayed', $payload['message'] );
		$this->assertStringContainsString( '📋', $payload['message'] );
	}

	/**
	 * The full chat template must not render keys that duplicate the response
	 * or expose internal execution metadata (`response`, `assistant_id`,
	 * `is_agentic`) from the envelope data section — they previously produced
	 * a duplicated response and a raw metadata footer.
	 */
	public function test_format_chat_full_redacts_duplicate_and_metadata_keys() {
		$response           = "Here's your inbox rundown for the last 6 hours. Four messages landed and three of them carry real actions.";
		$shared             = $this->chat_shared();
		$shared['summary']  = wp_trim_words( wp_strip_all_tags( $response ), 25, '…' );
		$shared['response'] = $response;

		$envelope = array(
			'data' => array(
				'response'      => $response,
				'assistant_id'  => 953,
				'is_agentic'    => 1,
				'posts_created' => 3,
			),
		);

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_chat',
			array( $shared, 'full', 'plain', $envelope )
		);

		// The duplicate response copy and internal flags are stripped.
		$this->assertStringNotContainsString( 'assistant_id', $payload['message'] );
		$this->assertStringNotContainsString( 'is_agentic', $payload['message'] );
		$this->assertSame( 1, substr_count( $payload['message'], 'inbox rundown' ) );
		// Legitimate data keys still render.
		$this->assertStringContainsString( 'posts_created: 3', $payload['message'] );
	}

	/**
	 * Workflow envelopes store the step log under `data.steps`; the full chat
	 * template must render it as a compact execution log — never as a
	 * flattened dot-notation dump of the nested step results.
	 */
	public function test_format_chat_full_renders_workflow_steps_as_compact_log() {
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
			'format_chat',
			array( $this->chat_shared(), 'full', 'plain', $envelope )
		);

		// Compact log: label, tool slug, status, and duration on one line.
		$this->assertStringContainsString( 'Search for new matching jobs', $payload['message'] );
		$this->assertStringContainsString( '(search_upwork_jobs)', $payload['message'] );
		$this->assertStringContainsString( 'completed', $payload['message'] );
		$this->assertStringContainsString( '0.94s', $payload['message'] );
		// The nested result payload is never flattened into dot notation.
		$this->assertStringNotContainsString( 'steps.0', $payload['message'] );
		$this->assertStringNotContainsString( 'tool_slug:', $payload['message'] );
		$this->assertStringNotContainsString( 'steps.', $payload['message'] );
		// The raw job title stays inside the response, not the data dump.
		$this->assertStringNotContainsString( 'web_1', $payload['message'] );
	}

	/**
	 * Failed workflow steps must surface their error message in the log line.
	 */
	public function test_format_chat_full_renders_failed_workflow_step() {
		$envelope = array(
			'data' => array(
				'steps' => array(
					array(
						'tool_slug' => 'search_upwork_jobs',
						'label'     => 'Search for new matching jobs',
						'result'    => new WP_Error( 'workflow_step_failed', 'Upwork API rejected the request.' ),
						'duration'  => 0.1,
					),
				),
			),
		);

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_chat',
			array( $this->chat_shared(), 'full', 'plain', $envelope )
		);

		$this->assertStringContainsString( 'failed: Upwork API rejected the request.', $payload['message'] );
	}

	/**
	 * The generic text flattening must skip empty values so structured data
	 * sections never render blank "key:" lines.
	 */
	public function test_envelope_data_to_text_skips_empty_values() {
		$data = array(
			'posts_created' => 5,
			'zero_count'    => 0,
			'tags'          => array( 'seo', 'news', '' ),
			'empty_string'  => '',
			'null_value'    => null,
			'empty_array'   => array(),
			'nested'        => array(
				'name'  => 'digest',
				'blank' => '',
			),
		);

		$text = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'envelope_data_to_text',
			array( $data )
		);

		$this->assertStringContainsString( 'posts_created: 5', $text );
		$this->assertStringContainsString( 'zero_count: 0', $text );
		$this->assertStringContainsString( 'tags: seo, news', $text );
		$this->assertStringContainsString( 'nested.name: digest', $text );
		$this->assertStringNotContainsString( 'empty_string', $text );
		$this->assertStringNotContainsString( 'null_value', $text );
		$this->assertStringNotContainsString( 'empty_array', $text );
		$this->assertStringNotContainsString( 'blank', $text );
	}

	/**
	 * The SMS summary format must not repeat the summary when the response
	 * excerpt already opens with it — the excerpt subsumes the summary.
	 */
	public function test_format_sms_skips_summary_prefix_of_response() {
		$response           = "Here's your 6-hour email review. Window reviewed: 13:52 – 19:52 UTC. Two actionable emails landed, both unread.";
		$shared             = $this->chat_shared();
		$shared['summary']  = wp_trim_words( wp_strip_all_tags( $response ), 25, '…' );
		$shared['response'] = $response;

		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_sms',
			array( $shared, 'summary' )
		);

		// The excerpt appears once; the standalone summary is skipped.
		$this->assertSame( 1, substr_count( $payload['message'], '6-hour email review' ) );
		$this->assertStringStartsWith( '✅ Daily Report: ', $payload['message'] );
	}

	/**
	 * The SMS summary format must keep summary and excerpt when the response
	 * does not open with the summary.
	 */
	public function test_format_sms_keeps_non_prefix_summary() {
		$payload = $this->invoke_static(
			'WP_MCP_AI_Result_Delivery_Service',
			'format_sms',
			array( $this->chat_shared(), 'summary' )
		);

		$this->assertStringStartsWith( '✅ Daily Report: Generated 5 posts.', $payload['message'] );
		$this->assertStringContainsString( 'All tasks completed successfully', $payload['message'] );
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

	/**
	 * Sanitize_result_delivery() must accept the action_items template for
	 * both email and chat channels.
	 */
	public function test_sanitize_result_delivery_accepts_action_items_template() {
		$delivery = array(
			'on_success' => array(
				'channels' => array(
					'telegram' => array(
						'enabled'  => true,
						'template' => 'action_items',
					),
					'email'    => array(
						'enabled'  => true,
						'template' => 'action_items',
						'to'       => 'ops@example.com',
					),
				),
			),
			'on_failure' => array(
				'channels' => array(),
			),
		);

		$sanitized = WP_MCP_AI_Pro_Schedule_Manager::sanitize_result_delivery( $delivery );
		$this->assertSame( 'action_items', $sanitized['on_success']['channels']['telegram']['template'] );
		$this->assertSame( 'action_items', $sanitized['on_success']['channels']['email']['template'] );
	}
}
