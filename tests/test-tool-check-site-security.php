<?php
/**
 * Tests for check_site_security tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test check_site_security tool — reads sensitive site configuration.
 */
class Test_Tool_Check_Site_Security extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Check_Site_Security
	 */
	private $tool;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->tool     = new WP_MCP_AI_Tool_Check_Site_Security();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'check_site_security', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Unauthenticated (user_id=0) is rejected.
	 */
	public function test_unauthenticated_returns_forbidden() {
		$result = $this->tool->execute( array(), array( 'user_id' => 0 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Admin receives a structured security report.
	 */
	public function test_admin_receives_security_report() {
		$result = $this->tool->execute( array(), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'risk_level', $result );
		$this->assertArrayHasKey( 'is_safe_to_use', $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertArrayHasKey( 'checks', $result );
	}

	/**
	 * Risk level is one of the expected values.
	 */
	public function test_risk_level_is_valid() {
		$result = $this->tool->execute( array(), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );
		$this->assertContains( $result['risk_level'], array( 'safe', 'low', 'moderate', 'high', 'critical' ) );
	}

	/**
	 * Summary has critical, warning, and pass counts.
	 */
	public function test_summary_has_severity_counts() {
		$result = $this->tool->execute( array(), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );
		$summary = $result['summary'];
		$this->assertArrayHasKey( 'critical', $summary );
		$this->assertArrayHasKey( 'warning', $summary );
		$this->assertArrayHasKey( 'pass', $summary );
	}

	/**
	 * Each check result has a severity field.
	 */
	public function test_each_check_has_severity() {
		$result = $this->tool->execute( array(), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['checks'] );

		foreach ( $result['checks'] as $check ) {
			$this->assertArrayHasKey( 'severity', $check );
			$this->assertContains( $check['severity'], array( 'pass', 'warning', 'critical' ) );
		}
	}
}
