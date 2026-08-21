<?php
/**
 * Tests for the Phase C OKF tool surface (includes/tools/okf/).
 *
 * Covers the three new tools — okf_list_bundles, okf_validate_bundle,
 * okf_import_bundle — plus the extended okf_write_concept provenance/trust
 * schema (resource, sources, usage_window, verified).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_OKF_Phase_C_Tools_Test extends WP_UnitTestCase {

	/**
	 * Temporary uploads root directory for testing.
	 *
	 * @var string
	 */
	private $test_uploads_dir;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->test_uploads_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-okf-phase-c-' . uniqid();
		mkdir( $this->test_uploads_dir, 0755, true );

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		wp_set_current_user( 1 ); // Administrator.
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		$this->recursive_rmdir( $this->test_uploads_dir );

		parent::tearDown();
	}

	/**
	 * Filter upload dir to use a temp directory for tests.
	 *
	 * @param array $upload_dir Upload directory data.
	 * @return array Modified upload directory data.
	 */
	public function filter_upload_dir( $upload_dir ) {
		$upload_dir['basedir'] = $this->test_uploads_dir;
		return $upload_dir;
	}

	/**
	 * Recursively remove a directory tree.
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	private function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file_info ) {
			if ( $file_info->isDir() ) {
				rmdir( $file_info->getPathname() );
			} else {
				unlink( $file_info->getPathname() );
			}
		}

		rmdir( $dir );
	}

	/**
	 * Test okf_list_bundles returns bundle descriptors without filesystem paths.
	 */
	public function test_list_bundles_tool() {
		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$manager->create_bundle( 'site-knowledge' );
		$manager->create_bundle( 'campaign-playbooks' );

		$writer = new WP_MCP_AI_OKF_Writer( $manager->resolve_bundle_root( 'campaign-playbooks' ) );
		$writer->write_concept( 'hello', array( 'type' => 'Note' ), 'Body.' );

		$tool   = new WP_MCP_AI_Tool_OKF_List_Bundles();
		$result = $tool->execute( array(), array() );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $result['bundles'] );

		$names = wp_list_pluck( $result['bundles'], 'name' );
		$this->assertContains( 'site-knowledge', $names );
		$this->assertContains( 'campaign-playbooks', $names );

		foreach ( $result['bundles'] as $bundle ) {
			// Server filesystem paths must not leak to assistants.
			$this->assertArrayNotHasKey( 'path', $bundle );
		}

		// The protected flag surfaces for skill-knowledge.
		$root = $manager->get_knowledge_root();
		wp_mkdir_p( $root . '/skill-knowledge' );

		$result = $tool->execute( array(), array() );
		foreach ( $result['bundles'] as $bundle ) {
			if ( 'skill-knowledge' === $bundle['name'] ) {
				$this->assertTrue( $bundle['protected'] );
			}
		}
	}

	/**
	 * Test okf_list_bundles capability gate.
	 *
	 * Every logged-in user holds the `read` capability, so the gate is only
	 * observable for a logged-out (no-user) context.
	 */
	public function test_list_bundles_requires_read() {
		wp_set_current_user( 0 ); // Logged out.

		$tool   = new WP_MCP_AI_Tool_OKF_List_Bundles();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Test okf_validate_bundle on conformant and non-conformant bundles.
	 */
	public function test_validate_bundle_tool() {
		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$manager->create_bundle( 'validate-me' );
		$bundle_root = $manager->resolve_bundle_root( 'validate-me' );

		$tool = new WP_MCP_AI_Tool_OKF_Validate_Bundle();

		// Conformant: one well-formed concept.
		$writer = new WP_MCP_AI_OKF_Writer( $bundle_root );
		$writer->write_concept( 'good', array( 'type' => 'Note' ), 'Body.' );

		$result = $tool->execute( array( 'bundle' => 'validate-me' ), array() );
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['conformant'] );
		$this->assertSame( 1, $result['concept_count'] );
		$this->assertSame( array(), $result['issues'] );
		$this->assertArrayHasKey( 'broken_links', $result );
		$this->assertArrayHasKey( 'broken_link_count', $result );
		$this->assertArrayHasKey( 'trust_tiers', $result );
		$this->assertSame( 0, $result['broken_link_count'] );
		$this->assertSame( 1, $result['trust_tiers']['unverified'] );

		// Non-conformant: a concept without frontmatter.
		file_put_contents( $bundle_root . '/broken.md', 'No frontmatter here.' );

		$result = $tool->execute( array( 'bundle' => 'validate-me' ), array() );
		$this->assertNotWPError( $result );
		$this->assertFalse( $result['conformant'] );
		$this->assertNotEmpty( $result['issues'] );
	}

	/**
	 * Test okf_validate_bundle rejects missing bundles.
	 */
	public function test_validate_bundle_missing_bundle() {
		$tool   = new WP_MCP_AI_Tool_OKF_Validate_Bundle();
		$result = $tool->execute( array( 'bundle' => 'does-not-exist' ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'okf_bundle_not_found', $result->get_error_code() );
	}

	/**
	 * Test okf_import_bundle imports a valid archive (admin).
	 */
	public function test_import_bundle_tool() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension is not available.' );
		}

		$zip_path = sys_get_temp_dir() . '/okf-import-tool-' . uniqid() . '.zip';

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, ZipArchive::CREATE ) );
		$zip->addFromString(
			'policy.md',
			"---\ntype: Policy\ntitle: Refunds\n---\n\nBody.\n"
		);
		$zip->close();

		$tool   = new WP_MCP_AI_Tool_OKF_Import_Bundle();
		$result = $tool->execute(
			array(
				'zip_path' => $zip_path,
				'bundle'   => 'imported-bundle',
			),
			array()
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'imported-bundle', $result['bundle'] );
		$this->assertSame( 1, $result['concepts'] );

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$this->assertDirectoryExists( $manager->resolve_bundle_root( 'imported-bundle' ) );

		unlink( $zip_path );
	}

	/**
	 * Test okf_import_bundle capability gate.
	 */
	public function test_import_bundle_requires_manage_options() {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$tool   = new WP_MCP_AI_Tool_OKF_Import_Bundle();
		$result = $tool->execute(
			array(
				'zip_path' => '/tmp/nope.zip',
				'bundle'   => 'nope',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Test okf_import_bundle rejects a missing archive file.
	 */
	public function test_import_bundle_missing_file() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension is not available.' );
		}

		$tool   = new WP_MCP_AI_Tool_OKF_Import_Bundle();
		$result = $tool->execute(
			array(
				'zip_path' => sys_get_temp_dir() . '/definitely-missing-' . uniqid() . '.zip',
				'bundle'   => 'ghost-bundle',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'okf_zip_open_error', $result->get_error_code() );
	}

	/**
	 * Test that okf_write_concept round-trips the v0.2 provenance/trust families.
	 */
	public function test_write_concept_extended_fields_roundtrip() {
		$tool   = new WP_MCP_AI_Tool_OKF_Write_Concept();
		$result = $tool->execute(
			array(
				'bundle'       => 'finance',
				'concept_id'   => 'metrics/revenue',
				'type'         => 'Metric',
				'title'        => 'Revenue',
				'body'         => '# Definition' . "\n\n" . 'Recognized revenue.',
				'resource'     => 'https://wiki.acme/finance/revenue',
				'sources'      => array(
					array(
						'id'            => 'rev-policy',
						'resource'      => 'https://wiki.acme/finance/revenue-recognition',
						'title'         => 'Revenue recognition policy',
						'author'        => 'team:finance',
						'usage_count'   => 42,
						'last_modified' => '2026-06-01T00:00:00Z',
					),
					array(
						'id'       => 'bogus',
						'evil_key' => '<script>alert(1)</script>',
						'resource' => 'https://example.com',
					),
				),
				'usage_window' => array(
					'from' => '2026-06-01T00:00:00Z',
					'to'   => '2026-06-30T00:00:00Z',
				),
				'verified'     => array(
					array(
						'by' => 'human:admin',
						'at' => '2026-06-25T09:00:00Z',
					),
				),
			),
			array()
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$reader  = new WP_MCP_AI_OKF_Reader( $manager->resolve_bundle_root( 'finance' ) );
		$concept = $reader->get_concept( 'metrics/revenue' );

		$this->assertNotWPError( $concept );
		$fm = $concept['frontmatter'];

		$this->assertSame( 'https://wiki.acme/finance/revenue', $fm['resource'] );

		// Recognized source keys survive; unknown keys are dropped.
		$this->assertCount( 2, $fm['sources'] );
		$this->assertSame( 'rev-policy', $fm['sources'][0]['id'] );
		$this->assertSame( 'team:finance', $fm['sources'][0]['author'] );
		$this->assertSame( 42, $fm['sources'][0]['usage_count'] );
		$this->assertArrayNotHasKey( 'evil_key', $fm['sources'][1] );
		$this->assertSame( 'https://example.com', $fm['sources'][1]['resource'] );

		$this->assertSame( '2026-06-01T00:00:00Z', $fm['usage_window']['from'] );
		$this->assertSame( '2026-06-30T00:00:00Z', $fm['usage_window']['to'] );

		$this->assertCount( 1, $fm['verified'] );
		$this->assertSame( 'human:admin', $fm['verified'][0]['by'] );

		// A human verifier raises the trust tier.
		$this->assertSame( 'human-reviewed', $reader->get_trust_tier( $fm ) );
	}
}
