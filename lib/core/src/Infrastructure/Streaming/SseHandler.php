<?php
/**
 * SSE (Server-Sent Events) handler — RFC 6202 compliant streaming.
 *
 * Sends properly framed SSE events to the client. Framework-agnostic:
 * relies on flush/echo, which works in any PHP SAPI.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Streaming;

class SseHandler {

	/**
	 * Number of characters per simulated streaming chunk.
	 */
	private const CHUNK_SIZE = 50;

	/**
	 * Microsecond delay between simulated streaming chunks.
	 */
	private const CHUNK_DELAY_US = 15_000;

	/**
	 * Retry interval (ms) sent to the client for reconnection.
	 */
	private const RETRY_INTERVAL_MS = 3000;

	/**
	 * Whether headers have already been sent.
	 */
	private bool $headersSent = false;

	/**
	 * Send SSE headers to the client.
	 *
	 * Must be called before any event is emitted. Disables output buffering
	 * at both the PHP and WordPress levels and sets the text/event-stream
	 * content type.
	 */
	public function sendHeaders(): void {
		if ( $this->headersSent ) {
			return;
		}

		// Disable PHP output buffering.
		// phpcs:disable WordPress.PHP.DevelopmentFunctions,WordPress.PHP.IniSet.Risky,Generic.PHP.NoSilencedErrors.Discouraged
		@\ini_set( 'output_buffering', 'off' );
		@\ini_set( 'zlib.output_compression', 'off' );
		// phpcs:enable

		// Flush WordPress output buffers.
		if ( \function_exists( 'wp_ob_end_flush_all' ) ) {
			\wp_ob_end_flush_all();
		}

		// Clear any remaining output buffers.
		while ( \ob_get_level() > 0 ) {
			\ob_end_clean();
		}

		\header( 'Content-Type: text/event-stream; charset=utf-8' );
		\header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		\header( 'Pragma: no-cache' );
		\header( 'Expires: 0' );
		\header( 'X-Accel-Buffering: no' ); // Disable nginx buffering.

		// Send retry interval.
		echo 'retry: ' . self::RETRY_INTERVAL_MS . "\n\n";
		\flush();

		$this->headersSent = true;
	}

	/**
	 * Send a single SSE event.
	 *
	 * @param string $event  Event name (e.g., 'message', 'status', 'error').
	 * @param mixed  $data   Event payload (will be JSON-encoded).
	 * @param string $id     Optional event ID for Last-Event-ID support.
	 */
	public function sendEvent( string $event, mixed $data, string $id = '' ): void {
		$this->sendHeaders();

		if ( '' !== $id ) {
			echo "id: {$id}\n";
		}

		echo "event: {$event}\n";

		$json = \json_encode( $data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			$json = \json_encode( array( 'error' => 'JSON encoding failed: ' . \json_last_error_msg() ) );
		}

		// Split into lines for multi-line data.
		foreach ( \explode( "\n", $json ) as $line ) {
			echo "data: {$line}\n";
		}

		echo "\n";
		\flush();
	}

	/**
	 * Send the [DONE] marker that signals stream completion.
	 */
	public function sendDone(): void {
		$this->sendHeaders();
		echo "data: [DONE]\n\n";
		\flush();
	}

	/**
	 * Send a heartbeat ping to keep the connection alive.
	 */
	public function sendPing(): void {
		$this->sendHeaders();
		echo ": ping\n\n";
		\flush();
	}

	/**
	 * Stream text content in simulated chunks for progressive rendering.
	 *
	 * @param callable $formatter  Called with each chunk, returns the SSE payload.
	 */
	public function streamChunks( string $text, callable $formatter ): void {
		$length    = \function_exists( 'mb_strlen' ) ? \mb_strlen( $text ) : \strlen( $text );
		$chunkSize = self::CHUNK_SIZE;
		$canSleep  = \function_exists( 'usleep' );

		for ( $offset = 0; $offset < $length; $offset += $chunkSize ) {
			$chunk = \function_exists( 'mb_substr' )
				? \mb_substr( $text, $offset, $chunkSize )
				: \substr( $text, $offset, $chunkSize );

			$payload = $formatter( $chunk );
			$this->sendEvent( 'message', $payload );

			if ( $canSleep ) {
				\usleep( self::CHUNK_DELAY_US );
			}
		}
	}

	/**
	 * Check whether the client requested an event stream.
	 *
	 * Inspects the Accept header and the stream query parameter.
	 */
	public function wantsEventStream( array $headers, array $params ): bool {
		// Check query parameter.
		if ( ! empty( $params['stream'] ) ) {
			return true;
		}

		// Check Accept header.
		$accept = $headers['Accept'] ?? $headers['accept'] ?? '';
		if ( '' !== $accept ) {
			foreach ( \explode( ',', $accept ) as $type ) {
				if ( 'text/event-stream' === \trim( \explode( ';', $type )[0] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Finish the response — flushes and terminates.
	 *
	 * Uses fastcgi_finish_request() when available (PHP-FPM) so the
	 * connection closes cleanly.
	 */
	public function finish(): void {
		if ( \function_exists( 'fastcgi_finish_request' ) ) {
			\fastcgi_finish_request();
		}
	}

	/**
	 * Whether headers have already been sent.
	 */
	public function isActive(): bool {
		return $this->headersSent;
	}
}
