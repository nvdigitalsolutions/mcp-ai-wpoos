<?php
/**
 * GTmetrix Poller + Fix Engine Tests — pure state machine, taxonomy, snippets.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: poller state machine and fix engine contract.
 */
class Test_GTmetrix_Poller_And_Fix_Engine extends WP_UnitTestCase {

	/**
	 * Path to the toolkit services.
	 *
	 * @var string
	 */
	private $toolkit_dir;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_GTMETRIX_USE_FIXTURES' ) ) {
			define( 'WP_MCP_AI_GTMETRIX_USE_FIXTURES', true );
		}

		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/gtmetrix';

		if ( ! class_exists( 'WP_MCP_AI_GTmetrix_Poller' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-gtmetrix-settings.php';
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-gtmetrix-fixture-reader.php';
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-gtmetrix-client.php';
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-gtmetrix-poller.php';
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-gtmetrix-score-engine.php';
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-gtmetrix-fix-engine.php';
		}
	}

	/**
	 * Skip when the toolkit files are unavailable.
	 */
	protected function maybe_skip() {
		if ( ! class_exists( 'WP_MCP_AI_GTmetrix_Poller' ) ) {
			$this->markTestSkipped( 'GTmetrix toolkit not available' );
		}
	}

	/**
	 * Test: pure advance() state machine transitions.
	 */
	public function test_poller_advance_state_machine() {
		$this->maybe_skip();

		$this->assertSame( 'reschedule', WP_MCP_AI_GTmetrix_Poller::advance( 'queued', 1 ) );
		$this->assertSame( 'reschedule', WP_MCP_AI_GTmetrix_Poller::advance( 'started', 19 ) );
		$this->assertSame( 'stop', WP_MCP_AI_GTmetrix_Poller::advance( 'queued', 20 ) );
		$this->assertSame( 'complete', WP_MCP_AI_GTmetrix_Poller::advance( 'completed', 1 ) );
		$this->assertSame( 'error', WP_MCP_AI_GTmetrix_Poller::advance( 'error', 1 ) );
		$this->assertSame( 'stop', WP_MCP_AI_GTmetrix_Poller::advance( 'weird', 1 ) );
	}

	/**
	 * Test: plan concurrency caps.
	 */
	public function test_concurrency_caps() {
		$this->maybe_skip();

		$this->assertSame( 8, WP_MCP_AI_GTmetrix_Poller::concurrency_cap( 'PRO' ) );
		$this->assertSame( 2, WP_MCP_AI_GTmetrix_Poller::concurrency_cap( 'Basic' ) );
	}

	/**
	 * Test: every fix taxonomy row carries the required contract keys.
	 */
	public function test_fix_taxonomy_contract() {
		$this->maybe_skip();

		foreach ( WP_MCP_AI_GTmetrix_Fix_Engine::fixes() as $key => $fix ) {
			$this->assertArrayHasKey( 'audit_ids', $fix, $key );
			$this->assertArrayHasKey( 'symptom', $fix, $key );
			$this->assertArrayHasKey( 'action', $fix, $key );
			$this->assertArrayHasKey( 'risk', $fix, $key );
			$this->assertArrayHasKey( 'block', $fix, $key );
			$this->assertContains( $fix['risk'], array( 'low', 'medium', 'high' ), $key );
		}
	}

	/**
	 * Test: matcher maps fixture audits to known fixes and never invents blocks.
	 */
	public function test_matcher_against_fixture_audits() {
		$this->maybe_skip();

		$lh      = WP_MCP_AI_GTmetrix_Fixture_Reader::get_resource( 'RGOOD', 'lighthouse.json' );
		$audits  = WP_MCP_AI_GTmetrix_Score_Engine::impact_order( $lh['audits'] );
		$matched = WP_MCP_AI_GTmetrix_Fix_Engine::match( $audits );

		$this->assertNotEmpty( $matched['fixes'] );
		foreach ( $matched['fixes'] as $fix ) {
			$this->assertNotEmpty( $fix['matched_audit'] );
		}
	}

	/**
	 * Test: generated snippet only contains matched blocks and carries the
	 * ABSPATH guard plus deploy instructions.
	 */
	public function test_generated_snippet_golden_shape() {
		$this->maybe_skip();

		$lh      = WP_MCP_AI_GTmetrix_Fixture_Reader::get_resource( 'RGOOD', 'lighthouse.json' );
		$audits  = WP_MCP_AI_GTmetrix_Score_Engine::impact_order( $lh['audits'] );
		$matched = WP_MCP_AI_GTmetrix_Fix_Engine::match( $audits );

		$snippet = WP_MCP_AI_GTmetrix_Fix_Engine::generate_snippet(
			$matched['fixes'],
			array(
				'report_id' => 'RGOOD',
				'url'       => 'https://example.test/',
			)
		);

		$this->assertStringContainsString( "defined( 'ABSPATH' )", $snippet );
		$this->assertStringContainsString( 'GTmetrix report: RGOOD', $snippet );
		$this->assertStringContainsString( 'WPCode', $snippet );

		// Unfixable/unknown audits never produce code blocks.
		$this->assertStringNotContainsString( 'dom-size', $snippet );
	}
}
