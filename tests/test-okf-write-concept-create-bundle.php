<?php
/**
 * Tests for the okf_write_concept tool's bundle-creation behavior.
 *
 * Covers the fix that lets the write tool create a missing OKF bundle on
 * first write (strict bundle-name validation, root index generation, and
 * the bundle-initialized event), instead of failing with
 * `okf_bundle_not_found`.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Tool_OKF_Write_Concept_Test extends WP_UnitTestCase {

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

		// Use a temp dir for the uploads target to avoid touching the real
		// WordPress uploads directory.
		$this->test_uploads_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-okf-write-' . uniqid();
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
	 * Build the absolute knowledge root used by the tool.
	 *
	 * @return string Absolute path to the knowledge root.
	 */
	private function get_knowledge_root() {
		return $this->test_uploads_dir . '/mcp-ai-wpoos/knowledge';
	}

	/**
	 * Test that writing a concept into a missing bundle creates it.
	 */
	public function test_write_creates_missing_bundle() {
		wp_set_current_user( 1 ); // Administrator: has edit_posts.

		$bundle_dir = $this->get_knowledge_root() . '/marketing-playbooks';
		$this->assertDirectoryDoesNotExist( $bundle_dir );

		$baseline_fires = did_action( 'wp_mcp_ai_okf_bundle_initialized' );

		$tool   = new WP_MCP_AI_Tool_OKF_Write_Concept();
		$result = $tool->execute(
			array(
				'bundle'     => 'marketing-playbooks',
				'concept_id' => 'playbooks/spring-launch',
				'type'       => 'Playbook',
				'title'      => 'Spring Launch',
				'body'       => '# Steps' . "\n\n" . '1. Prepare assets.',
			),
			array()
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['bundle_created'] );
		$this->assertTrue( $result['index_regenerated'] );

		$this->assertDirectoryExists( $bundle_dir );
		$this->assertFileExists( $bundle_dir . '/playbooks/spring-launch.md' );
		$this->assertFileExists( $bundle_dir . '/index.md' );

		// The documented bundle-initialized event must fire exactly once for
		// the new bundle.
		$this->assertSame( $baseline_fires + 1, did_action( 'wp_mcp_ai_okf_bundle_initialized' ) );

		// The concept must be parseable by the reader.
		$reader  = new WP_MCP_AI_OKF_Reader( $bundle_dir );
		$concept = $reader->get_concept( 'playbooks/spring-launch' );
		$this->assertNotWPError( $concept );
		$this->assertSame( 'Playbook', $concept['frontmatter']['type'] );

		// The generated root index must list the new subdirectory entry.
		$index_content = file_get_contents( $bundle_dir . '/index.md' );
		$this->assertNotFalse( $index_content );
		$this->assertStringContainsString( 'playbooks', $index_content );
	}

	/**
	 * Test that a second write into the same bundle does not recreate it.
	 */
	public function test_second_write_does_not_recreate_bundle() {
		wp_set_current_user( 1 ); // Administrator: has edit_posts.

		$tool = new WP_MCP_AI_Tool_OKF_Write_Concept();

		$first = $tool->execute(
			array(
				'bundle'     => 'marketing-playbooks',
				'concept_id' => 'playbooks/one',
				'type'       => 'Playbook',
				'body'       => 'One.',
			),
			array()
		);
		$this->assertNotWPError( $first );
		$this->assertTrue( $first['bundle_created'] );
		$this->assertTrue( $first['index_regenerated'] );

		$second = $tool->execute(
			array(
				'bundle'     => 'marketing-playbooks',
				'concept_id' => 'playbooks/two',
				'type'       => 'Playbook',
				'body'       => 'Two.',
			),
			array()
		);
		$this->assertNotWPError( $second );
		$this->assertFalse( $second['bundle_created'] );
		$this->assertFalse( $second['index_regenerated'] );

		$this->assertFileExists( $this->get_knowledge_root() . '/marketing-playbooks/playbooks/two.md' );
	}

	/**
	 * Test that invalid bundle names are rejected and never touch disk.
	 *
	 * @param string $bundle Invalid bundle name.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_invalid_bundle_names' )]
	public function test_invalid_bundle_names_rejected( $bundle ) {
		wp_set_current_user( 1 ); // Administrator: has edit_posts.

		$tool   = new WP_MCP_AI_Tool_OKF_Write_Concept();
		$result = $tool->execute(
			array(
				'bundle'     => $bundle,
				'concept_id' => 'hello',
				'type'       => 'Note',
				'body'       => 'Hello.',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'okf_invalid_bundle', $result->get_error_code() );

		// Nothing may be created for an invalid name: the knowledge root must
		// contain no bundle directories (only the .htaccess/index.php guards
		// and dotfiles).
		$knowledge_root = $this->get_knowledge_root();
		$entries        = scandir( $knowledge_root );
		$created        = array();
		foreach ( $entries as $entry ) {
			if ( '.' === $entry[0] ) {
				continue;
			}
			if ( is_dir( $knowledge_root . '/' . $entry ) ) {
				$created[] = $entry;
			}
		}
		$this->assertSame( array(), $created );
	}

	/**
	 * Data provider: bundle names that must be rejected.
	 *
	 * @return array<int, array<int, string>>
	 */
	public static function provide_invalid_bundle_names() {
		return array(
			array( '../evil' ),
			array( '..' ),
			array( 'bad name' ),
			array( 'UPPERCASE' ),
			array( 'trailing.' ),
			array( '/absolute' ),
			array( '-leading-dash' ),
		);
	}

	/**
	 * Test that users without edit_posts cannot create bundles.
	 */
	public function test_missing_capability_rejected() {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$tool   = new WP_MCP_AI_Tool_OKF_Write_Concept();
		$result = $tool->execute(
			array(
				'bundle'     => 'forbidden-bundle',
				'concept_id' => 'hello',
				'type'       => 'Note',
				'body'       => 'Hello.',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );

		$this->assertDirectoryDoesNotExist( $this->get_knowledge_root() . '/forbidden-bundle' );
	}
}
