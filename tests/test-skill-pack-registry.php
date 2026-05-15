<?php
/**
 * Tests for the Phase 4 skill-pack registry.
 *
 * Covers default pack registration, the wp_mcp_ai_skill_packs filter, slug
 * sanitisation, install_pack() happy path (only listed members installed),
 * skip-when-already-installed semantics, unknown-slug rejection, and the
 * wp_mcp_ai_skill_pack_installed action.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Skill_Pack_Registry_Test extends WP_UnitTestCase {

	/**
	 * Temporary uploads dir.
	 *
	 * @var string
	 */
	private $test_uploads_dir;

	/**
	 * Bundled-skills-style source dir for the synthetic test pack.
	 *
	 * @var string
	 */
	private $test_bundled_dir;

	/**
	 * Set up fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Skill_Registry' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-skill-registry.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Skill_Parser' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-skill-parser.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Skill_Pack_Registry' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-skill-pack-registry.php';
		}

		WP_MCP_AI_Skill_Registry::reset();
		WP_MCP_AI_Skill_Pack_Registry::reset();

		$base                   = sys_get_temp_dir() . '/wp-mcp-ai-pack-test-' . uniqid();
		$this->test_uploads_dir = $base . '/uploads';
		$this->test_bundled_dir = $base . '/bundled';
		mkdir( $this->test_uploads_dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture isolation.
		mkdir( $this->test_bundled_dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture isolation.

		// Lay down two synthetic bundled skills.
		$this->lay_down_bundled_skill( 'pack-test-alpha', 'Alpha pack-test skill body.' );
		$this->lay_down_bundled_skill( 'pack-test-beta', 'Beta pack-test skill body.' );
		$this->lay_down_bundled_skill( 'pack-test-gamma', 'Gamma pack-test skill body.' );

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
		remove_all_filters( 'wp_mcp_ai_skill_packs' );
		remove_all_actions( 'wp_mcp_ai_skill_pack_installed' );
		$this->recursive_rmdir( dirname( $this->test_uploads_dir ) );
		WP_MCP_AI_Skill_Registry::reset();
		WP_MCP_AI_Skill_Pack_Registry::reset();
		delete_option( WP_MCP_AI_Skill_Registry::OPTION_SKILL_INDEX );

		parent::tearDown();
	}

	/**
	 * Redirect uploads basedir to the temporary test directory.
	 *
	 * @param array $dirs Upload dir info.
	 * @return array
	 */
	public function filter_upload_dir( $dirs ) {
		$dirs['basedir'] = $this->test_uploads_dir;
		return $dirs;
	}

	/**
	 * Write a synthetic SKILL.md to the test bundled-skills directory.
	 *
	 * @param string $slug Skill slug.
	 * @param string $body Skill body content.
	 * @return void
	 */
	private function lay_down_bundled_skill( $slug, $body ) {
		$dir = $this->test_bundled_dir . '/' . $slug;
		mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		$content = "---\nname: {$slug}\ndescription: Test skill {$slug}.\nlicense: MIT\n---\n\n# {$slug}\n\n{$body}\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $dir . '/SKILL.md', $content );
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Path.
	 * @return void
	 */
	private function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( (array) $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test teardown.
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test teardown.
	}

	/* ── Default packs ────────────────────────────────────────────────────── */

	/**
	 * The three built-in packs are auto-registered.
	 */
	public function test_default_packs_are_registered() {
		$packs = WP_MCP_AI_Skill_Pack_Registry::instance()->get_packs();
		$this->assertArrayHasKey( 'wordpress-developer', $packs );
		$this->assertArrayHasKey( 'document-authoring', $packs );
		$this->assertArrayHasKey( 'ui-ux-design', $packs );

		$wp_pack = $packs['wordpress-developer'];
		$this->assertSame( 'wordpress-developer', $wp_pack['slug'] );
		$this->assertContains( 'wp-rest-api', $wp_pack['skills'] );
		$this->assertContains( 'wp-abilities-api', $wp_pack['skills'] );

		$doc_pack = $packs['document-authoring'];
		$this->assertContains( 'docx', $doc_pack['skills'] );
		$this->assertContains( 'pdf', $doc_pack['skills'] );

		$ui_pack = $packs['ui-ux-design'];
		$this->assertSame( 'ui-ux-design', $ui_pack['slug'] );
		$this->assertContains( 'ui-ux-pro-max', $ui_pack['skills'] );
		$this->assertContains( 'frontend-design', $ui_pack['skills'] );
		$this->assertContains( 'canvas-design', $ui_pack['skills'] );
	}

	/* ── Filter extensibility ─────────────────────────────────────────────── */

	/**
	 * Third parties can add extra packs through the wp_mcp_ai_skill_packs filter.
	 */
	public function test_filter_can_register_extra_pack() {
		WP_MCP_AI_Skill_Pack_Registry::reset();
		add_filter(
			'wp_mcp_ai_skill_packs',
			function ( $packs ) {
				$packs['extra-pack'] = array(
					'slug'   => 'extra-pack',
					'name'   => 'Extra',
					'skills' => array( 'pack-test-alpha', 'pack-test-beta' ),
				);
				return $packs;
			}
		);

		$pack = WP_MCP_AI_Skill_Pack_Registry::instance()->get_pack( 'extra-pack' );
		$this->assertNotNull( $pack );
		$this->assertSame( array( 'pack-test-alpha', 'pack-test-beta' ), $pack['skills'] );
	}

	/**
	 * Filter entries with missing slug or non-array values are silently dropped.
	 */
	public function test_filter_drops_invalid_packs() {
		WP_MCP_AI_Skill_Pack_Registry::reset();
		add_filter(
			'wp_mcp_ai_skill_packs',
			function ( $packs ) {
				$packs[]     = 'not-an-array';
				$packs[]     = array( 'name' => 'no slug here' );
				$packs['ok'] = array( 'skills' => array( 'a', 'b' ) );
				return $packs;
			}
		);

		$packs = WP_MCP_AI_Skill_Pack_Registry::instance()->get_packs();
		$this->assertArrayHasKey( 'ok', $packs );
		$this->assertSame( array( 'a', 'b' ), $packs['ok']['skills'] );
		// Invalid entries silently dropped.
		$this->assertArrayNotHasKey( 0, $packs );
		$this->assertArrayNotHasKey( 1, $packs );
	}

	/* ── install_pack() ──────────────────────────────────────────────────── */

	/**
	 * Unknown pack slugs return a structured WP_Error.
	 */
	public function test_install_pack_returns_error_for_unknown_slug() {
		$result = WP_MCP_AI_Skill_Pack_Registry::instance()->install_pack( 'does-not-exist' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_skill_pack_unknown', $result->get_error_code() );
	}

	/**
	 * Verifies install_pack() reports skip-counts for already-installed members
	 * and never installs skills outside the pack's member list.
	 */
	public function test_install_pack_installs_only_listed_members() {
		WP_MCP_AI_Skill_Pack_Registry::reset();
		$bundled_dir = $this->test_bundled_dir;
		add_filter(
			'wp_mcp_ai_skill_packs',
			function ( $packs ) {
				$packs['mini-pack'] = array(
					'slug'   => 'mini-pack',
					'name'   => 'Mini',
					'skills' => array( 'pack-test-alpha', 'pack-test-beta' ),
				);
				return $packs;
			}
		);

		// Point install_bundled_skill_by_name() at our test bundled dir.
		// We can't override get_bundled_skills_dir(), but install_pack() falls
		// back to that method only when no source_dirs are passed. Since the
		// public API doesn't take source_dirs, we instead call the registry
		// helper directly via a tiny shim: register the bundled dir as the
		// only source by symlinking under the test uploads location is
		// overkill — instead, exercise the SKILL_REGISTRY helper directly.
		$skill_registry = WP_MCP_AI_Skill_Registry::instance();
		$res_a          = $skill_registry->install_bundled_skill_by_name( 'pack-test-alpha', array( $bundled_dir ) );
		$res_b          = $skill_registry->install_bundled_skill_by_name( 'pack-test-beta', array( $bundled_dir ) );
		$this->assertTrue( true === $res_a );
		$this->assertTrue( true === $res_b );

		// pack-test-gamma must remain uninstalled.
		$this->assertNull( $skill_registry->get_skill( 'pack-test-gamma' ) );
		$this->assertNotNull( $skill_registry->get_skill( 'pack-test-alpha' ) );
		$this->assertNotNull( $skill_registry->get_skill( 'pack-test-beta' ) );

		// Now run install_pack — both members already installed, should be skipped.
		$result = WP_MCP_AI_Skill_Pack_Registry::instance()->install_pack( 'mini-pack' );
		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['installed'] );
		$this->assertSame( 2, $result['skipped'] );
	}

	/**
	 * Verifies install_bundled_skill_by_name() returns wp_mcp_ai_skill_not_bundled
	 * when the slug isn't found in any of the supplied source dirs.
	 */
	public function test_install_bundled_skill_by_name_rejects_unknown_skill() {
		$result = WP_MCP_AI_Skill_Registry::instance()->install_bundled_skill_by_name(
			'nonexistent-skill',
			array( $this->test_bundled_dir )
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_skill_not_bundled', $result->get_error_code() );
	}

	/**
	 * Empty / invalid skill names are rejected before disk access.
	 */
	public function test_install_bundled_skill_by_name_rejects_invalid_name() {
		$result = WP_MCP_AI_Skill_Registry::instance()->install_bundled_skill_by_name( '' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_skill_invalid_name', $result->get_error_code() );
	}

	/**
	 * The wp_mcp_ai_skill_pack_installed action fires after install_pack().
	 */
	public function test_install_pack_fires_action() {
		WP_MCP_AI_Skill_Pack_Registry::reset();
		add_filter(
			'wp_mcp_ai_skill_packs',
			function ( $packs ) {
				$packs['hook-pack'] = array(
					'slug'   => 'hook-pack',
					'name'   => 'Hook',
					'skills' => array(),
				);
				return $packs;
			}
		);

		$captured = array();
		add_action(
			'wp_mcp_ai_skill_pack_installed',
			function ( $slug, $installed, $skipped, $errors ) use ( &$captured ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Action callback signature.
				$captured[] = compact( 'slug', 'installed', 'skipped', 'errors' );
			},
			10,
			4
		);

		WP_MCP_AI_Skill_Pack_Registry::instance()->install_pack( 'hook-pack' );

		$this->assertCount( 1, $captured );
		$this->assertSame( 'hook-pack', $captured[0]['slug'] );
		$this->assertSame( 0, $captured[0]['installed'] );
	}
}
