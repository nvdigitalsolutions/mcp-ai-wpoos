<?php
/**
 * Tests for the framework-agnostic SSE handler.
 *
 * @package Nvoos\Core\Tests
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Infrastructure\Streaming;

use Nvoos\Core\Infrastructure\Streaming\PlatformFlushInterface;
use Nvoos\Core\Infrastructure\Streaming\SseHandler;
use PHPUnit\Framework\TestCase;

final class SseHandlerTest extends TestCase {

	/**
	 * A platform flush implementation that records calls.
	 */
	private function flush( object $spy ): PlatformFlushInterface {
		return new class( $spy ) implements PlatformFlushInterface {
			public function __construct( private object $spy ) {}

			public function flushPlatformBuffers(): void {
				++$this->spy->calls;
			}
		};
	}

	public function test_mark_headers_sent_suppresses_platform_flush_and_global_headers(): void {
		$spy = new class() {
			public int $calls = 0;
		};

		$sse = new SseHandler( $this->flush( $spy ) );

		$this->assertFalse( $sse->isActive() );

		$sse->markHeadersSent();
		$sse->sendHeaders();

		$this->assertTrue( $sse->isActive() );
		$this->assertSame( 0, $spy->calls );
	}

	public function test_wants_event_stream_accepts_query_parameter(): void {
		$spy = new class() {
			public int $calls = 0;
		};

		$sse = new SseHandler( $this->flush( $spy ) );

		$this->assertTrue( $sse->wantsEventStream( array(), array( 'stream' => '1' ) ) );
	}

	public function test_wants_event_stream_accepts_accept_header(): void {
		$spy = new class() {
			public int $calls = 0;
		};

		$sse = new SseHandler( $this->flush( $spy ) );

		$this->assertTrue( $sse->wantsEventStream( array( 'Accept' => 'text/event-stream, application/json' ), array() ) );
		$this->assertFalse( $sse->wantsEventStream( array( 'Accept' => 'application/json' ), array() ) );
	}
}
