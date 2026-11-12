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
use Nvoos\Core\Domain\Contract\QueueClientInterface;

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
