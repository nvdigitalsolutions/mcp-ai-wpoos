<?php
/**
 * Tests for the S3 Remote Driver — Phase 4 batch 3.
 *
 * Exercises the pure / static helpers (key shape detection, parent-prefix
 * extraction, basename, canonical query encoding) plus the XML response
 * parser, capability flags, config schema and registry registration. The
 * SigV4-signed network call is not exercised here — the driver is built
 * to gracefully no-op on missing config.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/bootstrap.php';

/**
 * Test_Graphify_S3_Driver
 */
class Test_Graphify_S3_Driver extends WP_UnitTestCase {

	/**
	 * Driver advertises the expected ID and capability flags.
	 */
	public function test_driver_id_and_capability_flags() {
		$driver = new NV_oOS_Graphify_Remote_S3();
		$this->assertSame( 's3', $driver->get_driver_id() );
		$flags = $driver->get_capability_flags();
		$this->assertTrue( $flags['supports_incremental'] );
		$this->assertTrue( $flags['supports_pagination'] );
		$this->assertTrue( $flags['supports_relationships'] );
		$this->assertFalse( $flags['supports_oauth'] );
		$this->assertFalse( $flags['supports_webhooks'] );
	}

	/**
	 * Config schema declares everything an admin needs to configure the
	 * connection and pagination behaviour.
	 */
	public function test_config_schema_keys() {
		$driver = new NV_oOS_Graphify_Remote_S3();
		$schema = $driver->get_config_schema();
		foreach (
			array(
				'endpoint',
				'region',
				'bucket',
				'access_key_id',
				'secret_access_key',
				'prefix',
				'use_path_style',
				'page_size',
				'max_pages',
				'emit_folder_edges',
			) as $key
		) {
			$this->assertArrayHasKey( $key, $schema, "schema key missing: {$key}" );
		}
	}

	/**
	 * Folder convention: keys ending in '/' are folders.
	 */
	public function test_is_folder_key() {
		$this->assertTrue( NV_oOS_Graphify_Remote_S3::is_folder_key( 'a/' ) );
		$this->assertTrue( NV_oOS_Graphify_Remote_S3::is_folder_key( 'a/b/c/' ) );
		$this->assertFalse( NV_oOS_Graphify_Remote_S3::is_folder_key( 'a/b/c.txt' ) );
		$this->assertFalse( NV_oOS_Graphify_Remote_S3::is_folder_key( '' ) );
	}

	/**
	 * Basename_of_key handles file keys, folder keys and root.
	 */
	public function test_basename_of_key() {
		$this->assertSame( 'c.txt', NV_oOS_Graphify_Remote_S3::basename_of_key( 'a/b/c.txt' ) );
		$this->assertSame( 'b', NV_oOS_Graphify_Remote_S3::basename_of_key( 'a/b/' ) );
		$this->assertSame( 'top', NV_oOS_Graphify_Remote_S3::basename_of_key( 'top' ) );
		$this->assertSame( '', NV_oOS_Graphify_Remote_S3::basename_of_key( '' ) );
	}

	/**
	 * Parent_prefix returns the slash-terminated parent or '' at root.
	 */
	public function test_parent_prefix() {
		$this->assertSame( 'a/b/', NV_oOS_Graphify_Remote_S3::parent_prefix( 'a/b/c.txt' ) );
		$this->assertSame( 'a/', NV_oOS_Graphify_Remote_S3::parent_prefix( 'a/b' ) );
		$this->assertSame( '', NV_oOS_Graphify_Remote_S3::parent_prefix( 'top' ) );
	}

	/**
	 * Canonical query string is RFC-3986 encoded and joined with '&'
	 * (callers are responsible for sorting before passing in).
	 */
	public function test_build_canonical_query() {
		$this->assertSame( '', NV_oOS_Graphify_Remote_S3::build_canonical_query( array() ) );
		$qs = NV_oOS_Graphify_Remote_S3::build_canonical_query(
			array(
				'list-type' => '2',
				'max-keys'  => '1000',
				'prefix'    => 'foo bar/baz+qux',
			)
		);
		// Spaces and '+' must be percent-encoded — '+' to '%2B', space to '%20'.
		$this->assertSame( 'list-type=2&max-keys=1000&prefix=foo%20bar%2Fbaz%2Bqux', $qs );
	}

	/**
	 * XML parser extracts objects and the next continuation token; an
	 * empty / malformed body returns the empty shape rather than raising.
	 */
	public function test_parse_list_objects_xml_extracts_objects_and_token() {
		$xml    = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
			. '<Name>my-bucket</Name>'
			. '<NextContinuationToken>NEXT_PAGE</NextContinuationToken>'
			. '<Contents>'
			. '<Key>folder/file.txt</Key>'
			. '<LastModified>2026-04-01T12:00:00.000Z</LastModified>'
			. '<ETag>&quot;abc123&quot;</ETag>'
			. '<Size>1024</Size>'
			. '<StorageClass>STANDARD</StorageClass>'
			. '</Contents>'
			. '<Contents>'
			. '<Key>folder/</Key>'
			. '<Size>0</Size>'
			. '</Contents>'
			. '</ListBucketResult>';
		$parsed = NV_oOS_Graphify_Remote_S3::parse_list_objects_xml( $xml );
		$this->assertSame( 'NEXT_PAGE', $parsed['next_token'] );
		$this->assertCount( 2, $parsed['objects'] );
		$this->assertSame( 'folder/file.txt', $parsed['objects'][0]['key'] );
		$this->assertSame( 1024, $parsed['objects'][0]['size'] );
		$this->assertSame( 'abc123', $parsed['objects'][0]['etag'] );
		$this->assertSame( 'STANDARD', $parsed['objects'][0]['storage_class'] );
		$this->assertSame( 'folder/', $parsed['objects'][1]['key'] );
	}

	/**
	 * Empty / malformed XML returns the safe empty shape.
	 */
	public function test_parse_list_objects_xml_handles_garbage() {
		$empty = NV_oOS_Graphify_Remote_S3::parse_list_objects_xml( '' );
		$this->assertSame( array(), $empty['objects'] );
		$this->assertSame( '', $empty['next_token'] );

		$bad = NV_oOS_Graphify_Remote_S3::parse_list_objects_xml( '<not valid' );
		$this->assertSame( array(), $bad['objects'] );
		$this->assertSame( '', $bad['next_token'] );
	}

	/**
	 * Fetch_nodes / fetch_edges short-circuit cleanly when the bucket is
	 * not configured — no remote call is attempted.
	 */
	public function test_fetch_returns_empty_when_not_configured() {
		$driver = new NV_oOS_Graphify_Remote_S3();
		$driver->set_config( array() );
		$this->assertSame( array(), $driver->fetch_nodes() );
		$this->assertSame( array(), $driver->fetch_edges() );
	}

	/**
	 * Reconcile is a no-op (matches generic_rest / generic_graphql / generic_sql).
	 */
	public function test_reconcile_is_noop() {
		$driver = new NV_oOS_Graphify_Remote_S3();
		$out    = $driver->reconcile( null );
		$this->assertFalse( $out['matched'] );
		$this->assertSame( 0.0, $out['confidence'] );
	}

	/**
	 * The driver registers cleanly through the shared registry.
	 */
	public function test_driver_registers_through_registry() {
		$registry = NV_oOS_Graphify_Remote_Registry::get_instance();
		$registry->register_driver( new NV_oOS_Graphify_Remote_S3() );
		$ids = array();
		foreach ( $registry->get_drivers() as $d ) {
			$ids[] = $d->get_driver_id();
		}
		$this->assertContains( 's3', $ids );
	}

	/**
	 * Hash_id produces a stable, sanitize_key-safe slug fragment.
	 */
	public function test_hash_id_is_stable_and_safe() {
		$h1 = NV_oOS_Graphify_Remote_S3::hash_id( 'bucket/path/file.txt' );
		$h2 = NV_oOS_Graphify_Remote_S3::hash_id( 'bucket/path/file.txt' );
		$this->assertSame( $h1, $h2 );
		$this->assertSame( 16, strlen( $h1 ) );
		$this->assertSame( $h1, sanitize_key( $h1 ) );
	}
}
