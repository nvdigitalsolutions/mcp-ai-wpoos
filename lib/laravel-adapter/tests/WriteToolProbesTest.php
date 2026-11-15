<?php
/**
 * Write-tool probe gate (Proposal 090-M §5.6, ADR M-7).
 *
 * A write tool is proven by a refusal, not by a rehearsal: no tool in
 * `lib/core` implements `dry_run`, so the evidence that is available for every
 * tool is that an incomplete or unauthorised call is refused *before* anything
 * is written.
 *
 * The probe therefore does two things per case: asserts a typed refusal
 * (returned or thrown — WordPress returns, Laravel throws) and asserts the
 * content store's row count did not move. Ordering is the property: authorise
 * → validate → write. A happy-path test never catches a violation of it.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Laravel\Models\NvoosPost;
use PHPUnit\Framework\Attributes\DataProvider;

final class WriteToolProbesTest extends TestCase {

	use RefreshDatabase;

	/**
	 * The host owns content abilities; the app defines them, the adapter does
	 * not. Defining them here mirrors the host contract so update/delete can
	 * resolve a target.
	 */
	protected function setUp(): void {
		parent::setUp();

		foreach ( array( 'read_content', 'edit_content', 'delete_content' ) as $ability ) {
			Gate::define( $ability, static fn ( $user = null, $id = null, $userId = null ): bool => true );
		}
	}

	/**
	 * Refusal cases.
	 *
	 * Two guards, tested separately: no context exercises `authorize()` (a
	 * thrown `AccessDeniedException`), and an authenticated-but-empty call
	 * exercises validation (a returned `ErrorFactory` error). Neither may write.
	 *
	 * @return array<int, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	public static function refusals(): array {
		$anonymous = array();
		$minimal   = array( 'user_id' => 1 );

		return array(
			// Content writers (090-M batch 4).
			array( 'create_post', array(), $anonymous ),
			array( 'create_post', array( 'title' => 'Refused write' ), $anonymous ),
			array( 'save_post', array(), $anonymous ),
			array( 'update_post', array( 'post_id' => 999999 ), $anonymous ),
			array( 'delete_post', array( 'post_id' => 999999 ), $anonymous ),

			// Project/task writes already registered before probes existed.
			array( 'create_task', array(), $anonymous ),
			array( 'update_task', array(), $anonymous ),
			array( 'delete_task', array(), $anonymous ),
			array( 'create_project', array(), $anonymous ),
			array( 'delete_project', array(), $anonymous ),
			array( 'create_task', array(), $minimal ),
			array( 'create_project', array(), $minimal ),
			array( 'bulk_import', array(), $anonymous ),
			array( 'bulk_import', array(), $minimal ),
			array( 'update_project', array(), $minimal ),

			// Classroom / ECA writes: the whole family, both guards.
			array( 'bulk_enroll_students', array(), $anonymous ),
			array( 'bulk_enroll_students', array(), $minimal ),
			array( 'create_eca', array(), $anonymous ),
			array( 'create_eca', array(), $minimal ),
			array( 'create_student', array(), $anonymous ),
			array( 'create_student', array(), $minimal ),
			array( 'enroll_student_eca', array(), $anonymous ),
			array( 'enroll_student_eca', array(), $minimal ),
			array( 'manage_eca_waitlist', array(), $anonymous ),
			array( 'manage_eca_waitlist', array(), $minimal ),
			array( 'mark_eca_attendance', array(), $anonymous ),
			array( 'mark_eca_attendance', array(), $minimal ),
			array( 'update_eca', array(), $anonymous ),
			array( 'update_eca', array(), $minimal ),
			array( 'update_student', array(), $anonymous ),
			array( 'update_student', array(), $minimal ),
			array( 'withdraw_student_eca', array(), $anonymous ),
			array( 'withdraw_student_eca', array(), $minimal ),
		);
	}

	/**
	 * @param array<string, mixed> $arguments Tool arguments (never valid + authorised).
	 * @param array<string, mixed> $context   Execution context.
	 */
	#[DataProvider( 'refusals' )]
	public function test_a_refused_write_leaves_the_store_untouched( string $slug, array $arguments, array $context ): void {
		$registry = $this->app->make( ToolRegistry::class );

		$this->assertTrue( $registry->has( $slug ), "Write tool '{$slug}' is not registered." );

		$before = $this->contentRows();

		try {
			$result = $registry->get( $slug )->execute( $arguments, $context );
		} catch ( \Throwable $error ) {
			// A thrown refusal is equally valid: `authorize()` throws in the
			// core tools, while several tools return an ErrorFactory error.
			$result = $error;
		}

		$this->assertInstanceOf(
			\Throwable::class,
			$result,
			"'{$slug}' accepted an incomplete or unauthorised request instead of refusing it."
		);

		$this->assertSame(
			$before,
			$this->contentRows(),
			"'{$slug}' wrote content before refusing the request."
		);
	}

	/**
	 * Every write probe must declare its tier, so a promotion says *how* it was
	 * proven rather than only that it was.
	 */
	public function test_every_probed_write_tool_declares_a_tier(): void {
		$manifest = $this->app->make( ToolReadiness::class );

		foreach ( self::refusals() as $case ) {
			$slug = $case[0];
			$row  = $manifest->get( $slug );

			$this->assertNotNull( $row, "'{$slug}' has a write probe but no manifest row." );
			$this->assertContains(
				$row['probe'] ?? '',
				array( 'validate_only', 'dry_run' ),
				"'{$slug}' is probed but does not declare a probe tier."
			);
			$this->assertTrue( $manifest->isServable( $slug ), "'{$slug}' is probed but not servable." );
			$this->assertFalse(
				$manifest->annotations( $slug )['readOnlyHint'] ?? true,
				"'{$slug}' writes but is annotated read-only."
			);
		}
	}

	/**
	 * `dry_run` must describe the change and write nothing (ADR M-8 phase 2).
	 *
	 * This is the stronger tier: the request is *valid and authorised*, so the
	 * only thing stopping a write is the tool honouring the flag.
	 */
	public function test_dry_run_describes_the_change_without_writing(): void {
		$registry = $this->app->make( ToolRegistry::class );
		$store    = $this->app->make( ContentStoreInterface::class );
		$userId   = 1;

		$before = $this->contentRows();

		// create_post — valid and authorised, refused only by dry_run.
		$create = $registry->get( 'create_post' )->execute(
			array( 'title' => 'Planned post', 'content' => 'Body', 'dry_run' => true ),
			array( 'user_id' => $userId )
		);
		$this->assertIsArray( $create, 'create_post dry run should return the canonical envelope.' );
		$this->assertTrue( $create['success'] ?? false );
		$this->assertTrue( $create['data']['dry_run'] ?? false );
		$this->assertArrayHasKey( 'id', $create['data'], 'A create plan must carry the id key (null).' );
		$this->assertNull( $create['data']['id'], 'A create plan has no id yet.' );
		$this->assertSame( 'create', $create['data']['action'] ?? '' );
		$this->assertSame( $before, $this->contentRows(), 'create_post wrote during a dry run.' );

		// save_post (create branch).
		$save = $registry->get( 'save_post' )->execute(
			array( 'title' => 'Planned via save', 'content' => 'Body', 'dry_run' => true ),
			array( 'user_id' => $userId )
		);
		$this->assertTrue( $save['success'] ?? false );
		$this->assertTrue( $save['data']['dry_run'] ?? false );
		$this->assertSame( $before, $this->contentRows(), 'save_post wrote during a dry run.' );

		// Seed one real post for the update/delete plans.
		$item = $store->create( new CreateContentCommand(
			title: 'Existing post',
			type: 'post',
			authorId: $userId,
			status: 'draft',
			content: 'Original body',
		) );
		$seeded = $this->contentRows();

		// update_post.
		$update = $registry->get( 'update_post' )->execute(
			array( 'post_id' => $item->id, 'title' => 'Changed title', 'dry_run' => true ),
			array( 'user_id' => $userId )
		);
		$this->assertTrue( $update['success'] ?? false, 'update_post dry run should succeed: ' . (string) json_encode( $update ) );
		$this->assertSame( $item->id, $update['data']['id'] ?? null );
		$this->assertSame( 'update', $update['data']['action'] ?? '' );
		$this->assertSame( $seeded, $this->contentRows() );
		$this->assertSame( 'Existing post', $store->find( $item->id )->title, 'update_post applied the dry run.' );

		// delete_post.
		$delete = $registry->get( 'delete_post' )->execute(
			array( 'post_id' => $item->id, 'dry_run' => true ),
			array( 'user_id' => $userId )
		);
		$this->assertTrue( $delete['success'] ?? false );
		$this->assertSame( 'delete', $delete['data']['action'] ?? '' );
		$this->assertSame( $seeded, $this->contentRows(), 'delete_post deleted during a dry run.' );
		$this->assertNotNull( $store->find( $item->id ), 'delete_post removed the post during a dry run.' );
	}

	/**
	 * `dry_run` across the project/task and classroom writes (ADR M-8 phase 3).
	 *
	 * Enrolment/waitlist/attendance tools are not covered here: they need a
	 * populated classroom fixture, so they stay `validate_only` until one exists.
	 */
	public function test_dry_run_covers_project_task_and_classroom_writes(): void {
		$registry = $this->app->make( ToolRegistry::class );
		$store    = $this->app->make( ContentStoreInterface::class );
		$context  = array( 'user_id' => 1 );

		$seed = static function ( string $type, string $title ) use ( $store ) {
			return $store->create( new CreateContentCommand(
				title: $title,
				type: $type,
				authorId: 1,
				status: 'publish',
				content: 'seed',
			) );
		};

		$task     = $seed( 'mcp_ai_task', 'Seed task' );
		$project  = $seed( 'mcp_ai_project', 'Seed project' );
		$eca      = $seed( 'mcp_ai_eca', 'Seed ECA' );
		$student  = $seed( 'mcp_ai_student', 'Seed student' );

		$cases = array(
			array( 'create_task', array( 'title' => 'Planned task' ), 'create', null ),
			array( 'create_project', array( 'name' => 'Planned project' ), 'create', null ),
			array( 'create_eca', array( 'name' => 'Planned ECA' ), 'create', null ),
			array( 'create_student', array( 'first_name' => 'Ada', 'last_name' => 'Lovelace' ), 'create', null ),
			array( 'update_task', array( 'task_id' => $task->id, 'title' => 'Changed' ), 'update', $task->id ),
			array( 'update_project', array( 'project_id' => $project->id, 'name' => 'Changed' ), 'update', $project->id ),
			array( 'update_eca', array( 'eca_id' => $eca->id ), 'update', $eca->id ),
			array( 'update_student', array( 'student_id' => $student->id ), 'update', $student->id ),
			array( 'delete_task', array( 'task_id' => $task->id ), 'delete', $task->id ),
			array( 'delete_project', array( 'project_id' => $project->id ), 'delete', $project->id ),
		);

		foreach ( $cases as $case ) {
			list( $slug, $arguments, $action, $expectedId ) = $case;
			$arguments['dry_run'] = true;

			$before = $this->contentRows();
			$result = $registry->get( $slug )->execute( $arguments, $context );

			$this->assertIsArray( $result, "'{$slug}' dry run should return the canonical envelope: " . (string) json_encode( $result ) );
			$this->assertTrue( $result['success'] ?? false, "'{$slug}' dry run failed: " . (string) json_encode( $result ) );
			$this->assertTrue( $result['data']['dry_run'] ?? false, "'{$slug}' did not report a dry run." );
			$this->assertFalse( $result['data']['writes'] ?? true, "'{$slug}' reported a write during a dry run." );
			$this->assertSame( $action, $result['data']['action'] ?? '', "'{$slug}' reported the wrong action." );
			$this->assertSame( $expectedId, $result['data']['id'] ?? null, "'{$slug}' reported the wrong target id." );
			$this->assertSame( $before, $this->contentRows(), "'{$slug}' wrote during a dry run." );
		}

		// The seeded rows survived every delete plan.
		$this->assertNotNull( $store->find( $task->id ), 'delete_task removed the task during a dry run.' );
		$this->assertNotNull( $store->find( $project->id ), 'delete_project removed the project during a dry run.' );
		$this->assertSame( 'Seed task', $store->find( $task->id )->title );

		// bulk_import has its own preview contract; dry_run forces it.
		$before = $this->contentRows();
		$bulk   = $registry->get( 'bulk_import' )->execute(
			array( 'entity' => 'tasks', 'rows' => array( array( 'title' => 'Imported' ) ), 'dry_run' => true ),
			$context
		);
		$this->assertTrue( $bulk['success'] ?? false, 'bulk_import dry run failed: ' . (string) json_encode( $bulk ) );
		$this->assertTrue( $bulk['data']['dry_run'] ?? false, 'bulk_import did not report a dry run.' );
		$this->assertSame( $before, $this->contentRows(), 'bulk_import wrote during a dry run.' );
	}

	/**
	 * Row count of the local content surface these writers use.
	 */
	private function contentRows(): int {
		return DB::table( ( new NvoosPost() )->getTable() )->count();
	}
}
