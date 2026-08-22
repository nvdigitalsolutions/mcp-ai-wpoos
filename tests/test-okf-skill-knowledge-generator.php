<?php
/**
 * Tests for the OKF skill-knowledge bundle generator.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_OKF_Skill_Knowledge_Generator_Test extends WP_UnitTestCase {

	/**
	 * Temporary knowledge root directory for testing.
	 *
	 * @var string
	 */
	private $test_uploads_dir;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// The framework resets the current user to 0 in tear_down(), and the
		// OKF tools check capabilities — restore the standard admin user.
		wp_set_current_user( 1 );

		// Use a temp dir for the uploads target to avoid touching the real
		// WordPress uploads directory.
		$this->test_uploads_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-okf-bundle-' . uniqid();
		mkdir( $this->test_uploads_dir, 0755, true );

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		// Start from a clean generation state.
		delete_option( WP_MCP_AI_OKF_Skill_Knowledge_Generator::GENERATED_OPTION );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		$this->recursive_rmdir( $this->test_uploads_dir );

		delete_option( WP_MCP_AI_OKF_Skill_Knowledge_Generator::GENERATED_OPTION );

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
	 * Test that the generator class is available and registers its hook.
	 */
	public function test_generator_registers_bootstrap_hook() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_OKF_Skill_Knowledge_Generator' ) );

		// The plugin bootstrap registers the hook; init() is idempotent.
		WP_MCP_AI_OKF_Skill_Knowledge_Generator::init();

		$this->assertSame(
			32,
			has_action( 'wp_mcp_ai_bootstrapped', array( 'WP_MCP_AI_OKF_Skill_Knowledge_Generator', 'maybe_generate' ) )
		);
	}

	/**
	 * Test that generate() creates the skill-knowledge bundle on disk.
	 */
	public function test_generate_creates_bundle() {
		$result = WP_MCP_AI_OKF_Skill_Knowledge_Generator::generate();

		$this->assertTrue( $result['generated'] );
		$this->assertGreaterThan( 0, $result['concepts'] );
		$this->assertEmpty( $result['errors'] );

		$bundle_path = WP_MCP_AI_OKF_Skill_Knowledge_Generator::get_bundle_root();
		$this->assertDirectoryExists( $bundle_path );
		$this->assertFileExists( $bundle_path . '/index.md' );
		$this->assertFileExists( $bundle_path . '/code-reviewer/SKILL.md' );

		// The option must be stored so bootstrap stays cheap on later requests.
		$this->assertSame(
			WP_MCP_AI_OKF_Skill_Knowledge_Generator::get_fingerprint(),
			get_option( WP_MCP_AI_OKF_Skill_Knowledge_Generator::GENERATED_OPTION, '' )
		);

		// Companion files referenced by skill bodies must be preserved.
		$this->assertFileExists( $bundle_path . '/wp-security-audit/reference.md' );
	}

	/**
	 * Test that the documented skeleton bundles exist after generation.
	 */
	public function test_generate_creates_runtime_bundle_skeletons() {
		WP_MCP_AI_OKF_Skill_Knowledge_Generator::generate();

		$knowledge_root = dirname( WP_MCP_AI_OKF_Skill_Knowledge_Generator::get_bundle_root() );

		$this->assertDirectoryExists( $knowledge_root );
		$this->assertDirectoryExists( $knowledge_root . '/site-knowledge' );
		$this->assertDirectoryExists( $knowledge_root . '/external-bundles' );
	}

	/**
	 * Test that generation is idempotent and gated by the stored fingerprint.
	 */
	public function test_generate_is_gated_by_fingerprint() {
		$first = WP_MCP_AI_OKF_Skill_Knowledge_Generator::generate();
		$this->assertTrue( $first['generated'] );

		// A second non-forced run is a no-op.
		$second = WP_MCP_AI_OKF_Skill_Knowledge_Generator::generate();
		$this->assertFalse( $second['generated'] );

		// maybe_generate() must not regenerate when the fingerprint matches.
		$option_before = get_option( WP_MCP_AI_OKF_Skill_Knowledge_Generator::GENERATED_OPTION, '' );
		WP_MCP_AI_OKF_Skill_Knowledge_Generator::maybe_generate();
		$this->assertSame( $option_before, get_option( WP_MCP_AI_OKF_Skill_Knowledge_Generator::GENERATED_OPTION, '' ) );
	}

	/**
	 * Test that a forced regeneration removes stale skill files.
	 */
	public function test_force_regenerate_removes_stale_files() {
		WP_MCP_AI_OKF_Skill_Knowledge_Generator::generate();

		$bundle_path = WP_MCP_AI_OKF_Skill_Knowledge_Generator::get_bundle_root();

		// Simulate a skill that was removed from the bundled set.
		mkdir( $bundle_path . '/removed-skill', 0755, true );
		file_put_contents( $bundle_path . '/removed-skill/SKILL.md', "---\ntype: Skill\n---\n" );

		$result = WP_MCP_AI_OKF_Skill_Knowledge_Generator::generate( true );

		$this->assertTrue( $result['generated'] );
		$this->assertDirectoryDoesNotExist( $bundle_path . '/removed-skill' );
	}

	/**
	 * Test that the okf_search tool can search the generated bundle.
	 */
	public function test_okf_search_tool_finds_skill_concepts() {
		WP_MCP_AI_OKF_Skill_Knowledge_Generator::generate();

		$tool = new WP_MCP_AI_Tool_OKF_Search();

		$response = $tool->execute(
			array(
				'bundle' => 'skill-knowledge',
				'type'   => 'Skill',
			),
			array()
		);

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertArrayHasKey( 'success', $response );
		$this->assertTrue( $response['success'] );
		$this->assertArrayHasKey( 'results', $response );
		$this->assertNotEmpty( $response['results'] );

		// Every result is a bundled skill concept.
		$concept_ids = array();
		foreach ( $response['results'] as $result ) {
			$this->assertSame( 'Skill', $result['type'] );
			$concept_ids[] = $result['concept_id'];
		}
		$this->assertContains( 'code-reviewer/SKILL', $concept_ids );
	}

	/**
	 * Test that the okf_read_concept tool resolves a generated skill concept.
	 */
	public function test_okf_read_concept_tool_reads_generated_concept() {
		WP_MCP_AI_OKF_Skill_Knowledge_Generator::generate();

		$tool = new WP_MCP_AI_Tool_OKF_Read_Concept();

		$response = $tool->execute(
			array(
				'bundle'     => 'skill-knowledge',
				'concept_id' => 'code-reviewer/SKILL',
			),
			array()
		);

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertTrue( $response['success'] );

		// The skill name must be reflected in the response payload.
		$payload = wp_json_encode( $response );
		$this->assertStringContainsString( 'code-reviewer', $payload );
	}
}
