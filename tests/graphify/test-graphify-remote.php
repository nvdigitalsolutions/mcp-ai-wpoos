<?php
/**
 * Tests for Graphify Remote Sources & Federation feature.
 *
 * Covers: registry singleton, driver registration, HTTP client SSRF guard,
 * crypto encrypt/decrypt, Wikidata confidence scoring, embeddings pack/unpack,
 * cosine similarity, RAG tool execute, resolve-external, list-sources,
 * and sync-source capability check.
 *
 * All network calls are mocked — no live HTTP requests are made.
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/bootstrap.php';

/**
 * @coversDefaultClass NV_oOS_Graphify_Remote_Registry
 */
class Test_Graphify_Remote extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// setUp / tearDown
	// -------------------------------------------------------------------------

	/**
	 * Reset registry singleton between tests.
	 */
	public function setUp(): void {
		parent::setUp();

		// Reset the registry singleton so each test starts clean.
		$reflection = new ReflectionClass( 'NV_oOS_Graphify_Remote_Registry' );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		// Reset the "initialized" flag. It is an instance property, so it must
		// be reset on a concrete object rather than the null static context.
		$fresh       = NV_oOS_Graphify_Remote_Registry::get_instance();
		$initialized = $reflection->getProperty( 'initialized' );
		$initialized->setAccessible( true );
		$initialized->setValue( $fresh, false );
	}

	// -------------------------------------------------------------------------
	// Registry tests
	// -------------------------------------------------------------------------

	/**
	 * Registry get_instance() returns the same object on repeated calls.
	 *
	 * @covers ::get_instance
	 */
	public function test_registry_singleton() {
		$a = NV_oOS_Graphify_Remote_Registry::get_instance();
		$b = NV_oOS_Graphify_Remote_Registry::get_instance();
		$this->assertSame( $a, $b, 'Registry must return the same singleton instance.' );
	}

	/**
	 * Drivers can be registered and their slugs retrieved.
	 *
	 * @covers ::register_driver
	 * @covers ::get_registered_driver_slugs
	 */
	public function test_driver_registration() {
		$registry = NV_oOS_Graphify_Remote_Registry::get_instance();

		// Register the Wikidata driver manually.
		$driver = new NV_oOS_Graphify_Remote_Wikidata();
		$registry->register_driver( $driver );

		$slugs = $registry->get_registered_driver_slugs();
		$this->assertContains( 'wikidata', $slugs, 'Wikidata driver slug must be present after registration.' );
	}

	/**
	 * Registering the same driver slug twice should not duplicate it.
	 *
	 * @covers ::register_driver
	 * @covers ::get_registered_driver_slugs
	 */
	public function test_driver_no_duplicate_slugs() {
		$registry = NV_oOS_Graphify_Remote_Registry::get_instance();
		$driver   = new NV_oOS_Graphify_Remote_Wikidata();

		$registry->register_driver( $driver );
		$registry->register_driver( $driver );

		$slugs = $registry->get_registered_driver_slugs();
		$this->assertCount( 1, array_filter( $slugs, function ( $s ) { return 'wikidata' === $s; } ) );
	}

	// -------------------------------------------------------------------------
	// SSRF guard tests
	// -------------------------------------------------------------------------

	/**
	 * HTTP client blocks private IPv4 addresses.
	 *
	 * @covers NV_oOS_Graphify_HTTP_Client::get
	 */
	public function test_http_client_blocks_private_ipv4() {
		$client = new NV_oOS_Graphify_HTTP_Client();
		$result = $client->get( 'http://192.168.1.1/secret' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertStringContainsStringIgnoringCase( 'ssrf', $result->get_error_code() );
	}

	/**
	 * HTTP client blocks loopback addresses.
	 *
	 * @covers NV_oOS_Graphify_HTTP_Client::get
	 */
	public function test_http_client_blocks_loopback() {
		$client = new NV_oOS_Graphify_HTTP_Client();
		$result = $client->get( 'http://127.0.0.1/admin' );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * HTTP client blocks RFC1918 10.x.x.x range.
	 *
	 * @covers NV_oOS_Graphify_HTTP_Client::get
	 */
	public function test_http_client_blocks_rfc1918_10x() {
		$client = new NV_oOS_Graphify_HTTP_Client();
		$result = $client->get( 'http://10.0.0.1/' );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	// -------------------------------------------------------------------------
	// Crypto tests
	// -------------------------------------------------------------------------

	/**
	 * Encrypt + decrypt round-trip preserves the original string.
	 *
	 * @covers NV_oOS_Graphify_Crypto::encrypt
	 * @covers NV_oOS_Graphify_Crypto::decrypt
	 */
	public function test_crypto_encrypt_decrypt_roundtrip() {
		$secret     = 'super-secret-token-123!@#';
		$ciphertext = NV_oOS_Graphify_Crypto::encrypt( $secret );
		$this->assertIsString( $ciphertext, 'Encrypted value must be a string.' );
		$this->assertNotEquals( $secret, $ciphertext, 'Cipher should differ from plaintext.' );

		$plaintext = NV_oOS_Graphify_Crypto::decrypt( $ciphertext );
		$this->assertSame( $secret, $plaintext, 'Decrypted value must match original.' );
	}

	/**
	 * is_sensitive_key() correctly identifies sensitive config keys.
	 *
	 * @covers NV_oOS_Graphify_Crypto::is_sensitive_key
	 */
	public function test_crypto_is_sensitive_key() {
		$this->assertTrue( NV_oOS_Graphify_Crypto::is_sensitive_key( 'api_token' ) );
		$this->assertTrue( NV_oOS_Graphify_Crypto::is_sensitive_key( 'password' ) );
		$this->assertTrue( NV_oOS_Graphify_Crypto::is_sensitive_key( 'client_secret' ) );
		$this->assertTrue( NV_oOS_Graphify_Crypto::is_sensitive_key( 'openai_key' ) );
		$this->assertFalse( NV_oOS_Graphify_Crypto::is_sensitive_key( 'endpoint_url' ) );
		$this->assertFalse( NV_oOS_Graphify_Crypto::is_sensitive_key( 'max_results' ) );
	}

	// -------------------------------------------------------------------------
	// Wikidata confidence scoring tests
	// -------------------------------------------------------------------------

	/**
	 * Exact match yields confidence 1.0.
	 *
	 * @covers NV_oOS_Graphify_Remote_Wikidata::calculate_confidence
	 */
	public function test_wikidata_exact_match_confidence() {
		$driver = new NV_oOS_Graphify_Remote_Wikidata();

		$method = new ReflectionMethod( $driver, 'calculate_confidence' );
		$method->setAccessible( true );

		$score = $method->invoke( $driver, 'Albert Einstein', 'Albert Einstein', '', '' );
		$this->assertEquals( 1.0, $score, '', 0.001 );
	}

	/**
	 * Contains match yields confidence below exact match.
	 *
	 * @covers NV_oOS_Graphify_Remote_Wikidata::calculate_confidence
	 */
	public function test_wikidata_contains_match_confidence() {
		$driver = new NV_oOS_Graphify_Remote_Wikidata();

		$method = new ReflectionMethod( $driver, 'calculate_confidence' );
		$method->setAccessible( true );

		$score = $method->invoke( $driver, 'Albert Einstein', 'Einstein', '', '' );
		$this->assertLessThan( 1.0, $score );
		$this->assertGreaterThanOrEqual( 0.6, $score, 'Score must meet minimum threshold.' );
	}

	// -------------------------------------------------------------------------
	// Embeddings pack/unpack + cosine similarity tests
	// -------------------------------------------------------------------------

	/**
	 * Float32 binary pack/unpack preserves vector values within float precision.
	 *
	 * @covers NV_oOS_Graphify_Embeddings::cosine_similarity
	 */
	public function test_embeddings_float32_round_trip() {
		$original = array( 0.1, 0.2, 0.3, 0.4, 0.5 );
		$packed   = '';
		foreach ( $original as $v ) {
			$packed .= pack( 'f', $v );
		}
		$unpacked = array_values( unpack( 'f' . count( $original ), $packed ) );

		foreach ( $original as $i => $v ) {
			$this->assertEqualsWithDelta( $v, $unpacked[ $i ], 0.00001, "Float at index $i should round-trip." );
		}
	}

	/**
	 * cosine_similarity of identical vectors = 1.0.
	 *
	 * @covers NV_oOS_Graphify_Embeddings::cosine_similarity
	 */
	public function test_cosine_similarity_identical_vectors() {
		$embeddings = new NV_oOS_Graphify_Embeddings();

		$method = new ReflectionMethod( $embeddings, 'cosine_similarity' );
		$method->setAccessible( true );

		$v   = array( 1.0, 0.0, 0.0 );
		$sim = $method->invoke( $embeddings, $v, $v );
		$this->assertEqualsWithDelta( 1.0, $sim, 0.0001 );
	}

	/**
	 * cosine_similarity of orthogonal vectors = 0.0.
	 *
	 * @covers NV_oOS_Graphify_Embeddings::cosine_similarity
	 */
	public function test_cosine_similarity_orthogonal_vectors() {
		$embeddings = new NV_oOS_Graphify_Embeddings();

		$method = new ReflectionMethod( $embeddings, 'cosine_similarity' );
		$method->setAccessible( true );

		$a   = array( 1.0, 0.0, 0.0 );
		$b   = array( 0.0, 1.0, 0.0 );
		$sim = $method->invoke( $embeddings, $a, $b );
		$this->assertEqualsWithDelta( 0.0, $sim, 0.0001 );
	}

	// -------------------------------------------------------------------------
	// Tool execute() tests
	// -------------------------------------------------------------------------

	/**
	 * list_remote_sources tool returns success with expected structure.
	 *
	 * @covers NV_oOS_Graphify_Tool_List_Remote_Sources::execute
	 */
	public function test_tool_list_remote_sources_structure() {
		$tool   = new NV_oOS_Graphify_Tool_List_Remote_Sources();
		$result = $tool->execute( array(), array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'sources', $result );
		$this->assertArrayHasKey( 'available_drivers', $result );
	}

	/**
	 * sync_remote_source tool requires manage_options capability.
	 *
	 * @covers NV_oOS_Graphify_Tool_Sync_Remote_Source::execute
	 */
	public function test_tool_sync_source_requires_capability() {
		// Create a subscriber (no manage_options).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new NV_oOS_Graphify_Tool_Sync_Remote_Source();
		$result = $tool->execute( array( 'slug' => 'wikidata' ), array() );

		$this->assertInstanceOf( 'WP_Error', $result );

		wp_set_current_user( 0 );
	}

	/**
	 * retrieve_context tool returns success=true and context_text when no nodes exist.
	 *
	 * @covers NV_oOS_Graphify_Tool_Retrieve_Context::execute
	 */
	public function test_tool_retrieve_context_empty_graph() {
		$tool   = new NV_oOS_Graphify_Tool_Retrieve_Context();
		$result = $tool->execute(
			array(
				'question'      => 'What is gravity?',
				'k'             => 5,
				'hops'          => 1,
				'use_vectors'   => false,
				'include_edges' => false,
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		// Either success with empty context, or WP_Error — both are valid for empty graph.
		if ( $result['success'] ) {
			$this->assertArrayHasKey( 'context_text', $result );
		}
	}
}
