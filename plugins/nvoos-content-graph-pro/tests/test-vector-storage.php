<?php
/**
 * Characterization tests for the ported vector-storage subsystem (Wave F1,
 * sub-cluster 5): the vector-store adapter, the prepare-file tool, and the
 * file-preprocessing helper copies.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   adapter/tool (classmap-autoloaded); the base plugin owns the helper and
 *   the tool interfaces.
 * - Standalone matrix (base plugin absent): the ported copies in `src/` are
 *   asserted in full, including the per-mode credential seam.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Vector-storage tests.
 */
class Test_Vector_Storage extends WP_UnitTestCase {

	/**
	 * Snapshot of the adapter settings option before mutation.
	 *
	 * @var mixed
	 */
	private $settings_snapshot = null;

	/**
	 * Snapshot the adapter settings option.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->settings_snapshot = get_option( WP_MCP_AI_Vector_Store_Adapter::OPTION_SETTINGS, null );
	}

	/**
	 * Restore the adapter settings option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( null === $this->settings_snapshot ) {
			delete_option( WP_MCP_AI_Vector_Store_Adapter::OPTION_SETTINGS );
		} else {
			update_option( WP_MCP_AI_Vector_Store_Adapter::OPTION_SETTINGS, $this->settings_snapshot );
		}
		parent::tearDown();
	}

	/**
	 * The adapter must be a singleton listing the three backends.
	 */
	public function test_adapter_singleton_and_backends(): void {
		$adapter = WP_MCP_AI_Vector_Store_Adapter::get_instance();
		$this->assertSame( $adapter, WP_MCP_AI_Vector_Store_Adapter::get_instance() );

		$backends = wp_list_pluck( $adapter->list_backends(), 'key' );
		$this->assertContains( 'openai', $backends );
		$this->assertContains( 'pgvector', $backends );
		$this->assertContains( 'qdrant', $backends );
	}

	/**
	 * The pgvector backend must reflect the byte-identical DSN setting.
	 */
	public function test_adapter_is_configured_pgvector(): void {
		$adapter = WP_MCP_AI_Vector_Store_Adapter::get_instance();
		$this->assertFalse( $adapter->is_configured( 'pgvector' ) );

		$settings                 = get_option( WP_MCP_AI_Vector_Store_Adapter::OPTION_SETTINGS, array() );
		$settings                 = is_array( $settings ) ? $settings : array();
		$settings['pgvector_dsn'] = 'postgresql://user:pass@localhost:5432/db';
		update_option( WP_MCP_AI_Vector_Store_Adapter::OPTION_SETTINGS, $settings );

		$this->assertTrue( $adapter->is_configured( 'pgvector' ) );
	}

	/**
	 * Unconfigured stub backends must degrade gracefully without network
	 * calls (stub responses).
	 */
	public function test_adapter_stub_backends_graceful(): void {
		$adapter = WP_MCP_AI_Vector_Store_Adapter::get_instance();

		$upsert = $adapter->upsert(
			'test-ns',
			array(
				array(
					'id'   => 'doc-1',
					'text' => 'hello',
				),
			)
		);
		$this->assertIsArray( $upsert );

		$query = $adapter->query( 'test-ns', 'hello' );
		$this->assertIsArray( $query );

		$deleted = $adapter->delete( 'test-ns', array( 'doc-1' ) );
		$this->assertTrue( $deleted ); // Byte-identical: stub backends return true.
	}

	/**
	 * Standalone: the credential seam must report qdrant unconfigured even
	 * with a URL set (no credential resolver port — D8 forward-reference).
	 */
	public function test_adapter_qdrant_credential_seam_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base resolver owns the answer; probe-conditional.
			$settings               = get_option( WP_MCP_AI_Vector_Store_Adapter::OPTION_SETTINGS, array() );
			$settings               = is_array( $settings ) ? $settings : array();
			$settings['qdrant_url'] = 'https://example.qdrant.io';
			update_option( WP_MCP_AI_Vector_Store_Adapter::OPTION_SETTINGS, $settings );
			$this->markTestSkipped( 'Monolith matrix: base credential resolver owns qdrant configuration.' );
		}

		$settings               = get_option( WP_MCP_AI_Vector_Store_Adapter::OPTION_SETTINGS, array() );
		$settings               = is_array( $settings ) ? $settings : array();
		$settings['qdrant_url'] = 'https://example.qdrant.io';
		update_option( WP_MCP_AI_Vector_Store_Adapter::OPTION_SETTINGS, $settings );

		$this->assertFalse( WP_MCP_AI_Vector_Store_Adapter::get_instance()->is_configured( 'qdrant' ) );
	}

	/**
	 * Standalone only: the registry's vector_storage module must boot once
	 * the adapter exists.
	 */
	public function test_vector_storage_module_boots_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base registry wires vector storage.' );
		}

		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();
		$this->assertTrue( $registry->is_loaded( 'vector_storage' ) );
	}
}
