<?php
/**
 * Tests for the OKF Bundle Manager (includes/okf/).
 *
 * Covers the bundle-level lifecycle: knowledge-root guards, path resolution
 * (traversal + symlink containment), creation, listing, rename, archive,
 * delete, ZIP export/import (incl. ZipSlip + symlink rejection), statistics,
 * log maintenance, protected-bundle enforcement in the tools, and the
 * knowledge-root filter used by the skill-knowledge generator.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_OKF_Bundle_Manager_Test extends WP_UnitTestCase {

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

		$this->test_uploads_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-okf-manager-' . uniqid();
		mkdir( $this->test_uploads_dir, 0755, true );

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
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
	 * Build a fresh manager instance.
	 *
	 * @return WP_MCP_AI_OKF_Bundle_Manager
	 */
	private function manager() {
		return new WP_MCP_AI_OKF_Bundle_Manager();
	}

	/**
	 * Test that the knowledge root is created with security guards.
	 */
	public function test_knowledge_root_created_with_guards() {
		$root = $this->manager()->get_knowledge_root();

		$this->assertNotWPError( $root );
		$this->assertDirectoryExists( $root );
		$this->assertFileExists( $root . '/.htaccess' );
		$this->assertFileExists( $root . '/index.php' );
	}

	/**
	 * Test that invalid bundle names are rejected by the resolver.
	 *
	 * @param string $bundle Invalid bundle name.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_invalid_bundle_names' )]
	public function test_resolve_rejects_invalid_names( $bundle ) {
		$result = $this->manager()->resolve_bundle_root( $bundle, true );

		$this->assertWPError( $result );
		$this->assertSame( 'okf_invalid_bundle', $result->get_error_code() );
	}

	/**
	 * Data provider: bundle names that must be rejected.
	 *
	 * @return array<int, array<int, string>>
	 */
	public static function provide_invalid_bundle_names() {
		return array(
			array( '' ),
			array( '..' ),
			array( '../evil' ),
			array( 'a/b' ),
			array( 'UPPERCASE' ),
			array( 'Bad Name' ),
			array( '-leading-dash' ),
			array( 'trailing.' ),
		);
	}

	/**
	 * Test resolving a missing bundle: not-found without create, path with create.
	 */
	public function test_resolve_missing_bundle() {
		$manager = $this->manager();

		$missing = $manager->resolve_bundle_root( 'brand-new-bundle' );
		$this->assertWPError( $missing );
		$this->assertSame( 'okf_bundle_not_found', $missing->get_error_code() );

		$path = $manager->resolve_bundle_root( 'brand-new-bundle', true );
		$this->assertNotWPError( $path );
		$this->assertStringEndsWith( '/brand-new-bundle', $path );
		$this->assertDirectoryDoesNotExist( $path ); // Resolution has no side effects.
	}

	/**
	 * Test that create_bundle() creates the directory, stamps okf_version,
	 * initializes log.md, and fires the bundle-initialized event.
	 */
	public function test_create_bundle() {
		$baseline_fires = did_action( 'wp_mcp_ai_okf_bundle_initialized' );

		$result = $this->manager()->create_bundle( 'marketing-playbooks' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'marketing-playbooks', $result['bundle'] );
		$this->assertDirectoryExists( $result['path'] );

		$index_content = file_get_contents( $result['path'] . '/index.md' );
		$this->assertNotFalse( $index_content );
		$this->assertStringContainsString( 'okf_version: "0.2"', $index_content );

		$log_content = file_get_contents( $result['path'] . '/log.md' );
		$this->assertNotFalse( $log_content );
		$this->assertStringContainsString( 'Initialization', $log_content );

		$this->assertSame( $baseline_fires + 1, did_action( 'wp_mcp_ai_okf_bundle_initialized' ) );
	}

	/**
	 * Test that creating an existing bundle fails cleanly.
	 */
	public function test_create_bundle_exists() {
		$this->manager()->create_bundle( 'marketing-playbooks' );

		$result = $this->manager()->create_bundle( 'marketing-playbooks' );

		$this->assertWPError( $result );
		$this->assertSame( 'okf_bundle_exists', $result->get_error_code() );
	}

	/**
	 * Test that list_bundles() reports created bundles with statistics.
	 */
	public function test_list_bundles_includes_created() {
		$manager = $this->manager();
		$manager->create_bundle( 'zeta-bundle' );
		$manager->create_bundle( 'alpha-bundle' );

		// Write one concept into zeta-bundle.
		$writer = new WP_MCP_AI_OKF_Writer( $manager->resolve_bundle_root( 'zeta-bundle' ) );
		$writer->write_concept(
			'hello',
			array(
				'type'  => 'Note',
				'title' => 'Hello',
			),
			'Body.'
		);

		$bundles = $manager->list_bundles();

		$this->assertNotWPError( $bundles );
		$this->assertSame( array( 'alpha-bundle', 'zeta-bundle' ), wp_list_pluck( $bundles, 'name' ) );

		$zeta = $bundles[1];
		$this->assertSame( 1, $zeta['concept_count'] );
		$this->assertFalse( $zeta['protected'] );
		$this->assertTrue( $zeta['conformant'] );
		$this->assertArrayHasKey( 'unverified', $zeta['trust_tiers'] );
	}

	/**
	 * Test rename: user bundles rename, standard bundles refuse.
	 */
	public function test_rename_bundle() {
		$manager = $this->manager();
		$manager->create_bundle( 'old-name' );

		$result = $manager->rename_bundle( 'old-name', 'new-name' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'new-name', $result['bundle'] );
		$this->assertDirectoryDoesNotExist( $manager->resolve_bundle_root( 'old-name', true ) );
		$this->assertDirectoryExists( $manager->resolve_bundle_root( 'new-name' ) );

		// Standard bundles cannot be renamed.
		$blocked = $manager->rename_bundle( 'site-knowledge', 'renamed' );
		$this->assertWPError( $blocked );
		$this->assertSame( 'okf_protected_bundle', $blocked->get_error_code() );
	}

	/**
	 * Test archive: bundle moves to .trash; protected bundles refuse.
	 */
	public function test_archive_bundle() {
		$manager = $this->manager();
		$manager->create_bundle( 'doomed' );

		$result = $manager->archive_bundle( 'doomed' );

		$this->assertNotWPError( $result );
		$this->assertDirectoryDoesNotExist( $manager->resolve_bundle_root( 'doomed', true ) );
		$this->assertDirectoryExists( $result['trash_path'] );

		// skill-knowledge is protected — create the directory so resolution
		// passes and the protection gate is what rejects the archive.
		$root = $manager->get_knowledge_root();
		wp_mkdir_p( $root . '/skill-knowledge' );

		$blocked = $manager->archive_bundle( 'skill-knowledge' );
		$this->assertWPError( $blocked );
		$this->assertSame( 'okf_protected_bundle', $blocked->get_error_code() );
	}

	/**
	 * Test delete: bundle tree removed; protected bundles refuse.
	 */
	public function test_delete_bundle() {
		$manager = $this->manager();
		$manager->create_bundle( 'doomed' );

		$result = $manager->delete_bundle( 'doomed' );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertDirectoryDoesNotExist( $manager->resolve_bundle_root( 'doomed', true ) );

		$root = $manager->get_knowledge_root();
		wp_mkdir_p( $root . '/skill-knowledge' );

		$blocked = $manager->delete_bundle( 'skill-knowledge' );
		$this->assertWPError( $blocked );
		$this->assertSame( 'okf_protected_bundle', $blocked->get_error_code() );
	}

	/**
	 * Test ZIP export/import round-trip preserves concepts.
	 */
	public function test_export_import_roundtrip() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension is not available.' );
		}

		$manager = $this->manager();
		$manager->create_bundle( 'source-bundle' );

		$writer = new WP_MCP_AI_OKF_Writer( $manager->resolve_bundle_root( 'source-bundle' ) );
		$writer->write_concept(
			'policies/refunds',
			array(
				'type'        => 'Policy',
				'title'       => 'Refunds',
				'description' => 'Refund policy',
				'generated'   => array(
					'by' => 'test',
					'at' => gmdate( 'c' ),
				),
			),
			'# Policy' . "\n\n" . 'Refunds within 30 days.'
		);

		$exported = $manager->export_bundle_zip( 'source-bundle' );
		$this->assertNotWPError( $exported );
		$this->assertFileExists( $exported['path'] );
		$this->assertGreaterThan( 0, $exported['entries'] );

		$imported = $manager->import_bundle_zip( $exported['path'], 'imported-bundle' );
		$this->assertNotWPError( $imported );
		$this->assertSame( 'imported-bundle', $imported['bundle'] );
		$this->assertGreaterThanOrEqual( 1, $imported['concepts'] );

		$reader  = new WP_MCP_AI_OKF_Reader( $imported['path'] );
		$concept = $reader->get_concept( 'policies/refunds' );
		$this->assertNotWPError( $concept );
		$this->assertSame( 'Policy', $concept['frontmatter']['type'] );
		$this->assertSame( 'Refunds', $concept['frontmatter']['title'] );
	}

	/**
	 * Test that ZipSlip entries are rejected and nothing is created.
	 */
	public function test_import_rejects_zip_slip() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension is not available.' );
		}

		$zip_path = sys_get_temp_dir() . '/okf-zipslip-' . uniqid() . '.zip';

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, ZipArchive::CREATE ) );
		$zip->addFromString( '../evil.md', "---\ntype: Evil\n---\n\nBody.\n" );
		$zip->close();

		$result = $this->manager()->import_bundle_zip( $zip_path, 'evil-import' );

		$this->assertWPError( $result );
		$this->assertSame( 'okf_zip_unsafe_entry', $result->get_error_code() );
		$this->assertDirectoryDoesNotExist( $this->manager()->resolve_bundle_root( 'evil-import', true ) );

		unlink( $zip_path );
	}

	/**
	 * Test that symlink entries are rejected (when the platform reports them).
	 */
	public function test_import_rejects_symlink_entries() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension is not available.' );
		}

		$zip_path = sys_get_temp_dir() . '/okf-symlink-' . uniqid() . '.zip';

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, ZipArchive::CREATE ) );
		$zip->addFromString( 'link.md', 'target' );
		$zip->setExternalAttributesName( 'link.md', ZipArchive::OPSYS_UNIX, ( 0120777 << 16 ) );
		$zip->close();

		$result = $this->manager()->import_bundle_zip( $zip_path, 'symlink-import' );

		// Either the symlink entry is detected upfront, or the entry cannot
		// parse as a concept and the import is rejected as concept-less.
		$this->assertWPError( $result );
		$this->assertDirectoryDoesNotExist( $this->manager()->resolve_bundle_root( 'symlink-import', true ) );

		unlink( $zip_path );
	}

	/**
	 * Test that an archive with no concepts is rejected and cleaned up.
	 */
	public function test_import_rejects_no_concepts() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension is not available.' );
		}

		$zip_path = sys_get_temp_dir() . '/okf-empty-' . uniqid() . '.zip';

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, ZipArchive::CREATE ) );
		$zip->addFromString( 'notes.txt', 'not a concept' );
		$zip->close();

		$result = $this->manager()->import_bundle_zip( $zip_path, 'empty-import' );

		$this->assertWPError( $result );
		$this->assertSame( 'okf_zip_no_concepts', $result->get_error_code() );
		$this->assertDirectoryDoesNotExist( $this->manager()->resolve_bundle_root( 'empty-import', true ) );

		unlink( $zip_path );
	}

	/**
	 * Test that the write tool refuses to touch the protected bundle.
	 */
	public function test_write_tool_blocks_protected_bundle() {
		wp_set_current_user( 1 ); // Administrator: has edit_posts.

		$tool   = new WP_MCP_AI_Tool_OKF_Write_Concept();
		$result = $tool->execute(
			array(
				'bundle'     => 'skill-knowledge',
				'concept_id' => 'sneaky',
				'type'       => 'Note',
				'body'       => 'Nope.',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'okf_protected_bundle', $result->get_error_code() );
	}

	/**
	 * Test that the delete tool refuses to touch the protected bundle.
	 */
	public function test_delete_tool_blocks_protected_bundle() {
		wp_set_current_user( 1 ); // Administrator: has delete_posts.

		$root = $this->manager()->get_knowledge_root();
		wp_mkdir_p( $root . '/skill-knowledge' );

		$tool   = new WP_MCP_AI_Tool_OKF_Delete_Concept();
		$result = $tool->execute(
			array(
				'bundle'     => 'skill-knowledge',
				'concept_id' => 'sneaky',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'okf_protected_bundle', $result->get_error_code() );
	}

	/**
	 * Test that append_log() writes into the bundle's log.md.
	 */
	public function test_append_log() {
		$manager = $this->manager();
		$manager->create_bundle( 'logged-bundle' );

		$result = $manager->append_log( 'logged-bundle', '', 'Something happened.', 'Update' );

		$this->assertNotWPError( $result );

		$log_content = file_get_contents( $manager->resolve_bundle_root( 'logged-bundle' ) . '/log.md' );
		$this->assertNotFalse( $log_content );
		$this->assertStringContainsString( '**Update**: Something happened.', $log_content );
	}

	/**
	 * Test that the knowledge-root filter routes the generator's bundle path.
	 */
	public function test_generator_respects_knowledge_root_filter() {
		$custom_root = sys_get_temp_dir() . '/wp-mcp-ai-test-okf-custom-' . uniqid();

		add_filter(
			'wp_mcp_ai_okf_knowledge_root',
			static function () use ( $custom_root ) {
				return $custom_root;
			}
		);

		$bundle_root = WP_MCP_AI_OKF_Skill_Knowledge_Generator::get_bundle_root();

		remove_all_filters( 'wp_mcp_ai_okf_knowledge_root' );

		$this->assertSame( wp_normalize_path( $custom_root . '/skill-knowledge' ), $bundle_root );

		$this->recursive_rmdir( $custom_root );
	}

	/**
	 * Test that bundle_stats() returns the documented descriptor shape.
	 */
	public function test_bundle_stats_shape() {
		$manager = $this->manager();
		$manager->create_bundle( 'stats-bundle' );

		$writer = new WP_MCP_AI_OKF_Writer( $manager->resolve_bundle_root( 'stats-bundle' ) );
		$writer->write_concept(
			'metric',
			array(
				'type'        => 'Metric',
				'verified'    => array( array( 'by' => 'human:admin', 'at' => gmdate( 'c' ) ) ),
				'stale_after' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
			),
			'Body.'
		);

		$stats = $manager->bundle_stats( 'stats-bundle' );

		$this->assertNotWPError( $stats );
		$this->assertSame( 1, $stats['concept_count'] );
		$this->assertSame( 1, $stats['stale_count'] );
		$this->assertSame( 1, $stats['trust_tiers']['human-reviewed'] );
		$this->assertContains( 'Metric', $stats['types'] );
		$this->assertSame( 0, $stats['broken_link_count'] );
	}

	/**
	 * Test that validate_bundle() reports broken cross-links without
	 * affecting conformance (OKF v0.2 §6.1).
	 */
	public function test_validate_reports_broken_links() {
		$manager = $this->manager();
		$manager->create_bundle( 'linked-bundle' );

		$root = $manager->resolve_bundle_root( 'linked-bundle' );

		$writer = new WP_MCP_AI_OKF_Writer( $root );
		$writer->write_concept( 'b', array( 'type' => 'Note' ), 'Body.' );
		$writer->write_concept(
			'a',
			array( 'type' => 'Note' ),
			'See [b](b.md), [missing](missing.md), [deep](/absent/deep.md), and [external](https://example.com/doc.md).'
		);

		$report = $writer->validate_bundle();

		// Structural issues only — broken links must not affect conformance.
		$this->assertTrue( $report['conformant'] );
		$this->assertCount( 2, $report['broken_links'] );

		$targets = wp_list_pluck( $report['broken_links'], 'target' );
		$this->assertContains( 'missing.md', $targets );
		$this->assertContains( '/absent/deep.md', $targets );
		$this->assertNotContains( 'b.md', $targets );
		$this->assertNotContains( 'https://example.com/doc.md', $targets );
	}

	/**
	 * Test that import stamps okf_version onto an existing index.md without
	 * frontmatter (OKF v0.2 §12).
	 */
	public function test_import_stamps_okf_version_when_absent() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension is not available.' );
		}

		$zip_path = sys_get_temp_dir() . '/okf-stamp-' . uniqid() . '.zip';

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, ZipArchive::CREATE ) );
		$zip->addFromString( 'index.md', "# My Bundle\n\n* [A](a.md) - A concept\n" );
		$zip->addFromString( 'a.md', "---\ntype: Note\n---\n\nBody.\n" );
		$zip->close();

		$imported = $this->manager()->import_bundle_zip( $zip_path, 'stamped-bundle' );
		unlink( $zip_path );

		$this->assertNotWPError( $imported );

		$index_content = file_get_contents( $imported['path'] . '/index.md' );
		$this->assertNotFalse( $index_content );
		$this->assertStringContainsString( 'okf_version: "0.2"', $index_content );
		$this->assertStringContainsString( '* [A](a.md)', $index_content ); // Entries preserved.
	}

	/**
	 * Test that import generates a missing root index.md (stamped).
	 */
	public function test_import_generates_missing_index() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension is not available.' );
		}

		$zip_path = sys_get_temp_dir() . '/okf-noindex-' . uniqid() . '.zip';

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, ZipArchive::CREATE ) );
		$zip->addFromString( 'a.md', "---\ntype: Note\n---\n\nBody.\n" );
		$zip->close();

		$imported = $this->manager()->import_bundle_zip( $zip_path, 'noindex-bundle' );
		unlink( $zip_path );

		$this->assertNotWPError( $imported );
		$this->assertFileExists( $imported['path'] . '/index.md' );

		$index_content = file_get_contents( $imported['path'] . '/index.md' );
		$this->assertNotFalse( $index_content );
		$this->assertStringContainsString( 'okf_version: "0.2"', $index_content );
	}
}
