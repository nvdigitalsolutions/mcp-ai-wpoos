<?php
/**
 * Tests for SessionTelemetry — the subscriber tap on the session log
 * (Proposal 029, Phase 5.8 telemetry single-path).
 *
 * @package Nvoos\Core\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Session;

use Nvoos\Core\Application\Session\SessionEvent;
use Nvoos\Core\Application\Session\SessionLog;
use Nvoos\Core\Application\Session\SessionTelemetry;
use PHPUnit\Framework\TestCase;

final class SessionTelemetryTest extends TestCase {

	public function testSubscribeAndNotify(): void {
		$telemetry = new SessionTelemetry();
		$seen      = array();

		$telemetry->subscribe(
			static function ( SessionEvent $event ) use ( &$seen ): void {
				$seen[] = $event->type;
			}
		);

		$this->assertSame( 1, $telemetry->listenerCount() );

		$event = new SessionEvent( seq: 1, time: 1.0, type: 'tool_result', data: array( 'name' => 'x' ) );
		$telemetry->notify( $event );

		$this->assertSame( array( 'tool_result' ), $seen );
		$this->assertSame( 0, $telemetry->failureCount() );
	}

	public function testThrowingListenerIsIsolatedAndCounted(): void {
		$telemetry = new SessionTelemetry();
		$seen      = array();

		$telemetry->subscribe(
			static function ( SessionEvent $event ): void {
				throw new \RuntimeException( 'listener boom' );
			}
		);
		$telemetry->subscribe(
			static function ( SessionEvent $event ) use ( &$seen ): void {
				$seen[] = $event->type;
			}
		);

		$telemetry->notify( new SessionEvent( seq: 1, time: 1.0, type: 'turn_ended', data: array() ) );

		$this->assertSame( array( 'turn_ended' ), $seen, 'A throwing listener must not block later listeners.' );
		$this->assertSame( 1, $telemetry->failureCount() );
	}

	public function testEveryAppendedEventFansOutThroughTheTap(): void {
		$log       = new SessionLog();
		$telemetry = new SessionTelemetry();
		$types     = array();

		$telemetry->subscribe(
			static function ( SessionEvent $event ) use ( &$types ): void {
				$types[] = $event->type . ':' . $event->seq;
			}
		);

		// Simulate the orchestrator's choke point: every append notifies.
		$tap = static function ( SessionLog $log, SessionTelemetry $telemetry, string $type, array $data ): void {
			$log->append( $type, $data );
			$last = $log->lastEvent();
			if ( null !== $last ) {
				$telemetry->notify( $last );
			}
		};

		$tap( $log, $telemetry, SessionLog::TYPE_TURN_STARTED, array( 'assistant_id' => 1 ) );
		$tap( $log, $telemetry, SessionLog::TYPE_TOOL_RESULT, array( 'name' => 'a' ) );
		$tap( $log, $telemetry, SessionLog::TYPE_TURN_ENDED, array( 'reason' => 'completed' ) );

		$this->assertSame(
			array( 'turn_started:1', 'tool_result:2', 'turn_ended:3' ),
			$types,
			'The tap must observe the same ordered stream the log holds.',
		);
	}
}
