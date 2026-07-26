<?php
/**
 * Tests for Batch 1b tools — cron job management.
 *
 * Covers: CreateCronJobTool, CreateCronJobValidatedTool,
 * DeleteCronJobTool, ListCronJobsTool, GetCronJobTool.
 *
 * @package Nvoos\Core\Tests
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Entity\JobStatus;
use Nvoos\Core\Tool\CreateCronJobTool;
use Nvoos\Core\Tool\CreateCronJobValidatedTool;
use Nvoos\Core\Tool\DeleteCronJobTool;
use Nvoos\Core\Tool\GetCronJobTool;
use Nvoos\Core\Tool\ListCronJobsTool;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

final class BatchOneBToolsTest extends TestCase {

	private QueueClientInterface $queue;
	private ErrorFactoryInterface $errorFactory;

	protected function setUp(): void {
		$this->queue       = $this->createMock( QueueClientInterface::class );
		$this->errorFactory = $this->createMock( ErrorFactoryInterface::class );
	}

	private function errorResponse( string $code, string $message ): array {
		return array(
			'success' => false,
			'error'   => array( 'code' => $code, 'message' => $message ),
		);
	}

	private function makeJobStatus( string $id = 'job_1', string $status = 'queued' ): JobStatus {
		return new JobStatus(
			jobId: $id,
			status: $status,
			queuedAt: new DateTimeImmutable(),
		);
	}

	// ═══════════════════════════════════════════════════════════════════
	// CreateCronJobTool
	// ═══════════════════════════════════════════════════════════════════

	public function testCreateCronJobSlug(): void {
		$tool = new CreateCronJobTool( $this->errorFactory, $this->queue );
		$this->assertSame( 'create_cron_job', $tool->getSlug() );
	}

	public function testCreateCronJobSingle(): void {
		$this->queue->expects( $this->once() )
			->method( 'enqueue' )
			->willReturn( 'job_abc123' );

		$tool   = new CreateCronJobTool( $this->errorFactory, $this->queue );
		$result = $tool->execute(
			array( 'hook' => 'my_custom_hook', 'args' => array( 'key' => 'val' ) ),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'job_abc123', $result['data']['job_id'] );
		$this->assertSame( 'single', $result['data']['schedule'] );
	}

	public function testCreateCronJobRecurring(): void {
		$this->queue->expects( $this->once() )
			->method( 'schedule' )
			->with( 'hourly_task', $this->anything(), 'hourly' )
			->willReturn( 'sched_xyz' );

		$tool   = new CreateCronJobTool( $this->errorFactory, $this->queue );
		$result = $tool->execute(
			array( 'hook' => 'hourly_task', 'schedule' => 'hourly' ),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'sched_xyz', $result['data']['schedule_id'] );
	}

	public function testCreateCronJobMissingHook(): void {
		$this->errorFactory->method( 'validationFailed' )
			->willReturn( $this->errorResponse( 'validation_failed', 'A valid hook name is required.' ) );

		$tool   = new CreateCronJobTool( $this->errorFactory, $this->queue );
		$result = $tool->execute( array(), array( 'user_id' => 1 ) );

		$this->assertFalse( $result['success'] );
	}

	public function testCreateCronJobNotLoggedIn(): void {
		$this->errorFactory->method( 'forbidden' )
			->willReturn( $this->errorResponse( 'forbidden', 'You must be logged in.' ) );

		$tool   = new CreateCronJobTool( $this->errorFactory, $this->queue );
		$result = $tool->execute( array( 'hook' => 'test' ), array() );

		$this->assertFalse( $result['success'] );
	}

	// ═══════════════════════════════════════════════════════════════════
	// CreateCronJobValidatedTool
	// ═══════════════════════════════════════════════════════════════════

	public function testCreateCronJobValidatedSlug(): void {
		$tool = new CreateCronJobValidatedTool( $this->errorFactory, $this->queue );
		$this->assertSame( 'create_cron_job_validated', $tool->getSlug() );
	}

	public function testCreateCronJobValidatedSchedulesRecurring(): void {
		$this->queue->expects( $this->once() )
			->method( 'schedule' )
			->willReturn( 'sched_v1' );

		$tool   = new CreateCronJobValidatedTool( $this->errorFactory, $this->queue );
		$result = $tool->execute(
			array( 'hook' => 'daily_cleanup', 'schedule' => 'daily' ),
			array( 'user_id' => 2 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'sched_v1', $result['data']['schedule_id'] );
	}

	// ═══════════════════════════════════════════════════════════════════
	// DeleteCronJobTool
	// ═══════════════════════════════════════════════════════════════════

	public function testDeleteCronJobSlug(): void {
		$tool = new DeleteCronJobTool( $this->errorFactory, $this->queue );
		$this->assertSame( 'delete_cron_job', $tool->getSlug() );
	}

	public function testDeleteCronJobSuccess(): void {
		$status = $this->makeJobStatus( 'job_del', 'queued' );

		$this->queue->method( 'getStatus' )->willReturn( $status );
		$this->queue->expects( $this->once() )
			->method( 'cancel' )
			->with( 'job_del' )
			->willReturn( true );

		$tool   = new DeleteCronJobTool( $this->errorFactory, $this->queue );
		$result = $tool->execute(
			array( 'job_id' => 'job_del' ),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'job_del', $result['data']['job_id'] );
	}

	public function testDeleteCronJobNotFound(): void {
		$this->queue->method( 'getStatus' )
			->willThrowException( new \RuntimeException( 'Not found' ) );

		$this->errorFactory->method( 'notFound' )
			->willReturn( $this->errorResponse( 'not_found', 'The specified task was not found.' ) );

		$tool   = new DeleteCronJobTool( $this->errorFactory, $this->queue );
		$result = $tool->execute(
			array( 'job_id' => 'missing' ),
			array( 'user_id' => 1 ),
		);

		$this->assertFalse( $result['success'] );
	}

	public function testDeleteCronJobCancelFails(): void {
		$status = $this->makeJobStatus( 'job_running', 'running' );

		$this->queue->method( 'getStatus' )->willReturn( $status );
		$this->queue->method( 'cancel' )->willReturn( false );

		$this->errorFactory->method( 'create' )
			->willReturn( $this->errorResponse( 'delete_failed', 'Failed to delete.' ) );

		$tool   = new DeleteCronJobTool( $this->errorFactory, $this->queue );
		$result = $tool->execute(
			array( 'job_id' => 'job_running' ),
			array( 'user_id' => 1 ),
		);

		$this->assertFalse( $result['success'] );
	}

	// ═══════════════════════════════════════════════════════════════════
	// ListCronJobsTool
	// ═══════════════════════════════════════════════════════════════════

	public function testListCronJobsSlug(): void {
		$tool = new ListCronJobsTool( $this->errorFactory, $this->queue );
		$this->assertSame( 'list_cron_jobs', $tool->getSlug() );
	}

	public function testListCronJobsReturnsJobs(): void {
		$jobs = array(
			$this->makeJobStatus( 'j1', 'queued' ),
			$this->makeJobStatus( 'j2', 'completed' ),
		);

		$this->queue->method( 'listJobs' )->willReturn( $jobs );

		$tool   = new ListCronJobsTool( $this->errorFactory, $this->queue );
		$result = $tool->execute( array(), array( 'user_id' => 1 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['data']['total'] );
	}

	public function testListCronJobsEmpty(): void {
		$this->queue->method( 'listJobs' )->willReturn( array() );

		$tool   = new ListCronJobsTool( $this->errorFactory, $this->queue );
		$result = $tool->execute( array(), array() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['data']['count'] );
	}

	public function testListCronJobsClampsLimit(): void {
		$this->queue->expects( $this->once() )
			->method( 'listJobs' )
			->with( array(), 100 )
			->willReturn( array() );

		$tool = new ListCronJobsTool( $this->errorFactory, $this->queue );
		$tool->execute( array( 'limit' => 999 ), array() );

		$this->assertTrue( true );
	}

	// ═══════════════════════════════════════════════════════════════════
	// GetCronJobTool
	// ═══════════════════════════════════════════════════════════════════

	public function testGetCronJobSlug(): void {
		$tool = new GetCronJobTool( $this->errorFactory, $this->queue );
		$this->assertSame( 'get_cron_job', $tool->getSlug() );
	}

	public function testGetCronJobReturnsStatus(): void {
		$status = new JobStatus(
			jobId: 'job_x',
			status: 'completed',
			result: array( 'output' => 'done' ),
			queuedAt: new DateTimeImmutable( '2026-01-01T00:00:00Z' ),
			startedAt: new DateTimeImmutable( '2026-01-01T00:00:05Z' ),
			completedAt: new DateTimeImmutable( '2026-01-01T00:01:00Z' ),
			attempts: 1,
		);

		$this->queue->method( 'getStatus' )->willReturn( $status );

		$tool   = new GetCronJobTool( $this->errorFactory, $this->queue );
		$result = $tool->execute(
			array( 'job_id' => 'job_x' ),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'job_x', $result['data']['job_id'] );
		$this->assertSame( 'completed', $result['data']['status'] );
		$this->assertTrue( $result['data']['is_terminal'] );
		$this->assertTrue( $result['data']['is_successful'] );
	}

	public function testGetCronJobNotFound(): void {
		$this->queue->method( 'getStatus' )
			->willThrowException( new \RuntimeException( 'Not found' ) );

		$this->errorFactory->method( 'notFound' )
			->willReturn( $this->errorResponse( 'not_found', 'The specified task was not found.' ) );

		$tool   = new GetCronJobTool( $this->errorFactory, $this->queue );
		$result = $tool->execute(
			array( 'job_id' => 'missing' ),
			array( 'user_id' => 1 ),
		);

		$this->assertFalse( $result['success'] );
	}

	public function testGetCronJobMissingJobId(): void {
		$this->errorFactory->method( 'validationFailed' )
			->willReturn( $this->errorResponse( 'validation_failed', 'A valid job ID is required.' ) );

		$tool   = new GetCronJobTool( $this->errorFactory, $this->queue );
		$result = $tool->execute( array(), array( 'user_id' => 1 ) );

		$this->assertFalse( $result['success'] );
	}

	public function testAllCronSchemasReturnValidJsonSchema(): void {
		$tools = array(
			new CreateCronJobTool( $this->errorFactory, $this->queue ),
			new CreateCronJobValidatedTool( $this->errorFactory, $this->queue ),
			new DeleteCronJobTool( $this->errorFactory, $this->queue ),
			new ListCronJobsTool( $this->errorFactory, $this->queue ),
			new GetCronJobTool( $this->errorFactory, $this->queue ),
		);

		foreach ( $tools as $tool ) {
			$schema = $tool->getParametersSchema();
			$this->assertIsArray( $schema );
			$this->assertSame( 'object', $schema['type'] );
			$this->assertArrayHasKey( 'properties', $schema );
			$this->assertFalse( $schema['additionalProperties'] ?? true );
		}
	}

	public function testAllCronToolsHaveUniqueSlugs(): void {
		$tools = array(
			new CreateCronJobTool( $this->errorFactory, $this->queue ),
			new CreateCronJobValidatedTool( $this->errorFactory, $this->queue ),
			new DeleteCronJobTool( $this->errorFactory, $this->queue ),
			new ListCronJobsTool( $this->errorFactory, $this->queue ),
			new GetCronJobTool( $this->errorFactory, $this->queue ),
		);

		$slugs = array();
		foreach ( $tools as $tool ) {
			$slug = $tool->getSlug();
			$this->assertNotContains( $slug, $slugs, "Duplicate slug: {$slug}" );
			$slugs[] = $slug;
		}
	}
}
