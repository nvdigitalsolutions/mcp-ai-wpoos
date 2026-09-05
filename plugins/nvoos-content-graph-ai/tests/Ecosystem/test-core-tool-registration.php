<?php
/**
 * D8 Cluster 1 — pre-ported core tool registration tests.
 *
 * Characterization suite for `CoreToolManifest` + `CoreToolFactory`:
 * the standalone registry must carry the portable core inventory (the
 * slug set the base plugin serves in monolith installs), never override
 * the addon's own AI tools, keep deferred buckets unregistered, and
 * execute a representative tool through the registry.
 *
 * Standalone-only: in monolith installs the base plugin's own registry
 * owns the same surface and `CoreToolFactory::register()` is a
 * documented no-op.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\CoreBridge;
use NvoosContentGraphAi\Tools\CoreToolManifest;

/**
 * @group tools
 */
class Test_Core_Tool_Registration extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Standalone-only registration surface.' );
		}
	}

	public function test_registry_carries_the_ported_core_inventory(): void {
		$bridge = CoreBridge::instance();
		$slugs  = array_keys( $bridge->tools->enabled() );

		// 203 manifest entries + the 13 AI tools (+ graph bridge tools).
		$this->assertGreaterThanOrEqual( 190, count( $slugs ) );

		$expected = array(
			'create_post',
			'get_recent_posts',
			'search_content',
			'web_search',
			'count_tokens',
			'deep_research',
			'list_openai_files',
			'client_summarize_text',
			'calculate_erlang_c',
			'list_available_models',
			'semantic_content_search',
			'create_chart',
		);
		foreach ( $expected as $slug ) {
			$this->assertTrue( $bridge->tools->has( $slug ), "Expected portable tool {$slug} to be registered." );
		}
	}

	public function test_ai_tools_are_not_overridden(): void {
		$bridge = CoreBridge::instance();

		$this->assertTrue( $bridge->tools->has( 'ai_summarize_text' ) );
		$this->assertInstanceOf(
			\NvoosContentGraphAi\Tools\AbstractAiTool::class,
			$bridge->tools->get( 'ai_summarize_text' )
		);
	}

	public function test_deferred_buckets_stay_unregistered(): void {
		$bridge = CoreBridge::instance();

		// E6 engine pieces (decision D4) and the platform-owned harness
		// tools (Cluster 3) must not surface here.
		$this->assertFalse( $bridge->tools->has( 'paper_store_list' ) );
		$this->assertFalse( $bridge->tools->has( 'okf_search' ) );
		$this->assertFalse( $bridge->tools->has( 'evolve_harness' ) );
		$this->assertFalse( $bridge->tools->has( 'get_woo_products' ) );

		// Base-coupled adapter tools await Cluster 2 hardening.
		$cluster_2 = array(
			'vectorize_image',
			'media_library_optimizer',
			'run_gemini_managed_agent',
			'delegate_to_a2a_agent',
			'probe_chat',
			'query_mesh_intelligent',
			'visualize_workflow_metrics',
		);
		foreach ( $cluster_2 as $slug ) {
			$this->assertFalse( $bridge->tools->has( $slug ), "Cluster-2 tool {$slug} must not register yet." );
		}
	}

	public function test_manifest_entries_resolve_to_classes(): void {
		$missing = array();
		foreach ( CoreToolManifest::manifest() as $slug => $spec ) {
			if ( ! class_exists( $spec[0] ) ) {
				$missing[] = $slug;
			}
		}

		$this->assertSame( array(), $missing );
	}

	public function test_smoke_execute_ported_core_tool(): void {
		self::factory()->post->create(
			array(
				'post_title'  => 'D8 registration smoke post',
				'post_status' => 'publish',
			)
		);

		$result = CoreBridge::instance()->tools->execute(
			'get_recent_posts',
			array(),
			array( 'user_id' => 0 )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
	}
}
