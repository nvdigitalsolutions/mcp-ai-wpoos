<?php
/**
 * Tenant Repository Tests
 *
 * Concrete implementation and tests for the abstract WP_MCP_AI_Tenant_Repository class.
 *
 * @package WP_MCP_AI
 */

// phpcs:disable WordPress.Files.FileName, Squiz.Commenting.FileComment, Generic.Commenting.DocComment, Generic.Files.OneObjectStructurePerFile

require_once WP_MCP_AI_PATH . 'includes/tenant/class-wp-mcp-ai-tenant-repository.php';

/**
 * Concrete implementation for testing the abstract repository.
 */
class Test_Tenant_Repository_Concrete extends WP_MCP_AI_Tenant_Repository {

	/**
	 * Expose tenant_where() for testing.
	 *
	 * @return string
	 */
	public function public_tenant_where() {
		return $this->tenant_where();
	}

	/**
	 * Expose tenant_meta_query() for testing.
	 *
	 * @return array
	 */
	public function public_tenant_meta_query() {
		return $this->tenant_meta_query();
	}

	/**
	 * Expose require_tenant() for testing.
	 *
	 * @return void
	 */
	public function public_require_tenant() {
		$this->require_tenant();
	}
}

/**
 * Test tenant repository base class.
 */
class Test_Tenant_Repository extends WP_UnitTestCase {

	/**
	 * Repository instance for testing.
	 *
	 * @var Test_Tenant_Repository_Concrete
	 */
	private $repo;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->repo = new Test_Tenant_Repository_Concrete();
	}

	/**
	 * Tenant_where() should return a prepared clause when context is set.
	 */
	public function test_tenant_where_with_context() {
		$this->repo->set_tenant_context( 'school', 42 );

		$where = $this->repo->public_tenant_where();
		$this->assertStringContainsString( 'tenant_type', $where );
		$this->assertStringContainsString( 'tenant_id', $where );
		$this->assertStringContainsString( '42', $where );
	}

	/**
	 * Tenant_where() should return bypass clause when no context.
	 */
	public function test_tenant_where_bypass() {
		$where = $this->repo->public_tenant_where();
		$this->assertEquals( '1=1', $where );
	}

	/**
	 * Tenant_where() should include tenant even with tenant_id=0 in STRICT mode.
	 */
	public function test_tenant_where_strict_mode() {
		$this->repo->set_strict( true );
		$this->repo->set_tenant_context( 'school', 1 );

		$where = $this->repo->public_tenant_where();
		$this->assertStringContainsString( 'tenant_type', $where );
		$this->assertStringContainsString( 'tenant_id', $where );
	}

	/**
	 * Tenant_meta_query() should return filter clause when context is set.
	 */
	public function test_tenant_meta_query_with_context() {
		$this->repo->set_tenant_context( 'company', 5 );

		$query = $this->repo->public_tenant_meta_query();
		$this->assertNotEmpty( $query );
		$this->assertEquals( 'AND', $query['relation'] );
	}

	/**
	 * Tenant_meta_query() should return empty array in bypass mode.
	 */
	public function test_tenant_meta_query_bypass() {
		$query = $this->repo->public_tenant_meta_query();
		$this->assertEmpty( $query );
	}

	/**
	 * Require_tenant() should not throw in bypass mode.
	 */
	public function test_require_tenant_bypass_no_throw() {
		$this->repo->public_require_tenant();
		$this->assertTrue( true );
	}

	/**
	 * Require_tenant() should throw in strict mode with no context.
	 */
	public function test_require_tenant_strict_throws() {
		$this->repo->set_strict( true );

		$this->expectException( \RuntimeException::class );
		$this->repo->public_require_tenant();
	}

	/**
	 * Require_tenant() should not throw in strict mode with valid context.
	 */
	public function test_require_tenant_strict_with_context() {
		$this->repo->set_strict( true );
		$this->repo->set_tenant_context( 'school', 1 );

		$this->repo->public_require_tenant();
		$this->assertTrue( true );
	}

	/**
	 * Get_tenant_type / get_tenant_id should return defaults.
	 */
	public function test_default_tenant_values() {
		$this->assertEquals( '', $this->repo->get_tenant_type() );
		$this->assertEquals( 0, $this->repo->get_tenant_id() );
	}

	/**
	 * Set_tenant_context sanitizes the type.
	 */
	public function test_set_tenant_context_sanitizes_type() {
		$this->repo->set_tenant_context( 'SCHOOL With Spaces!', 1 );
		$this->assertEquals( 'schoolwithspaces', $this->repo->get_tenant_type() );
	}
}
