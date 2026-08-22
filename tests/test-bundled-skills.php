<?php
/**
 * Tests for bundled Anthropic Agent Skills installation.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
	 * Filter upload dir to use a unique per-test temp directory.
	 *
	 * The registry resolves its skills dir as `basedir . '/mcp-ai-skills'`,
	 * so pointing `basedir` at the per-test directory keeps every test's
	 * installed skills isolated and lets tearDown remove them wholesale.
	 * (Previously the filter used `dirname()`, which shared the system temp
	 * dir across tests and leaked state between runs.)
	 *
	 * @param array $upload_dir Upload directory data.
	 * @return array Modified upload directory data.
	 */
	public function filter_upload_dir( $upload_dir ) {
		$upload_dir['basedir'] = $this->test_skills_dir;
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
		$this->assertGreaterThanOrEqual( 44, count( $dirs ) );
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

		$this->assertGreaterThanOrEqual( 44, $result['installed'] );
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
		$this->assertGreaterThanOrEqual( 44, count( $skills ) );

		// Check some known skill names — covers Anthropic-authored and curated WP skills.
		$expected_skills = array(
			// Anthropic-authored.
			'algorithmic-art',
			'brand-guidelines',
			'canvas-design',
			'doc-coauthoring',
			'frontend-design',
			'mcp-builder',
			'skill-creator',
			'browser-use',
			'code-reviewer',
			'remotion',
			'valyu',
			'planetscale',
			'excalidraw-diagram',
			'shannon',
			// Curated WordPress-developer skills (Lonsdale201/wp-agent-skills, MIT).
			'wp-security-audit',
			'wp-security-deep',
			'wp-security-secrets',
			'wp-i18n-audit',
			'wp-rest-api',
			'wp-abilities-api',
			'wp-html-api',
			'wp-utf8-text',
			'wp-query-cache',
			'wp-action-scheduler',
			'wp-plugin-architecture',
			'wp-plugin-bootstrap',
			'wp-plugin-hooks',
			'wp-plugin-lifecycle',
			'wp-plugin-options-storage',
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
		$this->assertGreaterThanOrEqual( 44, $first_result['installed'] );

		// Reset in-memory cache but keep files on disk.
		WP_MCP_AI_Skill_Registry::reset();
		$registry = WP_MCP_AI_Skill_Registry::instance();

		// Second install should skip all.
		$second_result = $registry->install_bundled_skills();
		$this->assertSame( 0, $second_result['installed'] );
		$this->assertGreaterThanOrEqual( 44, $second_result['skipped'] );
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

		// Verify new skills from the 10 must-have list.
		$browser = $registry->get_skill( 'browser-use' );
		$this->assertNotNull( $browser, 'browser-use skill should be installed' );
		$this->assertNotEmpty( $browser['instructions'] );

		$reviewer = $registry->get_skill( 'code-reviewer' );
		$this->assertNotNull( $reviewer, 'code-reviewer skill should be installed' );
		$this->assertNotEmpty( $reviewer['instructions'] );

		$shannon = $registry->get_skill( 'shannon' );
		$this->assertNotNull( $shannon, 'shannon skill should be installed' );
		$this->assertStringContainsString( 'authorization', strtolower( $shannon['instructions'] ) );

		// Verify curated WP-developer skills (sourced from Lonsdale201/wp-agent-skills, MIT).
		$wp_security = $registry->get_skill( 'wp-security-audit' );
		$this->assertNotNull( $wp_security, 'wp-security-audit skill should be installed' );
		$this->assertNotEmpty( $wp_security['instructions'] );
		$this->assertSame( 'MIT', $wp_security['license'] );
		$this->assertStringContainsString( 'WordPress', $wp_security['description'] );

		$wp_rest = $registry->get_skill( 'wp-rest-api' );
		$this->assertNotNull( $wp_rest, 'wp-rest-api skill should be installed' );
		$this->assertNotEmpty( $wp_rest['instructions'] );

		$wp_bootstrap = $registry->get_skill( 'wp-plugin-bootstrap' );
		$this->assertNotNull( $wp_bootstrap, 'wp-plugin-bootstrap skill should be installed' );
		$this->assertNotEmpty( $wp_bootstrap['instructions'] );
	}

	/**
	 * Test that the third-party notices file is shipped alongside bundled skills.
	 *
	 * Required by the upstream MIT license for redistribution.
	 */
	public function test_bundled_skills_third_party_notices_present() {
		$registry    = WP_MCP_AI_Skill_Registry::instance();
		$bundled_dir = $registry->get_bundled_skills_dir();
		$notices     = $bundled_dir . '/THIRD_PARTY_NOTICES.md';

		$this->assertFileExists( $notices, 'THIRD_PARTY_NOTICES.md is required for license compliance' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test reading local file.
		$content = file_get_contents( $notices );
		$this->assertStringContainsString( 'MIT License', $content );
		$this->assertStringContainsString( 'Lonsdale201', $content );
	}

	/**
	 * Test install_bundled_skills_from_dir with a custom directory.
	 */
	public function test_install_bundled_skills_from_dir() {
		$registry = WP_MCP_AI_Skill_Registry::instance();

		// Create a temporary skills directory with a test skill.
		$tmp_dir   = sys_get_temp_dir() . '/wp-mcp-ai-test-from-dir-' . uniqid();
		$skill_dir = $tmp_dir . '/test-extra-skill';
		mkdir( $skill_dir, 0755, true );

		$skill_md = "---\nname: test-extra-skill\ndescription: A temporary test skill for from-dir testing.\n---\n\n# Test Extra Skill\n\nInstructions here.";
		file_put_contents( $skill_dir . '/SKILL.md', $skill_md );

		// Install from the custom directory.
		$result = $registry->install_bundled_skills_from_dir( $tmp_dir );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['installed'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertEmpty( $result['errors'] );

		// Verify skill is accessible via registry.
		$registry->load_skills( true );
		$skill = $registry->get_skill( 'test-extra-skill' );
		$this->assertNotNull( $skill, 'Extra skill should be discoverable after install' );

		// Clean up temporary source directory.
		$this->recursive_rmdir( $tmp_dir );
	}

	/**
	 * Test install_bundled_skills_from_dir with non-existent directory.
	 */
	public function test_install_bundled_skills_from_dir_nonexistent() {
		$registry = WP_MCP_AI_Skill_Registry::instance();
		$result   = $registry->install_bundled_skills_from_dir( '/nonexistent/path/that/does/not/exist' );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['installed'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Test that companion files (e.g. reference.md) shipped alongside a
	 * bundled SKILL.md are also copied to the uploads directory.
	 *
	 * Several of the curated WordPress-developer skills (e.g. wp-security-audit)
	 * ship a `reference.md` alongside `SKILL.md` and reference it from the
	 * instructions body. This test guards the behaviour that the bundled-skills
	 * installer copies those companion files into uploads so the references
	 * resolve at runtime.
	 */
	public function test_bundled_skills_install_companion_files() {
		$registry    = WP_MCP_AI_Skill_Registry::instance();
		$bundled_dir = $registry->get_bundled_skills_dir();

		// Find any bundled skill that ships at least one companion file.
		$skill_with_companion = null;
		$companion_filename   = null;
		foreach ( glob( $bundled_dir . '/*', GLOB_ONLYDIR ) as $dir ) {
			$companions = array_filter(
				glob( $dir . '/*' ) ?: array(),
				static function ( $path ) {
					return is_file( $path ) && 'SKILL.md' !== basename( $path );
				}
			);
			if ( ! empty( $companions ) ) {
				$skill_with_companion = basename( $dir );
				$companion_filename   = basename( reset( $companions ) );
				break;
			}
		}

		if ( null === $skill_with_companion ) {
			$this->markTestSkipped( 'No bundled skill with companion files found.' );
			return;
		}

		$registry->install_bundled_skills();

		$installed_dir = trailingslashit( $registry->get_skills_dir() ) . $skill_with_companion;
		$this->assertFileExists(
			$installed_dir . '/' . $companion_filename,
			"Companion file '$companion_filename' should be copied for skill '$skill_with_companion'"
		);
	}

	/**
	 * via the same SKILL.md parser used at runtime.
	 *
	 * The Pro directory ships alongside the Pro add-on and is installed via
	 * `install_bundled_skills_from_dir()` on Pro activation. It is exercised
	 * here at the *parser* level so the test runs on Base-only PR CI as well
	 * (no Pro init required).
	 */
	public function test_pro_bundled_skills_parse_cleanly() {
		$pro_dir = dirname( __DIR__ ) . '/addons/pro/includes/bundled-skills';
		if ( ! is_dir( $pro_dir ) ) {
			$this->markTestSkipped( 'Pro bundled-skills directory not present.' );
			return;
		}

		$dirs = glob( $pro_dir . '/*', GLOB_ONLYDIR );
		$this->assertNotEmpty( $dirs, 'Pro bundled-skills directory should contain skill folders.' );

		$parser = new WP_MCP_AI_Skill_Parser();
		foreach ( $dirs as $dir ) {
			$skill_file = $dir . '/SKILL.md';
			$skill_name = basename( $dir );
			$this->assertFileExists( $skill_file, "SKILL.md missing for Pro skill $skill_name" );

			$parsed = $parser->parse_file( $skill_file );
			$this->assertNotWPError( $parsed, "SKILL.md parse failed for Pro skill $skill_name" );
			$this->assertSame(
				$skill_name,
				$parsed['name'],
				"Pro folder name '$skill_name' does not match skill name '{$parsed['name']}'"
			);
		}

		// Pro directory must ship the third-party notices file (license compliance).
		$this->assertFileExists(
			$pro_dir . '/THIRD_PARTY_NOTICES.md',
			'Pro bundled-skills THIRD_PARTY_NOTICES.md is required for license compliance'
		);
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
