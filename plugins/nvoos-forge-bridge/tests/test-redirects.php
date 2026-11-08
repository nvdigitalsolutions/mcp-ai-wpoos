<?php
// phpcs:ignoreFile
/**
 * Stored-redirects export tests — provider adapters, normalization, gating,
 * bounding, and the redaction contract.
 *
 * The provider tables are created once per class (DDL is not transactional)
 * and emptied per test (DML is rolled back by the WP test suite).
 *
 * @package NvoosForgeBridge
 */

use NvoosForgeBridge\Settings;

/**
 * Redirect export suite.
 */
class Test_Forge_Bridge_Redirects extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Create the provider tables (schema mirrors the real plugins).
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}redirection_items (
				id int(11) unsigned NOT NULL AUTO_INCREMENT,
				url mediumtext NOT NULL,
				match_url VARCHAR(2000) DEFAULT NULL,
				match_data TEXT DEFAULT NULL,
				regex INT(11) unsigned NOT NULL DEFAULT 0,
				position INT(11) unsigned NOT NULL DEFAULT 0,
				last_count INT(10) unsigned NOT NULL DEFAULT 0,
				last_access datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
				group_id INT(11) NOT NULL DEFAULT 0,
				status enum('enabled','disabled') NOT NULL DEFAULT 'enabled',
				action_type VARCHAR(20) NOT NULL,
				action_code INT(11) unsigned NOT NULL,
				action_data MEDIUMTEXT DEFAULT NULL,
				match_type VARCHAR(20) NOT NULL,
				title TEXT DEFAULT NULL,
				PRIMARY KEY (id)
			) {$charset}"
		);
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}redirection_groups (
				id int(11) unsigned NOT NULL AUTO_INCREMENT,
				name VARCHAR(50) NOT NULL,
				tracking INT(11) NOT NULL DEFAULT 1,
				module_id INT(11) unsigned NOT NULL DEFAULT 0,
				status enum('enabled','disabled') NOT NULL DEFAULT 'enabled',
				position INT(11) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (id)
			) {$charset}"
		);
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rank_math_redirections (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				sources text NOT NULL,
				url_to text NOT NULL,
				header_code smallint(4) unsigned NOT NULL,
				hits bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(25) NOT NULL DEFAULT 'active',
				created datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
				updated datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
				last_accessed datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
				PRIMARY KEY (id)
			) {$charset}"
		);
	}

	/**
	 * Drop the provider tables.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}redirection_items" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}redirection_groups" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rank_math_redirections" );
		parent::tearDownAfterClass();
	}

	/**
	 * Reset options + rows, seed one enabled group, boot the REST server.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		foreach ( Settings::option_keys() as $option ) {
			delete_option( $option );
		}

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}redirection_items" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}redirection_groups" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}rank_math_redirections" );
		$wpdb->insert(
			$wpdb->prefix . 'redirection_groups',
			array(
				'id'     => 1,
				'name'   => 'Redirections',
				'status' => 'enabled',
			)
		);

		global $wp_rest_server;
		$wp_rest_server = null;
		$this->server   = rest_get_server();
	}

	/**
	 * Teardown.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'nvoos_forge_bridge_redirect_providers' );
		parent::tearDown();
	}

	/**
	 * Insert a Redirection item.
	 *
	 * @param array<string, mixed> $overrides Column overrides.
	 * @return void
	 */
	private function add_redirection( array $overrides = array() ): void {
		global $wpdb;
		$row = array_merge(
			array(
				'url'         => '/old/',
				'regex'       => 0,
				'position'    => 0,
				'group_id'    => 1,
				'status'      => 'enabled',
				'action_type' => 'url',
				'action_code' => 301,
				'action_data' => wp_json_encode( array( 'url' => '/new/' ) ),
				'match_type'  => 'url',
			),
			$overrides
		);
		$wpdb->insert( $wpdb->prefix . 'redirection_items', $row );
	}

	/**
	 * Insert a Rank Math redirection.
	 *
	 * @param string $sources Serialized/JSON sources.
	 * @param string $target  Target.
	 * @param int    $code    Header code.
	 * @param string $status  Status.
	 * @return void
	 */
	private function add_rankmath( string $sources, string $target, int $code = 301, string $status = 'active' ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'rank_math_redirections',
			array(
				'sources'     => $sources,
				'url_to'      => $target,
				'header_code' => $code,
				'status'      => $status,
			)
		);
	}

	/**
	 * Dispatch the redirects route.
	 *
	 * @param array<string, string> $headers Extra headers.
	 * @return WP_REST_Response
	 */
	private function dispatch( array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/nvoos-forge/v1/redirects' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * The route registers under nvoos-forge/v1.
	 *
	 * @return void
	 */
	public function test_route_registered(): void {
		$paths = array_keys( $this->server->get_routes( 'nvoos-forge/v1' ) );
		$this->assertContains( '/nvoos-forge/v1/redirects', $paths );
	}

	/**
	 * Opt-in: disabled by default, and never an error.
	 *
	 * @return void
	 */
	public function test_disabled_by_default(): void {
		$this->add_redirection();
		$response = $this->dispatch();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['enabled'] );
		$this->assertSame( 0, $data['count'] );
		$this->assertSame( array(), $data['redirects'] );
	}

	/**
	 * Enabled but no provider installed — an empty list, not a 500.
	 *
	 * @return void
	 */
	public function test_no_provider_is_empty_not_an_error(): void {
		update_option( Settings::OPTION_REDIRECTS_ENABLED, '1' );
		add_filter( 'nvoos_forge_bridge_redirect_providers', '__return_empty_array' );

		$response = $this->dispatch();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['enabled'] );
		$this->assertFalse( $data['available'] );
		$this->assertSame( 0, $data['count'] );
	}

	/**
	 * Redirection: enabled + exact URL only; disabled, regex, non-URL match
	 * types, and query-scoped sources are counted and skipped.
	 *
	 * @return void
	 */
	public function test_redirection_export_filters_and_counts(): void {
		update_option( Settings::OPTION_REDIRECTS_ENABLED, '1' );

		$this->add_redirection( array( 'url' => '/old/', 'action_data' => wp_json_encode( array( 'url' => '/new/' ) ) ) );
		$this->add_redirection(
			array(
				'url'         => '/legacy/',
				'action_code' => 302,
				'action_data' => wp_json_encode( array( 'url' => 'https://elsewhere.example/landing/' ) ),
			)
		);
		$this->add_redirection(
			array(
				'url'         => '/gone/',
				'action_type' => 'error',
				'action_code' => 410,
				'action_data' => null,
			)
		);

		// Skipped: disabled item, regex item, agent match, query-scoped source.
		$this->add_redirection( array( 'url' => '/draft/', 'status' => 'disabled' ) );
		$this->add_redirection( array( 'url' => '/^regex-(.*)/', 'regex' => 1 ) );
		$this->add_redirection( array( 'url' => '/from-agent/', 'match_type' => 'agent' ) );
		$this->add_redirection( array( 'url' => '/search/?s=old' ) );

		$data = $this->dispatch()->get_data();

		$this->assertTrue( $data['available'] );
		$this->assertSame( 3, $data['count'] );
		$this->assertSame( 3, $data['providers']['redirection'] );
		$this->assertSame( 1, $data['skipped']['regex'] );
		$this->assertSame( 1, $data['skipped']['match'] );
		$this->assertSame( 1, $data['skipped']['query'] );
		$this->assertSame(
			array(
				array(
					'source' => '/gone/',
					'target' => '',
					'type'   => 410,
				),
				array(
					'source' => '/legacy/',
					'target' => 'https://elsewhere.example/landing/',
					'type'   => 302,
				),
				array(
					'source' => '/old/',
					'target' => '/new/',
					'type'   => 301,
				),
			),
			$data['redirects']
		);
	}

	/**
	 * Redirection page-groups are honoured — a disabled group's items are not
	 * live on WordPress, so they are not exported.
	 *
	 * @return void
	 */
	public function test_redirection_disabled_group_is_skipped(): void {
		global $wpdb;
		update_option( Settings::OPTION_REDIRECTS_ENABLED, '1' );
		$wpdb->insert(
			$wpdb->prefix . 'redirection_groups',
			array(
				'id'     => 2,
				'name'   => 'Archive',
				'status' => 'disabled',
			)
		);
		$this->add_redirection( array( 'group_id' => 2 ) );

		$data = $this->dispatch()->get_data();
		$this->assertSame( 0, $data['count'] );
	}

	/**
	 * Rank Math: only exact comparisons are portable; `sources` decodes from
	 * both PHP-serialized (historical) and JSON (current) shapes.
	 *
	 * @return void
	 */
	public function test_rankmath_export_exact_only(): void {
		update_option( Settings::OPTION_REDIRECTS_ENABLED, '1' );
		add_filter(
			'nvoos_forge_bridge_redirect_providers',
			static function () {
				return array( 'rankmath' );
			}
		);

		$this->add_rankmath(
			maybe_serialize(
				array(
					array(
						'pattern'    => 'legacy-no-slash',
						'comparison' => 'exact',
					),
					array(
						'pattern'    => 'contains-this',
						'comparison' => 'contains',
					),
				)
			),
			'/modern/'
		);
		$this->add_rankmath(
			wp_json_encode(
				array(
					array(
						'pattern'    => '/json-shape/',
						'comparison' => 'exact',
					),
				)
			),
			home_url( '/landing/' ),
			307
		);
		$this->add_rankmath( maybe_serialize( array( array( 'pattern' => '/draft/', 'comparison' => 'exact' ) ) ), '/x/', 301, 'inactive' );

		$data = $this->dispatch()->get_data();

		$this->assertSame( 2, $data['count'] );
		$this->assertSame(
			array(
				array(
					'source' => '/json-shape/',
					'target' => '/landing/',
					'type'   => 307,
				),
				array(
					// Rank Math stores the pattern without slashes; only the
					// leading slash is added, the rest is faithful.
					'source' => '/legacy-no-slash',
					'target' => '/modern/',
					'type'   => 301,
				),
			),
			$data['redirects']
		);
	}

	/**
	 * A source declared by both providers resolves once (Redirection wins by
	 * adapter order) and the conflict is reported.
	 *
	 * @return void
	 */
	public function test_duplicate_source_is_deduped(): void {
		update_option( Settings::OPTION_REDIRECTS_ENABLED, '1' );

		$this->add_redirection( array( 'url' => '/same/', 'action_data' => wp_json_encode( array( 'url' => '/from-redirection/' ) ) ) );
		$this->add_rankmath( maybe_serialize( array( array( 'pattern' => '/same/', 'comparison' => 'exact' ) ) ), '/from-rankmath/' );

		$data = $this->dispatch()->get_data();

		$this->assertSame( 1, $data['count'] );
		$this->assertSame( '/from-redirection/', $data['redirects'][0]['target'] );
		$this->assertSame( 1, $data['skipped']['duplicate'] );
	}

	/**
	 * The optional token gates the route and compares in constant time.
	 *
	 * @return void
	 */
	public function test_token_gate(): void {
		update_option( Settings::OPTION_REDIRECTS_ENABLED, '1' );
		update_option( Settings::OPTION_REDIRECTS_TOKEN, 'export-secret' );
		$this->add_redirection();

		$denied = $this->dispatch();
		$this->assertSame( 401, $denied->get_status() );
		$this->assertSame( 'nvoos_forge_bridge_redirects_forbidden', $denied->get_data()['code'] );

		$header = $this->dispatch( array( 'X-Forge-Redirects-Token' => 'export-secret' ) );
		$this->assertSame( 200, $header->get_status() );
		$this->assertSame( 1, $header->get_data()['count'] );

		$bearer = $this->dispatch( array( 'Authorization' => 'Bearer export-secret' ) );
		$this->assertSame( 200, $bearer->get_status() );

		$wrong = $this->dispatch( array( 'X-Forge-Redirects-Token' => 'nope' ) );
		$this->assertSame( 401, $wrong->get_status() );
	}

	/**
	 * The export is bounded and says so.
	 *
	 * @return void
	 */
	public function test_export_is_bounded(): void {
		global $wpdb;
		update_option( Settings::OPTION_REDIRECTS_ENABLED, '1' );

		for ( $i = 1; $i <= 501; $i++ ) {
			$this->add_redirection(
				array(
					'url'      => '/bulk-' . $i . '/',
					'position' => $i,
				)
			);
		}

		$data = $this->dispatch()->get_data();
		$this->assertTrue( $data['truncated'] );
		$this->assertSame( 500, $data['count'] );
	}

	/**
	 * Redaction: rules carry only source/target/type — no ids, hit counts,
	 * group ids, timestamps, or raw `action_data`.
	 *
	 * @return void
	 */
	public function test_response_is_redacted(): void {
		update_option( Settings::OPTION_REDIRECTS_ENABLED, '1' );
		$this->add_redirection();

		$data    = $this->dispatch()->get_data();
		$encoded = wp_json_encode( $data );

		$this->assertSame( array( 'source', 'target', 'type' ), array_keys( $data['redirects'][0] ) );
		$this->assertStringNotContainsString( 'action_data', $encoded );
		$this->assertStringNotContainsString( 'group_id', $encoded );
		$this->assertStringNotContainsString( 'last_access', $encoded );
	}
}
