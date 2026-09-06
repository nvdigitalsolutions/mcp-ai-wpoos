<?php
/**
 * Tests for the Phase 1 + Phase 2/4 connector expansion.
 *
 * Covers: capability flags, state store, OAuth broker URL builder + expiry,
 * field mapper resolution + collection mapping, entity resolver normalisation
 * and SAME_AS edge emission, WooCommerce + CSV + Webhook drivers, and webhook
 * signature verification.
 *
 * No live HTTP requests are made.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/bootstrap.php';

/**
 * Test_Graphify_Connectors
 */
class Test_Graphify_Connectors extends WP_UnitTestCase {

	/**
	 * Reset the registry singleton between tests.
	 */
	public function setUp(): void {
		parent::setUp();

		$reflection = new ReflectionClass( 'NV_oOS_Graphify_Remote_Registry' );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		// `initialized` is an instance property, so it must be reset on a
		// concrete object rather than the null static context.
		$fresh       = NV_oOS_Graphify_Remote_Registry::get_instance();
		$initialized = $reflection->getProperty( 'initialized' );
		$initialized->setAccessible( true );
		$initialized->setValue( $fresh, false );
	}

	// -------------------------------------------------------------------------
	// Capability flag tests
	// -------------------------------------------------------------------------

	/**
	 * Base class returns a 5-key capability_flags array of all booleans.
	 */
	public function test_base_class_default_capability_flags() {
		$driver = new NV_oOS_Graphify_Remote_Webhook();
		$flags  = $driver->get_capability_flags();
		$this->assertIsArray( $flags );
		foreach ( array( 'supports_incremental', 'supports_webhooks', 'supports_oauth', 'supports_pagination', 'supports_relationships' ) as $key ) {
			$this->assertArrayHasKey( $key, $flags );
			$this->assertIsBool( $flags[ $key ] );
		}
	}

	/**
	 * WooCommerce driver advertises incremental + relationships.
	 */
	public function test_woocommerce_driver_capability_flags() {
		$driver = new NV_oOS_Graphify_Remote_WooCommerce();
		$flags  = $driver->get_capability_flags();
		$this->assertTrue( $flags['supports_incremental'] );
		$this->assertTrue( $flags['supports_relationships'] );
		$this->assertFalse( $flags['supports_oauth'] );
	}

	/**
	 * Webhook driver advertises webhooks=true and incremental=true.
	 */
	public function test_webhook_driver_capability_flags() {
		$driver = new NV_oOS_Graphify_Remote_Webhook();
		$flags  = $driver->get_capability_flags();
		$this->assertTrue( $flags['supports_webhooks'] );
		$this->assertTrue( $flags['supports_incremental'] );
	}

	// -------------------------------------------------------------------------
	// State store tests
	// -------------------------------------------------------------------------

	/**
	 * State store round-trips a full state array.
	 */
	public function test_state_store_round_trip() {
		$slug = 'unit_test_source_' . wp_generate_password( 6, false );
		NV_oOS_Graphify_Remote_State_Store::replace(
			$slug,
			array(
				'last_cursor' => 'abc',
				'count'       => 42,
			)
		);
		$state = NV_oOS_Graphify_Remote_State_Store::get_state( $slug );
		$this->assertSame( 'abc', $state['last_cursor'] );
		$this->assertSame( 42, $state['count'] );
	}

	/**
	 * State store set() updates a single field without erasing siblings.
	 */
	public function test_state_store_set_preserves_siblings() {
		$slug = 'unit_test_source_' . wp_generate_password( 6, false );
		NV_oOS_Graphify_Remote_State_Store::replace(
			$slug,
			array(
				'a' => 1,
				'b' => 2,
			)
		);
		NV_oOS_Graphify_Remote_State_Store::set( $slug, 'b', 99 );
		$this->assertSame( 1, NV_oOS_Graphify_Remote_State_Store::get( $slug, 'a' ) );
		$this->assertSame( 99, NV_oOS_Graphify_Remote_State_Store::get( $slug, 'b' ) );
	}

	/**
	 * Mark_synced() records last_sync_at and optional cursor.
	 */
	public function test_state_store_mark_synced_writes_cursor() {
		$slug = 'unit_test_source_' . wp_generate_password( 6, false );
		NV_oOS_Graphify_Remote_State_Store::mark_synced( $slug, 'cursor-123' );
		$state = NV_oOS_Graphify_Remote_State_Store::get_state( $slug );
		$this->assertArrayHasKey( 'last_sync_at', $state );
		$this->assertSame( 'cursor-123', $state['last_cursor'] );
	}

	// -------------------------------------------------------------------------
	// OAuth broker tests
	// -------------------------------------------------------------------------

	/**
	 * Build_authorize_url returns a fully-formed URL with all query params.
	 */
	public function test_oauth_broker_build_authorize_url() {
		$url = NV_oOS_Graphify_OAuth_Broker::build_authorize_url(
			array(
				'authorize_url' => 'https://example.com/oauth/authorize',
				'client_id'     => 'cid',
				'redirect_uri'  => 'https://site.example/cb',
				'scope'         => 'read write',
				'state'         => 'csrf-token',
			)
		);
		$this->assertIsString( $url );
		$this->assertStringContainsString( 'response_type=code', $url );
		$this->assertStringContainsString( 'client_id=cid', $url );
		$this->assertStringContainsString( 'state=csrf-token', $url );
		$this->assertStringContainsString( 'scope=', $url );
	}

	/**
	 * Build_authorize_url returns WP_Error when required args are missing.
	 */
	public function test_oauth_broker_build_authorize_url_missing_args() {
		$result = NV_oOS_Graphify_OAuth_Broker::build_authorize_url( array() );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Is_expired returns true for empty / past timestamps and false for future.
	 */
	public function test_oauth_broker_is_expired() {
		$this->assertTrue( NV_oOS_Graphify_OAuth_Broker::is_expired( '' ) );
		$this->assertTrue( NV_oOS_Graphify_OAuth_Broker::is_expired( gmdate( 'c', time() - 3600 ) ) );
		$this->assertFalse( NV_oOS_Graphify_OAuth_Broker::is_expired( gmdate( 'c', time() + 3600 ) ) );
	}

	// -------------------------------------------------------------------------
	// Field mapper tests
	// -------------------------------------------------------------------------

	/**
	 * Resolve() walks dotted paths and numeric indices.
	 */
	public function test_field_mapper_resolve_paths() {
		$record = array(
			'name' => 'Alice',
			'user' => array( 'email' => 'alice@example.com' ),
			'tags' => array( 'red', 'green' ),
		);
		$this->assertSame( 'Alice', NV_oOS_Graphify_Field_Mapper::resolve( $record, 'name' ) );
		$this->assertSame( 'alice@example.com', NV_oOS_Graphify_Field_Mapper::resolve( $record, 'user.email' ) );
		$this->assertSame( 'green', NV_oOS_Graphify_Field_Mapper::resolve( $record, 'tags.1' ) );
		$this->assertNull( NV_oOS_Graphify_Field_Mapper::resolve( $record, 'missing.path' ) );
		$this->assertSame( 'fallback', NV_oOS_Graphify_Field_Mapper::resolve( $record, 'missing.path', 'fallback' ) );
	}

	/**
	 * Map_to_node returns null when required id/label are missing.
	 */
	public function test_field_mapper_map_to_node_validates_required() {
		$node = NV_oOS_Graphify_Field_Mapper::map_to_node(
			array( 'name' => 'x' ),
			array(
				'id'    => 'missing',
				'label' => 'name',
			),
			'src'
		);
		$this->assertNull( $node );
	}

	/**
	 * Map_to_node produces a fully-formed node array including properties.
	 */
	public function test_field_mapper_map_to_node_full() {
		$record = array(
			'id'    => 'p-100',
			'title' => 'Widget',
			'href'  => 'https://example.com/widget',
			'meta'  => array( 'color' => 'blue' ),
		);
		$node   = NV_oOS_Graphify_Field_Mapper::map_to_node(
			$record,
			array(
				'id'         => 'id',
				'label'      => 'title',
				'url'        => 'href',
				'type'       => 'product',
				'properties' => array( 'color' => 'meta.color' ),
			),
			'src'
		);
		$this->assertIsArray( $node );
		$this->assertSame( 'Widget', $node['label'] );
		$this->assertSame( 'product', $node['type'] );
		$this->assertSame( 'src', $node['source_slug'] );
		$this->assertStringStartsWith( 'remote_src_', $node['node_id'] );
		$this->assertSame( 'blue', $node['properties']['color'] );
	}

	/**
	 * Map_collection skips invalid records and keeps valid ones.
	 */
	public function test_field_mapper_map_collection_filters() {
		$records = array(
			array(
				'id'    => 1,
				'title' => 'A',
			),
			array( 'id' => 2 ),                  // missing label.
			array(
				'id'    => 3,
				'title' => 'C',
			),
		);
		$nodes   = NV_oOS_Graphify_Field_Mapper::map_collection(
			$records,
			array(
				'id'    => 'id',
				'label' => 'title',
			),
			'src'
		);
		$this->assertCount( 2, $nodes );
	}

	// -------------------------------------------------------------------------
	// Entity resolver tests
	// -------------------------------------------------------------------------

	/**
	 * Normalize() lower-cases and validates emails.
	 */
	public function test_entity_resolver_normalize_email() {
		$this->assertSame( 'alice@example.com', NV_oOS_Graphify_Entity_Resolver::normalize( 'email', '  Alice@Example.com ' ) );
		$this->assertSame( '', NV_oOS_Graphify_Entity_Resolver::normalize( 'email', 'not-an-email' ) );
	}

	/**
	 * Normalize() canonicalises URLs by stripping scheme and trailing slash.
	 */
	public function test_entity_resolver_normalize_url() {
		$a = NV_oOS_Graphify_Entity_Resolver::normalize( 'url', 'https://Example.com/' );
		$b = NV_oOS_Graphify_Entity_Resolver::normalize( 'url', 'http://example.com' );
		$this->assertSame( $a, $b );
	}

	/**
	 * Extract_canonical_keys reads sku and email from properties.
	 */
	public function test_entity_resolver_extract_canonical_keys() {
		$node = array(
			'node_id'    => 'n1',
			'label'      => 'Foo',
			'properties' => array(
				'sku'   => ' abc-123 ',
				'email' => 'Foo@Bar.com',
			),
		);
		$keys = NV_oOS_Graphify_Entity_Resolver::extract_canonical_keys( $node );
		$this->assertSame( 'ABC-123', $keys['sku'] );
		$this->assertSame( 'foo@bar.com', $keys['email'] );
	}

	/**
	 * Resolve_node creates SAME_AS edges between nodes that share a canonical key.
	 */
	public function test_entity_resolver_resolves_same_as_edges() {
		$node_a = array(
			'node_id'     => 'remote_src_a_aaa',
			'label'       => 'Acme',
			'type'        => 'organization',
			'properties'  => array( 'email' => 'contact@acme.com' ),
			'source_slug' => 'src_a',
		);
		$node_b = array(
			'node_id'     => 'remote_src_b_bbb',
			'label'       => 'Acme Corp',
			'type'        => 'organization',
			'properties'  => array( 'email' => 'Contact@Acme.com' ),
			'source_slug' => 'src_b',
		);

		NV_oOS_Graphify_DB::upsert_node( $node_a );
		NV_oOS_Graphify_DB::upsert_node( $node_b );

		$emitted = NV_oOS_Graphify_Entity_Resolver::resolve_node( $node_b, 'src_b' );
		$this->assertGreaterThanOrEqual( 1, $emitted );

		$edges = NV_oOS_Graphify_DB::get_edges_for_node( $node_b['node_id'] );
		$found = false;
		foreach ( $edges as $e ) {
			if ( 'SAME_AS' === $e->relation && $e->target_node_id === $node_a['node_id'] ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected SAME_AS edge from new node to existing match.' );
	}

	// -------------------------------------------------------------------------
	// WooCommerce driver tests
	// -------------------------------------------------------------------------

	/**
	 * WooCommerce driver test_connection returns failure when WC is not loaded.
	 */
	public function test_woocommerce_driver_test_connection_no_wc() {
		$driver = new NV_oOS_Graphify_Remote_WooCommerce();
		$result = $driver->test_connection();
		$this->assertIsArray( $result );
		// In the test env WooCommerce isn't loaded, so this MUST return success=false.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->assertFalse( $result['success'] );
		}
	}

	/**
	 * WooCommerce driver returns no nodes when WooCommerce is absent.
	 */
	public function test_woocommerce_driver_fetch_nodes_no_wc() {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is loaded; this test only checks the absence path.' );
		}
		$driver = new NV_oOS_Graphify_Remote_WooCommerce();
		$driver->set_config( array( '_slug' => 'wc' ) );
		$this->assertSame( array(), $driver->fetch_nodes() );
		$this->assertSame( array(), $driver->fetch_edges() );
	}

	// -------------------------------------------------------------------------
	// CSV driver tests
	// -------------------------------------------------------------------------

	/**
	 * CSV driver fetch_nodes returns empty when no path is configured.
	 */
	public function test_csv_driver_no_path() {
		$driver = new NV_oOS_Graphify_Remote_CSV();
		$driver->set_config( array( '_slug' => 'csv' ) );
		$this->assertSame( array(), $driver->fetch_nodes() );
	}

	/**
	 * CSV driver ingests a real CSV file from the uploads dir.
	 */
	public function test_csv_driver_fetch_nodes_from_uploads() {
		// The driver reads a local uploads file, which only works through the
		// `direct` filesystem method. Earlier suites can leave the global
		// `$wp_filesystem` initialised with `ftpsockets`, so force direct here
		// and let the driver re-initialise.
		$filesystem_filter = static function () {
			return 'direct';
		};
		add_filter( 'filesystem_method', $filesystem_filter );
		$GLOBALS['wp_filesystem'] = null;

		$uploads = wp_get_upload_dir();
		$this->assertNotEmpty( $uploads['basedir'] );
		if ( ! is_dir( $uploads['basedir'] ) ) {
			wp_mkdir_p( $uploads['basedir'] );
		}
		$path = trailingslashit( $uploads['basedir'] ) . 'graphify-test-' . wp_generate_password( 6, false ) . '.csv';
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$path,
			"id,name,homepage\n1,Alice,https://alice.example\n2,Bob,https://bob.example\n"
		);

		try {
			$driver = new NV_oOS_Graphify_Remote_CSV();
			$driver->set_config(
				array(
					'_slug'          => 'csv_unit',
					'file_path'      => $path,
					'has_header_row' => 1,
					'field_map'      => wp_json_encode(
						array(
							'id'    => 'id',
							'label' => 'name',
							'url'   => 'homepage',
							'type'  => 'person',
						)
					),
				)
			);

			$nodes = $driver->fetch_nodes();
			$this->assertCount( 2, $nodes );
			$this->assertSame( 'Alice', $nodes[0]['label'] );
			$this->assertSame( 'person', $nodes[0]['type'] );
			$this->assertSame( 'csv_unit', $nodes[0]['source_slug'] );
		} finally {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			remove_filter( 'filesystem_method', $filesystem_filter );
		}
	}

	/**
	 * CSV driver rejects file paths outside the uploads directory.
	 */
	public function test_csv_driver_rejects_path_traversal() {
		$driver = new NV_oOS_Graphify_Remote_CSV();
		$driver->set_config(
			array(
				'_slug'     => 'csv',
				'file_path' => '/etc/passwd',
				'field_map' => wp_json_encode(
					array(
						'id'    => 'a',
						'label' => 'b',
					)
				),
			)
		);
		$this->assertSame( array(), $driver->fetch_nodes() );
	}

	// -------------------------------------------------------------------------
	// Webhook driver tests
	// -------------------------------------------------------------------------

	/**
	 * Verify_signature accepts a correct HMAC-SHA256 with optional sha256= prefix.
	 */
	public function test_webhook_verify_signature_accepts_valid() {
		$driver = new NV_oOS_Graphify_Remote_Webhook();
		$driver->set_config(
			array(
				'webhook_secret' => 'shh',
				'_slug'          => 'wh',
			)
		);

		$body     = '{"id":"1","name":"x"}';
		$expected = hash_hmac( 'sha256', $body, 'shh' );

		$this->assertTrue( $driver->verify_signature( $body, $expected ) );
		$this->assertTrue( $driver->verify_signature( $body, 'sha256=' . $expected ) );
	}

	/**
	 * Verify_signature rejects invalid signatures and missing secrets.
	 */
	public function test_webhook_verify_signature_rejects_invalid() {
		$driver = new NV_oOS_Graphify_Remote_Webhook();
		$driver->set_config(
			array(
				'webhook_secret' => 'shh',
				'_slug'          => 'wh',
			)
		);

		$this->assertFalse( $driver->verify_signature( 'body', 'wrong' ) );
		$this->assertFalse( $driver->verify_signature( 'body', '' ) );

		$driver->set_config( array( '_slug' => 'wh' ) ); // No secret.
		$this->assertFalse( $driver->verify_signature( 'body', hash_hmac( 'sha256', 'body', 'x' ) ) );
	}

	/**
	 * Payload_to_nodes maps a payload through the configured field_map.
	 */
	public function test_webhook_payload_to_nodes_maps_records() {
		$driver = new NV_oOS_Graphify_Remote_Webhook();
		$driver->set_config(
			array(
				'_slug'          => 'wh_test',
				'webhook_secret' => 'shh',
				'records_path'   => 'data',
				'field_map'      => wp_json_encode(
					array(
						'id'    => 'id',
						'label' => 'name',
						'type'  => 'person',
					)
				),
			)
		);
		$payload = array(
			'data' => array(
				array(
					'id'   => 'p1',
					'name' => 'Alice',
				),
				array(
					'id'   => 'p2',
					'name' => 'Bob',
				),
			),
		);
		$nodes   = $driver->payload_to_nodes( $payload );
		$this->assertCount( 2, $nodes );
		$this->assertSame( 'Alice', $nodes[0]['label'] );
		$this->assertSame( 'person', $nodes[0]['type'] );
	}

	/**
	 * Payload_to_nodes treats a single-object payload as a one-record list.
	 */
	public function test_webhook_payload_to_nodes_single_record() {
		$driver = new NV_oOS_Graphify_Remote_Webhook();
		$driver->set_config(
			array(
				'_slug'          => 'wh_single',
				'webhook_secret' => 'shh',
				'field_map'      => wp_json_encode(
					array(
						'id'    => 'id',
						'label' => 'name',
					)
				),
			)
		);
		$nodes = $driver->payload_to_nodes(
			array(
				'id'   => 'x',
				'name' => 'Solo',
			)
		);
		$this->assertCount( 1, $nodes );
		$this->assertSame( 'Solo', $nodes[0]['label'] );
	}

	// -------------------------------------------------------------------------
	// Registry / wiring tests
	// -------------------------------------------------------------------------

	/**
	 * Default driver registration includes the new connectors.
	 */
	public function test_registry_registers_new_drivers() {
		$registry = NV_oOS_Graphify_Remote_Registry::get_instance();
		// Trigger the default-driver action hook.
		NV_oOS_Graphify::register_default_drivers( $registry );
		$slugs = $registry->get_registered_driver_slugs();
		$this->assertContains( 'woocommerce', $slugs );
		$this->assertContains( 'csv', $slugs );
		$this->assertContains( 'webhook', $slugs );
	}

	/**
	 * Get_active_sources() does not fatal-error after the registry call-site
	 * fix (was calling the non-existent NV_oOS_Graphify_DB::get_remote_sources()).
	 */
	public function test_registry_get_active_sources_does_not_fatal() {
		$registry = NV_oOS_Graphify_Remote_Registry::get_instance();
		$result   = $registry->get_active_sources();
		$this->assertIsArray( $result );
	}
}
