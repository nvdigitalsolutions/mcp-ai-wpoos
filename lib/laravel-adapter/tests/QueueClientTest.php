<?php
/**
 * QueueClient status tracking tests (P4).
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Laravel\Jobs\NvoosToolJob;

final class QueueClientTest extends TestCase {

	use RefreshDatabase;

	public function test_sync_job_records_completed_status(): void {
		$client = $this->app->make( QueueClientInterface::class );

		$jobId = $client->enqueue( QueueTestHandler::class, array( 'value' => 41 ) );

		$this->assertNotSame( '', $jobId );

		$status = $client->getStatus( $jobId );

		$this->assertSame( 'completed', $status->status );
		$this->assertSame( array( 'handled' => 41 ), $status->result );
		$this->assertGreaterThanOrEqual( 1, $status->attempts );
	}

	public function test_unknown_job_is_reported_as_unknown(): void {
		$client = $this->app->make( QueueClientInterface::class );

		$this->assertSame( 'unknown', $client->getStatus( 'does-not-exist' )->status );
	}

	/**
	 * A long-running handler gets a longer budget than the defaults.
	 *
	 * The polling handler for video generation runs once per status check, so it
	 * needs many attempts and a long retry window while each attempt stays short.
	 * Those are per-dispatch options, not new defaults: an ordinary tool keeps
	 * three attempts and five minutes.
	 */
	public function test_enqueue_carries_a_per_job_budget(): void {
		Queue::fake();

		$client = $this->app->make( QueueClientInterface::class );

		$jobId = $client->enqueue(
			QueueTestHandler::class,
			array( 'prediction_id' => 'pred-1' ),
			array(
				'tries'               => 60,
				'backoff'             => array( 15 ),
				'timeout'             => 120,
				'retry_until_minutes' => 20,
			)
		);

		Queue::assertPushed(
			NvoosToolJob::class,
			static function ( NvoosToolJob $job ) use ( $jobId ): bool {
				return $job->jobId === $jobId
					&& 60 === $job->tries
					&& array( 15 ) === $job->backoff
					&& 120 === $job->timeout
					&& 20 === $job->retryUntilMinutes;
			}
		);
	}

	public function test_enqueue_keeps_the_defaults_when_no_budget_is_given(): void {
		Queue::fake();

		$client = $this->app->make( QueueClientInterface::class );

		$client->enqueue( QueueTestHandler::class, array( 'value' => 1 ) );

		Queue::assertPushed(
			NvoosToolJob::class,
			static fn ( NvoosToolJob $job ): bool => 3 === $job->tries
				&& array( 5, 15, 30 ) === $job->backoff
				&& 300 === $job->timeout
				&& 5 === $job->retryUntilMinutes
		);
	}
}

/**
 * Test-only handler resolved by NvoosToolJob.
 */
final class QueueTestHandler {

	/**
	 * @param array<string, mixed> $payload Job payload.
	 * @return array<string, mixed>
	 */
	public function handle( array $payload ): array {
		return array( 'handled' => $payload['value'] ?? null );
	}
}
