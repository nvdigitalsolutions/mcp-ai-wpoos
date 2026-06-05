<?php
/**
 * Tests for queue and event tools.
 *
 * @package Nvoos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Entity\JobStatus;
use Nvoos\Core\Tool\CancelJobTool;
use Nvoos\Core\Tool\DispatchEventTool;
use Nvoos\Core\Tool\ListJobsTool;
use Nvoos\Core\Tool\ScheduleJobTool;
use Nvoos\Core\Tool\UnscheduleJobTool;
use PHPUnit\Framework\TestCase;

final class QueueAndEventToolsTest extends TestCase {

	// ─── DispatchEventTool ─────────────────────────────────────────

	public function testDispatchEvent(): void {
		$events = $this->createMock( EventDispatcherInterface::class );
		$events->expects( $this->once() )->method( 'dispatch' );

		$tool   = new DispatchEventTool( $this->stubErrors(), $events );
		$result = $tool->execute( array(
			'event'   => 'custom.data_updated',
			'payload' => array( 'key' => 'val' ),
		) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['data']['dispatched'] );
		$this->assertSame( 'custom.data_updated', $result['data']['event'] );
	}

	// ─── CancelJobTool ─────────────────────────────────────────────

	public function testCancelJob(): void {
		$queue = $this->createMock( QueueClientInterface::class );
		$queue->method( 'cancel' )->with( 'job_123' )->willReturn( true );

		$tool   = new CancelJobTool( $this->stubErrors(), $queue );
		$result = $tool->execute( array( 'job_id' => 'job_123' ) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['data']['cancelled'] );
	}

	// ─── ScheduleJobTool ───────────────────────────────────────────

	public function testScheduleJob(): void {
		$queue = $this->createMock( QueueClientInterface::class );
		$queue->method( 'schedule' )
			->with( 'cleanup', array( 'dry' => true ), '0 * * * *' )
			->willReturn( 'sched_456' );

		$tool   = new ScheduleJobTool( $this->stubErrors(), $queue );
		$result = $tool->execute( array(
			'handler'  => 'cleanup',
			'payload'  => array( 'dry' => true ),
			'schedule' => '0 * * * *',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'sched_456', $result['data']['schedule_id'] );
	}

	// ─── UnscheduleJobTool ─────────────────────────────────────────

	public function testUnscheduleJob(): void {
		$queue = $this->createMock( QueueClientInterface::class );
		$queue->expects( $this->once() )->method( 'unschedule' )->with( 'sched_456' );

		$tool   = new UnscheduleJobTool( $this->stubErrors(), $queue );
		$result = $tool->execute( array( 'schedule_id' => 'sched_456' ) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['data']['removed'] );
	}

	// ─── ListJobsTool ──────────────────────────────────────────────

	public function testListJobs(): void {
		$jobs = array(
			new JobStatus( jobId: 'j1', status: 'completed' ),
			new JobStatus( jobId: 'j2', status: 'queued' ),
		);

		$queue = $this->createMock( QueueClientInterface::class );
		$queue->method( 'listJobs' )->with( array(), 50 )->willReturn( $jobs );

		$tool   = new ListJobsTool( $this->stubErrors(), $queue );
		$result = $tool->execute();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['data']['total'] );
		$this->assertSame( 1, $result['data']['counts']['completed'] );
		$this->assertSame( 1, $result['data']['counts']['queued'] );
	}

	private function stubErrors(): ErrorFactoryInterface {
		return $this->createMock( ErrorFactoryInterface::class );
	}
}
