<?php
/**
 * Tests for bundled Anthropic Agent Skills installation.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Bundled_Skills_Test extends WP_UnitTestCase {

	/**
	 * Temporary skill directory for testing.
	 *
	 * @var string
	 */
	private $test_skills_dir;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		WP_MCP_AI_Skill_Registry::reset();

		// Use a temp dir for testing to avoid affecting real uploads.
		$this->test_skills_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-bundled-' . uniqid();
		mkdir( $this->test_skills_dir, 0755, true );

		// Override the upload dir for tests.
		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		// Clean up temp dir.
		$this->recursive_rmdir( $this->test_skills_dir );

		WP_MCP_AI_Skill_Registry::reset();
		delete_option( WP_MCP_AI_Skill_Registry::OPTION_SKILL_INDEX );

		parent::tearDown();
	}

	/**
	 * Filter upload dir to use a temp directory for tests.
	 *
	 * @param array $upload_dir Upload directory data.
	 * @return array Modified upload directory data.
	 */
	public function filter_upload_dir( $upload_dir ) {
		$upload_dir['basedir'] = dirname( $this->test_skills_dir );
		return $upload_dir;
	}

	/**
	 * Test that bundled skills directory exists in plugin.
	 */
	public function test_bundled_skills_directory_exists() {
		$registry    = WP_MCP_AI_Skill_Registry::instance();
		$bundled_dir = $registry->get_bundled_skills_dir();

		$this->assertNotEmpty( $bundled_dir );
		$this->assertDirectoryExists( $bundled_dir );
	}

	/**
	 * Test that bundled skills directory contains skill subdirectories.
	 */
	public function test_bundled_skills_directory_has_skills() {
		$registry    = WP_MCP_AI_Skill_Registry::instance();
		$bundled_dir = $registry->get_bundled_skills_dir();

		$dirs = glob( $bundled_dir . '/*', GLOB_ONLYDIR );
		$this->assertNotEmpty( $dirs );
		$this->assertGreaterThanOrEqual( 16, count( $dirs ) );
	}

	/**
	 * Test that all bundled skills have valid SKILL.md files.
	 */
	public function test_bundled_skills_have_valid_skill_files() {
		$registry    = WP_MCP_AI_Skill_Registry::instance();
		$bundled_dir = $registry->get_bundled_skills_dir();
		$parser      = new WP_MCP_AI_Skill_Parser();

		$dirs = glob( $bundled_dir . '/*', GLOB_ONLYDIR );
		foreach ( $dirs as $dir ) {
			$skill_file = $dir . '/SKILL.md';
			$skill_name = basename( $dir );

			$this->assertFileExists( $skill_file, "SKILL.md missing for $skill_name" );

			$parsed = $parser->parse_file( $skill_file );
			$this->assertNotWPError( $parsed, "SKILL.md parse failed for $skill_name" );
			$this->assertSame(
				$skill_name,
				$parsed['name'],
				"Folder name '$skill_name' does not match skill name '{$parsed['name']}'"
			);
		}
	}

	/**
	 * Test that install_bundled_skills installs all skills.
	 */
	public function test_install_bundled_skills() {
		$registry = WP_MCP_AI_Skill_Registry::instance();
		$result   = $registry->install_bundled_skills();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'installed', $result );
		$this->assertArrayHasKey( 'skipped', $result );
		$this->assertArrayHasKey( 'errors', $result );

		$this->assertGreaterThanOrEqual( 16, $result['installed'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * Test that installed skills are discoverable via registry.
	 */
	public function test_installed_bundled_skills_are_discoverable() {
		$registry = WP_MCP_AI_Skill_Registry::instance();
		$registry->install_bundled_skills();

		$skills = $registry->get_all_skills();
		$this->assertGreaterThanOrEqual( 16, count( $skills ) );

		// Check some known skill names.
		$expected_skills = array(
			'algorithmic-art',
			'brand-guidelines',
			'canvas-design',
			'doc-coauthoring',
			'frontend-design',
			'mcp-builder',
			'skill-creator',
		);
		foreach ( $expected_skills as $name ) {
			$this->assertArrayHasKey( $name, $skills, "Skill '$name' not found in registry" );
		}
	}

	/**
	 * Test that re-running install_bundled_skills skips existing skills.
	 */
	public function test_install_bundled_skills_skips_existing() {
		$registry = WP_MCP_AI_Skill_Registry::instance();

		// First install.
		$first_result = $registry->install_bundled_skills();
		$this->assertGreaterThanOrEqual( 16, $first_result['installed'] );

		// Reset in-memory cache but keep files on disk.
		WP_MCP_AI_Skill_Registry::reset();
		$registry = WP_MCP_AI_Skill_Registry::instance();

		// Second install should skip all.
		$second_result = $registry->install_bundled_skills();
		$this->assertSame( 0, $second_result['installed'] );
		$this->assertGreaterThanOrEqual( 16, $second_result['skipped'] );
	}

	/**
	 * Test that specific well-known skills have expected content.
	 */
	public function test_bundled_skill_content_integrity() {
		$registry = WP_MCP_AI_Skill_Registry::instance();
		$registry->install_bundled_skills();

		$frontend = $registry->get_skill( 'frontend-design' );
		$this->assertNotNull( $frontend );
		$this->assertStringContainsString( 'frontend', $frontend['description'] );
		$this->assertNotEmpty( $frontend['instructions'] );

		$mcp = $registry->get_skill( 'mcp-builder' );
		$this->assertNotNull( $mcp );
		$this->assertStringContainsString( 'MCP', $mcp['description'] );
	}

	/**
	 * Recursively remove a directory (test cleanup helper).
	 *
	 * @param string $dir Directory path.
	 */
	private function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
