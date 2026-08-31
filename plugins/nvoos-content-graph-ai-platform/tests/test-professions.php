<?php
/**
 * Professions ported-class tests (extraction P1).
 *
 * Verifies the port of the profession core (src/Professions/: CPT,
 * repository, knowledge-base loader, playbook loader, tool recommender,
 * dataset mappings, service) preserves the public behaviour of the base
 * plugin's profession classes (mcp-ai-wpoos/includes/).
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Professions\DatasetMappings;
use NvoosContentGraphAiPlatform\Professions\ProfessionBaseKnowledgeSeeder;
use NvoosContentGraphAiPlatform\Professions\ProfessionCpt;
use NvoosContentGraphAiPlatform\Professions\ProfessionKnowledgeBaseLoader;
use NvoosContentGraphAiPlatform\Professions\ProfessionOrchestrationCli;
use NvoosContentGraphAiPlatform\Professions\ProfessionOrchestrationSeeder;
use NvoosContentGraphAiPlatform\Professions\ProfessionPlaybookLoader;
use NvoosContentGraphAiPlatform\Professions\ProfessionPlaybookSeeder;
use NvoosContentGraphAiPlatform\Professions\ProfessionRepository;
use NvoosContentGraphAiPlatform\Professions\ProfessionSeeder;
use NvoosContentGraphAiPlatform\Professions\ProfessionService;
use NvoosContentGraphAiPlatform\Professions\ProfessionToolRecommender;
use NvoosContentGraphAiPlatform\Professions\Metaboxes\ProfessionMetaboxBase;
use NvoosContentGraphAiPlatform\Professions\Metaboxes\ProfessionMetaboxDetails;

/**
 * @group professions
 */
class Test_Platform_Professions extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Prevent test pollution between suites.
		$existing = get_posts(
			array(
				'post_type'      => ProfessionCpt::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $existing as $profession_id ) {
			wp_delete_post( $profession_id, true );
		}
	}

	public function test_meta_keys_match_base_cpt_values(): void {
		// Data stability: post type slug and meta keys must stay byte-identical
		// to the base plugin's WP_MCP_AI_Profession_CPT.
		$this->assertSame( 'mcp_ai_profession', ProfessionCpt::POST_TYPE );
		$this->assertSame( '_wp_mcp_ai_profession_category', ProfessionCpt::META_CATEGORY );
		$this->assertSame( '_wp_mcp_ai_profession_expertise', ProfessionCpt::META_EXPERTISE );
		$this->assertSame( '_wp_mcp_ai_profession_default_tools', ProfessionCpt::META_DEFAULT_TOOLS );
		$this->assertSame( '_wp_mcp_ai_profession_role_description', ProfessionCpt::META_ROLE_DESCRIPTION );
		$this->assertSame( '_wp_mcp_ai_profession_agent_role', ProfessionCpt::META_AGENT_ROLE );
		$this->assertSame( '_wp_mcp_ai_profession_preferred_datasets', ProfessionCpt::META_PREFERRED_DATASETS );
	}

	public function test_profession_cpt_registered_in_monolith_matrix(): void {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith-only check; standalone wiring is covered by the service wiring test.' );
		}

		$this->assertTrue( post_type_exists( ProfessionCpt::POST_TYPE ) );
	}

	public function test_profession_service_wires_cpt_in_standalone_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Standalone-only check; the base plugin owns profession wiring in monolith mode.' );
		}

		ProfessionService::instance()->register();
		do_action( 'init' );

		$this->assertTrue( post_type_exists( ProfessionCpt::POST_TYPE ) );
	}

	public function test_repository_saves_and_finds_profession(): void {
		$repository = new ProfessionRepository();
		$saved      = $repository->save(
			array(
				'title'            => 'Test Data Scientist',
				'slug'             => 'test_data_scientist',
				'category'         => 'technical',
				'expertise'        => array( 'python', 'machine learning' ),
				'warnings'         => array( 'Verify results' ),
				'role_description' => 'Analyzes data.',
			)
		);

		$this->assertNotWPError( $saved );

		$found = $repository->find_one( $saved );
		$this->assertNotNull( $found );
		$this->assertSame( 'Test Data Scientist', $found->post_title );
		$this->assertSame( 'technical', get_post_meta( $saved, ProfessionCpt::META_CATEGORY, true ) );

		$by_slug = $repository->find_one( 'test_data_scientist' );
		$this->assertSame( $saved, $by_slug->ID );
	}

	public function test_repository_find_many_and_category_counts(): void {
		$repository = new ProfessionRepository();
		$first      = $repository->save(
			array(
				'title'    => 'Alpha Analyst',
				'slug'     => 'alpha_analyst',
				'category' => 'technical',
			)
		);
		$second     = $repository->save(
			array(
				'title'    => 'Beta Lawyer',
				'slug'     => 'beta_lawyer',
				'category' => 'legal',
			)
		);

		$many = $repository->find_many( array( $first, $second ) );
		$this->assertCount( 2, $many );

		$counts = $repository->get_category_counts();
		$this->assertArrayHasKey( 'technical', $counts );
		$this->assertArrayHasKey( 'legal', $counts );
	}

	public function test_knowledge_base_loader_loads_bundled_data(): void {
		$loader = new ProfessionKnowledgeBaseLoader( NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'data/knowledge-base/professions/' );

		$professions = $loader->load_all();

		$this->assertIsArray( $professions );
		$this->assertNotEmpty( $professions );

		foreach ( $professions as $profession ) {
			$this->assertArrayHasKey( 'title', $profession );
			$this->assertArrayHasKey( 'slug', $profession );
			$this->assertArrayHasKey( 'category', $profession );
		}

		// Categories are discoverable from the bundled data.
		$categories = $loader->get_categories();
		$this->assertNotEmpty( $categories );
	}

	public function test_knowledge_base_loader_rejects_missing_fields(): void {
		$loader = new ProfessionKnowledgeBaseLoader( NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'data/knowledge-base/professions/' );

		$result = $this->invoke_protected(
			$loader,
			'validate_profession',
			array(
				'title' => 'No Slug Profession',
				'slug'  => '',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_required_field', $result->get_error_code() );
	}

	public function test_playbook_loader_degradation_and_type_check(): void {
		$loader = new ProfessionPlaybookLoader( NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'data/knowledge-base/profession-playbooks/' );

		// The bundled global playbook resolves to real content.
		$this->assertNotEmpty( $loader->get_global_text() );

		// Missing category/profession playbook files degrade to empty strings,
		// never fatals.
		$this->assertSame( '', $loader->get_category_text( 'no-such-category' ) );
		$this->assertSame( '', $loader->get_profession_text( 'no-such-profession' ) );

		// Non-profession posts are rejected.
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->assertSame( '', $loader->build_playbook( $post_id ) );
	}

	public function test_tool_recommender_null_registry_degrades(): void {
		$recommender = new ProfessionToolRecommender( null, null );

		$tools = $recommender->get_recommended_tools( 'data_scientist', 'technical' );

		$this->assertIsArray( $tools );
		$this->assertNotEmpty( $tools );
		$this->assertContains( 'web_search', $tools );
	}

	public function test_dataset_mappings_recommendations(): void {
		$mappings = DatasetMappings::recommendations( 'data_scientist' );
		$this->assertNotEmpty( $mappings );
		$this->assertArrayHasKey( 'dataset', $mappings[0] );

		$this->assertSame( array(), DatasetMappings::recommendations( 'no-such-profession' ) );

		$this->assertNotEmpty( DatasetMappings::all() );
	}

	public function test_dataset_mapping_shims_in_standalone_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin owns the global function surface.' );
		}

		// Shims are loaded by ProfessionService::register() in standalone mode.
		ProfessionService::instance()->register();

		$this->assertTrue( function_exists( 'wp_mcp_ai_get_all_profession_dataset_mappings' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_get_profession_dataset_recommendations' ) );
		$this->assertNotEmpty( wp_mcp_ai_get_profession_dataset_recommendations( 'data_scientist' ) );
	}

	public function test_service_merge_and_orchestration(): void {
		$repository = new ProfessionRepository();
		$saved      = $repository->save(
			array(
				'title'            => 'Merge Me',
				'slug'             => 'merge_me',
				'category'         => 'technical',
				'expertise'        => array( 'sql' ),
				'warnings'         => array( 'Check twice' ),
				'role_description' => 'Merges things.',
				'knowledge_base'   => 'KB text',
				'default_tools'    => array( 'web_search' ),
			)
		);

		$service = new ProfessionService( $repository );

		$merged = $service->merge_profession_data( array( 'merge_me' ) );
		$this->assertContains( 'Merge Me', $merged['names'] );
		$this->assertContains( 'Merges things.', $merged['roles'] );
		$this->assertContains( 'sql', $merged['expertise'] );
		$this->assertContains( 'Check twice', $merged['warnings'] );
		$this->assertContains( 'KB text', $merged['knowledge'] );
		$this->assertContains( 'web_search', $merged['tools'] );

		// Orchestration defaults.
		$config = $service->get_orchestration_config( $saved );
		$this->assertSame( 'generalist', $config['agent_role'] );
		$this->assertSame( array(), $config['task_patterns'] );

		// Orchestration update roundtrip.
		$updated = $service->update_orchestration_config(
			$saved,
			array(
				'agent_role'    => 'planner',
				'task_patterns' => array( 'analyze' ),
			)
		);
		$this->assertTrue( $updated );

		$config = $service->get_orchestration_config( $saved );
		$this->assertSame( 'planner', $config['agent_role'] );
		$this->assertSame( array( 'analyze' ), $config['task_patterns'] );
	}

	public function test_service_agent_role_mismatch_returns_error(): void {
		$repository = new ProfessionRepository();
		$repository->save(
			array(
				'title'    => 'Role Gated',
				'slug'     => 'role_gated',
				'category' => 'technical',
			)
		);

		$service = new ProfessionService( $repository );

		$result = $service->get_profession_for_agent_role( 'role_gated', 'executor' );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_wrong_agent_role', $result->get_error_code() );
	}

	public function test_service_global_shim_in_standalone_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin defines the global accessor.' );
		}

		$this->assertTrue( function_exists( 'wp_mcp_ai_get_profession_service' ) );
		$this->assertInstanceOf( ProfessionService::class, wp_mcp_ai_get_profession_service() );
	}

	public function test_seeder_option_keys_match_base(): void {
		// Data stability: seeding option keys must stay byte-identical to the
		// base plugin's seeders.
		$this->assertSame( 'wp_mcp_ai_professions_seeded', ProfessionSeeder::SEEDED_OPTION );
		$this->assertSame( 'wp_mcp_ai_profession_base_knowledge_seeded', ProfessionBaseKnowledgeSeeder::SEEDED_OPTION );
		$this->assertSame( 'wp_mcp_ai_playbooks_seeded', ProfessionPlaybookSeeder::SEEDED_OPTION );
	}

	public function test_seed_professions_from_bundled_data(): void {
		ProfessionSeeder::seed_professions();

		$this->assertTrue( (bool) get_option( ProfessionSeeder::SEEDED_OPTION, false ) );

		$counts = wp_count_posts( ProfessionCpt::POST_TYPE );
		$this->assertGreaterThan( 100, (int) ( $counts->publish ?? 0 ) );
	}

	public function test_seeders_bail_without_seeded_professions(): void {
		// Base-knowledge and playbook seeders must no-op (not fatal) when
		// profession seeding has not run yet.
		ProfessionBaseKnowledgeSeeder::init();
		ProfessionPlaybookSeeder::init();

		$this->assertTrue( true );
	}

	public function test_cleanup_all_duplicates_with_no_professions(): void {
		$result = ProfessionPlaybookSeeder::cleanup_all_duplicates();

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['professions_processed'] );
		$this->assertSame( 0, $result['duplicates_removed'] );
	}

	public function test_base_knowledge_document_loader_degradation(): void {
		// Missing document files degrade to empty strings, never fatals.
		$content = $this->invoke_protected_static( ProfessionBaseKnowledgeSeeder::class, 'load_knowledge_document_from_file', 'no-such-profession' );
		$this->assertSame( '', $content );
	}

	public function test_playbook_cleanup_method_contract(): void {
		// remove_duplicate_playbooks on a post with no memory files returns 0.
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$removed = $this->invoke_protected_static( ProfessionPlaybookSeeder::class, 'remove_duplicate_playbooks', $post_id );
		$this->assertSame( 0, $removed );
	}

	public function test_metabox_classes_extend_base_and_have_ids(): void {
		$classes = array(
			'ProfessionMetaboxDetails',
			'ProfessionMetaboxExpertise',
			'ProfessionMetaboxBaseKnowledge',
			'ProfessionMetaboxDefaults',
			'ProfessionMetaboxDatasets',
			'ProfessionMetaboxPlaybook',
			'ProfessionMetaboxAgentOrchestration',
		);

		foreach ( $classes as $class ) {
			$fqcn = 'NvoosContentGraphAiPlatform\\Professions\\Metaboxes\\' . $class;
			$this->assertTrue( class_exists( $fqcn ), "$fqcn should exist" );

			$metabox = new $fqcn();
			$this->assertInstanceOf( ProfessionMetaboxBase::class, $metabox );
			$this->assertNotEmpty( $metabox->get_id() );
			$this->assertNotEmpty( $metabox->get_title() );
		}
	}

	public function test_metabox_can_save_requires_nonce(): void {
		$metabox = new ProfessionMetaboxDetails();
		$post_id = self::factory()->post->create();

		// No nonce present in $_POST — save must be rejected.
		$this->assertFalse( $metabox->can_save( $post_id ) );
	}

	public function test_cpt_wires_ported_metaboxes_in_standalone_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Standalone-only wiring check; monolith mode uses the base CPT.' );
		}

		$cpt = new ProfessionCpt();

		$reflection = new \ReflectionProperty( ProfessionCpt::class, 'metaboxes' );
		$reflection->setAccessible( true );
		$metaboxes = $reflection->getValue( $cpt );

		$this->assertCount( 7, $metaboxes );
	}

	public function test_orchestration_seeder_option_key_matches_base(): void {
		// Data stability: the version option key must stay byte-identical.
		$this->assertSame( 'wp_mcp_ai_profession_orchestration_version', ProfessionOrchestrationSeeder::VERSION_OPTION );
	}

	public function test_orchestration_seeder_runs_on_empty_database(): void {
		$seeder = new ProfessionOrchestrationSeeder();
		$result = $seeder->seed_all( true );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'agent_roles_assigned', $result );
		$this->assertArrayHasKey( 'task_patterns_created', $result );
	}

	public function test_orchestration_cli_surface(): void {
		$this->assertTrue( class_exists( ProfessionOrchestrationCli::class ) );
		$this->assertTrue( method_exists( ProfessionOrchestrationCli::class, 'seed_orchestration' ) );
		$this->assertTrue( method_exists( ProfessionOrchestrationCli::class, 'orchestration_stats' ) );
	}

	/**
	 * Invoke a protected static method on a class for contract testing.
	 *
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @param mixed  $arg        Optional single method argument.
	 * @return mixed Method result.
	 */
	private function invoke_protected_static( $class_name, $method, $arg = null ) {
		$reflection = new \ReflectionMethod( $class_name, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, $arg );
	}

	/**
	 * Invoke a protected method on an object for contract testing.
	 *
	 * @param object $instance Object instance.
	 * @param string $method   Method name.
	 * @param mixed  $arg      Optional single method argument.
	 * @return mixed Method result.
	 */
	private function invoke_protected( $instance, $method, $arg = null ) {
		$reflection = new \ReflectionMethod( $instance, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( $instance, $arg );
	}
}
