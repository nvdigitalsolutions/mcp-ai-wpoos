<?php
/**
 * Chat Response Cache port tests (Wave D1b).
 *
 * Characterization suite for the ported
 * `NvoosContentGraphAi\Chat\ChatResponseCache`. Every assertion pins
 * behaviour that must match the base plugin's
 * `WP_MCP_AI_Chat_Response_Cache` (ecosystem port plan, principle:
 * behaviour-preserving).
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Chat\ChatResponseCache;

/**
 * @group chat
 */
class Test_Chat_Response_Cache extends \WP_UnitTestCase {

	private $cache;

	public function setUp(): void {
		parent::setUp();
		$this->cache = new ChatResponseCache();
	}

	public function tearDown(): void {
		// Clear any cache/version transients written by this test.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test isolation cleanup of plugin-owned transients.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_mcp_ai_chat_cache_%' OR option_name LIKE '_transient_timeout_wp_mcp_ai_chat_cache_%'" );
		parent::tearDown();
	}

	public function test_not_cacheable_without_cache_system_prompt(): void {
		$messages = array( array( 'role' => 'user', 'content' => 'Hi' ) );

		$this->assertFalse( $this->cache->get_cached_response( $messages, array( 'stream' => false ) ) );
		$this->assertFalse( $this->cache->set_cached_response( $messages, array( 'stream' => false ), array( 'response' => 'x' ) ) );
	}

	public function test_not_cacheable_with_positive_temperature(): void {
		$options = array(
			'cache_system_prompt' => true,
			'temperature'         => 0.7,
			'stream'              => false,
		);

		$this->assertFalse(
			$this->cache->set_cached_response(
				array( array( 'role' => 'user', 'content' => 'Hi' ) ),
				$options,
				array( 'response' => 'x' )
			)
		);
	}

	public function test_not_cacheable_for_streaming_or_bypass(): void {
		$messages = array( array( 'role' => 'user', 'content' => 'Hi' ) );

		$this->assertFalse(
			$this->cache->set_cached_response(
				$messages,
				array( 'cache_system_prompt' => true, 'stream' => true ),
				array( 'response' => 'x' )
			)
		);
		$this->assertFalse(
			$this->cache->set_cached_response(
				$messages,
				array( 'cache_system_prompt' => true, 'stream' => false, 'bypass_cache' => true ),
				array( 'response' => 'x' )
			)
		);
	}

	public function test_set_and_get_roundtrip_with_cache_metadata(): void {
		$messages = array( array( 'role' => 'user', 'content' => 'What is 2+2?' ) );
		$options  = array(
			'cache_system_prompt' => true,
			'temperature'         => 0,
			'stream'              => false,
		);
		$result = array(
			'response'      => array( 'content' => '4' ),
			'tool_results'  => array(),
			'iterations'    => 1,
			'cost'          => null,
		);

		$this->assertTrue( $this->cache->set_cached_response( $messages, $options, $result ) );

		$cached = $this->cache->get_cached_response( $messages, $options );
		$this->assertIsArray( $cached );
		$this->assertSame( '4', $cached['response']['content'] );
		$this->assertSame( 1, $cached['iterations'] );
		$this->assertArrayHasKey( 'cache_metadata', $cached );
		$this->assertSame( 'wp_transient', $cached['cache_metadata']['cache_source'] );
	}

	public function test_different_messages_miss(): void {
		$options = array(
			'cache_system_prompt' => true,
			'stream'              => false,
		);

		$this->assertTrue(
			$this->cache->set_cached_response(
				array( array( 'role' => 'user', 'content' => 'First' ) ),
				$options,
				array( 'response' => array( 'content' => 'A' ) )
			)
		);

		$this->assertFalse(
			$this->cache->get_cached_response(
				array( array( 'role' => 'user', 'content' => 'Second' ) ),
				$options
			)
		);
	}

	public function test_different_tool_sets_miss(): void {
		$messages = array( array( 'role' => 'user', 'content' => 'Same question' ) );
		$options  = array(
			'cache_system_prompt' => true,
			'stream'              => false,
			'tools'               => array( array( 'function' => array( 'name' => 'tool_a' ) ) ),
		);

		$this->assertTrue(
			$this->cache->set_cached_response( $messages, $options, array( 'response' => array( 'content' => 'A' ) ) )
		);

		$options['tools'] = array( array( 'function' => array( 'name' => 'tool_b' ) ) );
		$this->assertFalse( $this->cache->get_cached_response( $messages, $options ) );
	}

	public function test_invalidate_for_assistant_bumps_cache_version(): void {
		$messages = array( array( 'role' => 'user', 'content' => 'Hello' ) );
		$options  = array(
			'cache_system_prompt' => true,
			'stream'              => false,
			'assistant_id'        => 7,
		);

		$this->assertTrue(
			$this->cache->set_cached_response( $messages, $options, array( 'response' => array( 'content' => 'Hi' ) ) )
		);
		$this->assertNotFalse( $this->cache->get_cached_response( $messages, $options ) );

		$this->cache->invalidate_for_assistant( 7 );

		$this->assertFalse( $this->cache->get_cached_response( $messages, $options ) );
	}

	public function test_ttl_filter_is_applied(): void {
		$observed = array();
		add_filter(
			'wp_mcp_ai_chat_response_cache_ttl',
			static function ( $ttl, $options ) use ( &$observed ) {
				$observed = array( 'ttl' => $ttl, 'options' => $options );
				return 123;
			},
			10,
			2
		);

		$options = array(
			'cache_system_prompt' => true,
			'stream'              => false,
		);
		$this->cache->set_cached_response(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			$options,
			array( 'response' => array( 'content' => 'x' ) )
		);

		$this->assertSame( ChatResponseCache::DEFAULT_TTL, $observed['ttl'] );
		$this->assertSame( $options, $observed['options'] );

		remove_all_filters( 'wp_mcp_ai_chat_response_cache_ttl' );
	}
}
