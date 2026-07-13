<?php
/**
 * Tests for JobStatus value object.
 *
 * @package Nvoos\Core\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Entity;

use Nvoos\Core\Domain\Entity\JobStatus;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

final class JobStatusTest extends TestCase {

	public function testQueuedJob(): void {
		$queuedAt = new DateTimeImmutable( '2026-01-01T00:00:00Z' );
		$job      = new JobStatus(
			jobId: 'job_abc123',
			status: 'queued',
			queuedAt: $queuedAt,
		);

		$this->assertSame( 'job_abc123', $job->jobId );
		$this->assertSame( 'queued', $job->status );
		$this->assertSame( $queuedAt, $job->queuedAt );
		$this->assertNull( $job->result );
		$this->assertNull( $job->error );
		$this->assertNull( $job->startedAt );
		$this->assertNull( $job->completedAt );
		$this->assertSame( 0, $job->attempts );
	}

	public function testIsTerminalForCompleted(): void {
		$job = new JobStatus( jobId: 'j1', status: 'completed' );
		$this->assertTrue( $job->isTerminal() );
	}

	public function testIsTerminalForFailed(): void {
		$job = new JobStatus( jobId: 'j1', status: 'failed' );
		$this->assertTrue( $job->isTerminal() );
	}

	public function testIsTerminalForCancelled(): void {
		$job = new JobStatus( jobId: 'j1', status: 'cancelled' );
		$this->assertTrue( $job->isTerminal() );
	}

	public function testIsTerminalForQueued(): void {
		$job = new JobStatus( jobId: 'j1', status: 'queued' );
		$this->assertFalse( $job->isTerminal() );
	}

	public function testIsTerminalForRunning(): void {
		$job = new JobStatus( jobId: 'j1', status: 'running' );
		$this->assertFalse( $job->isTerminal() );
	}

	public function testIsRunning(): void {
		$running = new JobStatus( jobId: 'j1', status: 'running' );
		$this->assertTrue( $running->isRunning() );

		$queued = new JobStatus( jobId: 'j2', status: 'queued' );
		$this->assertFalse( $queued->isRunning() );

		$completed = new JobStatus( jobId: 'j3', status: 'completed' );
		$this->assertFalse( $completed->isRunning() );
	}

	public function testIsSuccessful(): void {
		$completed = new JobStatus( jobId: 'j1', status: 'completed' );
		$this->assertTrue( $completed->isSuccessful() );

		$failed = new JobStatus( jobId: 'j2', status: 'failed' );
		$this->assertFalse( $failed->isSuccessful() );

		$running = new JobStatus( jobId: 'j3', status: 'running' );
		$this->assertFalse( $running->isSuccessful() );
	}

	public function testCompletedJobWithResult(): void {
		$completedAt = new DateTimeImmutable( '2026-01-02T00:00:00Z' );
		$job         = new JobStatus(
			jobId: 'job_done',
			status: 'completed',
			result: array( 'output' => 'processed 100 items' ),
			completedAt: $completedAt,
			attempts: 2,
		);

		$this->assertTrue( $job->isSuccessful() );
		$this->assertTrue( $job->isTerminal() );
		$this->assertSame( array( 'output' => 'processed 100 items' ), $job->result );
		$this->assertSame( 2, $job->attempts );
		$this->assertSame( $completedAt, $job->completedAt );
	}

	public function testFailedJobWithError(): void {
		$job = new JobStatus(
			jobId: 'job_failed',
			status: 'failed',
			error: 'Connection timed out.',
			attempts: 3,
		);

		$this->assertTrue( $job->isTerminal() );
		$this->assertFalse( $job->isSuccessful() );
		$this->assertSame( 'Connection timed out.', $job->error );
		$this->assertSame( 3, $job->attempts );
	}

	public function testJsonSerialize(): void {
		$queuedAt    = new DateTimeImmutable( '2026-01-01T00:00:00Z' );
		$startedAt   = new DateTimeImmutable( '2026-01-01T00:00:01Z' );
		$completedAt = new DateTimeImmutable( '2026-01-01T00:00:05Z' );

		$job = new JobStatus(
			jobId: 'job_full',
			status: 'completed',
			result: array( 'summary' => 'OK' ),
			queuedAt: $queuedAt,
			startedAt: $startedAt,
			completedAt: $completedAt,
			attempts: 1,
		);

		$json = $job->jsonSerialize();

		$this->assertSame( 'job_full', $json['job_id'] );
		$this->assertSame( 'completed', $json['status'] );
		$this->assertSame( array( 'summary' => 'OK' ), $json['result'] );
		$this->assertNull( $json['error'] );
		$this->assertSame( 1, $json['attempts'] );
		$this->assertStringContainsString( '2026-01-01', $json['queued_at'] );
		$this->assertStringContainsString( '2026-01-01', $json['started_at'] );
		$this->assertStringContainsString( '2026-01-01', $json['completed_at'] );
	}
}
