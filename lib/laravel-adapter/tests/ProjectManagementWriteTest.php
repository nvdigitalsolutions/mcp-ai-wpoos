<?php
/**
 * Project-management write path (090-F Phase 2).
 *
 * Exercises `create_task` and `delete_task` through ToolRegistry against the
 * real ContentStore, so the chain under test is the one that ships: tool →
 * create/update command → nvoos_posts row → meta read back through TaskRow.
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

final class ProjectManagementWriteTest extends TestCase {

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

		// The content store authorises through Gate, and the application's
		// policy lives in its own provider. Define the abilities here so these
		// tests measure the tool, not the host app's capability map. The first
		// parameter must be nullable: Gate refuses to invoke a callback for a
		// guest when its signature cannot accept one.
		Gate::define( 'read_content', static fn ( $user = null ): bool => true );
		Gate::define( 'edit_content', static fn ( $user = null ): bool => true );
		Gate::define( 'delete_content', static fn ( $user = null ): bool => true );
	}

	/**
	 * @param array<string, mixed> $arguments Tool arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function runTool( array $arguments ): array {
		$result = $this->registry->execute( 'create_task', $arguments, $this->context );

		$this->assertIsArray( $result, is_object( $result ) ? $result->getMessage() : '' );

		return $result;
	}

	// ─── create_task ────────────────────────────────────────────────

	public function test_create_task_writes_a_row_and_its_meta(): void {
		$result = $this->runTool(
			array(
				'title'       => 'Ship the adapter',
				'description' => 'Port the write path',
				'project_id'  => 7,
				'status'      => 'in-progress',
				'priority'    => 'high',
				'due_date'    => '2026-12-01',
				'assigned_to' => 42,
				'tags'        => 'adapter,laravel',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['data']['updated'] );

		$task = $result['data']['task'];

		$this->assertSame( 'Ship the adapter', $task['title'] );
		$this->assertSame( 'Port the write path', $task['description'] );
		$this->assertSame( 7, $task['project_id'] );
		$this->assertSame( 'in-progress', $task['status'] );
		$this->assertSame( 'high', $task['priority'] );
		$this->assertSame( '2026-12-01', $task['due_date'] );
		$this->assertSame( 42, $task['assigned_to'] );
		$this->assertSame( 'adapter,laravel', $task['tags'] );

		$stored = $this->content->find( $result['data']['task_id'] );

		$this->assertNotNull( $stored );
		$this->assertSame( 'mcp_ai_task', $stored->type );
		$this->assertSame( 'publish', $stored->status );
		$this->assertSame( 'in-progress', $stored->meta['_task_status'] );
	}

	public function test_create_task_applies_the_documented_defaults(): void {
		$result = $this->runTool( array( 'title' => 'Bare task' ) );

		$task = $result['data']['task'];

		$this->assertSame( 'todo', $task['status'] );
		$this->assertSame( 'medium', $task['priority'] );
		$this->assertSame( 'general', $task['category'] );
		$this->assertNull( $task['project_id'] );
		$this->assertNull( $task['assigned_to'] );
	}

	public function test_create_task_updates_when_task_id_is_supplied(): void {
		$created = $this->runTool( array( 'title' => 'First', 'priority' => 'low' ) );
		$id      = $created['data']['task_id'];

		$updated = $this->runTool(
			array(
				'task_id'  => $id,
				'title'    => 'First (revised)',
				'priority' => 'urgent',
				'status'   => 'review',
			)
		);

		$this->assertTrue( $updated['data']['updated'] );
		$this->assertSame( $id, $updated['data']['task_id'] );
		$this->assertSame( 'First (revised)', $updated['data']['task']['title'] );
		$this->assertSame( 'urgent', $updated['data']['task']['priority'] );
		$this->assertSame( 'review', $updated['data']['task']['status'] );

		$this->assertSame( 1, NvoosPost::query()->where( 'type', 'mcp_ai_task' )->count() );
	}

	public function test_create_task_keeps_meta_it_was_not_given(): void {
		$created = $this->runTool(
			array(
				'title'    => 'Keep me',
				'priority' => 'high',
				'tags'     => 'keep',
			)
		);

		$updated = $this->runTool( array( 'task_id' => $created['data']['task_id'], 'title' => 'Renamed' ) );

		$this->assertSame( 'high', $updated['data']['task']['priority'] );
		$this->assertSame( 'keep', $updated['data']['task']['tags'] );
	}

	public function test_create_task_requires_a_title(): void {
		$result = $this->registry->execute( 'create_task', array( 'title' => '   ' ), $this->context );

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'title is required', $result->getMessage() );
	}

	public function test_create_task_rejects_an_unknown_status(): void {
		$result = $this->registry->execute(
			'create_task',
			array( 'title' => 'X', 'status' => 'doing' ),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'Unknown task status', $result->getMessage() );
	}

	public function test_create_task_rejects_an_impossible_date(): void {
		$result = $this->registry->execute(
			'create_task',
			array( 'title' => 'X', 'due_date' => '2026-02-31' ),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'YYYY-MM-DD', $result->getMessage() );
	}

	public function test_create_task_reports_an_unknown_task_id_as_not_found(): void {
		$result = $this->registry->execute(
			'create_task',
			array( 'task_id' => 9999, 'title' => 'Ghost' ),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'Task not found', $result->getMessage() );
	}

	// ─── delete_task ────────────────────────────────────────────────

	public function test_delete_task_removes_the_row(): void {
		$created = $this->runTool( array( 'title' => 'Doomed' ) );
		$id      = $created['data']['task_id'];

		$result = $this->registry->execute( 'delete_task', array( 'task_id' => $id ), $this->context );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $id, $result['data']['task_id'] );
		$this->assertNull( $this->content->find( $id ) );
	}

	public function test_delete_task_reports_an_unknown_id_as_not_found(): void {
		$result = $this->registry->execute( 'delete_task', array( 'task_id' => 9999 ), $this->context );

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'Task not found', $result->getMessage() );
	}

	public function test_delete_task_requires_an_id(): void {
		$result = $this->registry->execute( 'delete_task', array(), $this->context );

		$this->assertInstanceOf( \RuntimeException::class, $result );
	}

	// ─── parity ─────────────────────────────────────────────────────

	public function test_a_written_task_reads_back_through_list_tasks(): void {
		$created = $this->runTool(
			array(
				'title'      => 'Round trip',
				'project_id' => 3,
				'status'     => 'completed',
				'tags'       => 'rt',
			)
		);

		$listed = $this->registry->execute( 'list_tasks', array( 'project_id' => 3 ), $this->context );

		$this->assertSame( 1, $listed['data']['count'] );

		$row = $listed['data']['tasks'][0];

		$this->assertSame( $created['data']['task_id'], $row['id'] );
		$this->assertSame(
			$created['data']['task'],
			$row,
			'list_tasks must project the same row create_task returned.'
		);
		$this->assertSame( 'completed', $row['status'] );
		$this->assertSame( 'rt', $row['tags'] );
	}
}
