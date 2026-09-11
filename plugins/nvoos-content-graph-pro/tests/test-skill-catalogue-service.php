<?php
/**
 * Characterization tests for the ported skill-catalogue service + REST
 * controller (Wave F1, sub-cluster 3b-1).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the public catalogue + REST surface is asserted.
 * - Standalone matrix (base plugin absent): the ported classes in
 *   `src/services/class-wp-mcp-ai-skill-catalogue-service.php` +
 *   `src/rest/class-wp-mcp-ai-skill-catalogue-rest-controller.php` are
 *   asserted in full, including the per-mode registry seam.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Skill catalogue service tests.
 */
class Test_Skill_Catalogue_Service extends WP_UnitTestCase {

	/**
	 * The singleton contract must hold in both matrices.
	 */
	public function test_instance_is_singleton(): void {
		$this->assertSame(
			WP_MCP_AI_Skill_Catalogue_Service::instance(),
			WP_MCP_AI_Skill_Catalogue_Service::instance()
		);
	}

	/**
	 * Byte-identical constants: option, transient prefix, and cron hook.
	 */
	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'wp_mcp_ai_skill_catalogue_sources', WP_MCP_AI_Skill_Catalogue_Service::OPTION_SOURCES );
		$this->assertSame( 'wp_mcp_ai_skill_cat_', WP_MCP_AI_Skill_Catalogue_Service::TRANSIENT_PREFIX );
		$this->assertSame( 'wp_mcp_ai_skill_catalogue_refresh', WP_MCP_AI_Skill_Catalogue_Service::CRON_HOOK );
		$this->assertSame( DAY_IN_SECONDS, WP_MCP_AI_Skill_Catalogue_Service::DEFAULT_MANIFEST_TTL );
		$this->assertSame( 4 * 1024 * 1024, WP_MCP_AI_Skill_Catalogue_Service::MAX_RESPONSE_BYTES );
		$this->assertSame( 20, WP_MCP_AI_Skill_Catalogue_Service::HTTP_TIMEOUT );
	}

	/**
	 * Default sources must carry the full source shape with the known seed.
	 */
	public function test_default_sources_shape(): void {
		$sources = WP_MCP_AI_Skill_Catalogue_Service::get_default_sources();
		$this->assertNotEmpty( $sources );

		$ids = wp_list_pluck( $sources, 'id' );
		$this->assertContains( 'wp-agent-skills', $ids );

		foreach ( $sources as $source ) {
			foreach ( array( 'id', 'label', 'type', 'owner', 'repo', 'ref', 'manifest_path' ) as $key ) {
				$this->assertArrayHasKey( $key, $source );
			}
		}
	}

	/**
	 * Register/save/get/unregister source flows must round-trip.
	 */
	public function test_source_registration_round_trip(): void {
		$service = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$initial = $service->get_sources();
		$initial = is_array( $initial ) ? $initial : array();

		$source = array(
			'id'            => 'test-catalogue',
			'label'         => 'Test Catalogue',
			'type'          => 'github',
			'owner'         => 'example',
			'repo'          => 'skills',
			'ref'           => 'main',
			'manifest_path' => 'catalogue.json',
		);

		$saved = $service->save_sources( array_merge( $initial, array( $source ) ) );
		$this->assertContains( 'test-catalogue', wp_list_pluck( $saved, 'id' ) );

		$loaded = $service->get_sources();
		$this->assertContains( 'test-catalogue', wp_list_pluck( $loaded, 'id' ) );

		$fetched = $service->get_source( 'test-catalogue' );
		$this->assertSame( 'example', $fetched['owner'] );
		$this->assertSame( 'skills', $fetched['repo'] );

		// Restore the original source set (shared option discipline).
		$service->save_sources( $initial );
	}

	/**
	 * Unknown sources must produce the byte-identical error code.
	 */
	public function test_unknown_source_errors(): void {
		$service = WP_MCP_AI_Skill_Catalogue_Service::instance();

		$manifest = $service->get_manifest( 'no-such-source' );
		$this->assertInstanceOf( 'WP_Error', $manifest );
		$this->assertSame( 'wp_mcp_ai_skill_catalogue_unknown_source', $manifest->get_error_code() );

		$install = $service->install_from_catalogue( 'no-such-source', 'some/skill' );
		$this->assertInstanceOf( 'WP_Error', $install );
		$this->assertSame( 'wp_mcp_ai_skill_catalogue_unknown_source', $install->get_error_code() );
	}

	/**
	 * has_update must degrade to null for unknown sources.
	 */
	public function test_has_update_unknown_source_is_null(): void {
		$this->assertNull( WP_MCP_AI_Skill_Catalogue_Service::instance()->has_update( 'no-such-source', 'any-skill' ) );
	}

	/**
	 * The SSRF-safe fetch must reject insecure URLs with the byte-identical
	 * https-required error.
	 */
	public function test_safe_get_rejects_http_urls(): void {
		$result = WP_MCP_AI_Skill_Catalogue_Service::instance()->safe_get( 'http://example.com/skill.md' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_skill_catalogue_https_required', $result->get_error_code() );
	}

	/**
	 * The catalogue REST routes must register with the byte-identical
	 * namespace and require manage_options.
	 */
	public function test_catalogue_routes_register_and_gate(): void {
		// Standalone: the skills-manager init lands with sub-cluster 3b-3 —
		// construct the controller manually so its rest_api_init hook fires.
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			new WP_MCP_AI_Skill_Catalogue_REST_Controller();
		}

		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/catalogues', $routes );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/catalogues/(?P<id>[a-z0-9][a-z0-9_-]{0,62}[a-z0-9]?)/skills', $routes );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/catalogues/(?P<id>[a-z0-9][a-z0-9_-]{0,62}[a-z0-9]?)/install', $routes );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/catalogues/(?P<id>[a-z0-9][a-z0-9_-]{0,62}[a-z0-9]?)/refresh', $routes );

		// Subscribers must be rejected by the capability gate.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/catalogues' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Standalone only: the registry seam must resolve to the Platform
	 * addon's ported SkillRegistry.
	 */
	public function test_registry_class_seam_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base registry class is used directly.' );
		}

		require_once __DIR__ . '/helpers/test-skill-catalogue-seam.php';

		$this->assertSame(
			\NvoosContentGraphAiPlatform\Skills\SkillRegistry::class,
			\NvoosContentGraphPro\Tests\Test_Skill_Catalogue_Seam::registry_class()
		);
	}
}
