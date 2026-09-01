<?php
/**
 * Security posture port tests (Wave D4f).
 *
 * Characterization suite for `SecurityPosture`. Assertions mirror the
 * base plugin's posture service: report shape, score range, grade
 * validity, quick-win caps, cache invalidation/refresh, score sensitivity
 * to enabled controls, per-signal pass/fail flags, and the
 * `wp_mcp_ai_security_posture_signals` filter.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Security\SecurityPosture;

/**
 * @group security
 */
class Test_Security_Posture extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\delete_transient( SecurityPosture::CACHE_KEY );
		\delete_option( 'wp_mcp_ai_settings' );
		\delete_option( 'nvoos_content_graph_settings' );
	}

	public function tearDown(): void {
		\remove_all_filters( 'wp_mcp_ai_security_posture_signals' );

		\delete_transient( SecurityPosture::CACHE_KEY );
		\delete_option( 'wp_mcp_ai_settings' );
		\delete_option( 'nvoos_content_graph_settings' );

		parent::tearDown();
	}

	/**
	 * Write settings into the active store.
	 *
	 * @param array $settings Settings map.
	 * @return void
	 */
	private function set_settings( array $settings ): void {
		$option = defined( 'WP_MCP_AI_PATH' ) ? 'wp_mcp_ai_settings' : 'nvoos_content_graph_settings';
		\update_option( $option, $settings );
	}

	public function test_constants_match_base(): void {
		$this->assertSame( 'wp_mcp_ai_security_posture', SecurityPosture::CACHE_KEY );
		$this->assertSame( 300, SecurityPosture::CACHE_TTL );
	}

	public function test_report_shape_with_empty_settings(): void {
		$report = ( new SecurityPosture() )->get_report( true );

		$this->assertArrayHasKey( 'score', $report );
		$this->assertArrayHasKey( 'grade', $report );
		$this->assertArrayHasKey( 'signals', $report );
		$this->assertArrayHasKey( 'quick_wins', $report );
		$this->assertArrayHasKey( 'computed_at', $report );

		$this->assertIsInt( $report['score'] );
		$this->assertGreaterThanOrEqual( 0, $report['score'] );
		$this->assertLessThanOrEqual( 100, $report['score'] );
		$this->assertContains( $report['grade'], array( 'A', 'B', 'C', 'D', 'F' ) );

		$this->assertNotEmpty( $report['signals'] );
		$this->assertLessThanOrEqual( 3, count( $report['quick_wins'] ) );

		foreach ( $report['signals'] as $signal ) {
			$this->assertArrayHasKey( 'id', $signal );
			$this->assertArrayHasKey( 'label', $signal );
			$this->assertArrayHasKey( 'weight', $signal );
			$this->assertArrayHasKey( 'passed', $signal );
			$this->assertIsBool( $signal['passed'] );
		}
	}

	public function test_enabled_controls_raise_the_score(): void {
		$this->set_settings( array() );
		$bare = ( new SecurityPosture() )->get_report( true );

		$this->set_settings(
			array(
				'enable_rate_limiting'          => true,
				'rate_limit_by'                 => 'user_id',
				'enable_security_audit_log'     => true,
				'audit_log_retention_days'      => 90,
				'enable_security_headers'       => true,
				'csp_frame_ancestors'           => "'self'",
				'require_https'                 => true,
				'minimum_capability'            => 'edit_posts',
				'allow_guest_access'            => false,
				'enable_prompt_injection_detector' => true,
				'enable_pii_filter'             => true,
				'api_error_verbosity'           => 'safe',
				'enable_auth_rate_limiting'     => true,
				'max_request_body_size_kb'      => 1024,
				'require_authentication_all'    => true,
				'root_security_key'             => str_repeat( 'k', 40 ),
			)
		);
		$hardened = ( new SecurityPosture() )->get_report( true );

		$this->assertGreaterThan( $bare['score'], $hardened['score'] );
	}

	public function test_signal_flags_reflect_settings(): void {
		$this->set_settings(
			array(
				'enable_rate_limiting' => true,
				'enable_pii_filter'    => true,
			)
		);

		$report = ( new SecurityPosture() )->get_report( true );

		$by_id = array();
		foreach ( $report['signals'] as $signal ) {
			$by_id[ $signal['id'] ] = $signal;
		}

		$this->assertTrue( $by_id['rate_limiting_on']['passed'] );
		$this->assertTrue( $by_id['pii_filter']['passed'] );
		$this->assertFalse( $by_id['prompt_injection_detector']['passed'] );
		$this->assertFalse( $by_id['audit_log_on']['passed'] );

		// Unmet highest-weight signals surface as quick wins.
		$this->assertNotEmpty( $report['quick_wins'] );
	}

	public function test_report_is_cached_until_refresh(): void {
		$posture = new SecurityPosture();

		$first  = $posture->get_report();
		$second = $posture->get_report();

		$this->assertSame( $first['computed_at'], $second['computed_at'] );

		$posture->invalidate_cache();
		$this->assertFalse( \get_transient( SecurityPosture::CACHE_KEY ) );

		$refreshed = $posture->get_report( true );
		$this->assertSame( $refreshed['computed_at'], \get_transient( SecurityPosture::CACHE_KEY )['computed_at'] );
	}

	public function test_signals_filter_receives_settings(): void {
		$this->set_settings( array( 'custom_signal_setting' => true ) );

		$received = null;
		\add_filter(
			'wp_mcp_ai_security_posture_signals',
			static function ( $signals, $settings ) use ( &$received ) {
				$received = $settings;
				return $signals;
			},
			10,
			2
		);

		( new SecurityPosture() )->get_report( true );

		$this->assertIsArray( $received );
		$this->assertTrue( $received['custom_signal_setting'] );
	}

	public function test_2fa_consistency_requires_active_plugin(): void {
		// 2FA required but no 2FA plugin active → signal fails.
		$this->set_settings( array( 'enable_2fa_requirement' => true ) );
		$report = ( new SecurityPosture() )->get_report( true );

		$by_id = array();
		foreach ( $report['signals'] as $signal ) {
			$by_id[ $signal['id'] ] = $signal;
		}

		$this->assertFalse( $by_id['2fa_or_not_required']['passed'] );

		// Without the requirement the signal passes.
		$this->set_settings( array( 'enable_2fa_requirement' => false ) );
		$report = ( new SecurityPosture() )->get_report( true );

		$by_id = array();
		foreach ( $report['signals'] as $signal ) {
			$by_id[ $signal['id'] ] = $signal;
		}

		$this->assertTrue( $by_id['2fa_or_not_required']['passed'] );
	}
}
