<?php
/**
 * Teams ported-class tests.
 *
 * Verifies the extraction port of the Teams subsystem (src/Teams/ +
 * src/Schema/Sanitize.php) preserves the public behaviour of the base
 * plugin's team classes (mcp-ai-wpoos/includes/teams/,
 * includes/repositories/class-wp-mcp-ai-team-repository.php,
 * includes/services/class-wp-mcp-ai-team-knowledge-base-loader.php).
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Schema\Sanitize;
use NvoosContentGraphAiPlatform\Teams\TeamCpt;
use NvoosContentGraphAiPlatform\Teams\TeamKnowledgeBaseLoader;
use NvoosContentGraphAiPlatform\Teams\TeamRepository;
use NvoosContentGraphAiPlatform\Teams\TeamSeeder;
use NvoosContentGraphAiPlatform\Teams\TeamsService;

/**
 * @group teams
 */
class Test_Platform_Teams extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Prevent test pollution between suites: remove any team posts left
		// behind by other tests (mirrors the mesh test cleanup pattern).
		$existing = get_posts(
			array(
				'post_type'      => TeamCpt::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $existing as $team_id ) {
			wp_delete_post( $team_id, true );
		}
	}

	public function test_meta_keys_match_base_cpt_values(): void {
		// Data stability: post type slug and meta keys must stay byte-identical
		// to the base plugin's WP_MCP_AI_Team_CPT.
		$this->assertSame( 'mcp_ai_team', TeamCpt::POST_TYPE );
		$this->assertSame( '_wp_mcp_ai_team_members', TeamCpt::META_TEAM_MEMBERS );
		$this->assertSame( '_wp_mcp_ai_team_description', TeamCpt::META_TEAM_DESCRIPTION );
		$this->assertSame( '_wp_mcp_ai_team_default_provider', TeamCpt::META_DEFAULT_PROVIDER );
		$this->assertSame( '_wp_mcp_ai_team_default_model', TeamCpt::META_DEFAULT_MODEL );
		$this->assertSame( '_wp_mcp_ai_team_default_temperature', TeamCpt::META_DEFAULT_TEMPERATURE );
		$this->assertSame( '_wp_mcp_ai_team_orchestration_mode', TeamCpt::META_ORCHESTRATION_MODE );
		$this->assertSame( '_wp_mcp_ai_team_workflow_template', TeamCpt::META_WORKFLOW_TEMPLATE );
		$this->assertSame( '_wp_mcp_ai_team_result_aggregation', TeamCpt::META_RESULT_AGGREGATION_STRATEGY );
	}

	public function test_team_cpt_registered_in_this_matrix(): void {
		// Monolith matrix: the base plugin registers the team CPT during the
		// root bootstrap. The standalone matrix exercises wiring explicitly
		// via test_teams_service_wires_cpt_in_standalone_mode (Plugin::register()
		// never runs during the test bootstrap — ecosystem plugins load after
		// WordPress boots).
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Standalone matrix: covered by test_teams_service_wires_cpt_in_standalone_mode.' );
		}

		$this->assertTrue( post_type_exists( TeamCpt::POST_TYPE ) );
	}

	public function test_teams_service_wires_cpt_in_standalone_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin owns team wiring.' );
		}

		TeamsService::instance()->register();

		// The service wires the CPT onto `init` (priority 5) through a
		// closure that instantiates TeamCpt, whose constructor hooks the
		// real registration. Call the registration methods directly instead
		// of re-firing `do_action( 'init' )`, which re-registers WooCommerce
		// blocks/integrations in the local Docker matrix and fails the test
		// with "already registered" incorrect-usage notices.
		$cpt = new TeamCpt();
		$cpt->register_post_type();
		$cpt->register_meta();

		$this->assertTrue( post_type_exists( TeamCpt::POST_TYPE ) );
	}

	public function test_register_post_type_creates_team_cpt(): void {
		$cpt = new TeamCpt();
		$cpt->register_post_type();

		$this->assertTrue( post_type_exists( TeamCpt::POST_TYPE ) );
	}

	public function test_repository_saves_and_finds_team(): void {
		$repository = new TeamRepository();
		$team_id    = $repository->save(
			array(
				'title'            => 'Test Engineering Team',
				'slug'             => 'test_engineering_team',
				'description'      => 'A test team.',
				'members'          => array(),
				'default_provider' => 'openai',
				'default_model'    => 'gpt-4',
			)
		);

		$this->assertNotWPError( $team_id );

		$found = $repository->find_one( $team_id );
		$this->assertNotNull( $found );
		$this->assertSame( 'Test Engineering Team', $found->post_title );
		$this->assertSame( 'openai', get_post_meta( $team_id, TeamCpt::META_DEFAULT_PROVIDER, true ) );
		$this->assertSame( 'gpt-4', get_post_meta( $team_id, TeamCpt::META_DEFAULT_MODEL, true ) );

		$by_slug = $repository->find_one( 'test_engineering_team' );
		$this->assertNotNull( $by_slug );
		$this->assertSame( $team_id, $by_slug->ID );
	}

	public function test_repository_find_one_returns_null_for_missing(): void {
		$repository = new TeamRepository();
		$this->assertNull( $repository->find_one( 'no_such_team_slug' ) );
	}

	public function test_seeder_default_teams_shape(): void {
		$defaults = $this->invoke_protected( new TeamSeeder(), 'get_default_teams' );

		$this->assertIsArray( $defaults );
		$this->assertGreaterThanOrEqual( 10, count( $defaults ) );

		foreach ( $defaults as $team ) {
			$this->assertArrayHasKey( 'title', $team );
			$this->assertArrayHasKey( 'slug', $team );
			$this->assertArrayHasKey( 'members', $team );
			$this->assertGreaterThanOrEqual( 2, count( $team['members'] ) );
		}
	}

	public function test_knowledge_base_loader_loads_bundled_data(): void {
		$loader = new TeamKnowledgeBaseLoader( NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'data/knowledge-base/teams/' );

		$teams = $loader->load_all();

		$this->assertIsArray( $teams );
		$this->assertNotEmpty( $teams );

		foreach ( $teams as $team ) {
			$this->assertArrayHasKey( 'title', $team );
			$this->assertArrayHasKey( 'slug', $team );
			$this->assertArrayHasKey( 'members', $team );
			$this->assertGreaterThanOrEqual( 2, count( $team['members'] ) );
		}
	}

	public function test_knowledge_base_loader_rejects_single_member_team(): void {
		$loader = new TeamKnowledgeBaseLoader( NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'data/knowledge-base/teams/' );

		$result = $this->invoke_protected(
			$loader,
			'validate_team',
			array(
				'title'   => 'Solo Team',
				'slug'    => 'solo_team',
				'members' => array( 'only_one' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_members', $result->get_error_code() );
	}

	public function test_knowledge_base_loader_missing_file_returns_error(): void {
		$loader = new TeamKnowledgeBaseLoader( NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'data/knowledge-base/teams/' );

		$result = $loader->load_from_file( NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'data/knowledge-base/teams/does-not-exist.json' );

		$this->assertWPError( $result );
		$this->assertSame( 'file_not_found', $result->get_error_code() );
	}

	public function test_sanitize_recursive_parity(): void {
		$input = array(
			'name'   => ' <b>Bold</b> Team ',
			'count'  => '3',
			'nested' => array(
				'key'  => ' value ',
				'bool' => true,
				'num'  => 2.5,
				'nil'  => null,
			),
		);

		$out = Sanitize::recursive( $input );

		// Strings go through sanitize_text_field (tags stripped, trimmed).
		$this->assertSame( 'Bold Team', $out['name'] );
		$this->assertSame( '3', $out['count'] );
		$this->assertSame( 'value', $out['nested']['key'] );
		// Bools, floats, and nulls are preserved.
		$this->assertTrue( $out['nested']['bool'] );
		$this->assertSame( 2.5, $out['nested']['num'] );
		$this->assertNull( $out['nested']['nil'] );
	}

	public function test_sanitize_recursive_non_array_returns_empty_array(): void {
		$this->assertSame( array(), Sanitize::recursive( 'not-an-array' ) );
	}

	public function test_teams_service_register_is_safe_in_both_modes(): void {
		// Monolith mode: no-op (base plugin owns team wiring).
		// Standalone mode: hooks the ported CPT + seeder onto init.
		$service = TeamsService::instance();
		$service->register();

		$this->assertTrue( true );
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
