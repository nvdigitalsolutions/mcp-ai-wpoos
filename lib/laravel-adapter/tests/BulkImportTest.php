<?php
/**
 * Bulk import for the project-management family (090-F Phase 2).
 *
 * The contract mirrors the toolkit import: a row list, a preview dry run,
 * `skip_existing`, and a report where valid + duplicates + failed equals total.
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
use Nvoos\Laravel\Models\NvoosPost;

final class BulkImportTest extends TestCase {

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
		Gate::define( 'delete_content', static fn ( $user = null ): bool => true );
	}

	/**
	 * @param array<string, mixed> $arguments Tool arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function import( array $arguments ): array {
		$result = $this->registry->execute( 'bulk_import', $arguments, $this->context );

		$this->assertIsArray( $result, is_object( $result ) ? $result->getMessage() : '' );

		return $result;
	}

	private function taskCount(): int {
		return NvoosPost::query()->where( 'type', 'mcp_ai_task' )->count();
	}

	// ─── preview ────────────────────────────────────────────────────

	public function test_preview_reports_without_writing(): void {
		$result = $this->import(
			array(
				'entity'  => 'tasks',
				'preview' => true,
				'rows'    => array(
					array( 'title' => 'One' ),
					array( 'title' => 'Two', 'priority' => 'high' ),
					array( 'title' => '' ),
				),
			)
		);

		$this->assertTrue( $result['data']['preview'] );
		$this->assertSame( 3, $result['data']['total'] );
		$this->assertSame( 2, $result['data']['valid'] );
		$this->assertCount( 1, $result['data']['failed'] );
		$this->assertSame( 0, $result['data']['created'] );
		$this->assertCount( 2, $result['data']['rows'] );

		$this->assertSame( 0, $this->taskCount() );
	}

	// ─── tasks ──────────────────────────────────────────────────────

	public function test_it_creates_the_valid_rows(): void {
		$result = $this->import(
			array(
				'entity' => 'tasks',
				'rows'   => array(
					array( 'title' => 'Alpha', 'status' => 'in-progress', 'priority' => 'high' ),
					array( 'title' => 'Beta', 'tags' => 'bulk' ),
				),
			)
		);

		$this->assertSame( 2, $result['data']['created'] );
		$this->assertSame( 2, $this->taskCount() );

		$alpha = NvoosPost::query()->where( 'type', 'mcp_ai_task' )->where( 'title', 'Alpha' )->first();

		$this->assertSame( 'in-progress', $alpha->meta['_task_status'] );
		$this->assertSame( 'high', $alpha->meta['_task_priority'] );
	}

	public function test_duplicate_titles_inside_the_batch_are_counted(): void {
		$result = $this->import(
			array(
				'entity' => 'tasks',
				'rows'   => array(
					array( 'title' => 'Same' ),
					array( 'title' => ' same ' ),
					array( 'title' => 'Different' ),
				),
			)
		);

		$this->assertSame( 2, $result['data']['created'] );
		$this->assertSame( 1, $result['data']['duplicates'] );
		$this->assertSame( 2, $this->taskCount() );
	}

	public function test_an_existing_title_is_a_duplicate(): void {
		$this->import( array( 'entity' => 'tasks', 'rows' => array( array( 'title' => 'Already' ) ) ) );

		$result = $this->import(
			array( 'entity' => 'tasks', 'rows' => array( array( 'title' => 'ALREADY' ) ) )
		);

		$this->assertSame( 0, $result['data']['created'] );
		$this->assertSame( 1, $result['data']['duplicates'] );
		$this->assertSame( 1, $this->taskCount() );
	}

	public function test_skip_existing_false_reports_a_duplicate_as_a_failure(): void {
		$this->import( array( 'entity' => 'tasks', 'rows' => array( array( 'title' => 'Already' ) ) ) );

		$result = $this->import(
			array(
				'entity'        => 'tasks',
				'skip_existing' => false,
				'rows'          => array( array( 'title' => 'Already' ) ),
			)
		);

		$this->assertSame( 0, $result['data']['duplicates'] );
		$this->assertCount( 1, $result['data']['failed'] );
		$this->assertArrayHasKey( 'title', $result['data']['failed'][0]['fields'] );
	}

	public function test_an_invalid_row_reports_its_index_and_field(): void {
		$result = $this->import(
			array(
				'entity' => 'tasks',
				'rows'   => array(
					array( 'title' => 'Fine' ),
					array( 'title' => 'Bad', 'status' => 'doing' ),
				),
			)
		);

		$this->assertSame( 1, $result['data']['created'] );
		$this->assertSame( 1, $result['data']['failed'][0]['index'] );
		$this->assertArrayHasKey( 'status', $result['data']['failed'][0]['fields'] );
	}

	public function test_the_report_always_reconciles(): void {
		$result = $this->import(
			array(
				'entity' => 'tasks',
				'rows'   => array(
					array( 'title' => 'One' ),
					array( 'title' => 'One' ),
					array( 'title' => '' ),
					'not-an-object',
					array( 'title' => 'Two' ),
				),
			)
		);

		$data = $result['data'];

		$this->assertSame(
			$data['total'],
			$data['valid'] + $data['duplicates'] + count( $data['failed'] ),
			'valid + duplicates + failed must equal total.'
		);
	}

	// ─── projects ───────────────────────────────────────────────────

	public function test_it_imports_projects_with_assignees(): void {
		$result = $this->import(
			array(
				'entity' => 'projects',
				'rows'   => array(
					array(
						'name'        => 'Launch',
						'status'      => 'active',
						'start_date'  => '2026-01-01',
						'end_date'    => '2026-03-01',
						'assigned_to' => array( 3, 4 ),
					),
				),
			)
		);

		$this->assertSame( 1, $result['data']['created'] );

		$row = NvoosPost::query()->where( 'type', 'mcp_ai_project' )->first();

		$this->assertSame( array( '3', '4' ), $row->meta['_project_assigned_to'] );
		$this->assertSame( '2026-03-01', $row->meta['_project_end_date'] );
	}

	public function test_a_project_row_with_inverted_dates_fails(): void {
		$result = $this->import(
			array(
				'entity' => 'projects',
				'rows'   => array(
					array(
						'name'       => 'Backwards',
						'start_date' => '2026-05-01',
						'end_date'   => '2026-04-01',
					),
				),
			)
		);

		$this->assertSame( 0, $result['data']['created'] );
		$this->assertArrayHasKey( 'end_date', $result['data']['failed'][0]['fields'] );
	}

	public function test_projects_and_tasks_do_not_collide(): void {
		$this->import( array( 'entity' => 'tasks', 'rows' => array( array( 'title' => 'Shared' ) ) ) );

		$result = $this->import(
			array( 'entity' => 'projects', 'rows' => array( array( 'name' => 'Shared' ) ) )
		);

		$this->assertSame( 1, $result['data']['created'] );
		$this->assertSame( 0, $result['data']['duplicates'] );
	}

	// ─── request validation ─────────────────────────────────────────

	public function test_an_unknown_entity_is_rejected(): void {
		$result = $this->registry->execute(
			'bulk_import',
			array(
				'entity' => 'invoices',
				'rows'   => array(),
			),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'tasks', $result->getMessage() );
	}

	public function test_rows_must_be_a_list(): void {
		$result = $this->registry->execute(
			'bulk_import',
			array(
				'entity' => 'tasks',
				'rows'   => array( 'title' => 'One' ),
			),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( 'list', $result->getMessage() );
	}

	public function test_the_batch_is_capped(): void {
		$rows = array();

		for ( $i = 0; $i < 501; $i++ ) {
			$rows[] = array( 'title' => 'Task ' . $i );
		}

		$result = $this->registry->execute(
			'bulk_import',
			array(
				'entity' => 'tasks',
				'rows'   => $rows,
			),
			$this->context
		);

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertStringContainsString( '500', $result->getMessage() );
	}
}
