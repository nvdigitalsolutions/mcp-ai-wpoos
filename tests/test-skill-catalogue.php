<?php
/**
 * Tests for the Pro Skill Catalogue Service and REST controller.
 *
 * The remote HTTP layer is NOT exercised against the network. Instead we test:
 *   - source normalisation (id/owner/repo/ref validation, traversal blocking)
 *   - default seeding
 *   - manifest skill normalisation (path traversal, missing fields)
 *   - REST permission gating (anonymous → 401, admin → 200/201/400)
 *   - install path: rejects unknown sources, malformed paths, paths not in the manifest
 *
 * The HTTP-fetching helpers (`safe_get`, `fetch_raw`, `build_manifest`) are
 * intercepted by stubbing via `pre_http_request` so no real network call is
 * made during the test run.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Skill_Catalogue_Test extends WP_Test_REST_TestCase {

	/**
	 * Admin user.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Temporary skills dir.
	 *
	 * @var string
	 */
	private $test_skills_dir;

	/**
	 * Stubbed HTTP responses keyed by URL substring.
	 *
	 * @var array<string, array{body:string, code?:int}>
	 */
	private $http_stubs = array();

	/**
	 * Captured request headers keyed by URL (assertion aid).
	 *
	 * @var array<string, array<string, string>>
	 */
	private $captured_headers = array();

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
		if ( ! class_exists( 'WP_MCP_AI_Skill_Catalogue_Service' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/services/class-wp-mcp-ai-skill-catalogue-service.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Skill_Catalogue_REST_Controller' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/rest/class-wp-mcp-ai-skill-catalogue-rest-controller.php';
		}

		WP_MCP_AI_Skill_Registry::reset();
		WP_MCP_AI_Skill_Catalogue_Service::reset();
		delete_option( WP_MCP_AI_Skill_Catalogue_Service::OPTION_SOURCES );

		$this->test_skills_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-cat-skills-' . uniqid();
		mkdir( $this->test_skills_dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture isolation.
		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Intercept all outbound HTTP so the tests do not touch the network.
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		// Boot REST and register the catalogue routes.
		new WP_MCP_AI_Skill_Catalogue_REST_Controller();
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ) );
		remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
		$this->recursive_rmdir( $this->test_skills_dir );
		// The registry writes installed skills into a shared skills subdir of
		// the redirected uploads basedir; remove it so later suites sharing
		// the temp parent directory do not see leaked fixtures.
		$this->recursive_rmdir( trailingslashit( dirname( $this->test_skills_dir ) ) . WP_MCP_AI_Skill_Registry::UPLOAD_DIR );
		WP_MCP_AI_Skill_Registry::reset();
		WP_MCP_AI_Skill_Catalogue_Service::reset();
		delete_option( WP_MCP_AI_Skill_Catalogue_Service::OPTION_SOURCES );
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
		$dirs['basedir'] = dirname( $this->test_skills_dir );
		return $dirs;
	}

	/**
	 * Pre_http_request callback: serve canned responses, block real network.
	 *
	 * @param mixed  $pre  Default false.
	 * @param array  $args HTTP request args.
	 * @param string $url  Request URL.
	 * @return array|WP_Error
	 */
	public function intercept_http( $pre, $args, $url ) {
		$this->captured_headers[ $url ] = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		foreach ( $this->http_stubs as $needle => $stub ) {
			if ( false !== strpos( $url, $needle ) ) {
				return array(
					'response' => array(
						'code'    => isset( $stub['code'] ) ? (int) $stub['code'] : 200,
						'message' => 'OK',
					),
					'headers'  => array(),
					'body'     => isset( $stub['body'] ) ? (string) $stub['body'] : '',
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		return new WP_Error( 'test_http_blocked', 'Outbound HTTP blocked in test: ' . $url );
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Path to remove.
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

	/* ─── Service: source normalisation ──────────────────────────────────── */

	/**
	 * Default sources are seeded on first read.
	 */
	public function test_default_sources_are_seeded_on_first_read() {
		$svc     = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$sources = $svc->get_sources();
		$this->assertGreaterThanOrEqual( 2, count( $sources ) );
		$ids = wp_list_pluck( $sources, 'id' );
		$this->assertContains( 'wp-agent-skills', $ids );
		$this->assertContains( 'anthropics-skills', $ids );
		$this->assertContains( 'figma-skills', $ids );
		// The awesome-list repo carries zero SKILL.md files and can never list
		// skills — it must not be seeded as a default source.
		$this->assertNotContains( 'awesome-agent-skills', $ids );
	}

	/**
	 * Normalize_source rejects an invalid GitHub owner.
	 */
	public function test_normalize_source_rejects_invalid_owner() {
		$svc = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$this->assertNull(
			$svc->normalize_source(
				array(
					'owner' => '../etc',
					'repo'  => 'r',
				)
			)
		);
		$this->assertNull(
			$svc->normalize_source(
				array(
					'owner' => 'a b',
					'repo'  => 'r',
				)
			)
		);
	}

	/**
	 * Normalize_source rejects path traversal in the optional manifest path.
	 */
	public function test_normalize_source_rejects_path_traversal_in_manifest_path() {
		$svc  = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$norm = $svc->normalize_source(
			array(
				'owner'         => 'foo',
				'repo'          => 'bar',
				'manifest_path' => '../../etc/passwd',
			)
		);
		$this->assertSame( '', $norm['manifest_path'] );
	}

	/**
	 * Save_sources collapses duplicate ids and keeps the last entry.
	 */
	public function test_save_sources_dedupes_by_id_and_keeps_last() {
		$svc   = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$saved = $svc->save_sources(
			array(
				array(
					'id'    => 'dup',
					'owner' => 'a',
					'repo'  => 'b',
					'ref'   => 'main',
					'label' => 'first',
				),
				array(
					'id'    => 'dup',
					'owner' => 'a',
					'repo'  => 'b',
					'ref'   => 'v2',
					'label' => 'second',
				),
			)
		);
		$this->assertCount( 1, $saved );
		$this->assertSame( 'second', $saved[0]['label'] );
		$this->assertSame( 'v2', $saved[0]['ref'] );
	}

	/* ─── Service: manifest skill normalisation ──────────────────────────── */

	/**
	 * The manifest is taken from catalogue.json when the source ships one.
	 */
	public function test_manifest_via_catalogue_json_is_used_when_present() {
		$svc = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$svc->save_sources(
			array(
				array(
					'id'    => 'sample',
					'owner' => 'x',
					'repo'  => 'y',
					'ref'   => 'main',
				),
			)
		);

		$this->http_stubs = array(
			'/x/y/main/catalogue.json' => array(
				'body' => wp_json_encode(
					array(
						'skills' => array(
							array(
								'name'        => 'demo',
								'description' => 'Hello',
								'path'        => 'demo',
							),
							array(
								'name'        => '../bad',
								'description' => 'evil',
								'path'        => '../escape',
							),
							array( 'name' => 'no-path' ), // Missing path -> dropped.
						),
					)
				),
			),
		);

		$manifest = $svc->get_manifest( 'sample', true );
		$this->assertNotWPError( $manifest );
		$this->assertSame( 'sample', $manifest['source_id'] );
		// Only "demo" survives normalisation: `../bad` becomes invalid name, `no-path` dropped.
		$names = wp_list_pluck( $manifest['skills'], 'name' );
		$this->assertContains( 'demo', $names );
		$this->assertNotContains( 'no-path', $names );
		foreach ( $manifest['skills'] as $sk ) {
			$this->assertStringNotContainsString( '..', $sk['path'] );
			$this->assertNotSame( 0, strncmp( $sk['path'], '/', 1 ) );
		}
	}

	/**
	 * Duplicate skill names collapse to the shortest path entry.
	 *
	 * Mirrors real repos (e.g. figma/mcp-server-guide ships `skills/` and
	 * `skills-figquery/` copies of the same skills). The install slug is the
	 * skill name, so the manifest must not list colliding duplicates.
	 */
	public function test_manifest_deduplicates_duplicate_skill_names() {
		$svc = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$svc->save_sources(
			array(
				array(
					'id'    => 'dup',
					'owner' => 'x',
					'repo'  => 'y',
					'ref'   => 'main',
				),
			)
		);

		$this->http_stubs = array(
			'/x/y/main/catalogue.json' => array(
				'body' => wp_json_encode(
					array(
						'skills' => array(
							array(
								'name' => 'figma-use',
								'path' => 'skills-figquery/figma-use',
							),
							array(
								'name' => 'figma-use',
								'path' => 'skills/figma-use',
							),
							array(
								'name' => 'tie-a',
								'path' => 'aa/tie-a',
							),
							array(
								'name' => 'tie-a',
								'path' => 'bb/tie-a',
							),
							array(
								'name' => 'solo',
								'path' => 'solo',
							),
						),
					)
				),
			),
		);

		$manifest = $svc->get_manifest( 'dup', true );
		$this->assertNotWPError( $manifest );

		$by_name = array();
		foreach ( $manifest['skills'] as $sk ) {
			$by_name[ $sk['name'] ] = $sk['path'];
		}

		$this->assertCount( 3, $manifest['skills'] );
		$this->assertSame( 'skills/figma-use', $by_name['figma-use'] );
		$this->assertSame( 'aa/tie-a', $by_name['tie-a'] );
		$this->assertSame( 'solo', $by_name['solo'] );
	}

	/**
	 * Install rejects unknown sources.
	 */
	public function test_install_rejects_unknown_source() {
		$svc    = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$result = $svc->install_from_catalogue( 'does-not-exist', 'demo' );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_skill_catalogue_unknown_source', $result->get_error_code() );
	}

	/**
	 * Install rejects path traversal in the requested skill path.
	 */
	public function test_install_rejects_path_traversal() {
		$svc = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$svc->save_sources(
			array(
				array(
					'id'    => 's1',
					'owner' => 'a',
					'repo'  => 'b',
					'ref'   => 'main',
				),
			)
		);
		$result = $svc->install_from_catalogue( 's1', '../../etc/passwd' );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_skill_catalogue_bad_path', $result->get_error_code() );
	}

	/**
	 * Install rejects paths that are not present in the cached manifest.
	 */
	public function test_install_rejects_path_not_in_manifest() {
		$svc = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$svc->save_sources(
			array(
				array(
					'id'    => 's1',
					'owner' => 'a',
					'repo'  => 'b',
					'ref'   => 'main',
				),
			)
		);

		$this->http_stubs = array(
			'/a/b/main/catalogue.json' => array(
				'body' => wp_json_encode(
					array(
						'skills' => array(
							array(
								'name' => 'demo',
								'path' => 'demo',
							),
						),
					)
				),
			),
		);

		$result = $svc->install_from_catalogue( 's1', 'something-else' );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_skill_catalogue_not_in_manifest', $result->get_error_code() );
	}

	/**
	 * Happy path: a manifest-listed skill is fetched and installed via the registry.
	 */
	public function test_install_happy_path_writes_skill_via_registry() {
		$svc = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$svc->save_sources(
			array(
				array(
					'id'    => 's1',
					'owner' => 'a',
					'repo'  => 'b',
					'ref'   => 'main',
				),
			)
		);

		$skill_md = "---\nname: demo\ndescription: A demo skill installed via catalogue.\nlicense: MIT\n---\n\n# Demo\n\nDo demo things.\n";

		$this->http_stubs = array(
			'/a/b/main/catalogue.json'    => array(
				'body' => wp_json_encode(
					array(
						'skills' => array(
							array(
								'name'        => 'demo',
								'path'        => 'demo',
								'description' => 'A demo',
							),
						),
					)
				),
			),
			'/a/b/main/demo/SKILL.md'     => array( 'body' => $skill_md ),
			// Companion files: any 404 would still be a stub miss in our harness, so we
			// short-circuit them by returning an empty body. The service treats empty
			// strings as "no companion" and skips them.
			'/a/b/main/demo/reference.md' => array( 'body' => '' ),
			'/a/b/main/demo/examples.md'  => array( 'body' => '' ),
			'/a/b/main/demo/NOTES.md'     => array( 'body' => '' ),
			'/a/b/main/demo/LICENSE'      => array( 'body' => '' ),
		);

		$result = $svc->install_from_catalogue( 's1', 'demo' );
		$this->assertNotWPError( $result );
		$this->assertSame( 'demo', $result['name'] );

		$registry  = WP_MCP_AI_Skill_Registry::instance();
		$installed = $registry->get_skill( 'demo' );
		$this->assertNotNull( $installed );
		$this->assertSame( 'demo', $installed['name'] );
	}

	/**
	 * A configured GitHub token is sent as a Bearer Authorization header on
	 * every GitHub catalogue request (api.github.com + raw.githubusercontent.com).
	 */
	public function test_github_token_attaches_authorization_header() {
		$svc = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$svc->save_sources(
			array(
				array(
					'id'    => 'tok',
					'owner' => 'x',
					'repo'  => 'y',
					'ref'   => 'main',
				),
			)
		);

		$this->http_stubs = array(
			'api.github.com/repos/x/y/git/trees' => array(
				'body' => wp_json_encode(
					array(
						'tree' => array(
							array(
								'type' => 'blob',
								'path' => 'skills/demo/SKILL.md',
								'sha'  => str_repeat( 'a', 40 ),
							),
						),
					)
				),
			),
		);

		add_filter( 'wp_mcp_ai_skill_catalogue_github_token', array( $this, 'return_test_github_token' ) );
		$manifest = $svc->get_manifest( 'tok', true );
		remove_filter( 'wp_mcp_ai_skill_catalogue_github_token', array( $this, 'return_test_github_token' ) );

		$this->assertNotWPError( $manifest );
		$this->assertNotEmpty( $this->captured_headers );
		foreach ( $this->captured_headers as $url => $headers ) {
			$found = false;
			foreach ( $headers as $key => $value ) {
				if ( 'authorization' === strtolower( $key ) && 'Bearer ghp_test123' === $value ) {
					$found = true;
					break;
				}
			}
			$this->assertTrue( $found, 'Expected Bearer header missing for ' . $url );
		}
	}

	/**
	 * The settings-level `github_access_token` is the anonymous fallback when
	 * no constant/filter token is set.
	 */
	public function test_github_token_falls_back_to_settings() {
		$svc = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$svc->save_sources(
			array(
				array(
					'id'    => 'tok-settings',
					'owner' => 'x',
					'repo'  => 'y',
					'ref'   => 'main',
				),
			)
		);

		update_option( 'wp_mcp_ai_settings', array( 'github_access_token' => 'ghs_settings123' ) );

		$this->http_stubs = array(
			'api.github.com/repos/x/y/git/trees' => array(
				'body' => wp_json_encode(
					array(
						'tree' => array(
							array(
								'type' => 'blob',
								'path' => 'skills/demo/SKILL.md',
								'sha'  => str_repeat( 'a', 40 ),
							),
						),
					)
				),
			),
		);

		$manifest = $svc->get_manifest( 'tok-settings', true );

		$this->assertNotWPError( $manifest );
		$this->assertNotEmpty( $this->captured_headers );
		foreach ( $this->captured_headers as $url => $headers ) {
			$found = false;
			foreach ( $headers as $key => $value ) {
				if ( 'authorization' === strtolower( $key ) && 'Bearer ghs_settings123' === $value ) {
					$found = true;
					break;
				}
			}
			$this->assertTrue( $found, 'Expected settings-token header missing for ' . $url );
		}
	}

	/**
	 * Enrichment stops on a 403/429 instead of burning the anonymous budget,
	 * and the skill list itself stays complete.
	 */
	public function test_enrichment_stops_after_rate_limit_response() {
		update_option( 'wp_mcp_ai_settings', array() ); // Force the anonymous path.

		$svc = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$svc->save_sources(
			array(
				array(
					'id'    => 'rl',
					'owner' => 'x',
					'repo'  => 'y',
					'ref'   => 'main',
				),
			)
		);

		$tree = array( 'tree' => array() );
		foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $slug ) {
			$tree['tree'][] = array(
				'type' => 'blob',
				'path' => 'skills/' . $slug . '/SKILL.md',
				'sha'  => str_repeat( $slug, 40 ),
			);
		}

		$this->http_stubs = array(
			'api.github.com/repos/x/y/git/trees' => array( 'body' => wp_json_encode( $tree ) ),
			'raw.githubusercontent.com/x/y/main/skills/a/SKILL.md' => array(
				'code' => 403,
				'body' => '',
			),
		);

		$manifest = $svc->get_manifest( 'rl', true );
		$this->assertNotWPError( $manifest );
		// All five skills stay listed despite the rate-limit response.
		$this->assertCount( 5, $manifest['skills'] );

		// Only the first description fetch happened — the 403 stopped the loop.
		$description_fetches = 0;
		foreach ( $this->captured_headers as $url => $headers ) {
			if ( false !== strpos( $url, 'raw.githubusercontent.com/x/y/main/skills/' ) ) {
				++$description_fetches;
			}
		}
		$this->assertSame( 1, $description_fetches );
	}

	/**
	 * Install fetches the skill subtree: reference files under the skill folder
	 * are written alongside SKILL.md, while executable and dot-path sidecars
	 * are excluded by design.
	 */
	public function test_install_fetches_subfolder_sidecars() {
		$svc = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$svc->save_sources(
			array(
				array(
					'id'    => 'sc',
					'owner' => 'x',
					'repo'  => 'y',
					'ref'   => 'main',
				),
			)
		);

		$skill_md = "---\nname: sidecar-demo\ndescription: Sidecar demo skill.\nlicense: MIT\n---\n\n# Sidecar Demo\n";

		$tree = array(
			'tree' => array(
				array(
					'type' => 'blob',
					'path' => 'skills/sidecar-demo/SKILL.md',
					'sha'  => str_repeat( 'a', 40 ),
				),
				array(
					'type' => 'tree',
					'path' => 'skills/sidecar-demo/references',
				),
				array(
					'type' => 'blob',
					'path' => 'skills/sidecar-demo/references/guide.md',
					'sha'  => str_repeat( 'b', 40 ),
				),
				array(
					'type' => 'blob',
					'path' => 'skills/sidecar-demo/scripts/build.js',
					'sha'  => str_repeat( 'c', 40 ),
				),
				array(
					'type' => 'blob',
					'path' => 'skills/sidecar-demo/.hidden/secret.md',
					'sha'  => str_repeat( 'd', 40 ),
				),
				array(
					'type' => 'blob',
					'path' => 'skills/other/SKILL.md',
					'sha'  => str_repeat( 'e', 40 ),
				),
			),
		);

		$this->http_stubs = array(
			'/x/y/main/catalogue.json'           => array(
				'body' => wp_json_encode(
					array(
						'skills' => array(
							array(
								'name' => 'sidecar-demo',
								'path' => 'skills/sidecar-demo',
							),
						),
					)
				),
			),
			'api.github.com/repos/x/y/git/trees' => array( 'body' => wp_json_encode( $tree ) ),
			'raw.githubusercontent.com/x/y/main/skills/sidecar-demo/SKILL.md' => array( 'body' => $skill_md ),
			'raw.githubusercontent.com/x/y/main/skills/sidecar-demo/references/guide.md' => array( 'body' => 'guide content' ),
		);

		$result = $svc->install_from_catalogue( 'sc', 'skills/sidecar-demo' );
		$this->assertNotWPError( $result );
		$this->assertSame( 'sidecar-demo', $result['name'] );

		$registry  = WP_MCP_AI_Skill_Registry::instance();
		$skill_dir = trailingslashit( $registry->get_skills_dir() ) . 'sidecar-demo';

		$this->assertFileExists( $skill_dir . '/SKILL.md' );
		$this->assertFileExists( $skill_dir . '/references/guide.md' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture read from the isolated uploads dir.
		$this->assertSame( 'guide content', file_get_contents( $skill_dir . '/references/guide.md' ) );
		// Executable + dot-path sidecars are excluded by design (the registry
		// allowlist would reject them anyway).
		$this->assertFileDoesNotExist( $skill_dir . '/scripts/build.js' );
		$this->assertFileDoesNotExist( $skill_dir . '/.hidden/secret.md' );
	}

	/**
	 * Token-provider fixture for the token-header test.
	 *
	 * @return string
	 */
	public function return_test_github_token() {
		return 'ghp_test123';
	}

	/* ─── REST: permissions ──────────────────────────────────────────────── */

	/**
	 * Anonymous users cannot list catalogue sources.
	 */
	public function test_rest_get_sources_requires_admin() {
		wp_set_current_user( 0 );
		$req      = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/catalogues' );
		$response = rest_do_request( $req );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Admins receive the seeded source list from the REST endpoint.
	 */
	public function test_rest_get_sources_returns_seeded_list_for_admin() {
		wp_set_current_user( $this->admin_id );
		$req      = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/catalogues' );
		$response = rest_do_request( $req );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertNotEmpty( $data );
		$this->assertSame( 'wp-agent-skills', $data[0]['id'] );
	}

	/**
	 * The install endpoint returns a 4xx for unknown sources.
	 */
	public function test_rest_install_returns_error_for_unknown_source() {
		wp_set_current_user( $this->admin_id );
		$req = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/catalogues/no-such-source/install' );
		$req->set_body_params( array( 'path' => 'demo' ) );
		$response = rest_do_request( $req );
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}
}
