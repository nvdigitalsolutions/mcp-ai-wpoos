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

	public function test_add_model_config_round_trip(): void {
		$bridge   = CoreBridge::instance();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$context  = array(
			'user_id'       => $admin_id,
			'auth_provider' => new \Nvoos\WordPress\Adapter\AuthProvider(),
		);

		$config = array(
			'name'           => 'D8 Test Model',
			'provider'       => 'openai',
			'context_window' => 128000,
		);

		$added = $bridge->tools->execute(
			'add_model_config',
			array(
				'model_id' => 'd8-test-model',
				'config'   => $config,
			),
			$context
		);

		$this->assertIsArray( $added );
		$this->assertTrue( $added['success'] );
		$this->assertSame( 'added', $added['action'] );
		$this->assertSame( 'openai', $added['config']['provider'] );
		$this->assertSame( 128000, $added['config']['context_window'] );
		$this->assertSame( 80000, $added['config']['tpm'] ); // Defaulted.
		$this->assertSame( $admin_id, $added['config']['_metadata']['added_by'] );

		// Byte-identical storage: the base plugin's option key.
		$stored = get_option( 'wp_mcp_ai_model_configs', array() );
		$this->assertArrayHasKey( 'd8-test-model', $stored );

		// Duplicate without overwrite → byte-identical error code.
		$duplicate = $bridge->tools->execute(
			'add_model_config',
			array(
				'model_id' => 'd8-test-model',
				'config'   => $config,
			),
			$context
		);
		$this->assertInstanceOf( \WP_Error::class, $duplicate );
		$this->assertSame( 'wp_mcp_ai_model_exists', $duplicate->get_error_code() );

		// Overwrite updates and preserves original metadata.
		$updated = $bridge->tools->execute(
			'add_model_config',
			array(
				'model_id'  => 'd8-test-model',
				'config'    => array_merge( $config, array( 'context_window' => 256000 ) ),
				'overwrite' => true,
			),
			$context
		);
		$this->assertIsArray( $updated );
		$this->assertSame( 'updated', $updated['action'] );
		$this->assertSame( 256000, $updated['config']['context_window'] );
		$this->assertSame( $admin_id, $updated['config']['_metadata']['updated_by'] );
		$this->assertSame( $admin_id, $updated['config']['_metadata']['original_added_by'] );

		delete_option( 'wp_mcp_ai_model_configs' );
	}

	public function test_add_model_config_denies_non_admins(): void {
		$bridge = CoreBridge::instance();
		$tool   = new \NvoosContentGraphAi\Tools\AddModelConfigTool( $bridge->errors );

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Direct call: the execute-time capability check is byte-identical
		// to the base tool (the registry gate is a separate layer).
		$result = $tool->execute(
			array(
				'model_id' => 'd8-denied-model',
				'config'   => array(
					'name'           => 'Denied',
					'provider'       => 'openai',
					'context_window' => 8192,
				),
			),
			array( 'user_id' => $subscriber_id )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	public function test_research_model_cache_hit_returns_cached_config(): void {
		$bridge   = CoreBridge::instance();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$cached = array(
			'name'           => 'Cached Research Model',
			'provider'       => 'openai',
			'context_window' => 64000,
			'status'         => 'active',
		);

		// Seed the base-identical cache key so execute() takes the
		// cache-hit path (no network call in tests).
		wp_cache_set(
			'model_research_' . md5( 'openai_d8-cached-model' ),
			$cached,
			'wp_mcp_ai_model_research',
			7 * DAY_IN_SECONDS
		);

		$result = $bridge->tools->execute(
			'research_model',
			array(
				'model_id' => 'd8-cached-model',
				'provider' => 'openai',
			),
			array(
				'user_id'       => $admin_id,
				'auth_provider' => new \Nvoos\WordPress\Adapter\AuthProvider(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['_from_cache'] );
		$this->assertSame( 'Cached Research Model', $result['name'] );
		$this->assertSame( 64000, $result['context_window'] );
	}

	public function test_discover_new_models_anthropic_static_list(): void {
		$bridge   = CoreBridge::instance();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Anthropic needs no API call — the static known-model list is
		// served verbatim, making the discovery loop deterministic.
		$result = $bridge->tools->execute(
			'discover_new_models',
			array( 'providers' => array( 'anthropic' ) ),
			array(
				'user_id'       => $admin_id,
				'auth_provider' => new \Nvoos\WordPress\Adapter\AuthProvider(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertNotEmpty( $result['discovered'] );

		$model_ids = wp_list_pluck( $result['discovered'], 'model_id' );
		$this->assertContains( 'claude-opus-5', $model_ids );
		$this->assertContains( 'claude-sonnet-4-5-20250929', $model_ids );

		// Recommendations carry the base-identical scoring contract.
		$this->assertNotEmpty( $result['recommendations'] );
		$opus = null;
		foreach ( $result['recommendations'] as $recommendation ) {
			if ( 'claude-opus-5' === $recommendation['model_id'] ) {
				$opus = $recommendation;
				break;
			}
		}
		$this->assertNotNull( $opus );
		$this->assertSame( 'research_and_add', $opus['action'] );
		$this->assertSame( 85, $opus['confidence'] ); // 50 + 20 major + 15 naming.
	}

	public function test_discover_new_models_unsupported_provider_buckets_error(): void {
		$bridge   = CoreBridge::instance();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// 'nvidia' is accepted by the schema but has no discovery
		// implementation — byte-identical unsupported-provider error.
		$result = $bridge->tools->execute(
			'discover_new_models',
			array( 'providers' => array( 'nvidia' ) ),
			array(
				'user_id'       => $admin_id,
				'auth_provider' => new \Nvoos\WordPress\Adapter\AuthProvider(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'nvidia', $result['errors'] );
		$this->assertStringContainsString( 'not supported', $result['errors']['nvidia'] );
		$this->assertEmpty( $result['discovered'] );
		$this->assertSame( 'Found 0 new models', $result['message'] );
	}
}
