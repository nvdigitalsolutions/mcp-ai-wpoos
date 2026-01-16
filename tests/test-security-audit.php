<?php
/**
 * Class Test_Security_Audit
 *
 * Tests for WP_MCP_AI_Security_Audit class
 *
 * @package WP_MCP_AI
 */

/**
 * Security Audit System test case.
 */
class Test_Security_Audit extends WP_UnitTestCase {

	/**
	 * Test singleton instance
	 */
	public function test_singleton_instance() {
		$instance1 = WP_MCP_AI_Security_Audit::get_instance();
		$instance2 = WP_MCP_AI_Security_Audit::get_instance();

		$this->assertSame( $instance1, $instance2 );
		$this->assertInstanceOf( 'WP_MCP_AI_Security_Audit', $instance1 );
	}

	/**
	 * Test audit post type registration
	 */
	public function test_post_type_registered() {
		$this->assertTrue( post_type_exists( 'mcp_ai_audit' ) );
	}

	/**
	 * Test audit creation
	 */
	public function test_create_audit() {
		$audit_id = wp_insert_post(
			array(
				'post_title'   => 'Test Internal Audit',
				'post_type'    => 'mcp_ai_audit',
				'post_status'  => 'publish',
				'post_content' => 'Test audit content',
			)
		);

		$this->assertGreaterThan( 0, $audit_id );
		$this->assertFalse( is_wp_error( $audit_id ) );

		// Test meta data.
		update_post_meta( $audit_id, '_wp_mcp_ai_audit_date', '2026-01-06' );
		update_post_meta( $audit_id, '_wp_mcp_ai_audit_type', 'internal' );
		update_post_meta( $audit_id, '_wp_mcp_ai_audit_status', 'completed' );
		update_post_meta( $audit_id, '_wp_mcp_ai_auditor', 'Test Auditor' );

		$this->assertEquals( '2026-01-06', get_post_meta( $audit_id, '_wp_mcp_ai_audit_date', true ) );
		$this->assertEquals( 'internal', get_post_meta( $audit_id, '_wp_mcp_ai_audit_type', true ) );
		$this->assertEquals( 'completed', get_post_meta( $audit_id, '_wp_mcp_ai_audit_status', true ) );
		$this->assertEquals( 'Test Auditor', get_post_meta( $audit_id, '_wp_mcp_ai_auditor', true ) );
	}

	/**
	 * Test audit findings
	 */
	public function test_audit_findings() {
		$audit_id = wp_insert_post(
			array(
				'post_title'  => 'Audit with Findings',
				'post_type'   => 'mcp_ai_audit',
				'post_status' => 'publish',
			)
		);

		$findings = array(
			array(
				'control'        => 'A.5.1',
				'severity'       => 'high',
				'status'         => 'open',
				'description'    => 'Finding description',
				'recommendation' => 'Recommendation text',
				'due_date'       => '2026-02-01',
			),
			array(
				'control'        => 'A.8.1',
				'severity'       => 'medium',
				'status'         => 'resolved',
				'description'    => 'Another finding',
				'recommendation' => 'Another recommendation',
				'due_date'       => '2026-01-15',
			),
		);

		update_post_meta( $audit_id, '_wp_mcp_ai_audit_findings', $findings );

		$saved_findings = get_post_meta( $audit_id, '_wp_mcp_ai_audit_findings', true );
		$this->assertIsArray( $saved_findings );
		$this->assertCount( 2, $saved_findings );
		$this->assertEquals( 'A.5.1', $saved_findings[0]['control'] );
		$this->assertEquals( 'high', $saved_findings[0]['severity'] );
		$this->assertEquals( 'open', $saved_findings[0]['status'] );
	}

	/**
	 * Test audit statistics
	 */
	public function test_audit_statistics() {
		// Create test audits.
		$audit1 = wp_insert_post(
			array(
				'post_title'  => 'Completed Audit',
				'post_type'   => 'mcp_ai_audit',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $audit1, '_wp_mcp_ai_audit_status', 'completed' );
		update_post_meta( $audit1, '_wp_mcp_ai_audit_findings', array(
			array(
				'control'  => 'A.5.1',
				'severity' => 'high',
				'status'   => 'open',
			),
		) );

		$audit2 = wp_insert_post(
			array(
				'post_title'  => 'In Progress Audit',
				'post_type'   => 'mcp_ai_audit',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $audit2, '_wp_mcp_ai_audit_status', 'in_progress' );
		update_post_meta( $audit2, '_wp_mcp_ai_audit_findings', array(
			array(
				'control'  => 'A.6.1',
				'severity' => 'medium',
				'status'   => 'open',
			),
			array(
				'control'  => 'A.7.1',
				'severity' => 'low',
				'status'   => 'resolved',
			),
		) );

		$audit_system = WP_MCP_AI_Security_Audit::get_instance();
		$stats = $audit_system->get_audit_statistics();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total_audits', $stats );
		$this->assertArrayHasKey( 'completed', $stats );
		$this->assertArrayHasKey( 'in_progress', $stats );
		$this->assertArrayHasKey( 'total_findings', $stats );
		$this->assertArrayHasKey( 'open_findings', $stats );

		$this->assertGreaterThanOrEqual( 2, $stats['total_audits'] );
		$this->assertGreaterThanOrEqual( 1, $stats['completed'] );
		$this->assertGreaterThanOrEqual( 1, $stats['in_progress'] );
		$this->assertEquals( 3, $stats['total_findings'] );
		$this->assertEquals( 2, $stats['open_findings'] );
	}

	/**
	 * Test recent audits retrieval
	 */
	public function test_get_recent_audits() {
		// Create multiple audits.
		for ( $i = 1; $i <= 5; $i++ ) {
			wp_insert_post(
				array(
					'post_title'  => "Test Audit $i",
					'post_type'   => 'mcp_ai_audit',
					'post_status' => 'publish',
					'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( "-$i days" ) ),
				)
			);
		}

		$audit_system = WP_MCP_AI_Security_Audit::get_instance();
		$recent_audits = $audit_system->get_recent_audits( 3 );

		$this->assertIsArray( $recent_audits );
		$this->assertCount( 3, $recent_audits );
		$this->assertEquals( 'mcp_ai_audit', $recent_audits[0]->post_type );
	}

	/**
	 * Test audit constants
	 */
	public function test_audit_constants() {
		$this->assertEquals( 'scheduled', WP_MCP_AI_Security_Audit::STATUS_SCHEDULED );
		$this->assertEquals( 'in_progress', WP_MCP_AI_Security_Audit::STATUS_IN_PROGRESS );
		$this->assertEquals( 'completed', WP_MCP_AI_Security_Audit::STATUS_COMPLETED );
		$this->assertEquals( 'overdue', WP_MCP_AI_Security_Audit::STATUS_OVERDUE );

		$this->assertEquals( 'critical', WP_MCP_AI_Security_Audit::SEVERITY_CRITICAL );
		$this->assertEquals( 'high', WP_MCP_AI_Security_Audit::SEVERITY_HIGH );
		$this->assertEquals( 'medium', WP_MCP_AI_Security_Audit::SEVERITY_MEDIUM );
		$this->assertEquals( 'low', WP_MCP_AI_Security_Audit::SEVERITY_LOW );
		$this->assertEquals( 'observation', WP_MCP_AI_Security_Audit::SEVERITY_OBSERVATION );

		$this->assertEquals( 'open', WP_MCP_AI_Security_Audit::FINDING_OPEN );
		$this->assertEquals( 'in_progress', WP_MCP_AI_Security_Audit::FINDING_IN_PROGRESS );
		$this->assertEquals( 'resolved', WP_MCP_AI_Security_Audit::FINDING_RESOLVED );
		$this->assertEquals( 'accepted', WP_MCP_AI_Security_Audit::FINDING_ACCEPTED );

		$this->assertEquals( 'internal', WP_MCP_AI_Security_Audit::TYPE_INTERNAL );
		$this->assertEquals( 'external', WP_MCP_AI_Security_Audit::TYPE_EXTERNAL );
		$this->assertEquals( 'management_review', WP_MCP_AI_Security_Audit::TYPE_MANAGEMENT_REVIEW );
	}

	/**
	 * Clean up after tests
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clean up test audits.
		$audits = get_posts(
			array(
				'post_type'      => 'mcp_ai_audit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $audits as $audit_id ) {
			wp_delete_post( $audit_id, true );
		}
	}
}
