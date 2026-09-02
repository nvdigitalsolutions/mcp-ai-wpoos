<?php
/**
 * Semantic Cache port tests (Wave D1b).
 *
 * Characterization suite for the ported
 * `NvoosContentGraphAi\Chat\SemanticCache`. The port ships dormant
 * (the base implementation has no call sites either), so these tests pin
 * the exact-match tier, the enable gate, expiry, storage cleanup, and —
 * where no base embedding helper exists (standalone matrix) — the
 * semantic tier via a local fake helper.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

// Global fake embedding helper for the semantic tier. Only defined when the
// base plugin's real helper is absent (standalone matrix); the semantic-tier
// test skips itself in monolith mode to avoid calling the base's network path.
namespace {
	if ( ! function_exists( 'wp_mcp_ai_get_embeddings' ) ) {
		$GLOBALS['nvoos_cg_ai_fake_embeddings'] = true;

		function wp_mcp_ai_get_embeddings( $text, $model ) {
			// Deterministic per-text vectors chosen so the tests' prompts are
			// distinguishable: alpha-ish → [1,0,0], 'question' → [0,1,0],
			// 'other-question' → [0,0,1], everything else → [0,1,0].
			if ( 0 === strpos( (string) $text, 'alpha' ) ) {
				return array( 1.0, 0.0, 0.0 );
			}
			if ( 'other-question' === $text ) {
				return array( 0.0, 0.0, 1.0 );
			}
			return array( 0.0, 1.0, 0.0 );
		}
	}
}

namespace NvoosContentGraphAi\Tests {

	use NvoosContentGraphAi\Chat\SemanticCache;

	/**
	 * @group chat
	 */
	class Test_Semantic_Cache extends \WP_UnitTestCase {

		public function setUp(): void {
			parent::setUp();
			SemanticCache::flush();
		}

		public function tearDown(): void {
			remove_all_filters( 'wp_mcp_ai_semantic_cache_enabled' );
			SemanticCache::flush();
			parent::tearDown();
		}

		public function test_disabled_by_default(): void {
			$this->assertFalse( SemanticCache::is_enabled() );
			$this->assertNull( SemanticCache::get( 'prompt' ) );
			$this->assertFalse( SemanticCache::set( 'prompt', array( 'r' => 1 ) ) );

			$stats = SemanticCache::get_stats();
			$this->assertFalse( $stats['enabled'] );
		}

		public function test_exact_match_roundtrip_when_enabled(): void {
			add_filter( 'wp_mcp_ai_semantic_cache_enabled', '__return_true' );

			$response = array( 'content' => 'The answer.' );
			$this->assertTrue( SemanticCache::set( 'question', $response, 'test-model' ) );

			$cached = SemanticCache::get( 'question', 'test-model' );
			$this->assertSame( $response, $cached );

			$this->assertNull( SemanticCache::get( 'other-question', 'test-model' ) );
		}

		public function test_exact_match_expired_entry_is_skipped(): void {
			add_filter( 'wp_mcp_ai_semantic_cache_enabled', '__return_true' );

			$hash = md5( 'stale-prompt' . 'test-model' );
			\wp_cache_set(
				$hash,
				array(
					'response' => array( 'content' => 'stale' ),
					'expires'  => time() - 60,
				),
				SemanticCache::CACHE_GROUP_EXACT
			);

			$this->assertNull( SemanticCache::get( 'stale-prompt', 'test-model' ) );
		}

		public function test_flush_clears_storage(): void {
			add_filter( 'wp_mcp_ai_semantic_cache_enabled', '__return_true' );

			SemanticCache::set( 'question', array( 'content' => 'x' ), 'test-model' );
			$this->assertNotFalse( \wp_cache_get( md5( 'question' . 'test-model' ), SemanticCache::CACHE_GROUP_EXACT ) );

			$this->assertTrue( SemanticCache::flush() );

			$this->assertFalse( \wp_cache_get( md5( 'question' . 'test-model' ), SemanticCache::CACHE_GROUP_EXACT ) );
			$this->assertSame( array(), \get_option( 'wp_mcp_ai_semcache_semantic_store', array() ) );
		}

		public function test_semantic_tier_matches_similar_prompt(): void {
			if ( empty( $GLOBALS['nvoos_cg_ai_fake_embeddings'] ) ) {
				// Monolith matrix: the base plugin's real helper would be called —
				// skip rather than hit the network.
				$this->markTestSkipped( 'Base embedding helper present; semantic tier exercised in the standalone matrix.' );
			}

			add_filter( 'wp_mcp_ai_semantic_cache_enabled', '__return_true' );

			$response = array( 'content' => 'Alpha answer.' );
			SemanticCache::set( 'alpha', $response, 'test-model' );

			// Same embedding → similarity 1.0 ≥ 0.85 → semantic hit.
			$this->assertSame( $response, SemanticCache::get( 'alpha-ish', 'test-model' ) );

			// Orthogonal embedding → similarity 0 → miss.
			$this->assertNull( SemanticCache::get( 'beta', 'test-model' ) );

			$this->assertSame( 1, SemanticCache::get_stats()['semantic_count'] );
		}
	}
}
