<?php
/**
 * Project-management family end-to-end test (090-F Phase 2).
 *
 * Seeds nvoos_posts through ContentStoreInterface (type + meta), then executes
 * the three ported read tools through ToolRegistry, proving the whole chain:
 * schema registry → content rows → meta filters → tool output.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\SchemaStoreInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Laravel\Models\NvoosPost;

final class ProjectManagementFamilyTest extends TestCase {

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
	}

	// ─── registry ──────────────────────────────────────────────────

	public function test_registry_declares_the_family(): void {
		$schema = $this->app->make( SchemaStoreInterface::class );

		$task = $schema->getPostType( 'mcp_ai_task' );

		$this->assertNotNull( $task );
		$this->assertSame( 'Tasks', $task->label );
		$this->assertSame( array( 'title', 'editor', 'author' ), $task->supports );
		$this->assertArrayHasKey( '_task_status', $task->metaFields );

		$project = $schema->getPostType( 'mcp_ai_project' );

		$this->assertNotNull( $project );
		$this->assertArrayHasKey( '_project_assigned_to', $project->metaFields );
	}

	public function test_read_tools_are_registered(): void {
		foreach ( array( 'list_projects', 'list_tasks', 'get_task_dependencies' ) as $slug ) {
			$this->assertTrue( $this->registry->has( $slug ), $slug );
		}
	}

	// ─── list_projects ─────────────────────────────────────────────

	public function test_list_projects_returns_seeded_rows_newest_first(): void {
		$older = $this->seedProject( 'Older', array( '_project_status' => 'planning' ) );
		$newer = $this->seedProject( 'Newer', array( '_project_status' => 'active' ) );

		NvoosPost::where( 'id', $older )->update( array( 'created_at' => '2026-01-01 00:00:00' ) );
		NvoosPost::where( 'id', $newer )->update( array( 'created_at' => '2026-02-01 00:00:00' ) );

		$result = $this->registry->execute( 'list_projects', array(), $this->context );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['data']['count'] );
		$this->assertSame( 'Newer', $result['data']['projects'][0]['name'] );
		$this->assertSame( 'active', $result['data']['projects'][0]['status'] );
	}

	public function test_list_projects_filters_by_status(): void {
		$this->seedProject( 'Planning', array( '_project_status' => 'planning' ) );
		$this->seedProject( 'Active', array( '_project_status' => 'active' ) );

		$result = $this->registry->execute( 'list_projects', array( 'status' => 'active' ), $this->context );

		$this->assertSame( 1, $result['data']['count'] );
		$this->assertSame( 'Active', $result['data']['projects'][0]['name'] );
	}

	public function test_list_projects_filters_by_assigned_user(): void {
		$this->seedProject( 'Mine', array( '_project_assigned_to' => array( '7' ) ) );
		$this->seedProject( 'Theirs', array( '_project_assigned_to' => array( '9' ) ) );
		$this->seedProject( 'Unassigned', array() );

		$result = $this->registry->execute( 'list_projects', array( 'assigned_to' => 7 ), $this->context );

		$this->assertSame( 1, $result['data']['count'] );
		$this->assertSame( 'Mine', $result['data']['projects'][0]['name'] );
	}

	public function test_list_projects_filters_by_date_range(): void {
		$this->seedProject( 'Early', array( '_project_start_date' => '2026-01-10' ) );
		$this->seedProject( 'Late', array( '_project_start_date' => '2026-06-10' ) );

		$result = $this->registry->execute( 'list_projects', array( 'start_after' => '2026-03-01' ), $this->context );

		$this->assertSame( 1, $result['data']['count'] );
		$this->assertSame( 'Late', $result['data']['projects'][0]['name'] );
	}

	// ─── list_tasks ────────────────────────────────────────────────

	public function test_list_tasks_filters_by_project_and_status(): void {
		$this->seedTask( 'Alpha', array( '_task_project_id' => 10, '_task_status' => 'todo' ) );
		$this->seedTask( 'Beta', array( '_task_project_id' => 10, '_task_status' => 'completed' ) );
		$this->seedTask( 'Gamma', array( '_task_project_id' => 20, '_task_status' => 'todo' ) );

		$result = $this->registry->execute(
			'list_tasks',
			array(
				'project_id' => 10,
				'status'     => 'todo',
			),
			$this->context
		);

		$this->assertSame( 1, $result['data']['count'] );
		$this->assertSame( 'Alpha', $result['data']['tasks'][0]['title'] );
		$this->assertSame( 10, $result['data']['tasks'][0]['project_id'] );
	}

	public function test_list_tasks_applies_documented_defaults(): void {
		$this->seedTask( 'Bare', array() );

		$result = $this->registry->execute( 'list_tasks', array(), $this->context );
		$task   = $result['data']['tasks'][0];

		$this->assertSame( 'todo', $task['status'] );
		$this->assertSame( 'medium', $task['priority'] );
		$this->assertSame( 'general', $task['category'] );
		$this->assertNull( $task['project_id'] );
		$this->assertNull( $task['assigned_to'] );
		$this->assertNull( $task['estimated_effort'] );
	}

	public function test_list_tasks_filters_by_due_date_window(): void {
		$this->seedTask( 'DueSoon', array( '_task_due_date' => '2026-03-05' ) );
		$this->seedTask( 'DueLater', array( '_task_due_date' => '2026-09-05' ) );

		$result = $this->registry->execute(
			'list_tasks',
			array(
				'due_after'  => '2026-03-01',
				'due_before' => '2026-04-01',
			),
			$this->context
		);

		$this->assertSame( 1, $result['data']['count'] );
		$this->assertSame( 'DueSoon', $result['data']['tasks'][0]['title'] );
	}

	// ─── get_task_dependencies ─────────────────────────────────────

	public function test_dependencies_report_can_start_when_all_are_completed(): void {
		$first  = $this->seedTask( 'First', array( '_task_status' => 'completed' ) );
		$second = $this->seedTask( 'Second', array( '_task_status' => 'completed' ) );
		$child  = $this->seedTask(
			'Child',
			array(
				'_task_status'      => 'todo',
				'_task_depends_on'  => array( $first, $second ),
				'_task_blocks'      => array(),
			)
		);

		$result = $this->registry->execute( 'get_task_dependencies', array( 'task_id' => $child ), $this->context );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, count( $result['data']['depends_on'] ) );
		$this->assertTrue( $result['data']['can_start'] );
		$this->assertSame( 'First', $result['data']['depends_on'][0]['title'] );
	}

	public function test_dependencies_block_start_while_any_is_open(): void {
		$open  = $this->seedTask( 'Open', array( '_task_status' => 'in-progress' ) );
		$child = $this->seedTask( 'Child', array( '_task_depends_on' => array( $open ) ) );

		$result = $this->registry->execute( 'get_task_dependencies', array( 'task_id' => $child ), $this->context );

		$this->assertFalse( $result['data']['can_start'] );
	}

	public function test_dependencies_reports_blocked_tasks(): void {
		$blocker = $this->seedTask( 'Blocker', array( '_task_status' => 'todo' ) );
		$other   = $this->seedTask( 'Other', array( '_task_blocks' => array( $blocker ) ) );

		$result = $this->registry->execute( 'get_task_dependencies', array( 'task_id' => $other ), $this->context );

		$this->assertSame( 1, count( $result['data']['blocks'] ) );
		$this->assertSame( 'Blocker', $result['data']['blocks'][0]['title'] );
	}

	public function test_dependencies_reject_a_non_task_id(): void {
		$project = $this->seedProject( 'A project', array() );

		$result = $this->registry->execute( 'get_task_dependencies', array( 'task_id' => $project ), $this->context );

		$this->assertInstanceOf( \RuntimeException::class, $result );
	}

	// ─── helpers ───────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $meta Meta fields.
	 */
	private function seedProject( string $title, array $meta ): int {
		return $this->seedRecord( 'mcp_ai_project', $title, $meta );
	}

	/**
	 * @param array<string, mixed> $meta Meta fields.
	 */
	private function seedTask( string $title, array $meta ): int {
		return $this->seedRecord( 'mcp_ai_task', $title, $meta );
	}

	/**
	 * @param array<string, mixed> $meta Meta fields.
	 */
	private function seedRecord( string $type, string $title, array $meta ): int {
		$item = $this->content->create(
			new CreateContentCommand(
				title: $title,
				type: $type,
				authorId: 1,
				status: 'publish',
				content: $title . ' body',
				meta: $meta,
			)
		);

		return $item->id;
	}
}
