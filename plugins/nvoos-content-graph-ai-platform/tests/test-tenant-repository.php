<?php
/**
 * Tenant repository port tests (Wave E4, sub-cluster 1).
 *
 * Characterization suite for the ported `TenantRepository` abstract
 * base: the sanitized context binding, the prepared `tenant_where()`
 * SQL fragment with the `1=1` bypass, strict-mode `require_tenant()`,
 * the `tenant_meta_query()` clause, and the `save_tenant_meta()`
 * post-meta stamping. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Tenant\TenantRepository;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixture shares this file with its test case.

/**
 * Concrete repository exposing the protected tenant query builders.
 */
class ConcreteTenantRepository extends TenantRepository {

	/**
	 * Expose tenant_where().
	 *
	 * @return string
	 */
	public function public_tenant_where() {
		return $this->tenant_where();
	}

	/**
	 * Expose tenant_meta_query().
	 *
	 * @return array
	 */
	public function public_tenant_meta_query() {
		return $this->tenant_meta_query();
	}

	/**
	 * Expose require_tenant().
	 *
	 * @return void
	 */
	public function public_require_tenant() {
		$this->require_tenant();
	}

	/**
	 * Expose save_tenant_meta().
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function public_save_tenant_meta( int $post_id ) {
		$this->save_tenant_meta( $post_id );
	}
}

/**
 * Tenant repository characterization.
 */
class Test_Tenant_Repository extends \WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var ConcreteTenantRepository
	 */
	private $repo;

	public function setUp(): void {
		parent::setUp();
		$this->repo = new ConcreteTenantRepository();
	}

	public function test_tenant_where_with_context(): void {
		$this->repo->set_tenant_context( 'school', 42 );

		$where = $this->repo->public_tenant_where();
		$this->assertStringContainsString( 'tenant_type', $where );
		$this->assertStringContainsString( 'tenant_id', $where );
		$this->assertStringContainsString( '42', $where );
	}

	public function test_tenant_where_bypass(): void {
		$this->assertEquals( '1=1', $this->repo->public_tenant_where() );
	}

	public function test_tenant_where_strict_mode(): void {
		$this->repo->set_strict( true );
		$this->repo->set_tenant_context( 'school', 1 );

		$where = $this->repo->public_tenant_where();
		$this->assertStringContainsString( 'tenant_type', $where );
		$this->assertStringContainsString( 'tenant_id', $where );
	}

	public function test_tenant_meta_query_with_context(): void {
		$this->repo->set_tenant_context( 'company', 5 );

		$query = $this->repo->public_tenant_meta_query();
		$this->assertNotEmpty( $query );
		$this->assertEquals( 'AND', $query['relation'] );
	}

	public function test_tenant_meta_query_bypass(): void {
		$this->assertEmpty( $this->repo->public_tenant_meta_query() );
	}

	public function test_require_tenant_bypass_no_throw(): void {
		$this->repo->public_require_tenant();
		$this->assertTrue( true );
	}

	public function test_require_tenant_strict_throws(): void {
		$this->repo->set_strict( true );

		$this->expectException( \RuntimeException::class );
		$this->repo->public_require_tenant();
	}

	public function test_require_tenant_strict_with_context(): void {
		$this->repo->set_strict( true );
		$this->repo->set_tenant_context( 'school', 1 );

		$this->repo->public_require_tenant();
		$this->assertTrue( true );
	}

	public function test_default_tenant_values(): void {
		$this->assertEquals( '', $this->repo->get_tenant_type() );
		$this->assertEquals( 0, $this->repo->get_tenant_id() );
	}

	public function test_set_tenant_context_sanitizes_type(): void {
		$this->repo->set_tenant_context( 'SCHOOL With Spaces!', 1 );
		$this->assertEquals( 'schoolwithspaces', $this->repo->get_tenant_type() );
	}

	public function test_save_tenant_meta_stamps_only_when_set(): void {
		$post_id = self::factory()->post->create();

		$this->repo->set_tenant_context( 'school', 42 );
		$this->repo->public_save_tenant_meta( $post_id );

		$this->assertEquals( 'school', get_post_meta( $post_id, '_tenant_type', true ) );
		$this->assertEquals( 42, (int) get_post_meta( $post_id, '_tenant_id', true ) );
	}

	public function test_save_tenant_meta_skips_unset_values(): void {
		$post_id = self::factory()->post->create();

		$this->repo->public_save_tenant_meta( $post_id );

		$this->assertEquals( '', get_post_meta( $post_id, '_tenant_type', true ) );
		$this->assertEquals( '', get_post_meta( $post_id, '_tenant_id', true ) );
	}
}
