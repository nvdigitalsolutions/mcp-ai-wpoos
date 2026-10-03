<?php
/**
 * Tests for the Decision Scope Guard.
 *
 * The guard gates every decision-model dispatch by declared domain and
 * authority ceiling (Proposal 052). These tests pin:
 *  - allowed domains at inform/suggest pass through,
 *  - banned domains fail closed to the fallback (never to the model),
 *  - act-level authority requires an explicit per-domain grant,
 *  - unknown/empty declarations fail closed,
 *  - fallback resolution (callable, value, null → WP_Error).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Decision-scope guard unit tests.
 */
class Test_Decision_Scope_Guard extends WP_UnitTestCase {

	/**
	 * Set up: load the guard class and reset the act-grant filter.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-decision-scope-guard.php';

		remove_all_filters( 'wp_mcp_ai_decision_act_domains' );
	}

	/**
	 * Tear down: reset the act-grant filter.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_decision_act_domains' );

		parent::tearDown();
	}

	/**
	 * Allowed domains pass at inform and suggest authority.
	 *
	 * @return void
	 */
	public function test_gate_allows_inform_and_suggest_in_all_domains() {
		foreach ( array( 'advisory', 'content', 'operations', 'verification' ) as $domain ) {
			$this->assertTrue( WP_MCP_AI_Decision_Scope_Guard::gate( $domain, 'inform' ) );
			$this->assertTrue( WP_MCP_AI_Decision_Scope_Guard::gate( $domain, 'suggest' ) );
		}
	}

	/**
	 * Banned domains fail closed to the fallback at any authority.
	 *
	 * @return void
	 */
	public function test_gate_rejects_banned_domains_with_value_fallback() {
		$fallback = new WP_Error( 'test_fallback', 'fallback used' );

		foreach ( WP_MCP_AI_Decision_Scope_Guard::BANNED_DOMAINS as $domain ) {
			$this->assertSame( $fallback, WP_MCP_AI_Decision_Scope_Guard::gate( $domain, 'inform', $fallback ) );
			$this->assertSame( $fallback, WP_MCP_AI_Decision_Scope_Guard::gate( $domain, 'suggest', $fallback ) );
			$this->assertSame( $fallback, WP_MCP_AI_Decision_Scope_Guard::gate( $domain, 'act', $fallback ) );
		}
	}

	/**
	 * A callable fallback is invoked for banned domains.
	 *
	 * @return void
	 */
	public function test_gate_invokes_callable_fallback_for_banned_domain() {
		$invoked = 0;

		$result = WP_MCP_AI_Decision_Scope_Guard::gate(
			'people',
			'suggest',
			static function () use ( &$invoked ) {
				$invoked++;

				return 'human-default';
			}
		);

		$this->assertSame( 'human-default', $result );
		$this->assertSame( 1, $invoked );
	}

	/**
	 * Act-level authority is denied without an explicit grant.
	 *
	 * @return void
	 */
	public function test_gate_denies_act_without_grant() {
		$result = WP_MCP_AI_Decision_Scope_Guard::gate( 'operations', 'act' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_decision_scope_gated', $result->get_error_code() );
	}

	/**
	 * Act-level authority is allowed for domains listed in the grant filter.
	 *
	 * @return void
	 */
	public function test_gate_allows_act_with_explicit_grant() {
		add_filter(
			'wp_mcp_ai_decision_act_domains',
			static function () {
				return array( 'operations' );
			}
		);

		$this->assertTrue( WP_MCP_AI_Decision_Scope_Guard::gate( 'operations', 'act' ) );
	}

	/**
	 * Banned domains win over act-grants: a grant can never un-ban a domain.
	 *
	 * @return void
	 */
	public function test_gate_denies_act_for_banned_domain_even_when_granted() {
		add_filter(
			'wp_mcp_ai_decision_act_domains',
			static function () {
				return array( 'life' );
			}
		);

		$result = WP_MCP_AI_Decision_Scope_Guard::gate( 'life', 'act' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_decision_scope_gated', $result->get_error_code() );
	}

	/**
	 * Unknown authority levels fail closed (undeclared intent).
	 *
	 * @return void
	 */
	public function test_gate_rejects_unknown_authority() {
		$result = WP_MCP_AI_Decision_Scope_Guard::gate( 'advisory', 'execute' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_decision_scope_gated', $result->get_error_code() );
	}

	/**
	 * Empty domain declarations fail closed.
	 *
	 * @return void
	 */
	public function test_gate_rejects_empty_domain() {
		$result = WP_MCP_AI_Decision_Scope_Guard::gate( '', 'inform' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_decision_scope_gated', $result->get_error_code() );
	}

	/**
	 * Non-callable fallbacks are returned as-is.
	 *
	 * @return void
	 */
	public function test_gate_returns_non_callable_fallback_as_is() {
		$fallback = array( 'neutral' => true );

		$this->assertSame( $fallback, WP_MCP_AI_Decision_Scope_Guard::gate( 'identity', 'inform', $fallback ) );
	}

	/**
	 * Grant-filter values are sanitised before comparison.
	 *
	 * @return void
	 */
	public function test_gate_sanitizes_granted_domains() {
		add_filter(
			'wp_mcp_ai_decision_act_domains',
			static function () {
				return array( 'Operations-With-Uppercase', 123, null );
			}
		);

		// sanitize_key() lowercases and strips invalid characters; 123 → '123'.
		$this->assertTrue( WP_MCP_AI_Decision_Scope_Guard::domain_grants_act( 'operations-with-uppercase' ) );
		$this->assertTrue( WP_MCP_AI_Decision_Scope_Guard::domain_grants_act( '123' ) );
		$this->assertFalse( WP_MCP_AI_Decision_Scope_Guard::domain_grants_act( 'operations' ) );
	}

	/**
	 * The guard constant values match the taxonomy the PHPCS sniff embeds.
	 *
	 * Drift between the two would let undeclared dispatches pass the sniff or
	 * reject valid declarations at runtime; pin the values here.
	 *
	 * @return void
	 */
	public function test_domain_and_authority_constants_are_stable() {
		$this->assertEquals( 'advisory', WP_MCP_AI_Decision_Scope_Guard::DOMAIN_ADVISORY );
		$this->assertEquals( 'content', WP_MCP_AI_Decision_Scope_Guard::DOMAIN_CONTENT );
		$this->assertEquals( 'operations', WP_MCP_AI_Decision_Scope_Guard::DOMAIN_OPERATIONS );
		$this->assertEquals( 'verification', WP_MCP_AI_Decision_Scope_Guard::DOMAIN_VERIFICATION );

		$this->assertEquals( array( 'life', 'people', 'ethics', 'identity' ), WP_MCP_AI_Decision_Scope_Guard::BANNED_DOMAINS );

		$this->assertEquals( 'inform', WP_MCP_AI_Decision_Scope_Guard::AUTHORITY_INFORM );
		$this->assertEquals( 'suggest', WP_MCP_AI_Decision_Scope_Guard::AUTHORITY_SUGGEST );
		$this->assertEquals( 'act', WP_MCP_AI_Decision_Scope_Guard::AUTHORITY_ACT );
		$this->assertEquals( array( 'inform', 'suggest', 'act' ), WP_MCP_AI_Decision_Scope_Guard::AUTHORITIES );
	}
}
