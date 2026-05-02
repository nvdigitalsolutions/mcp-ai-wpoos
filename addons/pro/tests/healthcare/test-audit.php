<?php
/**
 * Tests for the unified Healthcare Toolkit PHI audit ledger.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
	$audit_path = dirname( __DIR__, 2 ) . '/includes/tools/healthcare/class-wp-mcp-ai-healthcare-audit.php';
	if ( file_exists( $audit_path ) ) {
		require_once $audit_path;
	}
}

/**
 * Test case for WP_MCP_AI_Healthcare_Audit.
 */
class Test_Healthcare_Audit extends WP_UnitTestCase {

	/**
	 * Reset between tests.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Healthcare_Audit::clear();
	}

	/**
	 * Drop filters between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_healthcare_before_phi_access' );
		remove_all_actions( 'wp_mcp_ai_healthcare_after_phi_access' );
		WP_MCP_AI_Healthcare_Audit::clear();
		parent::tearDown();
	}

	/**
	 * Recording an entry persists it in the buffer.
	 */
	public function test_record_persists_entry() {
		WP_MCP_AI_Healthcare_Audit::record( 'member_viewed', 'member', 42, array( 'note' => 'first read' ) );
		$entries = WP_MCP_AI_Healthcare_Audit::recent();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'member_viewed', $entries[0]['event'] );
		$this->assertSame( 'member', $entries[0]['resource_type'] );
		$this->assertSame( '42', $entries[0]['resource_id'] );
		$this->assertSame( 'first read', $entries[0]['meta']['note'] );
		$this->assertNotEmpty( $entries[0]['timestamp'] );
	}

	/**
	 * The legacy `log()` shim adapts old calls to `record()`.
	 */
	public function test_legacy_log_alias() {
		WP_MCP_AI_Healthcare_Audit::log(
			'study_viewed',
			array(
				'study_id' => 'study-99',
				'extra'    => 'meta',
			)
		);
		$entries = WP_MCP_AI_Healthcare_Audit::recent();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'study_viewed', $entries[0]['event'] );
		$this->assertSame( 'imaging_study', $entries[0]['resource_type'] );
		$this->assertSame( 'study-99', $entries[0]['resource_id'] );
	}

	/**
	 * IP addresses are hashed, not stored as plain text.
	 */
	public function test_ip_is_hashed() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.42';
		WP_MCP_AI_Healthcare_Audit::record( 'member_viewed', 'member', 1 );
		$entries = WP_MCP_AI_Healthcare_Audit::recent();
		$this->assertNotEmpty( $entries[0]['ip_hash'] );
		$this->assertNotEquals( '203.0.113.42', $entries[0]['ip_hash'] );
		$this->assertSame( 64, strlen( $entries[0]['ip_hash'] ) );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	/**
	 * Non-array filter return value suppresses the entry.
	 */
	public function test_before_filter_can_suppress() {
		add_filter(
			'wp_mcp_ai_healthcare_before_phi_access',
			static function () {
				return false;
			}
		);
		WP_MCP_AI_Healthcare_Audit::record( 'member_viewed', 'member', 1 );
		$this->assertCount( 0, WP_MCP_AI_Healthcare_Audit::recent() );
	}

	/**
	 * `after_phi_access` action receives the persisted entry.
	 */
	public function test_after_action_fires() {
		$captured = null;
		add_action(
			'wp_mcp_ai_healthcare_after_phi_access',
			static function ( $entry ) use ( &$captured ) {
				$captured = $entry;
			}
		);
		WP_MCP_AI_Healthcare_Audit::record( 'vital_logged', 'vital_log', 'vl-1' );
		$this->assertNotNull( $captured );
		$this->assertSame( 'vital_logged', $captured['event'] );
	}

	/**
	 * `recent()` honours the requested limit.
	 */
	public function test_recent_limit() {
		for ( $i = 0; $i < 25; $i++ ) {
			WP_MCP_AI_Healthcare_Audit::record( 'member_viewed', 'member', $i );
		}
		$this->assertCount( 10, WP_MCP_AI_Healthcare_Audit::recent( 10 ) );
		$this->assertCount( 25, WP_MCP_AI_Healthcare_Audit::recent( 100 ) );
	}
}
