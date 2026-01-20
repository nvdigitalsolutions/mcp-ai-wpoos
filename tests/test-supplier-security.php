<?php
/**
 * Tests for Supplier Security Management System.
 *
 * @package WP_MCP_AI
 */

/**
 * Supplier Security test case.
 */
class Test_Supplier_Security extends WP_UnitTestCase {
	/**
	 * Supplier Security instance.
	 *
	 * @var WP_MCP_AI_Supplier_Security
	 */
	protected $supplier_security;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->supplier_security = WP_MCP_AI_Supplier_Security::get_instance();
	}

	/**
	 * Test singleton instance.
	 */
	public function test_singleton_instance() {
		$instance1 = WP_MCP_AI_Supplier_Security::get_instance();
		$instance2 = WP_MCP_AI_Supplier_Security::get_instance();

		$this->assertSame( $instance1, $instance2, 'Singleton should return same instance' );
	}

	/**
	 * Test getting default suppliers.
	 */
	public function test_get_suppliers() {
		$suppliers = $this->supplier_security->get_suppliers();

		$this->assertIsArray( $suppliers, 'Suppliers should be an array' );
		$this->assertNotEmpty( $suppliers, 'Should have default suppliers' );
		$this->assertArrayHasKey( 'openai', $suppliers, 'Should include OpenAI supplier' );
		$this->assertArrayHasKey( 'google', $suppliers, 'Should include Google supplier' );
		$this->assertArrayHasKey( 'github', $suppliers, 'Should include GitHub supplier' );
	}

	/**
	 * Test getting a single supplier.
	 */
	public function test_get_supplier() {
		$supplier = $this->supplier_security->get_supplier( 'openai' );

		$this->assertIsArray( $supplier, 'Supplier should be an array' );
		$this->assertEquals( 'OpenAI', $supplier['name'], 'Supplier name should match' );
		$this->assertEquals( 'critical', $supplier['category'], 'OpenAI should be critical category' );
	}

	/**
	 * Test adding a new supplier.
	 */
	public function test_upsert_supplier() {
		$new_supplier = array(
			'name'       => 'Test Supplier',
			'service'    => 'Test Service',
			'category'   => 'low_risk',
			'risk_level' => 'low',
			'status'     => 'pending',
		);

		$result = $this->supplier_security->upsert_supplier( 'test_supplier', $new_supplier );

		$this->assertTrue( $result, 'Upsert should succeed' );

		$supplier = $this->supplier_security->get_supplier( 'test_supplier' );
		$this->assertIsArray( $supplier, 'New supplier should exist' );
		$this->assertEquals( 'Test Supplier', $supplier['name'], 'Supplier name should match' );
	}

	/**
	 * Test updating an existing supplier.
	 */
	public function test_update_supplier() {
		$update_data = array(
			'risk_level' => 'high',
			'status'     => 'approved',
		);

		$result = $this->supplier_security->upsert_supplier( 'openai', $update_data );

		$this->assertTrue( $result, 'Update should succeed' );

		$supplier = $this->supplier_security->get_supplier( 'openai' );
		$this->assertEquals( 'high', $supplier['risk_level'], 'Risk level should be updated' );
		$this->assertEquals( 'approved', $supplier['status'], 'Status should be updated' );
	}

	/**
	 * Test deleting a supplier.
	 */
	public function test_delete_supplier() {
		// Add a test supplier first.
		$this->supplier_security->upsert_supplier(
			'test_delete',
			array(
				'name'    => 'Delete Me',
				'service' => 'Test',
			)
		);

		$result = $this->supplier_security->delete_supplier( 'test_delete' );

		$this->assertTrue( $result, 'Delete should succeed' );

		$supplier = $this->supplier_security->get_supplier( 'test_delete' );
		$this->assertNull( $supplier, 'Supplier should be deleted' );
	}

	/**
	 * Test filtering suppliers by category.
	 */
	public function test_get_suppliers_by_category() {
		$critical = $this->supplier_security->get_suppliers_by_category( 'critical' );

		$this->assertIsArray( $critical, 'Should return array' );

		foreach ( $critical as $supplier ) {
			$this->assertEquals( 'critical', $supplier['category'], 'All suppliers should be critical category' );
		}
	}

	/**
	 * Test filtering suppliers by risk level.
	 */
	public function test_get_suppliers_by_risk() {
		$medium_risk = $this->supplier_security->get_suppliers_by_risk( 'medium' );

		$this->assertIsArray( $medium_risk, 'Should return array' );

		foreach ( $medium_risk as $supplier ) {
			$this->assertEquals( 'medium', $supplier['risk_level'], 'All suppliers should be medium risk' );
		}
	}

	/**
	 * Test getting suppliers due for review.
	 */
	public function test_get_suppliers_due_for_review() {
		// Add a supplier with past review date.
		$this->supplier_security->upsert_supplier(
			'test_overdue',
			array(
				'name'        => 'Overdue Supplier',
				'service'     => 'Test',
				'next_review' => date( 'Y-m-d', strtotime( '-1 day' ) ),
			)
		);

		$due = $this->supplier_security->get_suppliers_due_for_review();

		$this->assertIsArray( $due, 'Should return array' );
		$this->assertArrayHasKey( 'test_overdue', $due, 'Overdue supplier should be in results' );
	}

	/**
	 * Test recording a supplier incident.
	 */
	public function test_record_incident() {
		$incident = array(
			'title'       => 'Test Incident',
			'description' => 'Test incident description',
			'severity'    => 'medium',
		);

		$result = $this->supplier_security->record_incident( 'openai', $incident );

		$this->assertTrue( $result, 'Recording incident should succeed' );

		$supplier = $this->supplier_security->get_supplier( 'openai' );
		$this->assertArrayHasKey( 'incidents', $supplier, 'Supplier should have incidents array' );
		$this->assertNotEmpty( $supplier['incidents'], 'Incidents array should not be empty' );

		$last_incident = end( $supplier['incidents'] );
		$this->assertEquals( 'Test Incident', $last_incident['title'], 'Incident title should match' );
	}

	/**
	 * Test getting supplier statistics.
	 */
	public function test_get_statistics() {
		$stats = $this->supplier_security->get_statistics();

		$this->assertIsArray( $stats, 'Statistics should be an array' );
		$this->assertArrayHasKey( 'total', $stats, 'Should have total count' );
		$this->assertArrayHasKey( 'by_category', $stats, 'Should have category breakdown' );
		$this->assertArrayHasKey( 'by_risk', $stats, 'Should have risk breakdown' );
		$this->assertArrayHasKey( 'by_status', $stats, 'Should have status breakdown' );
		$this->assertArrayHasKey( 'avg_uptime', $stats, 'Should have average uptime' );

		$this->assertGreaterThan( 0, $stats['total'], 'Should have at least one supplier' );
	}

	/**
	 * Test generating SBOM.
	 */
	public function test_generate_sbom() {
		$sbom = $this->supplier_security->generate_sbom();

		$this->assertIsArray( $sbom, 'SBOM should be an array' );
		$this->assertArrayHasKey( 'timestamp', $sbom, 'SBOM should have timestamp' );
		$this->assertArrayHasKey( 'format', $sbom, 'SBOM should have format' );
		$this->assertArrayHasKey( 'version', $sbom, 'SBOM should have version' );
		$this->assertArrayHasKey( 'components', $sbom, 'SBOM should have components' );

		$this->assertEquals( 'CycloneDX', $sbom['format'], 'SBOM format should be CycloneDX' );
	}

	/**
	 * Test dependency scanning.
	 */
	public function test_scan_dependencies() {
		$results = $this->supplier_security->scan_dependencies();

		$this->assertIsArray( $results, 'Scan results should be an array' );
		$this->assertArrayHasKey( 'composer', $results, 'Should have Composer results' );
		$this->assertArrayHasKey( 'npm', $results, 'Should have NPM results' );
	}

	/**
	 * Test supplier constants.
	 */
	public function test_supplier_constants() {
		$this->assertIsArray( WP_MCP_AI_Supplier_Security::RISK_CATEGORIES, 'RISK_CATEGORIES should be an array' );
		$this->assertIsArray( WP_MCP_AI_Supplier_Security::RISK_LEVELS, 'RISK_LEVELS should be an array' );
		$this->assertIsArray( WP_MCP_AI_Supplier_Security::ASSESSMENT_STATUS, 'ASSESSMENT_STATUS should be an array' );

		$this->assertCount( 3, WP_MCP_AI_Supplier_Security::RISK_CATEGORIES, 'Should have 3 risk categories' );
		$this->assertCount( 4, WP_MCP_AI_Supplier_Security::RISK_LEVELS, 'Should have 4 risk levels' );
		$this->assertCount( 4, WP_MCP_AI_Supplier_Security::ASSESSMENT_STATUS, 'Should have 4 assessment statuses' );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Clean up test data.
		delete_option( 'wp_mcp_ai_suppliers' );
		delete_option( 'wp_mcp_ai_last_dependency_scan' );

		parent::tearDown();
	}
}
