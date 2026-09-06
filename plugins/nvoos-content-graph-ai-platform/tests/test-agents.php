<?php
/**
 * Agents ported-class tests.
 *
 * Verifies the extraction port of the Agents role system (src/Agents/
 * AgentRoleBase + Planner/Executor/Critic, AgentApprovalGate,
 * AgentAuditTrail, AgentCapabilityBoundary(+Hooks), AgentCodeSandbox,
 * AgentHarnessBootstrap, AgentHarnessEvolver(+RoleAdapter),
 * EvolvedPromptResolver, AgentRoleInterface) preserves the public
 * behaviour and data keys of the base plugin's includes/agents/ classes.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Agents\AgentApprovalGate;
use NvoosContentGraphAiPlatform\Agents\AgentAuditTrail;
use NvoosContentGraphAiPlatform\Agents\AgentCapabilityBoundary;
use NvoosContentGraphAiPlatform\Agents\AgentHarnessBootstrap;
use NvoosContentGraphAiPlatform\Agents\AgentRoleBase;
use NvoosContentGraphAiPlatform\Agents\AgentRoleCritic;
use NvoosContentGraphAiPlatform\Agents\AgentRoleExecutor;
use NvoosContentGraphAiPlatform\Agents\AgentRoleInterface;
use NvoosContentGraphAiPlatform\Agents\AgentRolePlanner;
use NvoosContentGraphAiPlatform\Agents\Agents;
use NvoosContentGraphAiPlatform\Agents\EvolvedPromptResolver;

/**
 * @group agents
 */
class Test_Platform_Agents extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Data-stability cleanup: remove any option keys and posts these
		// tests create so suites never pollute each other (mirrors the
		// mesh/teams cleanup pattern).
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-isolation cleanup of plugin-owned option keys; caching must not hide stale rows.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp_mcp_ai_harness_bootstrap_%'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-isolation cleanup of plugin-owned option keys; caching must not hide stale rows.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp_mcp_ai_audit_%'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-isolation cleanup of plugin-owned option keys; caching must not hide stale rows.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_mcp_ai_approval_%' OR option_name LIKE '_transient_timeout_wp_mcp_ai_approval_%'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-isolation cleanup of plugin-owned option keys; caching must not hide stale rows.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_mcp_ai_boundary_%' OR option_name LIKE '_transient_timeout_wp_mcp_ai_boundary_%'" );

		// Audit-trail event posts (the monolith matrix stores events as CPT posts).
		$events = get_posts(
			array(
				'post_type'      => AgentAuditTrail::CPT_SLUG,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $events as $event_id ) {
			wp_delete_post( $event_id, true );
		}

		// Assistant posts created by these tests.
		$assistants = get_posts(
			array(
				'post_type'      => Agents::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $assistants as $assistant_id ) {
			wp_delete_post( $assistant_id, true );
		}
	}

	/**
	 * Create a throwaway assistant post for tests that need an assistant ID.
	 *
	 * @return int Assistant post ID.
	 */
	private function create_assistant(): int {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => Agents::POST_TYPE,
				'post_title'  => 'Agents Test Assistant',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $assistant_id );

		return (int) $assistant_id;
	}

	public function test_option_and_meta_keys_match_base_values(): void {
		// Data stability: constants must stay byte-identical to the base
		// plugin's includes/agents/ classes (extraction plan §3).
		$this->assertSame( 'mcp_ai_assistant', Agents::POST_TYPE );

		$this->assertSame( 'wp_mcp_ai_approval_', AgentApprovalGate::TRANSIENT_PREFIX );
		$this->assertSame( HOUR_IN_SECONDS, AgentApprovalGate::TRANSIENT_TTL );

		$this->assertSame( 'mcp_ai_audit_event', AgentAuditTrail::CPT_SLUG );
		$this->assertSame( 'wp_mcp_ai_audit_', AgentAuditTrail::OPTION_PREFIX );
		$this->assertSame( 'wp_mcp_ai_audit_session_index', AgentAuditTrail::SESSION_INDEX_OPTION );

		$this->assertSame( 'wp_mcp_ai_boundary_', AgentCapabilityBoundary::TRANSIENT_PREFIX );
		$this->assertSame( 60, AgentCapabilityBoundary::DEFAULT_RATE_WINDOW );
		$this->assertSame( 30, AgentCapabilityBoundary::DEFAULT_RATE_MAX_CALLS );

		$this->assertSame( 'wp_mcp_ai_harness_bootstrap_', AgentHarnessBootstrap::OPTION_PREFIX );
		$this->assertSame( 'wp_mcp_ai_harness_bootstrap_index', AgentHarnessBootstrap::INDEX_OPTION );
		$this->assertSame( 10, AgentHarnessBootstrap::DEFAULT_MAX_BUNDLES );
	}

	public function test_role_classes_extend_base_and_implement_interface(): void {
		$planner  = new AgentRolePlanner();
		$executor = new AgentRoleExecutor();
		$critic   = new AgentRoleCritic();

		foreach ( array( $planner, $executor, $critic ) as $role ) {
			$this->assertInstanceOf( AgentRoleBase::class, $role );
			$this->assertInstanceOf( AgentRoleInterface::class, $role );
		}

		$this->assertSame( 'planner', $planner->get_role_type() );
		$this->assertSame( 'executor', $executor->get_role_type() );
		$this->assertSame( 'critic', $critic->get_role_type() );

		$this->assertTrue( $planner->can_delegate() );
		$this->assertFalse( $executor->can_delegate() );
		$this->assertFalse( $critic->can_delegate() );

		// Interface contract methods must exist on every role.
		$methods = array(
			'get_role_type',
			'get_role_name',
			'get_role_description',
			'get_capabilities',
			'can_delegate',
			'get_recommended_tools',
			'get_system_prompt_additions',
			'execute_role_task',
		);
		foreach ( $methods as $method ) {
			$this->assertTrue( method_exists( $planner, $method ), "Method {$method} missing." );
		}

		$this->assertNotEmpty( $planner->get_recommended_tools() );
		$this->assertNotSame( '', $planner->get_system_prompt_additions() );
	}

	public function test_planner_decomposes_task(): void {
		$planner = new AgentRolePlanner();

		$result = $planner->execute_role_task(
			array(
				'description' => 'Analyze and research the market comprehensively with multiple sources',
			),
			array(
				'assistant_id' => $this->create_assistant(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'planned', $result['status'] );
		$this->assertArrayHasKey( 'subtasks', $result );
		$this->assertNotEmpty( $result['subtasks'] );
		$this->assertNotEmpty( $result['task_id'] );
	}

	public function test_role_task_validation_errors_match_base_codes(): void {
		$planner = new AgentRolePlanner();
		$post_id = $this->create_assistant();

		// Missing description.
		$missing_description = $planner->execute_role_task( array(), array( 'assistant_id' => $post_id ) );
		$this->assertWPError( $missing_description );
		$this->assertSame( 'wp_mcp_ai_invalid_task', $missing_description->get_error_code() );

		// Missing assistant_id in context.
		$missing_context = $planner->execute_role_task( array( 'description' => 'Do a thing' ), array() );
		$this->assertWPError( $missing_context );
		$this->assertSame( 'wp_mcp_ai_invalid_context', $missing_context->get_error_code() );
	}

	public function test_critic_validates_result(): void {
		$critic = new AgentRoleCritic();

		$result = $critic->execute_role_task(
			array(
				'description'        => 'Validate the produced result',
				'result_to_validate' => array( 'data' => 'validated' ),
			),
			array(
				'assistant_id' => $this->create_assistant(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'passes', $result );
		$this->assertArrayHasKey( 'overall_score', $result );
		$this->assertArrayHasKey( 'feedback', $result );
	}

	public function test_executor_degrades_gracefully_without_tool_registry(): void {
		$executor = new AgentRoleExecutor();

		$result = $executor->execute_role_task(
			array(
				'description' => 'Say hello',
				'type'        => 'generic',
			),
			array(
				'assistant_id' => $this->create_assistant(),
			)
		);

		$this->assertIsArray( $result );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith matrix: the base tool registry is available and the
			// generic task type acknowledges without executing any tool.
			$this->assertSame( 'completed', $result['status'] );
			$this->assertSame( 'Task received and acknowledged', $result['result']['message'] );
		} else {
			// Standalone matrix: no tool registry exists, so the executor
			// must fail fast with the canonical error instead of a fatal.
			$this->assertSame( 'failed', $result['status'] );
			$this->assertWPError( $result['result'] );
			$this->assertSame( 'wp_mcp_ai_no_tool_registry', $result['result']->get_error_code() );
		}
	}

	public function test_capability_boundary_contract(): void {
		$boundary = new AgentCapabilityBoundary( 'agents-test-session', array( 'web_search' ) );

		$this->assertSame( 'agents-test-session', $boundary->get_session_id() );
		$this->assertTrue( $boundary->can_execute( 'web_search' ) );
		$this->assertFalse( $boundary->can_execute( 'delete_posts' ) );
		$this->assertFalse( $boundary->is_budget_exhausted() );

		// Record one execution below the default iteration cap (5).
		$boundary->record_execution( 'web_search', array( 'query' => 'test' ), array( 'ok' => true ) );

		$remaining = $boundary->get_remaining_budget();
		$this->assertIsArray( $remaining );
		$this->assertSame( 1, $remaining['iterations_used'] );
		$this->assertGreaterThan( 0, $remaining['iterations_max'] );
		$this->assertSame( 1, $remaining['tool_calls_used'] );
		$this->assertFalse( $boundary->is_budget_exhausted() );

		$boundary->destroy();
	}

	public function test_harness_bootstrap_save_load_roundtrip(): void {
		$assistant_id = $this->create_assistant();

		update_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', 'Evolved prompt v1' );
		update_post_meta( $assistant_id, '_wp_mcp_ai_agent_role', 'planner' );

		$bundle_id = AgentHarnessBootstrap::save_state( $assistant_id, 'agents-test-session' );
		$this->assertIsString( $bundle_id );
		$this->assertStringStartsWith( 'bundle_', $bundle_id );

		$latest = AgentHarnessBootstrap::get_latest_bundle( $assistant_id );
		$this->assertNotNull( $latest );
		$this->assertSame( $bundle_id, $latest['bundle_id'] );
		$this->assertSame( 'Evolved prompt v1', $latest['prompt'] );
		$this->assertSame( 'planner', $latest['roles']['type'] );

		$listed = AgentHarnessBootstrap::list_bundles( $assistant_id );
		$this->assertCount( 1, $listed );
		$this->assertSame( $bundle_id, $listed[0]['bundle_id'] );

		// Mutate current state, then restore from the bundle.
		update_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', 'Evolved prompt v2' );
		$summary = AgentHarnessBootstrap::load_state( $assistant_id, $bundle_id );
		$this->assertIsArray( $summary );
		$this->assertContains( 'prompt', $summary['restored'] );
		$this->assertSame( 'Evolved prompt v1', get_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', true ) );
		$this->assertSame( 1, $summary['generation_count'] );

		$this->assertTrue( AgentHarnessBootstrap::delete_bundle( $bundle_id ) );
		$this->assertNull( AgentHarnessBootstrap::get_latest_bundle( $assistant_id ) );
	}

	public function test_harness_bootstrap_rejects_invalid_input(): void {
		$no_assistant = AgentHarnessBootstrap::save_state( 0, 'agents-test-session' );
		$this->assertWPError( $no_assistant );
		$this->assertSame( 'wp_mcp_ai_bootstrap_invalid_assistant', $no_assistant->get_error_code() );

		$no_session = AgentHarnessBootstrap::save_state( $this->create_assistant(), '' );
		$this->assertWPError( $no_session );
		$this->assertSame( 'wp_mcp_ai_bootstrap_invalid_session', $no_session->get_error_code() );
	}

	public function test_audit_trail_session_lifecycle(): void {
		$assistant_id = $this->create_assistant();

		$trail_id = AgentAuditTrail::start_session(
			'agents-audit-session',
			array(
				'assistant_id' => $assistant_id,
				'provider'     => 'openai',
				'model'        => 'gpt-4',
			)
		);
		$this->assertIsString( $trail_id );
		$this->assertNotSame( '', $trail_id );

		$event = AgentAuditTrail::log_decision(
			$trail_id,
			'task_decomposition',
			array( 'reasoning' => 'Split into two subtasks' )
		);
		$this->assertIsArray( $event );
		$this->assertSame( 'decision', $event['step_type'] );

		$trail = AgentAuditTrail::get_trail( $trail_id );
		$this->assertIsArray( $trail );
		$this->assertCount( 2, $trail );
		$this->assertSame( 'session_start', $trail[0]['step_type'] );
		$this->assertSame( 'decision', $trail[1]['step_type'] );
	}

	public function test_approval_gate_risk_tiers(): void {
		// Low risk: silent auto-approval.
		$this->assertTrue( AgentApprovalGate::request_approval( 'agents-gate-session', 'tool_execution', array(), 'low' ) );

		// Invalid risk level.
		$invalid = AgentApprovalGate::request_approval( 'agents-gate-session', 'tool_execution', array(), 'bogus' );
		$this->assertWPError( $invalid );
		$this->assertSame( 'invalid_risk_level', $invalid->get_error_code() );

		// High risk: pending approval that can be resolved.
		$pending = AgentApprovalGate::request_approval(
			'agents-gate-session',
			'data_modification',
			array( 'assistant_id' => $this->create_assistant() ),
			'high'
		);
		$this->assertWPError( $pending );
		$this->assertSame( 'pending_approval', $pending->get_error_code() );

		$approval_data = $pending->get_error_data();
		$approval_id   = is_array( $approval_data ) && isset( $approval_data['approval_id'] ) ? $approval_data['approval_id'] : '';
		$this->assertNotSame( '', $approval_id );

		$this->assertTrue( AgentApprovalGate::resolve_approval( $approval_id, true, 'Approved by test' ) );

		// Resolving twice must fail.
		$resolved = AgentApprovalGate::resolve_approval( $approval_id, true, 'Again' );
		$this->assertWPError( $resolved );
		$this->assertSame( 'already_resolved', $resolved->get_error_code() );

		// Critical risk: denied without an explicit override.
		$critical = AgentApprovalGate::request_approval( 'agents-gate-session', 'code_execution', array(), 'critical' );
		$this->assertWPError( $critical );
		$this->assertSame( 'approval_denied', $critical->get_error_code() );
	}

	public function test_evolved_prompt_resolver_opt_in_behaviour(): void {
		$assistant_id = $this->create_assistant();
		update_post_meta( $assistant_id, '_wp_mcp_ai_evolved_system_prompt', 'Evolved prompt payload' );

		// Reset the register-once flag: hook globals are wiped between
		// tests, so the filter must be re-registered for this test.
		$reflection = new \ReflectionProperty( EvolvedPromptResolver::class, 'registered' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, false );

		EvolvedPromptResolver::register();

		$this->assertSame(
			15,
			has_filter( 'wp_mcp_ai_resolved_system_prompt', array( EvolvedPromptResolver::class, 'filter' ) )
		);

		// Default: not opted in — prompt passes through unchanged.
		$this->assertSame(
			'base prompt',
			apply_filters( 'wp_mcp_ai_resolved_system_prompt', 'base prompt', $assistant_id, array() )
		);

		// Opt in: the evolved prompt stored in post meta is served.
		add_filter( 'wp_mcp_ai_harness_use_evolved_prompt', '__return_true' );
		$result = apply_filters( 'wp_mcp_ai_resolved_system_prompt', 'base prompt', $assistant_id, array() );
		remove_filter( 'wp_mcp_ai_harness_use_evolved_prompt', '__return_true' );

		$this->assertSame( 'Evolved prompt payload', $result );
	}

	public function test_audit_trail_cpt_registered_in_monolith_matrix(): void {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Standalone matrix: covered by test_audit_trail_cpt_registered_in_standalone_mode.' );
		}

		// The base plugin's agents-init.php registers the audit CPT on init
		// during the test bootstrap.
		$this->assertTrue( post_type_exists( AgentAuditTrail::CPT_SLUG ) );
	}

	public function test_audit_trail_cpt_registered_in_standalone_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin owns the audit trail wiring.' );
		}

		Agents::instance()->register();

		// Agents::register() calls AgentAuditTrail::init(), which hooks
		// register_cpt + schedule_pruning onto `init`. Call them directly
		// instead of re-firing `do_action( 'init' )`, which re-registers
		// WooCommerce blocks/integrations in the local Docker matrix and
		// fails the test with "already registered" incorrect-usage notices.
		AgentAuditTrail::register_cpt();
		AgentAuditTrail::schedule_pruning();

		$this->assertTrue( post_type_exists( AgentAuditTrail::CPT_SLUG ) );

		// Hygiene: drop the pruning cron scheduled by the init hook.
		wp_clear_scheduled_hook( 'wp_mcp_ai_audit_trail_prune' );
	}

	public function test_agents_register_is_safe_in_both_modes(): void {
		// Monolith mode: no-op (base plugin owns the role-system wiring).
		// Standalone mode: wires the ported audit trail, capability
		// boundary gate, and evolved prompt resolver.
		Agents::instance()->register();

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
