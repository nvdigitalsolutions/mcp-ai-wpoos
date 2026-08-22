<?php
/**
 * AJAX tests for the OKF Bundle Manager admin handlers (Base).
 *
 * Covers the 4-point coverage contract (nonce, capability, validation,
 * happy path) for:
 *   - wp_mcp_ai_okf_bundle_create        (ajax_create_bundle)
 *   - wp_mcp_ai_okf_bundle_archive       (ajax_archive_bundle)
 *   - wp_mcp_ai_okf_bundle_import        (ajax_import_bundle)
 *   - wp_mcp_ai_okf_bundle_save_concept  (ajax_save_concept)
 *   - wp_mcp_ai_okf_bundle_delete_concept (ajax_delete_concept)
 *
 * All handlers require `manage_options` and a valid nonce. Note: this suite
 * uses the WP_Ajax_UnitTestCase dispatch harness, which runs in CI (Linux);
 * the underlying handler logic is independently covered by
 * tests/test-okf-bundle-manager.php so local environments without a working
 * AJAX harness still exercise every code path.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * AJAX cluster: OKF Bundle Manager admin page.
 */
class Test_OKF_Bundle_Manager_Admin_AJAX extends WP_MCP_AI_Ajax_TestCase {

	const NONCE = 'wp_mcp_ai_okf_bundle_manager';

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

		$this->test_uploads_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-okf-admin-ajax-' . uniqid();
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
	 * Get a fresh bundle manager instance.
	 *
	 * @return WP_MCP_AI_OKF_Bundle_Manager
	 */
	private function manager() {
		return new WP_MCP_AI_OKF_Bundle_Manager();
	}

	/**
	 * Get a fresh admin page instance (registers the AJAX hooks).
	 *
	 * @return WP_MCP_AI_OKF_Bundle_Manager_Admin_Page
	 */
	private function page() {
		return new WP_MCP_AI_OKF_Bundle_Manager_Admin_Page();
	}

	// ---
	// wp_mcp_ai_okf_bundle_create
	// ---

	/** Missing nonce. */
	public function test_create_bundle_rejects_missing_nonce() {
		$this->as_admin();
		$this->page();

		$response = $this->dispatch( 'wp_mcp_ai_okf_bundle_create', array( 'bundle' => 'new-bundle' ) );

		$this->assertAjaxForbidden( $response );
	}

	/** Insufficient capabilities. */
	public function test_create_bundle_rejects_subscriber() {
		$this->as_subscriber();
		$this->page();

		$response = $this->dispatch(
			'wp_mcp_ai_okf_bundle_create',
			array(
				'nonce'  => wp_create_nonce( self::NONCE ),
				'bundle' => 'new-bundle',
			)
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Happy path. */
	public function test_create_bundle_happy_path() {
		$this->as_admin();
		$this->page();

		$response = $this->dispatch(
			'wp_mcp_ai_okf_bundle_create',
			array(
				'nonce'  => wp_create_nonce( self::NONCE ),
				'bundle' => 'new-bundle',
			)
		);

		$this->assertAjaxSuccess( $response );
		$this->assertDirectoryExists( $this->manager()->resolve_bundle_root( 'new-bundle' ) );
	}

	/** Invalid names are rejected. */
	public function test_create_bundle_rejects_invalid_name() {
		$this->as_admin();
		$this->page();

		$response = $this->dispatch(
			'wp_mcp_ai_okf_bundle_create',
			array(
				'nonce'  => wp_create_nonce( self::NONCE ),
				'bundle' => 'Bad Name!',
			)
		);

		$this->assertAjaxError( $response, 'Invalid bundle name' );
	}

	/** The auto-generated bundle cannot be created manually. */
	public function test_create_bundle_rejects_protected_name() {
		$this->as_admin();
		$this->page();

		$response = $this->dispatch(
			'wp_mcp_ai_okf_bundle_create',
			array(
				'nonce'  => wp_create_nonce( self::NONCE ),
				'bundle' => 'skill-knowledge',
			)
		);

		$this->assertAjaxError( $response, 'auto-generated' );
	}

	// ---
	// wp_mcp_ai_okf_bundle_archive
	// ---

	/** Missing nonce. */
	public function test_archive_bundle_rejects_missing_nonce() {
		$this->as_admin();
		$this->page();
		$this->manager()->create_bundle( 'doomed' );

		$response = $this->dispatch( 'wp_mcp_ai_okf_bundle_archive', array( 'bundle' => 'doomed' ) );

		$this->assertAjaxForbidden( $response );
	}

	/** Happy path. */
	public function test_archive_bundle_happy_path() {
		$this->as_admin();
		$this->page();
		$this->manager()->create_bundle( 'doomed' );

		$response = $this->dispatch(
			'wp_mcp_ai_okf_bundle_archive',
			array(
				'nonce'  => wp_create_nonce( self::NONCE ),
				'bundle' => 'doomed',
			)
		);

		$this->assertAjaxSuccess( $response );
		$this->assertDirectoryDoesNotExist( $this->manager()->resolve_bundle_root( 'doomed', true ) );
	}

	// ---
	// wp_mcp_ai_okf_bundle_save_concept
	// ---

	/** Missing nonce. */
	public function test_save_concept_rejects_missing_nonce() {
		$this->as_admin();
		$this->page();
		$this->manager()->create_bundle( 'editable' );

		$response = $this->dispatch(
			'wp_mcp_ai_okf_bundle_save_concept',
			array(
				'bundle'     => 'editable',
				'concept_id' => 'hello',
				'content'    => "---\ntype: Note\n---\n\nBody.\n",
			)
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Happy path: content round-trips to disk. */
	public function test_save_concept_happy_path() {
		$this->as_admin();
		$this->page();
		$this->manager()->create_bundle( 'editable' );

		$content = "---\ntype: Policy\ntitle: Refunds\n---\n\n# Policy\n\nRefunds within 30 days.\n";

		$response = $this->dispatch(
			'wp_mcp_ai_okf_bundle_save_concept',
			array(
				'nonce'      => wp_create_nonce( self::NONCE ),
				'bundle'     => 'editable',
				'concept_id' => 'policies/refunds',
				'content'    => $content,
			)
		);

		$this->assertAjaxSuccess( $response );

		$root  = $this->manager()->resolve_bundle_root( 'editable' );
		$saved = file_get_contents( $root . '/policies/refunds.md' );
		$this->assertSame( $content, $saved );
	}

	/** Content without a type is rejected. */
	public function test_save_concept_rejects_missing_type() {
		$this->as_admin();
		$this->page();
		$this->manager()->create_bundle( 'editable' );

		$response = $this->dispatch(
			'wp_mcp_ai_okf_bundle_save_concept',
			array(
				'nonce'      => wp_create_nonce( self::NONCE ),
				'bundle'     => 'editable',
				'concept_id' => 'hello',
				'content'    => 'No frontmatter at all.',
			)
		);

		$this->assertAjaxError( $response, 'type' );
	}

	/** Reserved filenames cannot be saved. */
	public function test_save_concept_rejects_reserved_filename() {
		$this->as_admin();
		$this->page();
		$this->manager()->create_bundle( 'editable' );

		$response = $this->dispatch(
			'wp_mcp_ai_okf_bundle_save_concept',
			array(
				'nonce'      => wp_create_nonce( self::NONCE ),
				'bundle'     => 'editable',
				'concept_id' => 'index',
				'content'    => "---\ntype: Note\n---\n\nBody.\n",
			)
		);

		$this->assertAjaxError( $response, 'reserved' );
	}

	/** The protected bundle cannot be edited. */
	public function test_save_concept_rejects_protected_bundle() {
		$this->as_admin();
		$this->page();

		$root = $this->manager()->get_knowledge_root();
		wp_mkdir_p( $root . '/skill-knowledge' );

		$response = $this->dispatch(
			'wp_mcp_ai_okf_bundle_save_concept',
			array(
				'nonce'      => wp_create_nonce( self::NONCE ),
				'bundle'     => 'skill-knowledge',
				'concept_id' => 'sneaky',
				'content'    => "---\ntype: Note\n---\n\nBody.\n",
			)
		);

		$this->assertAjaxError( $response, 'auto-generated' );
	}

	// ---
	// wp_mcp_ai_okf_bundle_delete_concept
	// ---

	/** Happy path: soft delete renames the file. */
	public function test_delete_concept_happy_path() {
		$this->as_admin();
		$this->page();
		$this->manager()->create_bundle( 'editable' );

		$root   = $this->manager()->resolve_bundle_root( 'editable' );
		$writer = new WP_MCP_AI_OKF_Writer( $root );
		$writer->write_concept( 'hello', array( 'type' => 'Note' ), 'Body.' );

		$response = $this->dispatch(
			'wp_mcp_ai_okf_bundle_delete_concept',
			array(
				'nonce'      => wp_create_nonce( self::NONCE ),
				'bundle'     => 'editable',
				'concept_id' => 'hello',
			)
		);

		$this->assertAjaxSuccess( $response );
		$this->assertFileDoesNotExist( $root . '/hello.md' );

		$deleted = glob( $root . '/hello.md.deleted.*' );
		$this->assertNotEmpty( $deleted, 'The soft-deleted backup was not created.' );
	}

	// ---
	// wp_mcp_ai_okf_bundle_import
	// ---

	/** Happy path: a valid ZIP imports as a new bundle. */
	public function test_import_bundle_happy_path() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension is not available.' );
		}

		$this->as_admin();
		$this->page();

		$zip_path = sys_get_temp_dir() . '/okf-admin-import-' . uniqid() . '.zip';
		$zip      = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, ZipArchive::CREATE ) );
		$zip->addFromString( 'policy.md', "---\ntype: Policy\n---\n\nBody.\n" );
		$zip->close();

		$_FILES['zip_file'] = array(
			'name'     => 'bundle.zip',
			'type'     => 'application/zip',
			'tmp_name' => $zip_path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $zip_path ),
		);

		$response = $this->dispatch(
			'wp_mcp_ai_okf_bundle_import',
			array(
				'nonce'  => wp_create_nonce( self::NONCE ),
				'bundle' => 'imported-bundle',
			)
		);

		$this->assertAjaxSuccess( $response );
		$this->assertDirectoryExists( $this->manager()->resolve_bundle_root( 'imported-bundle' ) );
	}

	/** Missing nonce. */
	public function test_import_bundle_rejects_missing_nonce() {
		$this->as_admin();
		$this->page();

		$response = $this->dispatch( 'wp_mcp_ai_okf_bundle_import', array( 'bundle' => 'imported-bundle' ) );

		$this->assertAjaxForbidden( $response );
	}
}
