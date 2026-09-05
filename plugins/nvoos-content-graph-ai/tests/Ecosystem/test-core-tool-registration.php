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
	}

	public function test_hardened_adapter_tools_are_registered(): void {
		$bridge = CoreBridge::instance();

		$hardened = array(
			'vectorize_image',
			'media_library_optimizer',
			'run_gemini_managed_agent',
			'delegate_to_a2a_agent',
			'probe_chat',
			'query_mesh_intelligent',
			'visualize_workflow_metrics',
		);
		foreach ( $hardened as $slug ) {
			$this->assertTrue( $bridge->tools->has( $slug ), "Hardened tool {$slug} must be registered (Cluster 2)." );
		}
	}

	public function test_hardened_tools_degrade_without_the_base_plugin(): void {
		$bridge  = CoreBridge::instance();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$context = array(
			'user_id'       => $user_id,
			'auth_provider' => new \Nvoos\WordPress\Adapter\AuthProvider(),
		);

		// probe_chat needs the base REST controller — standalone must
		// answer the documented degradation error, not fatal.
		$result = $bridge->tools->execute(
			'probe_chat',
			array( 'assistant_id' => 12345 ),
			$context
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_rest_unavailable', $result->get_error_code() );

		// delegate_to_a2a_agent needs the base A2A client — same contract.
		$a2a = $bridge->tools->execute(
			'delegate_to_a2a_agent',
			array(
				'agent_url'        => 'https://example.com/.well-known/agent.json',
				'task_description' => 'Probe',
			),
			$context
		);

		$this->assertInstanceOf( \WP_Error::class, $a2a );
		$this->assertSame( 'wp_mcp_ai_a2a_unavailable', $a2a->get_error_code() );
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

	public function test_taxonomy_quartet_round_trip(): void {
		$bridge  = CoreBridge::instance();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$context = array(
			'user_id'       => $user_id,
			'auth_provider' => new \Nvoos\WordPress\Adapter\AuthProvider(),
		);

		// create_term.
		$created = $bridge->tools->execute(
			'create_term',
			array(
				'name'        => 'D8 Cluster 2b term',
				'taxonomy'    => 'category',
				'description' => 'Ported-tool round trip.',
			),
			$context
		);
		$this->assertIsArray( $created );
		$this->assertArrayHasKey( 'term_id', $created );
		$term_id = $created['term_id'];

		// list_terms.
		$listed = $bridge->tools->execute(
			'list_terms',
			array( 'taxonomy' => 'category' ),
			$context
		);
		$this->assertIsArray( $listed );
		$names = wp_list_pluck( $listed['terms'], 'name' );
		$this->assertContains( 'D8 Cluster 2b term', $names );

		// update_term.
		$updated = $bridge->tools->execute(
			'update_term',
			array(
				'term_id'     => $term_id,
				'taxonomy'    => 'category',
				'description' => 'Updated description.',
			),
			$context
		);
		$this->assertIsArray( $updated );
		$this->assertSame( 'Updated description.', $updated['description'] );

		// list_taxonomies.
		$taxonomies = $bridge->tools->execute( 'list_taxonomies', array(), $context );
		$this->assertIsArray( $taxonomies );
		$taxonomy_names = wp_list_pluck( $taxonomies['taxonomies'], 'name' );
		$this->assertContains( 'category', $taxonomy_names );

		// Cleanup.
		wp_delete_term( $term_id, 'category' );
	}

	public function test_list_mcp_tools_and_environment_status_smoke(): void {
		$bridge  = CoreBridge::instance();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $user_id );
		$context = array(
			'user_id'       => $user_id,
			'auth_provider' => new \Nvoos\WordPress\Adapter\AuthProvider(),
		);

		$listed = $bridge->tools->execute(
			'list_mcp_tools',
			array( 'limit' => 10 ),
			$context
		);
		$this->assertIsArray( $listed );
		$this->assertGreaterThan( 0, $listed['total_found'] );
		$this->assertNotContains( 'list_mcp_tools', wp_list_pluck( $listed['tools'], 'name' ) );

		$status = $bridge->tools->execute( 'get_environment_status', array(), $context );
		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'environment', $status );
		$this->assertSame( get_bloginfo( 'version' ), $status['environment']['wordpress_version'] );
	}

	public function test_enable_reasoning_mode_activates_for_complex_task(): void {
		$bridge = CoreBridge::instance();

		// Engineered task crossing the base's 0.7 threshold across all five
		// indicators (multi-step, logical, code, domain, verification).
		$result = $bridge->tools->execute(
			'enable_reasoning_mode',
			array(
				'task'    => 'Implement a PHP class to process payments, then analyze the result and calculate the total, because compliance requires verification. If the result is correct then test it, finally review for security, ensure accuracy and confirm production readiness.',
				'context' => array(
					'task_type'  => 'code_generation',
					'multi_step' => true,
				),
			),
			array( 'user_id' => 0 )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertTrue( $result['data']['reasoning_recommended'] );
		$this->assertTrue( $result['data']['reasoning_activated'] );
		$this->assertSame( 0.3, $result['data']['enhancements']['temperature'] );

		// Decision history persists under the byte-identical option key.
		$history = get_option( 'wp_mcp_ai_reasoning_history', array() );
		$this->assertNotEmpty( $history );
	}

	public function test_enable_reasoning_mode_passes_through_simple_task(): void {
		$bridge = CoreBridge::instance();

		$result = $bridge->tools->execute(
			'enable_reasoning_mode',
			array( 'task' => 'Say hello.' ),
			array( 'user_id' => 0 )
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['data']['reasoning_recommended'] );
		$this->assertFalse( $result['data']['reasoning_activated'] );
	}
}
