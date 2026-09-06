<?php
/**
 * OKF bundle manager/generator/bootstrap port tests (Wave E6, sub-cluster 4).
 *
 * Characterization suite for the ported `NvoosContentGraphAi\Engine\Okf`
 * bundle manager (knowledge-root resolution with the
 * `wp_mcp_ai_okf_knowledge_root` filter + security guards, realpath
 * containment, protected/standard bundle sets, listing/descriptors,
 * create/rename/archive/delete lifecycle, ZIP export/import with the
 * ZipSlip defenses, raw-concept saves, log maintenance), the
 * skill-knowledge generator (per-mode fingerprint, generation flow,
 * the priority-32 hook), and the bootstrap hook surface. Runs in both
 * matrices.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Engine\Okf\OkfBootstrap;
use NvoosContentGraphAi\Engine\Okf\OkfBundleManager;
use NvoosContentGraphAi\Engine\Okf\OkfSkillKnowledgeGenerator;

/**
 * @group okf
 */
class Test_Okf_Bundle_Manager extends \WP_UnitTestCase {

	/**
	 * Temp knowledge root.
	 *
	 * @var string
	 */
	private $knowledge_root = '';

	/**
	 * Manager under test.
	 *
	 * @var OkfBundleManager
	 */
	private $manager;

	public function setUp(): void {
		parent::setUp();

		$this->knowledge_root = \sys_get_temp_dir() . '/nvoos-cg-okf-root-' . \wp_rand( 100000, 999999 );
		if ( ! \is_dir( $this->knowledge_root ) ) {
			\mkdir( $this->knowledge_root, 0777, true );
		}

		\add_filter(
			'wp_mcp_ai_okf_knowledge_root',
			function () {
				return $this->knowledge_root;
			},
			999
		);

		$this->manager = new OkfBundleManager();

		\delete_option( OkfSkillKnowledgeGenerator::GENERATED_OPTION );

		// The ecosystem composition roots never run in the test process;
		// reset the bootstrap's static gate so each test can wire it
		// independently (standalone only — the base init owns the same
		// hooks monolith).
		$ref = new \ReflectionProperty( OkfBootstrap::class, 'registered' );
		$ref->setAccessible( true );
		$ref->setValue( null, false );

		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			OkfBootstrap::register();
		}
	}

	public function tearDown(): void {
		\remove_all_filters( 'wp_mcp_ai_okf_knowledge_root', 999 );
		\delete_option( OkfSkillKnowledgeGenerator::GENERATED_OPTION );

		if ( '' !== $this->knowledge_root && \is_dir( $this->knowledge_root ) ) {
			$this->delete_directory( $this->knowledge_root );
		}

		parent::tearDown();
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function delete_directory( string $dir ): void {
		if ( ! \is_dir( $dir ) ) {
			return;
		}
		$items = \scandir( $dir );
		if ( ! \is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( \is_dir( $path ) ) {
				$this->delete_directory( $path );
			} else {
				\unlink( $path );
			}
		}
		\rmdir( $dir );
	}

	/**
	 * Create a concept file inside a bundle dir.
	 *
	 * @param string $bundle_path Absolute bundle path.
	 * @param string $concept_id  Concept ID.
	 * @return void
	 */
	private function seed_concept( string $bundle_path, string $concept_id ): void {
		$file = \trailingslashit( $bundle_path ) . $concept_id . '.md';
		$dir  = \dirname( $file );
		if ( ! \is_dir( $dir ) ) {
			\mkdir( $dir, 0777, true );
		}
		\file_put_contents( $file, "---\ntype: concept\ntitle: " . \ucfirst( $concept_id ) . "\n---\n\nBody\n" );
	}

	// ─── Knowledge root + resolution ──────────────────────────────

	public function test_knowledge_root_creates_dir_and_security_guards(): void {
		$root = $this->manager->get_knowledge_root();

		$this->assertSame( $this->knowledge_root, $root );
		$this->assertFileExists( \trailingslashit( $root ) . '.htaccess' );
		$this->assertFileExists( \trailingslashit( $root ) . 'index.php' );
		$this->assertSame( "Deny from all\n", \file_get_contents( \trailingslashit( $root ) . '.htaccess' ) );
	}

	public function test_resolve_bundle_root_validation(): void {
		$this->assertWPError( $this->manager->resolve_bundle_root( '' ) );
		$this->assertSame( 'okf_invalid_bundle', $this->manager->resolve_bundle_root( '' )->get_error_code() );

		$bad = $this->manager->resolve_bundle_root( 'Bad Name!' );
		$this->assertWPError( $bad );
		$this->assertSame( 'okf_invalid_bundle', $bad->get_error_code() );

		$missing = $this->manager->resolve_bundle_root( 'missing-bundle' );
		$this->assertWPError( $missing );
		$this->assertSame( 'okf_bundle_not_found', $missing->get_error_code() );

		// create=true returns the (normalized) path for a valid slug.
		$path = $this->manager->resolve_bundle_root( 'new-bundle', true );
		$this->assertSame( \wp_normalize_path( \trailingslashit( $this->knowledge_root ) . 'new-bundle' ), $path );
	}

	public function test_resolve_bundle_root_reads_legacy_names(): void {
		$legacy = \trailingslashit( $this->knowledge_root ) . 'Legacy Name';
		\wp_mkdir_p( $legacy );

		$this->assertSame( \wp_normalize_path( $legacy ), $this->manager->resolve_bundle_root( 'Legacy Name' ) );
	}

	public function test_protected_and_standard_bundles(): void {
		$this->assertTrue( $this->manager->is_protected_bundle( 'skill-knowledge' ) );
		$this->assertFalse( $this->manager->is_protected_bundle( 'site-knowledge' ) );
		$this->assertTrue( $this->manager->is_standard_bundle( 'site-knowledge' ) );
		$this->assertTrue( $this->manager->is_standard_bundle( 'external-bundles' ) );

		$writable = $this->manager->assert_bundle_writable( 'skill-knowledge' );
		$this->assertWPError( $writable );
		$this->assertSame( 'okf_protected_bundle', $writable->get_error_code() );
		$this->assertTrue( $this->manager->assert_bundle_writable( 'site-knowledge' ) );
	}

	public function test_list_bundles_skips_dotdirs_and_sorts(): void {
		$created = $this->manager->create_bundle( 'zebra' );
		$this->assertIsArray( $created );
		$this->manager->create_bundle( 'alpha' );
		\wp_mkdir_p( \trailingslashit( $this->knowledge_root ) . '.hidden' );

		$bundles = $this->manager->list_bundles();
		$names   = \wp_list_pluck( $bundles, 'name' );

		$this->assertSame( array( 'alpha', 'zebra' ), $names );
		$descriptor = $bundles[0];
		$this->assertArrayHasKey( 'protected', $descriptor );
		$this->assertArrayHasKey( 'concept_count', $descriptor );
		$this->assertArrayHasKey( 'conformant', $descriptor );
		$this->assertArrayHasKey( 'trust_tiers', $descriptor );
	}

	public function test_bundle_stats(): void {
		$this->manager->create_bundle( 'stats-bundle' );
		$this->seed_concept( \trailingslashit( $this->knowledge_root ) . 'stats-bundle', 'one' );

		$stats = $this->manager->bundle_stats( 'stats-bundle' );

		$this->assertSame( 'stats-bundle', $stats['name'] );
		$this->assertSame( 1, $stats['concept_count'] );
		$this->assertTrue( $stats['conformant'] );
	}

	// ─── Lifecycle ────────────────────────────────────────────────

	public function test_create_bundle_stamps_version_and_log(): void {
		$created = $this->manager->create_bundle( 'fresh' );

		$this->assertSame( 'fresh', $created['bundle'] );
		$index = \file_get_contents( $created['path'] . '/index.md' );
		$this->assertStringContainsString( 'okf_version: "0.2"', $index );
		$this->assertFileExists( $created['path'] . '/log.md' );
	}

	public function test_create_bundle_protected_and_existing_errors(): void {
		$protected = $this->manager->create_bundle( 'skill-knowledge' );
		$this->assertWPError( $protected );
		$this->assertSame( 'okf_protected_bundle', $protected->get_error_code() );

		$this->manager->create_bundle( 'dup' );
		$dup = $this->manager->create_bundle( 'dup' );
		$this->assertWPError( $dup );
		$this->assertSame( 'okf_bundle_exists', $dup->get_error_code() );
	}

	public function test_rename_bundle(): void {
		$this->manager->create_bundle( 'before' );

		$renamed = $this->manager->rename_bundle( 'before', 'after' );
		$this->assertSame( 'after', $renamed['bundle'] );
		$this->assertDirectoryExists( $renamed['path'] );

		$standard = $this->manager->rename_bundle( 'site-knowledge', 'other' );
		$this->assertWPError( $standard );
		$this->assertSame( 'okf_protected_bundle', $standard->get_error_code() );
	}

	public function test_archive_bundle_moves_to_trash(): void {
		$this->manager->create_bundle( 'archived-soon' );

		$archived = $this->manager->archive_bundle( 'archived-soon' );

		$this->assertSame( 'archived-soon', $archived['bundle'] );
		$this->assertStringContainsString( '.trash', $archived['trash_path'] );
		$this->assertDirectoryExists( $archived['trash_path'] );

		$protected = $this->manager->archive_bundle( 'skill-knowledge' );
		$this->assertWPError( $protected );
		$this->assertSame( 'okf_protected_bundle', $protected->get_error_code() );
	}

	public function test_delete_bundle_removes_tree(): void {
		$this->manager->create_bundle( 'doomed' );
		$path = \trailingslashit( $this->knowledge_root ) . 'doomed';
		$this->seed_concept( $path, 'inner' );

		$deleted = $this->manager->delete_bundle( 'doomed' );

		$this->assertSame(
			array(
				'bundle'  => 'doomed',
				'deleted' => true,
			),
			$deleted
		);
		$this->assertDirectoryDoesNotExist( $path );
	}

	// ─── ZIP export/import ────────────────────────────────────────

	public function test_zip_export_import_round_trip(): void {
		$this->manager->create_bundle( 'zip-source' );
		$this->seed_concept( \trailingslashit( $this->knowledge_root ) . 'zip-source', 'one' );
		$this->seed_concept( \trailingslashit( $this->knowledge_root ) . 'zip-source', 'sub/two' );

		$exported = $this->manager->export_bundle_zip( 'zip-source' );
		$this->assertIsArray( $exported );
		$this->assertFileExists( $exported['path'] );
		$this->assertGreaterThanOrEqual( 2, $exported['entries'] );

		$imported = $this->manager->import_bundle_zip( $exported['path'], 'zip-target' );
		$this->assertSame( 'zip-target', $imported['bundle'] );
		$this->assertSame( 2, $imported['concepts'] );
		$this->assertTrue( $imported['conformant'] );

		$stats = $this->manager->bundle_stats( 'zip-target' );
		$this->assertSame( 2, $stats['concept_count'] );
	}

	public function test_zip_import_rejects_unsafe_entry(): void {
		$zip_path = \trailingslashit( $this->knowledge_root ) . 'evil.zip';
		$zip      = new \ZipArchive();
		$zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$zip->addFromString( '../evil.md', 'content' );
		$zip->close();

		$result = $this->manager->import_bundle_zip( $zip_path, 'target' );
		$this->assertWPError( $result );
		$this->assertSame( 'okf_zip_unsafe_entry', $result->get_error_code() );
	}

	public function test_zip_import_rejects_archive_without_concepts(): void {
		$zip_path = \trailingslashit( $this->knowledge_root ) . 'empty.zip';
		$zip      = new \ZipArchive();
		$zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$zip->addFromString( 'index.md', "# No concepts\n" );
		$zip->close();

		$result = $this->manager->import_bundle_zip( $zip_path, 'target' );
		$this->assertWPError( $result );
		$this->assertSame( 'okf_zip_no_concepts', $result->get_error_code() );
		$this->assertDirectoryDoesNotExist( \trailingslashit( $this->knowledge_root ) . 'target' );
	}

	public function test_zip_import_protected_and_existing_errors(): void {
		$protected = $this->manager->import_bundle_zip( '/tmp/nonexistent.zip', 'skill-knowledge' );
		$this->assertWPError( $protected );
		$this->assertSame( 'okf_protected_bundle', $protected->get_error_code() );

		$this->manager->create_bundle( 'already-there' );
		$existing = $this->manager->import_bundle_zip( '/tmp/nonexistent.zip', 'already-there' );
		$this->assertWPError( $existing );
		$this->assertSame( 'okf_bundle_exists', $existing->get_error_code() );
	}

	// ─── Raw concept saves + logs ─────────────────────────────────

	public function test_save_concept_raw_round_trip(): void {
		$this->manager->create_bundle( 'editor-bundle' );

		$content = "---\ntype: concept\ntitle: Edited\n---\n\nBody\n";
		$result  = $this->manager->save_concept_raw( 'editor-bundle', 'edited', $content );

		$this->assertTrue( $result );
		$this->assertStringContainsString( 'Edited', \file_get_contents( \trailingslashit( $this->knowledge_root ) . 'editor-bundle/edited.md' ) );
		$this->assertStringContainsString( 'edited', \file_get_contents( \trailingslashit( $this->knowledge_root ) . 'editor-bundle/log.md' ) );
	}

	public function test_save_concept_raw_validation_errors(): void {
		$this->manager->create_bundle( 'guard-bundle' );

		$protected = $this->manager->save_concept_raw( 'skill-knowledge', 'x', 'content' );
		$this->assertWPError( $protected );
		$this->assertSame( 'okf_protected_bundle', $protected->get_error_code() );

		$invalid = $this->manager->save_concept_raw( 'guard-bundle', '../evil', 'content' );
		$this->assertWPError( $invalid );
		$this->assertSame( 'okf_invalid_concept', $invalid->get_error_code() );

		$reserved = $this->manager->save_concept_raw( 'guard-bundle', 'index', "---\ntype: x\n---\n" );
		$this->assertWPError( $reserved );
		$this->assertSame( 'okf_reserved_filename', $reserved->get_error_code() );

		$missing_type = $this->manager->save_concept_raw( 'guard-bundle', 'ok', 'no frontmatter' );
		$this->assertWPError( $missing_type );
		$this->assertSame( 'okf_missing_type', $missing_type->get_error_code() );
	}

	public function test_manager_append_log(): void {
		$this->manager->create_bundle( 'log-bundle' );

		$this->assertTrue( $this->manager->append_log( 'log-bundle', '', 'Hello.', 'Creation' ) );
		$this->assertStringContainsString( '**Creation**: Hello.', \file_get_contents( \trailingslashit( $this->knowledge_root ) . 'log-bundle/log.md' ) );
	}

	// ─── Generator ────────────────────────────────────────────────

	public function test_generator_fingerprint_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( WP_MCP_AI_VERSION, OkfSkillKnowledgeGenerator::get_fingerprint() );
		} else {
			$this->assertSame( NVOOS_CONTENT_GRAPH_AI_VERSION, OkfSkillKnowledgeGenerator::get_fingerprint() );
		}
	}

	public function test_generator_generate_resolves_per_install_mode(): void {
		$summary = OkfSkillKnowledgeGenerator::generate();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base plugin's 74 bundled skills are copied into
			// the bundle and the fingerprint option is persisted.
			$this->assertTrue( $summary['generated'] );
			$this->assertGreaterThan( 0, $summary['concepts'] );
			$this->assertSame( array(), $summary['errors'] );
			$this->assertSame( WP_MCP_AI_VERSION, \get_option( OkfSkillKnowledgeGenerator::GENERATED_OPTION ) );
			$this->assertFileExists( \trailingslashit( $this->knowledge_root ) . 'skill-knowledge/index.md' );
		} else {
			// Standalone: no bundled skills shipped with the addon — the
			// byte-identical degradation error, and the option stays unset.
			$this->assertFalse( $summary['generated'] );
			$this->assertSame( 0, $summary['concepts'] );
			$this->assertNotEmpty( $summary['errors'] );
			$this->assertFalse( \get_option( OkfSkillKnowledgeGenerator::GENERATED_OPTION ) );
		}
	}

	public function test_generator_maybe_generate_skips_when_fingerprint_current(): void {
		\update_option( OkfSkillKnowledgeGenerator::GENERATED_OPTION, OkfSkillKnowledgeGenerator::get_fingerprint() );

		OkfSkillKnowledgeGenerator::maybe_generate();

		// Nothing generated — the summary path was short-circuited.
		$this->assertDirectoryDoesNotExist( \trailingslashit( $this->knowledge_root ) . 'skill-knowledge' );
	}

	public function test_generator_init_hooks_at_priority_32(): void {
		global $wp_filter;

		OkfSkillKnowledgeGenerator::init();
		OkfSkillKnowledgeGenerator::init(); // Guarded — no double hook.

		$count = 0;
		foreach ( $wp_filter['wp_mcp_ai_bootstrapped']->callbacks[32] as $cb ) {
			if ( isset( $cb['function'] ) && \is_array( $cb['function'] )
				&& OkfSkillKnowledgeGenerator::class === $cb['function'][0]
				&& 'maybe_generate' === $cb['function'][1] ) {
				++$count;
			}
		}
		$this->assertSame( 1, $count );
	}

	// ─── Bootstrap ────────────────────────────────────────────────

	public function test_bootstrap_register_is_idempotent(): void {
		OkfBootstrap::register();
		OkfBootstrap::register();

		$this->assertSame( 1, $this->count_closure_hooks( 'wp_mcp_ai_bootstrapped', 'OkfBootstrap.php' ) );
	}

	public function test_bootstrap_hooks_tool_registration_at_priority_32(): void {
		global $wp_filter;

		OkfBootstrap::register();

		$this->assertArrayHasKey( 32, $wp_filter['wp_mcp_ai_bootstrapped']->callbacks );

		$listener_count = 0;
		foreach ( $wp_filter['wp_mcp_ai_bootstrapped']->callbacks[32] as $cb ) {
			if ( $cb['function'] instanceof \Closure && false !== \strpos( (string) ( new \ReflectionFunction( $cb['function'] ) )->getFileName(), 'OkfBootstrap.php' ) ) {
				++$listener_count;
			}
		}
		$this->assertSame( 1, $listener_count );
	}

	public function test_bootstrap_register_tools_is_mode_resolved_noop(): void {
		OkfBootstrap::register_tools();
		$this->addToAssertionCount( 1 );
	}

	public function test_bootstrapped_fire_is_safe_and_coexists_with_base(): void {
		OkfBootstrap::register();
		\do_action( 'wp_mcp_ai_bootstrapped' );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base loader owns the ten registrations.
			$registry = \WP_MCP_AI_Tool_Registry::get_instance();
			$this->assertTrue( $registry->is_tool_registered( 'okf_read_concept' ) );
			$this->assertTrue( $registry->is_tool_registered( 'okf_import_bundle' ) );
		}

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Count closures on a hook whose defining file matches a suffix.
	 *
	 * @param string $tag      Hook tag.
	 * @param string $filename File-name fragment to match.
	 * @return int
	 */
	private function count_closure_hooks( string $tag, string $filename ): int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $tag ] ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter[ $tag ]->callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $cb ) {
				if ( ! isset( $cb['function'] ) || ! ( $cb['function'] instanceof \Closure ) ) {
					continue;
				}
				$file = ( new \ReflectionFunction( $cb['function'] ) )->getFileName();
				if ( false !== $file && false !== \strpos( $file, $filename ) ) {
					++$count;
				}
			}
		}
		return $count;
	}
}
