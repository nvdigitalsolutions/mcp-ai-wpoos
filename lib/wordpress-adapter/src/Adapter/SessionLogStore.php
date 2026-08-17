<?php
/**
 * SessionLogStore — WordPress adapter persisting session logs as JSONL
 * files under wp-content/uploads/mcp-ai/session-logs/.
 *
 * JSONL keeps options tables untouched and makes logs replayable with
 * standard tooling. Directory creation is guarded; write failures fail
 * soft (session logging is observability, not a correctness dependency).
 *
 * @package Nvoos\WordPress
 * @since   1.3.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\SessionLogStoreInterface;

final class SessionLogStore implements SessionLogStoreInterface {

	/**
	 * Relative uploads subdirectory for session logs.
	 */
	private const SUBDIR = 'mcp-ai/session-logs';

	/**
	 * Resolve the log file path for a session id.
	 *
	 * Session ids are restricted to a safe slug alphabet before touching
	 * the filesystem (path-traversal guard).
	 */
	private function filePath( string $sessionId ): string {
		$safeId = \preg_replace( '/[^a-z0-9_\-]/i', '_', $sessionId ) ?: 'unknown';
		$dir    = \wp_upload_dir();

		return \trailingslashit( (string) ( $dir['basedir'] ?? \sys_get_temp_dir() ) ) . self::SUBDIR . '/' . $safeId . '.jsonl';
	}

	public function append( string $sessionId, array $entry ): void {
		$path = $this->filePath( $sessionId );
		$dir  = \dirname( $path );

		if ( ! \is_dir( $dir ) && ! \wp_mkdir_p( $dir ) ) {
			return;
		}

		$line = \json_encode( $entry, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE );
		if ( false === $line ) {
			return;
		}

		// Fail soft: a full disk or locked file must never break the chat.
		$fp = @\fopen( $path, 'a' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fail-soft persistence.
		if ( false === $fp ) {
			return;
		}

		@\fwrite( $fp, $line . "\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fail-soft persistence.
		@\fclose( $fp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fail-soft persistence.
	}

	public function load( string $sessionId ): array {
		$path = $this->filePath( $sessionId );

		if ( ! \is_readable( $path ) ) {
			return array();
		}

		$lines = @\file( $path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fail-soft persistence.
		if ( false === $lines ) {
			return array();
		}

		$entries = array();
		foreach ( $lines as $line ) {
			$entry = \json_decode( $line, true );
			if ( \is_array( $entry ) ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}
}
