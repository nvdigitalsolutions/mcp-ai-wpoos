<?php
/**
 * Partial-update tools for the project-management family (090-F Phase 2).
 *
 * `create_task`/`create_project` are upserts that require a title or name.
 * WordPress also ships `update_task`/`update_project`, which change only the
 * fields supplied — the difference that lets a caller mark a task `completed`
 * without re-sending everything.
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

final class ProjectManagementUpdateTest extends TestCase {

	use RefreshDatabase;

	private ToolRegistry $registry;

	/**
	 * @var array<string, mixed>
	 */
	private array $context;

	protected function setUp(): void {
		parent::setUp();

		$this->registry = $this->app->make( ToolRegistry::class );

		$auth = $this->createMock( AuthProviderInterface::class );
		$auth->method( 'userCan' )->willReturn( true );

		$this->context = array(
			'user_id'       => 1,
			'auth_provider' => $auth,
		);

		Gate::define( 'read_content', static fn ( $user = null ): bool => true );
		Gate::define( 'edit_content', static fn ( $user = null ): bool => true );
		Gate::define( 'delete_content', static fn( $user = null ): bool => true );
	}

	/**
	 * @param array<string, mixed> $arguments Tool arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function runTool( string $slug, array $arguments ): array {
		$result = $this->registry->execute( $slug, $arguments, $this->context );

		$this->assertIsArray( $result, is_object( $result ) ? $result->getMessage() : '' );

		return $result;
	}

	// ─── update_task ────────────────────────────────────────────────

	public function test_update_task_changes_only_the_fields_given(): void {
		$created = $this->runTool(
			'create_task',
			array(
				'title'       => 'Original',
				'description' => 'Keep me',
				'priority'    => 'high',
				'tags'        => 'keep',
			)
		);

		$updated = $this->runTool(
			'update_task',
			array(
				'task_id' => $created['data']['task_id'],
				'status'  => 'completed',
			)
		);

		$task = $updated['data']['task'];

		$this->assertSame( 'completed', $task['status'] );
		$this->assertSame( 'Original', $task['title'] );
		$this->assertSame( 'Keep me', $task['description'] );
		$this->assertSame( 'high', $task['priority'] );
		$this->assertSame( 'keep', $task['tags'] );
	}

	public function test_update_task_can_change_the_title_without_touching_meta(): void {
		$created = $this->runTool( 'create_task', array( 'title' => 'Old', 'priority' => 'urgent' ) );

		$updated = $this->runTool(
			'update_task',
			array(
				'task_id' => $created['data']['task_id'],
				'title'   => 'New',
			)
		);

		$this->assertSame( 'New', $updated['data']['task']['title'] );
		$this->assertSame( 'urgent', $updated['data']['task']['priority'] );
	}

	public function test_update_task_rejects_a_call_with_nothing_to_change(): void {
		$created = $this->runTool( 'create_task', array( 'title' => 'Any' ) );

		$result = $this->registry->execute(
			'update_task',
			array( 'task_id' => $created['data']['task_id'] ),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'at least one field', $result->getMessage() );
	}

	public function test_update_task_validates_the_enum_and_the_date(): void {
		$created = $this->runTool( 'create_task', array( 'title' => 'Any' ) );
		$id      = $created['data']['task_id'];

		$bad_status = $this->registry->execute(
			'update_task',
			array(
				'task_id' => $id,
				'status'  => 'doing',
			),
			$this->context
		);
		$this->assertInstanceOf( \RuntimeException::class, $bad_status );

		$bad_date = $this->registry->execute(
			'update_task',
			array(
				'task_id'  => $id,
				'due_date' => '2026-02-30',
			),
			$this->context
		);
		$this->assertInstanceOf( \RuntimeException::class, $bad_date );
	}

	public function test_update_task_reports_an_unknown_id_as_not_found(): void {
		$result = $this->registry->execute(
			'update_task',
			array(
				'task_id' => 9999,
				'status'  => 'completed',
			),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'Task not found', $result->getMessage() );
	}

	// ─── update_project ─────────────────────────────────────────────

	public function test_update_project_changes_only_the_fields_given(): void {
		$created = $this->runTool(
			'create_project',
			array(
				'name'        => 'Original',
				'description' => 'Keep me',
				'start_date'  => '2026-01-01',
				'assigned_to' => array( 1, 2 ),
			)
		);

		$updated = $this->runTool(
			'update_project',
			array(
				'project_id' => $created['data']['project_id'],
				'status'     => 'active',
			)
		);

		$project = $updated['data']['project'];

		$this->assertSame( 'active', $project['status'] );
		$this->assertSame( 'Original', $project['name'] );
		$this->assertSame( 'Keep me', $project['description'] );
		$this->assertSame( '2026-01-01', $project['start_date'] );
		$this->assertSame( array( '1', '2' ), $project['assigned_to'] );
	}

	public function test_update_project_can_replace_the_assignee_list(): void {
		$created = $this->runTool( 'create_project', array( 'name' => 'Team', 'assigned_to' => array( 1, 2 ) ) );

		$updated = $this->runTool(
			'update_project',
			array(
				'project_id'  => $created['data']['project_id'],
				'assigned_to' => array( 9 ),
			)
		);

		$this->assertSame( array( '9' ), $updated['data']['project']['assigned_to'] );
	}

	public function test_update_project_checks_dates_against_the_stored_value(): void {
		$created = $this->runTool(
			'create_project',
			array(
				'name'       => 'Window',
				'start_date' => '2026-05-01',
			)
		);

		// Only end_date is supplied; it must still not precede the stored start.
		$result = $this->registry->execute(
			'update_project',
			array(
				'project_id' => $created['data']['project_id'],
				'end_date'   => '2026-04-01',
			),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'must not precede', $result->getMessage() );
	}

	public function test_update_project_accepts_a_valid_end_date(): void {
		$created = $this->runTool(
			'create_project',
			array(
				'name'       => 'Window',
				'start_date' => '2026-05-01',
			)
		);

		$updated = $this->runTool(
			'update_project',
			array(
				'project_id' => $created['data']['project_id'],
				'end_date'   => '2026-06-01',
			)
		);

		$this->assertSame( '2026-06-01', $updated['data']['project']['end_date'] );
	}

	public function test_update_project_rejects_a_call_with_nothing_to_change(): void {
		$created = $this->runTool( 'create_project', array( 'name' => 'Any' ) );

		$result = $this->registry->execute(
			'update_project',
			array( 'project_id' => $created['data']['project_id'] ),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'at least one field', $result->getMessage() );
	}

	public function test_update_project_reports_an_unknown_id_as_not_found(): void {
		$result = $this->registry->execute(
			'update_project',
			array(
				'project_id' => 9999,
				'status'     => 'completed',
			),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'Project not found', $result->getMessage() );
	}

	public function test_update_project_cannot_touch_a_task(): void {
		$task = $this->runTool( 'create_task', array( 'title' => 'Not a project' ) );

		$result = $this->registry->execute(
			'update_project',
			array(
				'project_id' => $task['data']['task_id'],
				'status'     => 'completed',
			),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'Project not found', $result->getMessage() );
	}
}
