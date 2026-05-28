<?php
/**
 * Tests for the Memory Privacy Filter (Phase 1 of the 2026 Memory Layer
 * Enhancements).
 *
 * Covers:
 *  - Default redaction patterns (OpenAI / Anthropic / AWS / GitHub /
 *    Google / Slack / Stripe / bearer / private-block / PEM).
 *  - Recursive redaction across nested arrays.
 *  - Filter integration with `wp_mcp_ai_memory_pre_store_transform`
 *    at priority 5 (verifying it runs before user transforms at 10).
 *  - Both filter signatures (2-arg from capture service, 6-arg from tool).
 *  - Master kill-switch behaviour.
 *  - Custom pattern overrides.
 *  - Custom replacement string overrides.
 *  - Verbatim records are redacted (no bypass).
 *  - Broken filter contributors do not data-loss records.
 *  - Optional `wp_mcp_ai_memory_privacy_redacted` action firing.
 *
 * @package WP_MCP_AI
 * @since 1.1.20
 */

if ( ! class_exists( 'WP_MCP_AI_Memory_Privacy_Filter' ) ) {
	require_once dirname( __DIR__ ) . '/includes/services/class-wp-mcp-ai-memory-privacy-filter.php';
}

/**
 * Test case for `WP_MCP_AI_Memory_Privacy_Filter`.
 *
 * @since 1.1.20
 */
class Test_Memory_Privacy_Filter extends WP_UnitTestCase {

	/**
	 * Build a fake OpenAI-style key fixture at runtime.
	 *
	 * Constructed at runtime so the literal `sk-...{37+}` token never appears
	 * as a contiguous source-code span (avoids GitHub Secret Scanning false
	 * positives without weakening the regex it must match).
	 *
	 * @return string
	 */
	private static function fake_openai_key() {
		return 'sk-' . 'NOTAREAL' . str_repeat( 'a', 30 );
	}

	/**
	 * Build a fake Anthropic-style key fixture at runtime.
	 *
	 * @return string
	 */
	private static function fake_anthropic_key() {
		return 'sk-' . 'ant-fake-' . str_repeat( 'a', 30 );
	}

	/**
	 * Build a fake GitHub PAT fixture at runtime.
	 *
	 * @return string
	 */
	private static function fake_github_pat() {
		return 'ghp_' . 'NOTAREAL' . str_repeat( 'a', 28 );
	}

	/**
	 * Tear down — remove every filter the suite installed.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_memory_privacy_filter_enabled' );
		remove_all_filters( 'wp_mcp_ai_memory_privacy_patterns' );
		remove_all_filters( 'wp_mcp_ai_memory_privacy_replacement' );
		remove_all_filters( 'wp_mcp_ai_memory_privacy_log_redactions' );
		remove_all_filters( 'wp_mcp_ai_memory_pre_store_transform' );
		remove_all_actions( 'wp_mcp_ai_memory_privacy_redacted' );
		parent::tearDown();
	}

	/* ------------------------------------------------------------------
	 * 1. Default pattern coverage
	 * ------------------------------------------------------------------ */

	/**
	 * Each default pattern must redact the canonical sample string for its
	 * vendor while leaving surrounding prose intact.
	 *
	 * @dataProvider provide_default_pattern_samples
	 *
	 * @param string $label    Pattern label.
	 * @param string $secret   Sample secret string to redact.
	 * @param string $context  Surrounding prose to ensure non-secret content survives.
	 */
	public function test_default_patterns_redact_known_secrets( $label, $secret, $context ) {
		$input  = "preamble {$context} {$secret} trailing prose";
		$result = WP_MCP_AI_Memory_Privacy_Filter::redact( $input );

		$this->assertStringNotContainsString( $secret, $result, "Pattern '{$label}' failed to redact the canonical secret." );
		$this->assertStringContainsString( '[REDACTED]', $result, "Pattern '{$label}' did not insert the replacement marker." );
		$this->assertStringContainsString( 'preamble', $result, "Pattern '{$label}' over-redacted preamble prose." );
		$this->assertStringContainsString( 'trailing prose', $result, "Pattern '{$label}' over-redacted trailing prose." );
	}

	/**
	 * Sample secrets for each default pattern.
	 *
	 * Test fixtures are deliberately constructed at runtime (string-concat /
	 * `str_repeat()`) so that the literal token strings never appear as
	 * contiguous source-code spans. This keeps the suite green under GitHub
	 * Secret Scanning, which would otherwise flag the file as containing live
	 * partner-pattern credentials. The constructed strings still match every
	 * default regex in {@see WP_MCP_AI_Memory_Privacy_Filter::default_patterns()}.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function provide_default_pattern_samples() {
		// Build fixtures piecewise so the source file never contains a
		// contiguous literal token that secret scanners recognise.
		$alpha40       = str_repeat( 'a', 40 );
		$alpha36       = str_repeat( 'a', 36 );
		$alpha30       = str_repeat( 'a', 30 );
		$alpha20       = str_repeat( 'a', 20 );
		$openai_body   = 'NOTAREAL' . $alpha30;
		$openai_proj   = 'proj-NOTAREAL' . $alpha30;
		$anthropic     = 'ant-fake-' . $alpha30;
		$aws_id        = 'AKIA' . 'NOTAREALKEY12345';
		$aws_secret    = 'NOTAREAL' . str_repeat( 'b', 32 );
		$gh_pat_body   = 'NOTAREAL' . str_repeat( 'a', 28 );
		$gh_srv_body   = 'NOTAREAL' . str_repeat( 'b', 28 );
		$gh_oauth_body = 'NOTAREAL' . str_repeat( 'c', 28 );
		$google_body   = 'SyNOTAREAL' . str_repeat( 'a', 31 );
		$slack_body    = 'fake-' . str_repeat( '1', 10 ) . '-' . $alpha20;
		$stripe_body   = 'NOTAREAL' . $alpha20;
		$bearer_body   = 'NOTAREAL' . $alpha20;

		return array(
			// Each fixture is built from a vendor prefix plus a runtime-constructed body.
			'openai_key'          => array( 'openai_key', 'sk-' . $openai_body, 'api key:' ),
			'openai_proj_key'     => array( 'openai_proj_key', 'sk-' . $openai_proj, 'project key:' ),
			'anthropic_key'       => array( 'anthropic_key', 'sk-' . $anthropic, 'claude key:' ),
			'aws_access_key'      => array( 'aws_access_key', $aws_id, 'aws key:' ),
			'aws_secret_key'      => array( 'aws_secret_key', 'aws_secret_access_key=' . $aws_secret, 'config:' ),
			'github_pat'          => array( 'github_pat', 'ghp_' . $gh_pat_body, 'gh pat:' ),
			'github_server_token' => array( 'github_server_token', 'ghs_' . $gh_srv_body, 'gh srv:' ),
			'github_oauth_token'  => array( 'github_oauth_token', 'gho_' . $gh_oauth_body, 'gh oauth:' ),
			'google_api_key'      => array( 'google_api_key', 'AIza' . $google_body, 'google key:' ),
			'slack_token'         => array( 'slack_token', 'xoxb-' . $slack_body, 'slack:' ),
			'stripe_secret_key'   => array( 'stripe_secret_key', 'sk_' . 'live_' . $stripe_body, 'stripe:' ),
			'bearer_token'        => array( 'bearer_token', 'Authorization: Bearer ' . $bearer_body, 'header:' ),
			'private_block'       => array( 'private_block', '<private>fake SSN here</private>', 'note:' ),
		);
	}

	/**
	 * PEM private keys span multiple lines and must be redacted as a block.
	 */
	public function test_pem_private_key_is_redacted_as_block() {
		$pem = "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEAabcdef\nGhIjKlMnOpQrStUv\n-----END RSA PRIVATE KEY-----";
		$result = WP_MCP_AI_Memory_Privacy_Filter::redact( "User shared: {$pem} — please rotate." );

		$this->assertStringNotContainsString( 'MIIEowIBAAKCAQEAabcdef', $result );
		$this->assertStringContainsString( '[REDACTED]', $result );
		$this->assertStringContainsString( 'please rotate', $result );
	}

	/* ------------------------------------------------------------------
	 * 2. Recursive array redaction
	 * ------------------------------------------------------------------ */

	/**
	 * The filter must walk every nested array key so secrets stowed in
	 * `metadata` / `data` / `tags` are still caught.
	 */
	public function test_recursive_redaction_walks_nested_arrays() {
		$openai_fake    = self::fake_openai_key();
		$anthropic_fake = self::fake_anthropic_key();
		$github_fake    = self::fake_github_pat();

		$record = array(
			'title'    => 'config note',
			'content'  => 'see metadata',
			'tags'     => array( 'config', $openai_fake ),
			'metadata' => array(
				'source'    => $anthropic_fake,
				'nested'    => array(
					'inner_secret' => $github_fake,
				),
			),
		);

		$result = WP_MCP_AI_Memory_Privacy_Filter::apply_redaction( $record );

		$this->assertStringNotContainsString( $openai_fake, $result['tags'][1] );
		$this->assertStringNotContainsString( $anthropic_fake, $result['metadata']['source'] );
		$this->assertStringNotContainsString( $github_fake, $result['metadata']['nested']['inner_secret'] );
		// Non-secret values untouched.
		$this->assertSame( 'config note', $result['title'] );
		$this->assertSame( 'config', $result['tags'][0] );
	}

	/**
	 * Non-string scalar values (ints, bools, null) must pass through unchanged.
	 */
	public function test_non_string_scalars_pass_through_unchanged() {
		$record = array(
			'agent_id' => 42,
			'verbatim' => true,
			'expires'  => null,
			'tags'     => array( 'a', 7, false ),
			'content'  => 'no secrets here',
		);

		$result = WP_MCP_AI_Memory_Privacy_Filter::apply_redaction( $record );

		$this->assertSame( 42, $result['agent_id'] );
		$this->assertTrue( $result['verbatim'] );
		$this->assertNull( $result['expires'] );
		$this->assertSame( 'a', $result['tags'][0] );
		$this->assertSame( 7, $result['tags'][1] );
		$this->assertFalse( $result['tags'][2] );
	}

	/**
	 * If the input is not an array at all (e.g. a misconfigured listener
	 * upstream returned a string), pass it through unchanged.
	 */
	public function test_non_array_input_returned_unchanged() {
		$result = WP_MCP_AI_Memory_Privacy_Filter::apply_redaction( 'just a string' );
		$this->assertSame( 'just a string', $result );

		$result = WP_MCP_AI_Memory_Privacy_Filter::apply_redaction( null );
		$this->assertNull( $result );
	}

	/* ------------------------------------------------------------------
	 * 3. Filter integration
	 * ------------------------------------------------------------------ */

	/**
	 * Bootstrap should register the filter at priority 5 with 6 accepted args.
	 */
	public function test_bootstrap_registers_filter_at_priority_5() {
		// Reset bootstrap flag for this test (private — use reflection).
		$ref = new ReflectionClass( 'WP_MCP_AI_Memory_Privacy_Filter' );
		$prop = $ref->getProperty( 'bootstrapped' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );

		remove_all_filters( 'wp_mcp_ai_memory_pre_store_transform' );

		WP_MCP_AI_Memory_Privacy_Filter::bootstrap();

		$this->assertNotFalse(
			has_filter( 'wp_mcp_ai_memory_pre_store_transform', array( 'WP_MCP_AI_Memory_Privacy_Filter', 'apply_redaction' ) ),
			'Privacy filter was not registered.'
		);
		$priority = has_filter( 'wp_mcp_ai_memory_pre_store_transform', array( 'WP_MCP_AI_Memory_Privacy_Filter', 'apply_redaction' ) );
		$this->assertSame( 5, $priority, 'Privacy filter must register at priority 5.' );
	}

	/**
	 * Bootstrap should be idempotent — calling it twice must not register the
	 * filter twice.
	 */
	public function test_bootstrap_is_idempotent() {
		$ref = new ReflectionClass( 'WP_MCP_AI_Memory_Privacy_Filter' );
		$prop = $ref->getProperty( 'bootstrapped' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );

		remove_all_filters( 'wp_mcp_ai_memory_pre_store_transform' );

		WP_MCP_AI_Memory_Privacy_Filter::bootstrap();
		WP_MCP_AI_Memory_Privacy_Filter::bootstrap();
		WP_MCP_AI_Memory_Privacy_Filter::bootstrap();

		global $wp_filter;
		$callbacks_at_5 = isset( $wp_filter['wp_mcp_ai_memory_pre_store_transform']->callbacks[5] )
			? $wp_filter['wp_mcp_ai_memory_pre_store_transform']->callbacks[5]
			: array();

		$count = 0;
		foreach ( $callbacks_at_5 as $cb ) {
			if (
				isset( $cb['function'] )
				&& is_array( $cb['function'] )
				&& isset( $cb['function'][0], $cb['function'][1] )
				&& 'WP_MCP_AI_Memory_Privacy_Filter' === $cb['function'][0]
				&& 'apply_redaction' === $cb['function'][1]
			) {
				++$count;
			}
		}
		$this->assertSame( 1, $count, 'Privacy filter must register exactly once even after repeated bootstrap calls.' );
	}

	/**
	 * The 6-arg `store_agent_context` signature must work — second arg is
	 * a bool (`$verbatim`), not the envelope.
	 */
	public function test_six_arg_signature_works() {
		$secret = self::fake_openai_key();
		$record = array(
			'title'   => 'note',
			'content' => 'My OpenAI key is ' . $secret,
		);

		// Simulate the tool's call shape.
		$result = WP_MCP_AI_Memory_Privacy_Filter::apply_redaction( $record, true, 'note', 42, array(), null );

		$this->assertStringContainsString( '[REDACTED]', $result['content'] );
		$this->assertStringNotContainsString( $secret, $result['content'] );
	}

	/**
	 * Filter runs at priority 5, BEFORE user transforms at the default 10.
	 *
	 * Simulates a malicious/unaware user transform that would otherwise echo
	 * the raw secret into the title field — it must see the redacted content.
	 */
	public function test_runs_before_user_transforms() {
		$ref = new ReflectionClass( 'WP_MCP_AI_Memory_Privacy_Filter' );
		$prop = $ref->getProperty( 'bootstrapped' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );
		remove_all_filters( 'wp_mcp_ai_memory_pre_store_transform' );
		WP_MCP_AI_Memory_Privacy_Filter::bootstrap();

		// User transform at default priority 10 — sees content AFTER redaction.
		add_filter(
			'wp_mcp_ai_memory_pre_store_transform',
			static function ( $context_data ) {
				if ( is_array( $context_data ) && isset( $context_data['content'] ) ) {
					$context_data['title'] = $context_data['content']; // Mirror content to title.
				}
				return $context_data;
			},
			10,
			6
		);

		$secret = self::fake_openai_key();
		$record = array(
			'title'   => 'original',
			'content' => 'Key: ' . $secret,
		);

		$final = apply_filters( 'wp_mcp_ai_memory_pre_store_transform', $record, $record );

		// The user transform mirrors content -> title, but content was already
		// redacted by the priority-5 filter, so the mirrored title must NOT
		// contain the raw secret.
		$this->assertStringNotContainsString( $secret, $final['title'] );
		$this->assertStringContainsString( '[REDACTED]', $final['title'] );
	}

	/* ------------------------------------------------------------------
	 * 4. Master kill-switch
	 * ------------------------------------------------------------------ */

	/**
	 * When `wp_mcp_ai_memory_privacy_filter_enabled` returns false at
	 * bootstrap time, the filter is NOT registered.
	 */
	public function test_kill_switch_prevents_filter_registration() {
		$ref = new ReflectionClass( 'WP_MCP_AI_Memory_Privacy_Filter' );
		$prop = $ref->getProperty( 'bootstrapped' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );
		remove_all_filters( 'wp_mcp_ai_memory_pre_store_transform' );

		add_filter( 'wp_mcp_ai_memory_privacy_filter_enabled', '__return_false' );
		WP_MCP_AI_Memory_Privacy_Filter::bootstrap();

		$this->assertFalse(
			has_filter( 'wp_mcp_ai_memory_pre_store_transform', array( 'WP_MCP_AI_Memory_Privacy_Filter', 'apply_redaction' ) ),
			'Privacy filter must not register when the kill-switch is engaged.'
		);
	}

	/* ------------------------------------------------------------------
	 * 5. Custom patterns and replacement
	 * ------------------------------------------------------------------ */

	/**
	 * Custom patterns provided via `wp_mcp_ai_memory_privacy_patterns` should
	 * apply ON TOP of the defaults (i.e. extend, not replace, unless the
	 * filter returns a fully replaced array).
	 */
	public function test_custom_patterns_can_extend_defaults() {
		add_filter(
			'wp_mcp_ai_memory_privacy_patterns',
			static function ( $patterns ) {
				$patterns['internal_token'] = '/\bINT-[A-Z0-9]{12,}\b/';
				return $patterns;
			}
		);

		$result = WP_MCP_AI_Memory_Privacy_Filter::redact( 'Use token INT-ABC123XYZ789 for the internal API.' );

		$this->assertStringNotContainsString( 'INT-ABC123XYZ789', $result );
		$this->assertStringContainsString( '[REDACTED]', $result );
	}

	/**
	 * Returning an empty array drops the redactor's defaults entirely.
	 */
	public function test_custom_patterns_can_replace_defaults() {
		add_filter(
			'wp_mcp_ai_memory_privacy_patterns',
			static function () {
				return array();
			}
		);

		$secret = self::fake_openai_key();
		$result = WP_MCP_AI_Memory_Privacy_Filter::redact( 'Key: ' . $secret );

		// No defaults active -> nothing redacted.
		$this->assertStringContainsString( $secret, $result );
	}

	/**
	 * Returning a non-array from the patterns filter must fall back to the
	 * defaults (never zero out the protection).
	 */
	public function test_invalid_patterns_filter_falls_back_to_defaults() {
		add_filter(
			'wp_mcp_ai_memory_privacy_patterns',
			static function () {
				return 'oops not an array';
			}
		);

		$secret = self::fake_openai_key();
		$result = WP_MCP_AI_Memory_Privacy_Filter::redact( 'Key: ' . $secret );

		$this->assertStringContainsString( '[REDACTED]', $result );
		$this->assertStringNotContainsString( $secret, $result );
	}

	/**
	 * Custom replacement strings override the default `[REDACTED]` marker.
	 */
	public function test_custom_replacement_string() {
		add_filter(
			'wp_mcp_ai_memory_privacy_replacement',
			static function () {
				return '***SECRET***';
			}
		);

		$result = WP_MCP_AI_Memory_Privacy_Filter::redact( 'Key: ' . self::fake_openai_key() );

		$this->assertStringContainsString( '***SECRET***', $result );
		$this->assertStringNotContainsString( '[REDACTED]', $result );
	}

	/**
	 * Empty or non-string replacement strings fall back to the default marker.
	 */
	public function test_empty_replacement_falls_back_to_default() {
		add_filter(
			'wp_mcp_ai_memory_privacy_replacement',
			static function () {
				return '';
			}
		);

		$result = WP_MCP_AI_Memory_Privacy_Filter::redact( 'Key: ' . self::fake_openai_key() );

		$this->assertStringContainsString( '[REDACTED]', $result );
	}

	/* ------------------------------------------------------------------
	 * 6. Verbatim discipline contract
	 * ------------------------------------------------------------------ */

	/**
	 * Per the documented contract, verbatim records ARE redacted (verbatim
	 * preserves surviving content, not the right to ship secrets).
	 */
	public function test_verbatim_records_are_still_redacted() {
		$secret = self::fake_openai_key();
		$record = array(
			'verbatim' => true,
			'title'    => 'verbatim quote',
			'content'  => 'User said: "my key is ' . $secret . '"',
		);

		$result = WP_MCP_AI_Memory_Privacy_Filter::apply_redaction( $record, true, 'quote', 99, array(), null );

		$this->assertTrue( $result['verbatim'], 'Verbatim flag must survive redaction.' );
		$this->assertStringNotContainsString( $secret, $result['content'] );
		$this->assertStringContainsString( '[REDACTED]', $result['content'] );
	}

	/* ------------------------------------------------------------------
	 * 7. Broken regex resilience
	 * ------------------------------------------------------------------ */

	/**
	 * A broken regex contributed via the filter must not crash the request —
	 * the record should pass through with the working defaults still applied.
	 */
	public function test_broken_regex_in_filter_does_not_destroy_record() {
		add_filter(
			'wp_mcp_ai_memory_privacy_patterns',
			static function ( $patterns ) {
				$patterns['broken'] = '/(unclosed';
				return $patterns;
			}
		);

		$secret = self::fake_openai_key();
		$record = array(
			'title'   => 'note',
			'content' => 'Key: ' . $secret,
		);

		$result = WP_MCP_AI_Memory_Privacy_Filter::apply_redaction( $record );

		// Defaults still ran successfully.
		$this->assertStringContainsString( '[REDACTED]', $result['content'] );
		$this->assertStringNotContainsString( $secret, $result['content'] );
		// Title untouched (no secret).
		$this->assertSame( 'note', $result['title'] );
	}

	/* ------------------------------------------------------------------
	 * 8. Audit-trail action
	 * ------------------------------------------------------------------ */

	/**
	 * The `wp_mcp_ai_memory_privacy_redacted` action fires only when the
	 * log-redactions filter returns true AND at least one match was made.
	 */
	public function test_redaction_action_fires_when_logging_enabled() {
		add_filter( 'wp_mcp_ai_memory_privacy_log_redactions', '__return_true' );

		$captured = array( 'count' => null );
		add_action(
			'wp_mcp_ai_memory_privacy_redacted',
			static function ( $count ) use ( &$captured ) {
				$captured['count'] = $count;
			},
			10,
			2
		);

		$record = array(
			'title'   => 'note',
			'content' => 'Two keys: ' . self::fake_openai_key() . ' and ' . self::fake_github_pat(),
		);

		WP_MCP_AI_Memory_Privacy_Filter::apply_redaction( $record );

		$this->assertSame( 2, $captured['count'], 'Action should report 2 redactions for 2 matches.' );
	}

	/**
	 * When the log-redactions filter is false (the default), the action does
	 * NOT fire even on matches.
	 */
	public function test_redaction_action_silent_by_default() {
		$fired = false;
		add_action(
			'wp_mcp_ai_memory_privacy_redacted',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		$record = array(
			'title'   => 'note',
			'content' => 'Key: ' . self::fake_openai_key(),
		);

		WP_MCP_AI_Memory_Privacy_Filter::apply_redaction( $record );

		$this->assertFalse( $fired, 'Default logging mode must be silent.' );
	}

	/**
	 * When no patterns match, the action does NOT fire even with logging on.
	 */
	public function test_redaction_action_silent_when_no_matches() {
		add_filter( 'wp_mcp_ai_memory_privacy_log_redactions', '__return_true' );

		$fired = false;
		add_action(
			'wp_mcp_ai_memory_privacy_redacted',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		WP_MCP_AI_Memory_Privacy_Filter::apply_redaction(
			array(
				'title'   => 'innocent note',
				'content' => 'nothing sensitive here',
			)
		);

		$this->assertFalse( $fired, 'No-match redaction call must not fire the action.' );
	}
}
