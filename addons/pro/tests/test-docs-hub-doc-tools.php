<?php
/**
 * Tests for the Docs Hub content management tools in the Document Generation Toolkit.
 *
 * Covers docs_hub_write_doc, docs_hub_read_doc, docs_hub_list_docs,
 * docs_hub_delete_doc and docs_hub_rebuild, plus the shared helper's path
 * validation.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

/**
 * Test Docs Hub document tools class.
 *
 * @group tools
 * @group pro
 * @group document-generation
 */
class Test_WP_MCP_AI_Docs_Hub_Doc_Tools extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Skip if Pro addon is not loaded.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $this->user_id );

		// Enable the Document Generation Toolkit.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_document_generation_toolkit' => 1,
			)
		);

		$tool_dir = WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/';
		require_once $tool_dir . 'class-wp-mcp-ai-docs-hub-helper.php';
		require_once $tool_dir . 'class-wp-mcp-ai-tool-docs-hub-write-doc.php';
		require_once $tool_dir . 'class-wp-mcp-ai-tool-docs-hub-read-doc.php';
		require_once $tool_dir . 'class-wp-mcp-ai-tool-docs-hub-list-docs.php';
		require_once $tool_dir . 'class-wp-mcp-ai-tool-docs-hub-delete-doc.php';
		require_once $tool_dir . 'class-wp-mcp-ai-tool-docs-hub-rebuild.php';

		$this->cleanup_content_dir();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		$this->cleanup_content_dir();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Recursively remove the fallback Docs Hub content folder used by tests.
	 *
	 * @return void
	 */
	protected function cleanup_content_dir() {
		$info = wp_upload_dir();
		$root = isset( $info['basedir'] ) ? untrailingslashit( (string) $info['basedir'] ) . '/nvoos-docs-hub' : '';

		if ( '' === $root || ! is_dir( $root ) ) {
			return;
		}

		self::rmdir( $root );
	}

	/**
	 * Data provider of traversal / invalid paths that must be rejected.
	 *
	 * @return array
	 */
	public static function provide_invalid_paths() {
		return array(
			'parent traversal'        => array( '../evil.md' ),
			'deep parent traversal'   => array( '../../wp-config.php' ),
			'nested parent traversal' => array( 'guides/../../evil.txt' ),
			'absolute path'           => array( '/etc/passwd' ),
			'windows drive'           => array( 'C:/windows/system32/config.md' ),
			'windows backslash'       => array( '..\\evil.txt' ),
			'dot segment'             => array( './secret.md' ),
			'no extension'            => array( 'readme' ),
			'php extension'           => array( 'evil.php' ),
			'hidden directory'        => array( '.hidden/secret.md' ),
			'empty path'              => array( '' ),
			'null byte'               => array( "ok.md\x00evil.md" ),
		);
	}

	/**
	 * Invalid and traversal paths are rejected by the helper.
	 *
	 * @dataProvider provide_invalid_paths
	 *
	 * @param string $path Raw path.
	 */
	public function test_sanitize_relative_path_rejects_invalid_input( $path ) {
		$this->assertSame( '', WP_MCP_AI_Docs_Hub_Helper::sanitize_relative_path( $path ) );
	}

	/**
	 * Valid relative paths are normalised through the helper.
	 */
	public function test_sanitize_relative_path_accepts_valid_input() {
		$this->assertSame( 'readme.md', WP_MCP_AI_Docs_Hub_Helper::sanitize_relative_path( 'readme.md' ) );
		$this->assertSame( 'guides/start.md', WP_MCP_AI_Docs_Hub_Helper::sanitize_relative_path( ' guides/start.md ' ) );
		$this->assertSame( 'guides/start.md', WP_MCP_AI_Docs_Hub_Helper::sanitize_relative_path( 'guides\\start.md' ) );
		$this->assertSame( 'nested/deep/file.txt', WP_MCP_AI_Docs_Hub_Helper::sanitize_relative_path( 'nested/deep/file.txt' ) );
		$this->assertSame( 'UPPER.MD', WP_MCP_AI_Docs_Hub_Helper::sanitize_relative_path( 'UPPER.MD' ) );
		// Subdirectory listing (no file-extension requirement).
		$this->assertSame( 'guides', WP_MCP_AI_Docs_Hub_Helper::sanitize_relative_path( 'guides', false ) );
	}

	/**
	 * Tool metadata: slugs and names are sane.
	 */
	public function test_tool_metadata() {
		$tools = array(
			'WP_MCP_AI_Tool_Docs_Hub_Write_Doc'  => 'docs_hub_write_doc',
			'WP_MCP_AI_Tool_Docs_Hub_Read_Doc'   => 'docs_hub_read_doc',
			'WP_MCP_AI_Tool_Docs_Hub_List_Docs'  => 'docs_hub_list_docs',
			'WP_MCP_AI_Tool_Docs_Hub_Delete_Doc' => 'docs_hub_delete_doc',
			'WP_MCP_AI_Tool_Docs_Hub_Rebuild'    => 'docs_hub_rebuild',
		);

		foreach ( $tools as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug() );
			$this->assertNotEmpty( $tool->get_name() );
			$this->assertNotEmpty( $tool->get_description() );
			$this->assertSame( 'object', $tool->get_parameters_schema()['type'] );
		}
	}

	/**
	 * The write → read → list → delete round trip works against the fallback
	 * content folder when the Docs Hub plugin is inactive.
	 */
	public function test_write_read_list_delete_round_trip() {
		$write = new WP_MCP_AI_Tool_Docs_Hub_Write_Doc();

		$result = $write->execute(
			array(
				'path'    => 'guides/start.md',
				'content' => '# Start Here' . "\n\n" . 'Welcome to the wiki.',
				'title'   => 'Start Here',
				'order'   => 1,
			),
			array()
		);

		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'created', $result['action'] );
		$this->assertSame( 'guides/start.md', $result['path'] );

		// When the Docs Hub plugin (or its stub) is absent, the write cannot
		// queue a rebuild and reports the plugin as inactive.
		if ( ! class_exists( 'NV_oOS_Docs_Hub_Plugin' ) ) {
			$this->assertFalse( $result['docs_hub_active'] );
			$this->assertSame( 'skipped', $result['rebuild']['status'] );
		}

		// File exists on disk with the expected frontmatter.
		$info = wp_upload_dir();
		$file = untrailingslashit( (string) $info['basedir'] ) . '/nvoos-docs-hub/content/guides/start.md';
		$this->assertFileExists( $file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the local file the tool under test just wrote.
		$raw = file_get_contents( $file );
		$this->assertStringContainsString( "title: Start Here\norder: 1\n---", $raw );

		// Read it back.
		$read   = new WP_MCP_AI_Tool_Docs_Hub_Read_Doc();
		$result = $read->execute( array( 'path' => 'guides/start.md' ), array() );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Start Here', $result['title'] );
		$this->assertStringContainsString( '# Start Here', $result['content'] );

		// List it.
		$list   = new WP_MCP_AI_Tool_Docs_Hub_List_Docs();
		$result = $list->execute( array(), array() );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'guides/start.md', $result['files'][0]['path'] );

		// Delete (dry run first, then for real).
		$delete = new WP_MCP_AI_Tool_Docs_Hub_Delete_Doc();
		$result = $delete->execute( array( 'path' => 'guides/start.md' ), array() );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 'previewed', $result['action'] );
		$this->assertFileExists( $file );

		$result = $delete->execute(
			array(
				'path'    => 'guides/start.md',
				'dry_run' => false,
			),
			array()
		);
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'deleted', $result['action'] );
		$this->assertFileDoesNotExist( $file );

		// Reading a deleted file fails with a WP_Error.
		$result = $read->execute( array( 'path' => 'guides/start.md' ), array() );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Writing to an existing file without overwrite permission fails.
	 */
	public function test_write_respects_overwrite_flag() {
		$write = new WP_MCP_AI_Tool_Docs_Hub_Write_Doc();

		$write->execute(
			array(
				'path'    => 'readme.txt',
				'content' => 'First version.',
			),
			array()
		);

		$result = $write->execute(
			array(
				'path'      => 'readme.txt',
				'content'   => 'Second version.',
				'overwrite' => false,
			),
			array()
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_docs_hub_exists', $result->get_error_code() );

		// With overwrite allowed the same call succeeds.
		$result = $write->execute(
			array(
				'path'      => 'readme.txt',
				'content'   => 'Second version.',
				'overwrite' => true,
			),
			array()
		);
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'updated', $result['action'] );
	}

	/**
	 * Traversal paths are rejected by the write tool.
	 *
	 * @dataProvider provide_invalid_paths
	 *
	 * @param string $path Raw path.
	 */
	public function test_write_rejects_invalid_paths( $path ) {
		$write  = new WP_MCP_AI_Tool_Docs_Hub_Write_Doc();
		$result = $write->execute(
			array(
				'path'    => $path,
				'content' => 'nope',
			),
			array()
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_docs_hub_invalid_path', $result->get_error_code() );
	}

	/**
	 * Users without manage_options cannot write or delete.
	 */
	public function test_capability_gates() {
		$subscriber = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		wp_set_current_user( $subscriber );

		$write  = new WP_MCP_AI_Tool_Docs_Hub_Write_Doc();
		$result = $write->execute(
			array(
				'path'    => 'x.md',
				'content' => 'x',
			),
			array()
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );

		$delete = new WP_MCP_AI_Tool_Docs_Hub_Delete_Doc();
		$result = $delete->execute( array( 'path' => 'x.md' ), array() );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * The rebuild tool reports the Docs Hub plugin as inactive when it is
	 * not loaded, and works when stub plugin classes exist.
	 */
	public function test_rebuild_tool() {
		$rebuild = new WP_MCP_AI_Tool_Docs_Hub_Rebuild();

		// Plugin inactive.
		$result = $rebuild->execute( array(), array() );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_docs_hub_inactive', $result->get_error_code() );

		// Load scoped stubs so the integration path can be exercised.
		require_once __DIR__ . '/class-docs-hub-stubs.php';

		// Async mode.
		$result = $rebuild->execute( array( 'mode' => 'async' ), array() );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'job-9', $result['job_id'] );

		// Sync mode.
		$result = $rebuild->execute( array( 'mode' => 'sync' ), array() );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['pages'] );
	}

	/**
	 * With the stub plugin classes defined, writes trigger a rebuild and use
	 * the plugin's content-dir resolver.
	 */
	public function test_write_triggers_rebuild_when_plugin_active() {
		require_once __DIR__ . '/class-docs-hub-stubs.php';

		$write  = new WP_MCP_AI_Tool_Docs_Hub_Write_Doc();
		$result = $write->execute(
			array(
				'path'    => 'wiki.md',
				'content' => 'A wiki page.',
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['docs_hub_active'] );
		$this->assertSame( 'queued', $result['rebuild']['status'] );
		$this->assertSame( 'job-9', $result['rebuild']['job_id'] );
	}

	/**
	 * The list tool reports an empty inventory before anything is written.
	 */
	public function test_list_reports_empty_inventory() {
		$list   = new WP_MCP_AI_Tool_Docs_Hub_List_Docs();
		$result = $list->execute( array(), array() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['count'] );
		$this->assertSame( array(), $result['files'] );
	}
}
