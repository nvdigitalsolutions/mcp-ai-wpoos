<?php
/**
 * Project-management project write path (090-F Phase 2).
 *
 * `create_project` and `delete_project` through ToolRegistry against the real
 * ContentStore, plus the round-trip assertion that a written project reads back
 * through `list_projects` unchanged.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Laravel\Models\NvoosPost;

final class ProjectWriteTest extends TestCase {

	use RefreshDatabase;

	private ContentStoreInterface $content;

	private ToolRegistry $registry;

	/**
	 * @var array<string, mixed>
	 */
	private array $context;

	protected function setUp(): void {
		parent::setUp();

		$this->content  = $this->app->make( ContentStoreInterface::class );
		$this->registry = $this->app->make( ToolRegistry::class );

		$auth = $this->createMock( AuthProviderInterface::class );
		$auth->method( 'userCan' )->willReturn( true );

		$this->context = array(
			'user_id'       => 1,
			'auth_provider' => $auth,
		);

		// See ProjectManagementWriteTest: the adapter's Testbench app does not
		// load the host application's Gate policy, and Gate will not invoke a
		// callback for a guest unless its first parameter is nullable.
		Gate::define( 'read_content', static fn ( $user = null ): bool => true );
		Gate::define( 'edit_content', static fn ( $user = null ): bool => true );
		Gate::define( 'delete_content', static fn ( $user = null ): bool => true );
	}

	/**
	 * @param array<string, mixed> $arguments Tool arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function create( array $arguments ): array {
		$result = $this->registry->execute( 'create_project', $arguments, $this->context );

		$this->assertIsArray( $result, is_object( $result ) ? $result->getMessage() : '' );

		return $result;
	}

	// ─── create_project ─────────────────────────────────────────────

	public function test_create_project_writes_a_row_and_its_meta(): void {
		$result = $this->create(
			array(
				'name'        => 'Q1 Launch',
				'description' => 'Ship the release',
				'status'      => 'active',
				'start_date'  => '2026-01-05',
				'end_date'    => '2026-03-31',
				'assigned_to' => array( 7, 9 ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['data']['updated'] );

		$project = $result['data']['project'];

		$this->assertSame( 'Q1 Launch', $project['name'] );
		$this->assertSame( 'Ship the release', $project['description'] );
		$this->assertSame( 'active', $project['status'] );
		$this->assertSame( '2026-01-05', $project['start_date'] );
		$this->assertSame( '2026-03-31', $project['end_date'] );
		$this->assertSame( array( '7', '9' ), $project['assigned_to'] );

		$stored = $this->content->find( $result['data']['project_id'] );

		$this->assertNotNull( $stored );
		$this->assertSame( 'mcp_ai_project', $stored->type );
		$this->assertSame( array( '7', '9' ), $stored->meta['_project_assigned_to'] );
	}

	public function test_create_project_defaults_to_planning(): void {
		$result = $this->create( array( 'name' => 'Bare project' ) );

		$this->assertSame( 'planning', $result['data']['project']['status'] );
		$this->assertSame( '', $result['data']['project']['start_date'] );
		$this->assertSame( array(), $result['data']['project']['assigned_to'] );
	}

	public function test_create_project_updates_when_project_id_is_supplied(): void {
		$created = $this->create( array( 'name' => 'First', 'status' => 'planning' ) );
		$id      = $created['data']['project_id'];

		$updated = $this->create(
			array(
				'project_id' => $id,
				'name'       => 'First (revised)',
				'status'     => 'completed',
			)
		);

		$this->assertTrue( $updated['data']['updated'] );
		$this->assertSame( $id, $updated['data']['project_id'] );
		$this->assertSame( 'First (revised)', $updated['data']['project']['name'] );
		$this->assertSame( 'completed', $updated['data']['project']['status'] );

		$this->assertSame( 1, NvoosPost::query()->where( 'type', 'mcp_ai_project' )->count() );
	}

	public function test_create_project_replaces_the_assignee_list(): void {
		$created = $this->create( array( 'name' => 'Team', 'assigned_to' => array( 1, 2 ) ) );

		$updated = $this->create(
			array(
				'project_id'  => $created['data']['project_id'],
				'name'        => 'Team',
				'assigned_to' => array( 3 ),
			)
		);

		$this->assertSame( array( '3' ), $updated['data']['project']['assigned_to'] );
	}

	public function test_create_project_deduplicates_assignees(): void {
		$result = $this->create( array( 'name' => 'Dupes', 'assigned_to' => array( 4, 4, '5' ) ) );

		$this->assertSame( array( '4', '5' ), $result['data']['project']['assigned_to'] );
	}

	public function test_create_project_requires_a_name(): void {
		$result = $this->registry->execute( 'create_project', array( 'name' => '  ' ), $this->context );

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'name is required', $result->getMessage() );
	}

	public function test_create_project_rejects_an_unknown_status(): void {
		$result = $this->registry->execute(
			'create_project',
			array( 'name' => 'X', 'status' => 'paused' ),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'Unknown project status', $result->getMessage() );
	}

	public function test_create_project_rejects_an_impossible_date(): void {
		$result = $this->registry->execute(
			'create_project',
			array( 'name' => 'X', 'start_date' => '2026-13-01' ),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'YYYY-MM-DD', $result->getMessage() );
	}

	public function test_create_project_rejects_an_end_before_the_start(): void {
		$result = $this->registry->execute(
			'create_project',
			array(
				'name'       => 'X',
				'start_date' => '2026-05-01',
				'end_date'   => '2026-04-01',
			),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'must not precede', $result->getMessage() );
	}

	public function test_create_project_reports_an_unknown_id_as_not_found(): void {
		$result = $this->registry->execute(
			'create_project',
			array( 'project_id' => 9999, 'name' => 'Ghost' ),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'Project not found', $result->getMessage() );
	}

	// ─── delete_project ─────────────────────────────────────────────

	public function test_delete_project_removes_the_row(): void {
		$created = $this->create( array( 'name' => 'Doomed' ) );
		$id      = $created['data']['project_id'];

		$result = $this->registry->execute( 'delete_project', array( 'project_id' => $id ), $this->context );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $id, $result['data']['project_id'] );
		$this->assertNull( $this->content->find( $id ) );
	}

	public function test_delete_project_reports_an_unknown_id_as_not_found(): void {
		$result = $this->registry->execute( 'delete_project', array( 'project_id' => 9999 ), $this->context );

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'Project not found', $result->getMessage() );
	}

	public function test_delete_project_requires_an_id(): void {
		$result = $this->registry->execute( 'delete_project', array(), $this->context );

		$this->assertInstanceOf( \RuntimeException::class, $result );
	}

	public function test_deleting_a_project_leaves_its_tasks_alone(): void {
		$project = $this->create( array( 'name' => 'Parent' ) );
		$id      = $project['data']['project_id'];

		$task = $this->registry->execute(
			'create_task',
			array( 'title' => 'Child', 'project_id' => $id ),
			$this->context
		);

		$this->registry->execute( 'delete_project', array( 'project_id' => $id ), $this->context );

		$stored = $this->content->find( $task['data']['task_id'] );

		$this->assertNotNull( $stored, 'Deleting a project must not cascade to its tasks.' );
		$this->assertSame( $id, (int) $stored->meta['_task_project_id'] );
	}

	// ─── parity ─────────────────────────────────────────────────────

	public function test_a_written_project_reads_back_through_list_projects(): void {
		$created = $this->create(
			array(
				'name'        => 'Round trip',
				'status'      => 'active',
				'assigned_to' => array( 11 ),
			)
		);

		$listed = $this->registry->execute( 'list_projects', array(), $this->context );

		$this->assertSame( 1, $listed['data']['count'] );

		$this->assertSame(
			$created['data']['project'],
			$listed['data']['projects'][0],
			'list_projects must project the same row create_project returned.'
		);
	}
}
