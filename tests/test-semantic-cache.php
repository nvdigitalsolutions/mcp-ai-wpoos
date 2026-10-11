<?php
/**
 * Tests for the WP_MCP_AI_Semantic_Cache tier-2 rewrite.
 *
 * Covers the v1.1.98 per-hash transient storage, the generation-counter
 * flush, and the FIFO-capped registry — the replacements for the old
 * single-option blob.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test semantic cache tier-2 storage.
 *
 * @covers WP_MCP_AI_Semantic_Cache::set
 * @covers WP_MCP_AI_Semantic_Cache::get
 * @covers WP_MCP_AI_Semantic_Cache::flush
 */
class Test_Semantic_Cache extends WP_UnitTestCase {

	/**
	 * Enable the cache and provide a deterministic embedding stub.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wp_mcp_ai_semantic_cache_enabled', '__return_true' );

		// The embedding helper is an optional integration seam (no plugin
		// definition exists) — define a deterministic stub for the suite.
		if ( ! function_exists( 'wp_mcp_ai_get_embeddings' ) ) {
			eval( 'function wp_mcp_ai_get_embeddings( $text, $model ) { return array_fill( 0, 8, 0.1 ); }' ); // phpcs:ignore Squiz.PHP.Eval -- Test-only stub for an optional integration seam.
		}

		WP_MCP_AI_Semantic_Cache::flush();
	}

	/**
	 * Reset the generation counter so later suites start clean.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Semantic_Cache::flush();
		delete_option( WP_MCP_AI_Semantic_Cache::GENERATION_KEY );
		parent::tearDown();
	}

	/**
	 * Expected per-hash transient key for a prompt/model pair.
	 *
	 * @param string $prompt Prompt text.
	 * @param string $model  Model identifier.
	 * @return string Transient key.
	 */
	private function expected_key( $prompt, $model ) {
		$generation  = (int) get_option( WP_MCP_AI_Semantic_Cache::GENERATION_KEY, 0 );
		$prompt_hash = md5( $prompt . $model );
		return WP_MCP_AI_Semantic_Cache::SEMANTIC_KEY_PREFIX . $generation . '_' . md5( $prompt_hash );
	}

	/**
	 * Tier-2 set() stores the entry in its own per-hash transient (not a
	 * single option blob) and tracks the key in the registry.
	 */
	public function test_tier2_store_writes_per_hash_transient_and_registry() {
		$result = WP_MCP_AI_Semantic_Cache::set(
			'prompt-a',
			array( 'text' => 'response-a' ),
			'model-x',
			array( 'ttl' => 3600 )
		);

		$this->assertTrue( $result );

		$key   = $this->expected_key( 'prompt-a', 'model-x' );
		$entry = get_transient( $key );
		$this->assertIsArray( $entry );
		$this->assertEquals( 'response-a', $entry['response']['text'] );
		$this->assertEquals( (int) get_option( WP_MCP_AI_Semantic_Cache::GENERATION_KEY ), $entry['generation'] );

		$registry = get_option( WP_MCP_AI_Semantic_Cache::REGISTRY_KEY, array() );
		$this->assertContains( $key, $registry );
	}

	/**
	 * The flush() method bumps the generation counter and clears the registry
	 * so stale per-hash transients from the previous generation are ignored.
	 */
	public function test_flush_invalidates_stored_entries() {
		WP_MCP_AI_Semantic_Cache::set( 'prompt-a', array( 'text' => 'response-a' ), 'model-x', array( 'ttl' => 3600 ) );

		$generation_before = (int) get_option( WP_MCP_AI_Semantic_Cache::GENERATION_KEY );
		$stale_key         = $this->expected_key( 'prompt-a', 'model-x' );
		$this->assertIsArray( get_transient( $stale_key ) );

		WP_MCP_AI_Semantic_Cache::flush();

		$this->assertEquals( $generation_before + 1, (int) get_option( WP_MCP_AI_Semantic_Cache::GENERATION_KEY ) );
		$this->assertFalse( get_option( WP_MCP_AI_Semantic_Cache::REGISTRY_KEY ) );

		// The stale transient may still physically exist, but the lookup must
		// ignore it (generation mismatch) and return a miss.
		$this->assertNull( WP_MCP_AI_Semantic_Cache::get( 'prompt-a', 'model-x', array( 'similarity_threshold' => 0.5 ) ) );
	}

	/**
	 * A semantically similar prompt hits the stored entry via the tier-2
	 * registry-backed lookup.
	 */
	public function test_semantic_lookup_hits_stored_entry() {
		WP_MCP_AI_Semantic_Cache::set(
			'how to bake bread',
			array( 'text' => 'BREAD_RESPONSE' ),
			'model-x',
			array( 'ttl' => 3600 )
		);

		$result = WP_MCP_AI_Semantic_Cache::get(
			'how to bake bred',
			'model-x',
			array( 'similarity_threshold' => 0.5 )
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'BREAD_RESPONSE', $result['text'] );
	}

	/**
	 * The key registry is FIFO-capped: the oldest entry is evicted (and its
	 * transient deleted) once the cap is reached.
	 */
	public function test_registry_cap_evicts_oldest_keys() {
		$method = new ReflectionMethod( WP_MCP_AI_Semantic_Cache::class, 'add_semantic_cache_entry' );
		$method->setAccessible( true );

		$generation = (int) get_option( WP_MCP_AI_Semantic_Cache::GENERATION_KEY, 0 );

		for ( $i = 0; $i < WP_MCP_AI_Semantic_Cache::REGISTRY_CAP + 1; $i++ ) {
			$method->invoke(
				null,
				array(
					'prompt_hash' => 'hash-' . $i,
					'response'    => array( 'i' => $i ),
					'expires'     => time() + 3600,
				)
			);
		}

		$registry = get_option( WP_MCP_AI_Semantic_Cache::REGISTRY_KEY, array() );
		$this->assertCount( WP_MCP_AI_Semantic_Cache::REGISTRY_CAP, $registry );

		$first_key = WP_MCP_AI_Semantic_Cache::SEMANTIC_KEY_PREFIX . $generation . '_' . md5( 'hash-0' );
		$last_key  = WP_MCP_AI_Semantic_Cache::SEMANTIC_KEY_PREFIX . $generation . '_' . md5( 'hash-' . WP_MCP_AI_Semantic_Cache::REGISTRY_CAP );

		$this->assertNotContains( $first_key, $registry );
		$this->assertFalse( get_transient( $first_key ), 'Evicted entry transient should be deleted.' );
		$this->assertContains( $last_key, $registry );
		$this->assertIsArray( get_transient( $last_key ) );
	}
}
