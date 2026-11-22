<?php
/**
 * Agent orchestration adapter (Proposal 149 §163).
 *
 * These tests pin the behaviour that makes the four orchestration tools safe to
 * serve from this runtime: composition is deterministic and explainable, teams
 * are estate-scoped, and every execution path is one bounded turn run as the
 * caller. The fakes at the bottom of this file are deliberate — one file keeps
 * the fixture next to the assertions that explain it.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Domain\Contract\AgentRunnerInterface;
use Nvoos\Core\Domain\Contract\AssistantServiceInterface;
use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\TenantResolverInterface;
use Nvoos\Laravel\Adapter\AgentOrchestration;
use PHPUnit\Framework\TestCase;

final class AgentOrchestrationTest extends TestCase {

	/** @var ArrayCacheStore */
	private ArrayCacheStore $cache;

	/** @var StubAssistantService */
	private StubAssistantService $assistants;

	/** @var RecordingAgentRunner */
	private RecordingAgentRunner $runner;

	/** @var MutableTenantResolver */
	private MutableTenantResolver $tenant;

	/** @var AgentOrchestration */
	private AgentOrchestration $orchestration;

	protected function setUp(): void {
		parent::setUp();

		$this->cache       = new ArrayCacheStore();
		$this->assistants  = new StubAssistantService(
			array(
				10 => array(
					'title' => 'Research Analyst',
					'slug'  => 'research-analyst',
					'roles' => array( 'researcher' ),
				),
				11 => array(
					'title' => 'Copywriter',
					'slug'  => 'copywriter',
					'roles' => array( 'writer' ),
				),
				12 => array(
					'title' => 'Editor',
					'slug'  => 'editor',
					'roles' => array( 'editor' ),
				),
				13 => array(
					'title' => 'Fact Checker',
					'slug'  => 'fact-checker',
					'roles' => array( 'checker' ),
				),
				14 => array(
					'title' => 'Publisher',
					'slug'  => 'publisher',
					'roles' => array( 'publisher' ),
				),
				15 => array(
					'title' => 'General Assistant',
					'slug'  => 'general',
					'roles' => array(),
				),
			),
			15
		);
		$this->runner      = new RecordingAgentRunner();
		$this->tenant      = new MutableTenantResolver( 'estate-a' );
		$this->orchestration = new AgentOrchestration(
			$this->cache,
			$this->assistants,
			$this->runner,
			$this->tenant
		);
	}

	public function test_unconfigured_host_reports_an_honest_failure(): void {
		$bare = new AgentOrchestration( new ArrayCacheStore() );

		$composed = $bare->composeTeam( array( 'task' => 'Draft a launch brief' ) );

		$this->assertFalse( $composed['success'] );
		$this->assertStringContainsString( 'assistant host', $composed['error'] );

		$this->assertSame( array( 'found' => false ), $bare->getTeamStatus( 'team_x' ) );

		$workflow = $bare->executeWorkflow( 'team_x', array( 'steps' => array( array( 'instruction' => 'go' ) ) ) );

		$this->assertFalse( $workflow['success'] );
		$this->assertSame( array(), $workflow['results'] );

		$delegated = $bare->delegateToAgent( 'team_x', '10', array( 'instruction' => 'go' ) );

		$this->assertFalse( $delegated['success'] );
	}

	public function test_compose_requires_a_task(): void {
		$result = $this->orchestration->composeTeam( array( 'roles' => array( 'writer' ) ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'task description', $result['error'] );
	}

	public function test_compose_fails_when_the_estate_has_no_assistants(): void {
		$orchestration = new AgentOrchestration(
			$this->cache,
			new StubAssistantService( array(), 0 ),
			$this->runner,
			$this->tenant
		);

		$result = $orchestration->composeTeam( array( 'task' => 'Do the thing' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'No assistants', $result['error'] );
	}

	public function test_compose_prefers_an_explicit_agents_map_over_the_slug(): void {
		$result = $this->orchestration->composeTeam(
			array(
				'task'  => 'Write the launch copy',
				'roles' => array( 'writer' ),
				// The slug for `writer` is Copywriter (11); the caller asked for 10.
				'agents' => array( 'writer' => 10 ),
			)
		);

		$this->assertTrue( $result['success'] );

		$members = $result['team']['members'];

		$this->assertCount( 1, $members );
		$this->assertSame( 10, $members[0]['assistant_id'] );
		$this->assertSame( 'explicit', $members[0]['matched_by'] );
	}

	public function test_compose_resolves_slug_then_primary_role_then_title(): void {
		$bySlug = $this->composeSingle( 'copywriter' );

		$this->assertSame( 11, $bySlug['assistant_id'] );
		$this->assertSame( 'slug', $bySlug['matched_by'] );

		$byRole = $this->composeSingle( 'researcher' );

		$this->assertSame( 10, $byRole['assistant_id'] );
		$this->assertSame( 'primary_role', $byRole['matched_by'] );

		$byTitle = $this->composeSingle( 'General Assistant' );

		$this->assertSame( 15, $byTitle['assistant_id'] );
		$this->assertSame( 'title', $byTitle['matched_by'] );
	}

	public function test_compose_falls_back_to_the_default_assistant(): void {
		$member = $this->composeSingle( 'unstaffed-role' );

		$this->assertSame( 15, $member['assistant_id'] );
		$this->assertSame( 'default', $member['matched_by'] );
	}

	public function test_compose_defaults_empty_roles_to_one_default_member(): void {
		$result = $this->orchestration->composeTeam( array( 'task' => 'Triage the inbox' ) );

		$this->assertTrue( $result['success'] );

		$members = $result['team']['members'];

		$this->assertCount( 1, $members );
		$this->assertSame( 'default', $members[0]['role'] );
		$this->assertSame( 15, $members[0]['assistant_id'] );
	}

	public function test_compose_caps_the_team_at_five_members(): void {
		$roles = array( 'writer', 'researcher', 'editor', 'checker', 'publisher' );

		$result = $this->orchestration->composeTeam(
			array(
				'task'   => 'Ship the campaign',
				'roles'  => array_merge( $roles, array( 'writer' ) ),
				'agents' => array(
					'writer'     => 11,
					'researcher' => 10,
					'editor'     => 12,
					'checker'    => 13,
					'publisher'  => 14,
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertCount( AgentOrchestration::MAX_MEMBERS, $result['team']['members'] );
	}

	public function test_compose_deduplicates_members(): void {
		$result = $this->orchestration->composeTeam(
			array(
				'task'  => 'Write it twice',
				'roles' => array( 'writer', 'writer' ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $result['team']['members'] );
	}

	public function test_team_round_trips_through_the_cache_and_expires(): void {
		$teamId = $this->composeTeamId();

		$status = $this->orchestration->getTeamStatus( $teamId );

		$this->assertTrue( $status['found'] );
		$this->assertSame( $teamId, $status['team']['id'] );

		// Rewind the stored expiry: the same lookup must then refuse the team.
		$key  = 'agent_team:estate-a:' . $teamId;
		$team = $this->cache->values[ $key ];

		$team['expires_at'] = gmdate( 'c', time() - 60 );

		$this->cache->setValue( $key, $team, 60 );

		$this->assertSame( array( 'found' => false ), $this->orchestration->getTeamStatus( $teamId ) );
	}

	public function test_teams_are_namespaced_per_estate(): void {
		$teamId = $this->composeTeamId();

		$this->assertArrayHasKey( 'agent_team:estate-a:' . $teamId, $this->cache->values );

		$this->tenant->id = 'estate-b';

		$this->assertSame( array( 'found' => false ), $this->orchestration->getTeamStatus( $teamId ) );

		$delegated = $this->orchestration->delegateToAgent( $teamId, '11', array( 'instruction' => 'go' ) );

		$this->assertFalse( $delegated['success'] );
	}

	public function test_delegate_refuses_an_agent_outside_the_team(): void {
		$teamId = $this->composeTeamId( array( 'writer' ) );

		$refused = $this->orchestration->delegateToAgent( $teamId, '10', array( 'instruction' => 'go' ) );

		$this->assertFalse( $refused['success'] );
		$this->assertStringContainsString( 'not a member', $refused['error'] );
		$this->assertSame( array(), $this->runner->calls );

		$allowed = $this->orchestration->delegateToAgent( $teamId, '11', array( 'instruction' => 'go' ) );

		$this->assertTrue( $allowed['success'] );
		$this->assertSame( 'done:11', $allowed['result'] );
	}

	public function test_delegate_runs_as_the_caller_and_passes_context_unchanged(): void {
		$teamId = $this->composeTeamId( array( 'writer' ) );

		$this->orchestration->delegateToAgent(
			$teamId,
			'11',
			array( 'instruction' => 'Draft the intro' ),
			array( 'user_id' => 7 )
		);

		$this->assertCount( 1, $this->runner->calls );
		$this->assertSame( 11, $this->runner->calls[0]['assistantId'] );
		$this->assertSame( 'Draft the intro', $this->runner->calls[0]['prompt'] );
		$this->assertSame( 7, $this->runner->calls[0]['userId'] );
		// Depth 1 is structural: the runner receives only the caller's context,
		// so it has no handle on the orchestrator to fan out again.
		$this->assertSame( array( 'user_id' => 7 ), $this->runner->calls[0]['context'] );
	}

	public function test_workflow_runs_each_step_and_threads_prior_results(): void {
		$teamId = $this->composeTeamId( array( 'researcher', 'writer' ) );

		$result = $this->orchestration->executeWorkflow(
			$teamId,
			array(
				'steps' => array(
					array(
						'role'        => 'researcher',
						'instruction' => 'Gather the facts',
					),
					array(
						'role'        => 'writer',
						'instruction' => 'Write the brief',
					),
				),
			),
			array( 'user_id' => 3 )
		);

		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $result['results'] );
		$this->assertSame( 10, $result['results'][0]['assistant_id'] );
		$this->assertSame( 11, $result['results'][1]['assistant_id'] );

		$this->assertSame( 'Gather the facts', $this->runner->calls[0]['prompt'] );
		$this->assertStringContainsString( 'Previous step results:', $this->runner->calls[1]['prompt'] );
		$this->assertStringContainsString( 'done:10', $this->runner->calls[1]['prompt'] );
		$this->assertSame( 3, $this->runner->calls[1]['userId'] );
	}

	public function test_workflow_can_address_an_agent_by_id(): void {
		$teamId = $this->composeTeamId( array( 'researcher', 'writer' ) );

		$result = $this->orchestration->executeWorkflow(
			$teamId,
			array(
				'steps' => array(
					array(
						'agent_id'    => 11,
						'instruction' => 'Write it',
					),
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 11, $this->runner->calls[0]['assistantId'] );
	}

	public function test_workflow_refuses_more_than_ten_steps(): void {
		$teamId = $this->composeTeamId();
		$steps  = array();

		for ( $i = 0; $i < AgentOrchestration::MAX_STEPS + 1; $i++ ) {
			$steps[] = array( 'instruction' => 'step ' . $i );
		}

		$result = $this->orchestration->executeWorkflow( $teamId, array( 'steps' => $steps ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'at most 10 steps', $result['error'] );
		$this->assertSame( array(), $this->runner->calls );
	}

	public function test_workflow_requires_steps(): void {
		$teamId = $this->composeTeamId();

		$result = $this->orchestration->executeWorkflow( $teamId, array( 'steps' => array() ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'at least one step', $result['error'] );
	}

	public function test_workflow_refuses_a_step_with_an_unknown_role(): void {
		$teamId = $this->composeTeamId( array( 'researcher', 'writer' ) );

		$result = $this->orchestration->executeWorkflow(
			$teamId,
			array(
				'steps' => array(
					array(
						'role'        => 'nobody',
						'instruction' => 'go',
					),
				),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'no resolvable agent', $result['error'] );
		$this->assertSame( array(), $this->runner->calls );
	}

	public function test_workflow_refuses_a_step_without_an_instruction(): void {
		$teamId = $this->composeTeamId( array( 'researcher' ) );

		$result = $this->orchestration->executeWorkflow(
			$teamId,
			array( 'steps' => array( array( 'role' => 'researcher' ) ) )
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'no instruction', $result['error'] );
	}

	public function test_a_single_member_team_answers_an_unlabelled_step(): void {
		$teamId = $this->composeTeamId( array( 'researcher' ) );

		$result = $this->orchestration->executeWorkflow(
			$teamId,
			array( 'steps' => array( array( 'instruction' => 'go' ) ) )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 10, $this->runner->calls[0]['assistantId'] );
	}

	/**
	 * Compose a team and return one member.
	 *
	 * @return array<string, mixed>
	 */
	private function composeSingle( string $role ): array {
		$result = $this->orchestration->composeTeam(
			array(
				'task'  => 'Compose for ' . $role,
				'roles' => array( $role ),
			)
		);

		$this->assertTrue( $result['success'], $result['error'] ?? 'composition failed' );

		return $result['team']['members'][0];
	}

	/**
	 * @param array<int, string> $roles
	 */
	private function composeTeamId( array $roles = array( 'writer' ) ): string {
		$result = $this->orchestration->composeTeam(
			array(
				'task'  => 'Compose a team',
				'roles' => $roles,
			)
		);

		$this->assertTrue( $result['success'], $result['error'] ?? 'composition failed' );

		return $result['team']['id'];
	}
}

/**
 * In-memory cache store, mirroring the core test fixture.
 */
final class ArrayCacheStore implements CacheStoreInterface {

	/** @var array<string, mixed> */
	public array $values = array();

	/** @var string[] */
	public array $writes = array();

	public function getValue( string $key, mixed $default = null ): mixed {
		return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $default;
	}

	public function setValue( string $key, mixed $value, int $ttl = 3600 ): bool {
		$this->values[ $key ] = $value;
		$this->writes[]       = $key;

		return true;
	}

	public function deleteValue( string $key ): bool {
		unset( $this->values[ $key ] );

		return true;
	}

	public function increment( string $key, int $by = 1, int $ttl = 3600 ): int {
		$current            = (int) ( $this->values[ $key ] ?? 0 );
		$this->values[ $key ] = $current + $by;

		return $current + $by;
	}

	public function remember( string $key, int $ttl, callable $callback ): mixed {
		if ( array_key_exists( $key, $this->values ) ) {
			return $this->values[ $key ];
		}

		$value = $callback();

		$this->setValue( $key, $value, $ttl );

		return $value;
	}
}

/**
 * Assistant catalogue with fixed records.
 */
final class StubAssistantService implements AssistantServiceInterface {

	/**
	 * @param array<int, array{title: string, slug: string, roles: array<int, string>}> $records
	 */
	public function __construct( private array $records, private int $defaultId ) {
	}

	public function validate( int $assistantId, int $userId = 0 ): array {
		return array(
			'valid'   => isset( $this->records[ $assistantId ] ),
			'config'  => $this->records[ $assistantId ] ?? array(),
		);
	}

	public function getConfig( int $assistantId ): array {
		if ( ! isset( $this->records[ $assistantId ] ) ) {
			return array( 'found' => false );
		}

		return array(
			'found'  => true,
			'config' => array(
				'id'                => $assistantId,
				'title'             => $this->records[ $assistantId ]['title'],
				'slug'              => $this->records[ $assistantId ]['slug'],
				'primary_role_slugs' => $this->records[ $assistantId ]['roles'],
			),
		);
	}

	public function getDefault(): array {
		if ( 0 === $this->defaultId || ! isset( $this->records[ $this->defaultId ] ) ) {
			return array( 'found' => false );
		}

		return array(
			'found'        => true,
			'assistant_id' => $this->defaultId,
			'config'       => $this->getConfig( $this->defaultId )['config'],
		);
	}

	public function listForUser( int $userId = 0 ): array {
		$rows = array();

		foreach ( $this->records as $id => $record ) {
			$rows[] = array(
				'id'     => $id,
				'title'  => $record['title'],
				'status' => 'publish',
			);
		}

		return $rows;
	}
}

/**
 * Runner that records every call and answers deterministically.
 */
final class RecordingAgentRunner implements AgentRunnerInterface {

	/** @var array<int, array{assistantId: int, prompt: string, userId: int, context: array<string, mixed>}> */
	public array $calls = array();

	public function run( int $assistantId, string $prompt, int $userId, array $context = array() ): array {
		$this->calls[] = array(
			'assistantId' => $assistantId,
			'prompt'      => $prompt,
			'userId'      => $userId,
			'context'     => $context,
		);

		return array(
			'iterations' => 1,
			'response'   => 'done:' . $assistantId,
		);
	}
}

/**
 * Estate resolver whose value the test can move.
 */
final class MutableTenantResolver implements TenantResolverInterface {

	public function __construct( public string $id ) {
	}

	public function current(): string {
		return $this->id;
	}
}
