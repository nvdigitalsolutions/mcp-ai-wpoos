<?php
/**
 * Tests the WordPress console endpoint capability manifest.
 *
 * The Complete Console builds one endpoint map for both backends (Proposal
 * 090-B, ADR-6). The Laravel runtime answers from its router; this suite pins
 * the WordPress twin (`WP_MCP_AI_Endpoint_Capabilities`) against the shared
 * contract (`docker/laravel/app-skeleton/contracts/console-endpoints.json`)
 * so a route move, a new key, or a reason typo fails here instead of in a
 * browser.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 * @since     1.2.22
 */
class WP_MCP_AI_REST_Console_Endpoints_Test extends WP_UnitTestCase {

	/**
	 * Register the plugin's REST routes for the test case.
	 */
	public function setUp(): void {
		parent::setUp();

		// Fresh server — rest_get_server() fires rest_api_init, which is the
		// only point at which the plugin's route callbacks run.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * Tear down the shared server so another test's routes cannot leak in.
	 */
	public function tearDown(): void {
		$GLOBALS['wp_rest_server'] = null;

		parent::tearDown();
	}

	/**
	 * Load the shared console contract asserted by both runtimes.
	 *
	 * @return array
	 */
	private function contract() {
		$path = dirname( __DIR__, 2 ) . '/docker/laravel/app-skeleton/contracts/console-endpoints.json';

		if ( ! file_exists( $path ) ) {
			$this->markTestSkipped( 'The shared console contract is not present in this tree.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture, not a remote URL.
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $decoded, 'contracts/console-endpoints.json must be valid JSON.' );

		return $decoded;
	}

	/**
	 * The manifest covers exactly the shared contract's route-backed keys.
	 */
	public function test_manifest_covers_the_shared_contract() {
		$contract = $this->contract();
		$manifest = WP_MCP_AI_Endpoint_Capabilities::manifest();

		$expected = (array) $contract['route_backed'];
		$actual   = array_keys( $manifest['endpoints'] );

		sort( $expected );
		sort( $actual );

		$this->assertSame( $expected, $actual, 'The WordPress manifest and the console contract disagree on the key set.' );
		$this->assertSame( 2, $manifest['version'], 'The capability manifest is v2 (endpoints + reasons + gaps).' );

		$classified = array_keys( $manifest['reasons'] );
		sort( $classified );

		$this->assertSame( $expected, $classified, 'Every route-backed key needs exactly one reason.' );
	}

	/**
	 * The route fragments match the shared golden file.
	 */
	public function test_fragments_match_the_contract() {
		$contract = $this->contract();
		$expected = (array) ( $contract['fragments']['wordpress'] ?? array() );
		$actual   = WP_MCP_AI_Endpoint_Capabilities::ROUTES;

		ksort( $expected );
		ksort( $actual );

		$this->assertSame(
			$expected,
			$actual,
			// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- contract key name.
			'WP_MCP_AI_Endpoint_Capabilities::ROUTES and fragments.wordpress disagree.'
		);
	}

	/**
	 * Every reason comes from the shared vocabulary.
	 */
	public function test_every_key_has_a_known_reason() {
		$manifest = WP_MCP_AI_Endpoint_Capabilities::manifest();
		$unknown  = array_values(
			array_diff( array_values( $manifest['reasons'] ), WP_MCP_AI_Endpoint_Capabilities::REASON_CODES )
		);

		$this->assertSame( array(), $unknown, 'Unknown reason codes: ' . implode( ', ', $unknown ) );
	}

	/**
	 * The invariant the manifest exists for: nothing this runtime is meant to
	 * serve is silently missing. An unavailable key is unavailable on purpose.
	 */
	public function test_no_route_supplied_endpoint_is_a_gap() {
		$manifest = WP_MCP_AI_Endpoint_Capabilities::manifest();

		$this->assertSame(
			array(),
			$manifest['gaps'],
			'Unexplained endpoint gaps: ' . implode( ', ', $manifest['gaps'] )
		);
	}

	/**
	 * The WordPress classification is the contract's canonical map plus the
	 * documented overrides (mediaCc, professions, orgTeams).
	 */
	public function test_reasons_match_the_contract_merge() {
		$contract = $this->contract();
		$expected = array_merge(
			(array) ( $contract['reasons'] ?? array() ),
			(array) ( $contract['wordpress_reasons'] ?? array() )
		);
		$actual   = WP_MCP_AI_Endpoint_Capabilities::REASONS;

		ksort( $expected );
		ksort( $actual );

		$this->assertSame(
			$expected,
			$actual,
			'WP_MCP_AI_Endpoint_Capabilities::REASONS and the contract merge disagree.'
		);
	}

	/**
	 * The WordPress overrides are deliberate and read correctly.
	 */
	public function test_wordpress_overrides_are_applied() {
		$reasons = WP_MCP_AI_Endpoint_Capabilities::reasons();

		// WordPress computes media health locally; it needs no worker.
		$this->assertSame( 'route', $reasons['mediaCc'] );
		// The catalogue is served on both runtimes (090-p W1).
		$this->assertSame( 'route', $reasons['professions'] );
		// The org-team contract is served on both runtimes (090-p W7).
		$this->assertSame( 'route', $reasons['orgTeams'] );
		// The admin page points at a route no controller registers — the
		// deferred communications-toolkit family, not a broken route.
		$this->assertSame( 'toolkit-shaped', $reasons['chatChannels'] );
	}

	/**
	 * Regression pin for the drift this manifest fixed: WordPress serves the
	 * toolkit engine at `mcp-ai-pro/v1/toolkit-manifests`, so the console must
	 * not blank the key. When Pro is inactive the key reports `not-installed`
	 * instead of becoming a gap.
	 */
	public function test_toolkits_is_served_when_pro_is_active() {
		$map     = WP_MCP_AI_Endpoint_Capabilities::map();
		$reasons = WP_MCP_AI_Endpoint_Capabilities::reasons();

		if ( defined( 'WP_MCP_AI_PRO_FILE' ) ) {
			$this->assertTrue( $map['toolkits'], 'WordPress registers mcp-ai-pro/v1/toolkit-manifests.' );
			$this->assertSame( 'route', $reasons['toolkits'] );
		} else {
			$this->assertFalse( $map['toolkits'] );
			$this->assertSame( 'not-installed', $reasons['toolkits'] );
		}

		$this->assertNotContains( 'toolkits', WP_MCP_AI_Endpoint_Capabilities::gaps() );
	}

	/**
	 * A Pro-supplied key never becomes a gap on a Base install.
	 */
	public function test_pro_keys_are_not_gaps_when_pro_is_inactive() {
		if ( defined( 'WP_MCP_AI_PRO_FILE' ) ) {
			$this->markTestSkipped( 'Pro is active in this suite; the Base downgrade does not apply.' );
		}

		$reasons = WP_MCP_AI_Endpoint_Capabilities::reasons();

		$this->assertSame( 'not-installed', $reasons['scheduleManager'] );
		$this->assertSame( 'not-installed', $reasons['toolkits'] );
		$this->assertNotContains( 'scheduleManager', WP_MCP_AI_Endpoint_Capabilities::gaps() );
	}

	/**
	 * The matcher is boundary-aware, so a sibling route cannot satisfy a key.
	 *
	 * @dataProvider matcher_cases
	 *
	 * @param string $route    Registered route pattern.
	 * @param string $fragment Endpoint fragment.
	 * @param bool   $expected Whether the route satisfies the fragment.
	 */
	public function test_matcher_is_boundary_aware( $route, $fragment, $expected ) {
		$this->assertSame( $expected, WP_MCP_AI_Endpoint_Capabilities::matches( $route, $fragment ) );
	}

	/**
	 * Matcher cases.
	 *
	 * @return array[]
	 */
	public static function matcher_cases() {
		return array(
			'exact'                       => array( '/mcp-ai/v1/tools', 'mcp-ai/v1/tools', true ),
			'regex tail'                  => array( '/mcp-ai-pro/v1/remote-sites/(?P<id>[\w-]+)', 'mcp-ai-pro/v1/remote-sites', true ),
			'child route'                 => array( '/wp/v2/media/(?P<id>[\d]+)', 'wp/v2', true ),
			'prefix sibling is not a hit' => array( '/mcp-ai/v1/chat-transcripts', 'mcp-ai/v1/chat', false ),
			'prefix sibling with regex'   => array( '/mcp-ai/v1/chat-transcripts/(?P<session_key>[^/]+)', 'mcp-ai/v1/chat', false ),
			'namespace root is not a hit' => array( '/mcp-ai-pro/v1', 'mcp-ai-pro/v1/schedules', false ),
			'alternation tail'            => array( '/mcp-ai/v1/security/(posture|snapshots)', 'mcp-ai/v1/security', true ),
			'empty fragment'              => array( '/mcp-ai/v1/tools', '', false ),
		);
	}

	/**
	 * A discovered connected app flips its key and publishes its base.
	 *
	 * Class C (090-i Phase 4): the product is on its own origin, so no route is
	 * registered here — the probe answer is the evidence the surface exists.
	 */
	public function test_connected_apps_flip_the_key_and_publish_the_url() {
		$manifest                                = WP_MCP_AI_Endpoint_Capabilities::manifest();
		$manifest['endpoints']['docsHub']        = false;
		$manifest['endpoints']['documentEditor'] = false;

		$merged = WP_MCP_AI_Endpoint_Capabilities::apply_connected_apps(
			$manifest,
			array( 'docsHub' => 'https://docs.example.test/wp-json/nvoos-docs/v1' )
		);

		$this->assertTrue( $merged['endpoints']['docsHub'], 'a reached product is served' );
		$this->assertFalse( $merged['endpoints']['documentEditor'], 'an unprobed key is untouched' );
		$this->assertSame(
			array( 'docsHub' => 'https://docs.example.test/wp-json/nvoos-docs/v1' ),
			$merged['endpoint_urls']
		);
		$this->assertSame( array(), $merged['gaps'], 'a flipped key is never a gap' );
	}

	/**
	 * With nothing discovered, the manifest is returned untouched.
	 */
	public function test_connected_apps_are_a_no_op_when_empty() {
		$manifest = WP_MCP_AI_Endpoint_Capabilities::manifest();
		$merged   = WP_MCP_AI_Endpoint_Capabilities::apply_connected_apps( $manifest, array() );

		$this->assertSame( $manifest, $merged );
		$this->assertArrayNotHasKey( 'endpoint_urls', $merged );
	}

	/**
	 * An unknown key cannot be injected into the manifest.
	 */
	public function test_connected_apps_cannot_add_an_unknown_key() {
		$merged = WP_MCP_AI_Endpoint_Capabilities::apply_connected_apps(
			WP_MCP_AI_Endpoint_Capabilities::manifest(),
			array( 'evilProduct' => 'https://evil.example.test' )
		);

		$this->assertArrayNotHasKey( 'evilProduct', $merged['endpoints'] );
	}

	/**
	 * GET /features publishes discovery results and keeps both maps in step.
	 *
	 * The full wiring: loader → features controller → registry → response.
	 * HTTP is stubbed and the host whitelisted, so no network runs.
	 */
	public function test_features_publishes_discovered_connected_apps() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$allow  = static function ( $hosts ) {
			$hosts[] = 'docs.example.test';
			return $hosts;
		};
		$config = static function () {
			return array( 'docsHub' => array( 'base' => 'https://docs.example.test/wp-json/nvoos-docs/v1' ) );
		};
		$http   = static function () {
			return array(
				'headers'  => array(),
				'body'     => '{}',
				'response' => array(
					'code'    => 200,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'wp_mcp_ai_http_allowed_host', $allow );
		add_filter( WP_MCP_AI_Connected_App_Registry::FILTER, $config );
		add_filter( 'pre_http_request', $http );
		WP_MCP_AI_Connected_App_Registry::reset_instance();

		try {
			$response = rest_do_request( new WP_REST_Request( 'GET', '/mcp-ai/v1/features' ) );
			$data     = $response->get_data()['data'];

			$this->assertSame( 200, $response->get_status() );
			$this->assertSame(
				array( 'docsHub' => 'https://docs.example.test/wp-json/nvoos-docs/v1' ),
				(array) $data['endpoint_urls']
			);
			$this->assertTrue( ( (array) $data['endpoints'] )['docsHub'], 'the key flips for a served product' );
			$this->assertTrue( $data['features']['docs_hub'], 'the feature flag agrees with the endpoint map' );
			$this->assertSame( array(), $data['endpoint_gaps'] );
		} finally {
			remove_filter( 'wp_mcp_ai_http_allowed_host', $allow );
			remove_filter( WP_MCP_AI_Connected_App_Registry::FILTER, $config );
			remove_filter( 'pre_http_request', $http );
			WP_MCP_AI_Connected_App_Registry::reset_instance();
		}
	}

	/**
	 * GET /features is unchanged when nothing is configured.
	 */
	public function test_features_has_no_endpoint_urls_when_unconfigured() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		WP_MCP_AI_Connected_App_Registry::reset_instance();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/mcp-ai/v1/features' ) );
		$data     = $response->get_data()['data'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'endpoint_urls', $data );
		$this->assertSame( array(), $data['endpoint_gaps'] );
	}
}
