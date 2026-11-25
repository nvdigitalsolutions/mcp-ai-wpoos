<?php
/**
 * Laravel adapter: resumable uploads (tus 1.0.0 core).
 *
 * Chunks live on a private disk; the state lives in `nvoos_upload_sessions`. The
 * two are separate on purpose — a chunk is scratch, a session is a claim about
 * bytes that are owed.
 *
 * ## Completion is the single-request path, not a second one
 *
 * When the last chunk lands, the assembled file is handed to the *same* rules the
 * multipart endpoint applies: `FileUploadService::detectMimeType()` for the sniff,
 * `isMimeTypeAllowed()` for the policy, and `FileVisibilityStoreInterface` for the
 * write, which sniffs again and enforces the per-group cap. Nothing here re-implements
 * a decision. What it does not go through is `upload()`, and that is deliberate:
 * `upload()` enforces the per-**request** size cap, which is exactly the number
 * chunking exists to escape. The per-**file** ceiling is the registry's group cap,
 * and a separate `maxTotalBytes` bounds the session before a byte arrives.
 *
 * ## Offsets are the spec's, and a mismatch writes nothing
 *
 * A `PATCH` whose `Upload-Offset` is not the current offset is a **409** — never a
 * best-effort append. Two clients resuming the same id, or one client retrying a
 * chunk whose response was lost, are indistinguishable from here, and appending
 * at a guessed offset would corrupt a file that otherwise looked fine.
 *
 * ## Abandoned sessions expire
 *
 * A session that never completes would hold its chunks forever. `begin()` prunes
 * sessions older than the configured window, which is the cheapest place to do it:
 * no scheduled job to forget to deploy, and a host that never opens an upload
 * never pays for the check.
 *
 * @package Nvoos\Laravel
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Nvoos\Core\Domain\Contract\ChunkedUploadInterface;
use Nvoos\Core\Domain\Contract\FileUploadServiceInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Error\ConflictException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;
use Throwable;

class ChunkedUploadService implements ChunkedUploadInterface {

	private const TABLE = 'nvoos_upload_sessions';

	/** Where sessions live on the private disk. */
	private const PREFIX = 'chunked-uploads';

	/** The longest client-supplied filename kept for the display name. */
	private const MAX_NAME = 200;

	/**
	 * @param string                           $disk           Private disk holding chunks (never the public one).
	 * @param int                              $maxTotalBytes  Largest declared total.
	 * @param int                              $maxChunkBytes  Largest single PATCH body.
	 * @param int                              $expiresHours   How long an incomplete session survives.
	 * @param FileVisibilityStoreInterface|null $store          The file registry, when the host binds one.
	 * @param FileUploadServiceInterface|null   $uploads        The allowlist, when the host binds one.
	 */
	public function __construct(
		private readonly string $disk = 'local',
		private readonly int $maxTotalBytes = 536870912,
		private readonly int $maxChunkBytes = 4194304,
		private readonly int $expiresHours = 24,
		private readonly ?FileVisibilityStoreInterface $store = null,
		private readonly ?FileUploadServiceInterface $uploads = null,
	) {
	}

	public function maxTotalBytes(): int {
		return $this->maxTotalBytes;
	}

	public function maxChunkBytes(): int {
		return $this->maxChunkBytes;
	}

	public function begin( string $filename, string $declaredMime, int $totalBytes, array $context = array() ): array {
		if ( $totalBytes <= 0 ) {
			throw new ValidationException( 'Upload-Length must be a positive number of bytes.' );
		}

		if ( $totalBytes > $this->maxTotalBytes ) {
			throw new ValidationException(
				\sprintf(
					'That file is %d bytes; a resumable upload may declare at most %d.',
					$totalBytes,
					$this->maxTotalBytes,
				),
			);
		}

		if ( null === $this->store ) {
			throw new ValidationException( 'This host has no file registry, so a resumable upload has nowhere to land.' );
		}

		$this->prune();

		$id   = \bin2hex( \random_bytes( 16 ) );
		$name = $this->cleanName( $filename );
		$now  = \now();

		DB::table( self::TABLE )->insert(
			array(
				'id'             => $id,
				'tenant_id'      => $this->tenantFrom( $context ),
				'user_id'        => (int) ( $context['user_id'] ?? 0 ),
				'filename'       => $name,
				'declared_mime'  => \substr( \trim( $declaredMime ), 0, 128 ),
				'total_bytes'    => $totalBytes,
				'received_bytes' => 0,
				'attachment_id'  => null,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
		);

		return array( 'id' => $id, 'offset' => 0, 'total' => $totalBytes );
	}

	public function status( string $uploadId, array $context = array() ): ?array {
		$session = $this->session( $uploadId, $context );

		if ( null === $session ) {
			return null;
		}

		return array(
			'offset'        => (int) $session->received_bytes,
			'total'         => (int) $session->total_bytes,
			'complete'      => null !== $session->attachment_id,
			'attachment_id' => null === $session->attachment_id ? null : (int) $session->attachment_id,
			'url'           => null === $session->attachment_id ? null : $this->urlOf( (int) $session->attachment_id ),
		);
	}

	public function append( string $uploadId, int $offset, string $bytes, array $context = array() ): array {
		$session = $this->session( $uploadId, $context );

		if ( null === $session ) {
			throw new NotFoundException( 'No such upload.' );
		}

		if ( null !== $session->attachment_id ) {
			throw new ConflictException(
				'That upload has already completed.',
				array( 'offset' => (int) $session->total_bytes ),
			);
		}

		$received = (int) $session->received_bytes;
		$total    = (int) $session->total_bytes;
		$length   = \strlen( $bytes );

		if ( $offset !== $received ) {
			throw new ConflictException(
				\sprintf( 'Upload-Offset is %d; the request said %d.', $received, $offset ),
				array( 'offset' => $received, 'total' => $total ),
			);
		}

		// An empty chunk is only meaningful as "finish the upload" — the case where
		// a completion attempt was refused and the client retries it. It is never a
		// way to move the offset.
		if ( 0 === $length && $received !== $total ) {
			throw new ValidationException( 'An empty chunk does not advance the upload.' );
		}

		if ( $length > $this->maxChunkBytes ) {
			throw new ValidationException(
				\sprintf(
					'A chunk may be at most %d bytes; this one is %d.',
					$this->maxChunkBytes,
					$length,
				),
			);
		}

		if ( $received + $length > $total ) {
			throw new ValidationException(
				\sprintf(
					'That chunk would take the upload to %d bytes, past the declared %d.',
					$received + $length,
					$total,
				),
			);
		}

		if ( $length > 0 ) {
			$this->writeChunk( $uploadId, $received, $bytes );

			DB::table( self::TABLE )
				->where( 'id', $uploadId )
				->update(
					array(
						'received_bytes' => $received + $length,
						'updated_at'     => \now(),
					),
				);

			$received += $length;
		}

		if ( $received < $total ) {
			return array(
				'offset'        => $received,
				'total'         => $total,
				'complete'      => false,
				'attachment_id' => null,
				'url'           => null,
			);
		}

		return $this->complete( $session, $context );
	}

	public function abort( string $uploadId, array $context = array() ): bool {
		$session = $this->session( $uploadId, $context );

		if ( null === $session ) {
			return false;
		}

		DB::table( self::TABLE )->where( 'id', $uploadId )->delete();
		Storage::disk( $this->disk )->deleteDirectory( $this->directory( $uploadId ) );

		return true;
	}

	/**
	 * Assemble, validate and hand the bytes to the registry.
	 *
	 * Failures leave the session at `received === total` so a client can retry the
	 * completion (an empty `PATCH`) or abort. The chunks are only deleted once the
	 * registry has the file: deleting them on a refusal would lose an upload the
	 * caller might otherwise fix by choosing another name.
	 *
	 * @param object               $session Row from `nvoos_upload_sessions`.
	 * @param array<string, mixed> $context
	 *
	 * @return array{offset: int, total: int, complete: bool, attachment_id: int|null, url: string|null}
	 */
	private function complete( object $session, array $context ): array {
		$uploadId = (string) $session->id;
		$total    = (int) $session->total_bytes;
		$path     = $this->assemble( $uploadId );

		try {
			$size = \filesize( $path );

			if ( false === $size || $size !== $total ) {
				throw new ValidationException(
					\sprintf( 'The assembled upload is %s bytes; the session declared %d.', false === $size ? 'an unknown number of' : (string) $size, $total ),
				);
			}

			$mime = FileUploadService::detectMimeType( $path );

			if ( null === $mime ) {
				throw new ValidationException( 'Unable to determine the file type.' );
			}

			if ( null !== $this->uploads && ! $this->uploads->isMimeTypeAllowed( $mime ) ) {
				throw new ValidationException( 'File type ' . $mime . ' is not allowed.' );
			}

			$stored = $this->store->storeWithVisibility(
				$path,
				(string) $session->filename,
				$mime,
				(int) $session->user_id,
				FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
				(string) ( $session->tenant_id ?? 'default' ),
			);
		} finally {
			if ( \is_file( $path ) ) {
				\unlink( $path );
			}
		}

		DB::table( self::TABLE )
			->where( 'id', $uploadId )
			->update(
				array(
					'attachment_id' => $stored->id,
					'updated_at'    => \now(),
				),
			);

		Storage::disk( $this->disk )->deleteDirectory( $this->directory( $uploadId ) );

		return array(
			'offset'        => $total,
			'total'         => $total,
			'complete'      => true,
			'attachment_id' => $stored->id,
			'url'           => $stored->publicUrl,
		);
	}

	/**
	 * Concatenate the chunks into one temporary file, in offset order.
	 *
	 * Streams rather than reads: a 500 MiB video assembled with
	 * `file_get_contents()` would need 500 MiB of memory, and the shipped image
	 * has 128.
	 */
	private function assemble( string $uploadId ): string {
		$chunks = Storage::disk( $this->disk )->files( $this->directory( $uploadId ) );

		// Chunk names are zero-padded offsets, so a string sort is an offset sort.
		\sort( $chunks, SORT_STRING );

		$path = \tempnam( \sys_get_temp_dir(), 'nvoos-assemble-' );

		if ( false === $path ) {
			throw new ValidationException( 'Could not open a temporary file to assemble the upload.' );
		}

		$out = \fopen( $path, 'wb' );

		if ( false === $out ) {
			throw new ValidationException( 'Could not open a temporary file to assemble the upload.' );
		}

		try {
			foreach ( $chunks as $chunk ) {
				$in = Storage::disk( $this->disk )->readStream( $chunk );

				if ( ! \is_resource( $in ) ) {
					throw new ValidationException( 'A chunk could not be read back from storage.' );
				}

				\stream_copy_to_stream( $in, $out );
				\fclose( $in );
			}
		} finally {
			\fclose( $out );
		}

		return $path;
	}

	private function writeChunk( string $uploadId, int $offset, string $bytes ): void {
		$written = Storage::disk( $this->disk )->put(
			$this->directory( $uploadId ) . '/' . \sprintf( '%012d', $offset ),
			$bytes,
		);

		if ( false === $written ) {
			throw new ValidationException( 'Could not write the chunk to storage.' );
		}
	}

	/**
	 * The session, when it belongs to this caller.
	 *
	 * Unknown and not-yours answer the same way, so a session id cannot be probed.
	 *
	 * @param array<string, mixed> $context
	 */
	private function session( string $uploadId, array $context ): ?object {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return null;
		}

		$row = DB::table( self::TABLE )->where( 'id', $uploadId )->first();

		if ( null === $row ) {
			return null;
		}

		$userId = (int) ( $context['user_id'] ?? 0 );

		if ( (int) $row->user_id !== $userId ) {
			return null;
		}

		$tenant = $this->tenantFrom( $context );

		if ( (string) ( $row->tenant_id ?? 'default' ) !== $tenant ) {
			return null;
		}

		return $row;
	}

	/**
	 * Drop sessions that were started and never finished.
	 *
	 * Runs on `begin()` because that is the one moment a client has already paid
	 * for a database write. A host that never opens an upload never pays for the
	 * check, and no scheduled job has to be deployed for the sweep to happen.
	 */
	private function prune(): void {
		if ( ! Schema::hasTable( self::TABLE ) || $this->expiresHours <= 0 ) {
			return;
		}

		$cutoff = \now()->subHours( $this->expiresHours );

		$stale = DB::table( self::TABLE )
			->where( 'created_at', '<', $cutoff )
			->whereNull( 'attachment_id' )
			->limit( 200 )
			->pluck( 'id' );

		foreach ( $stale as $id ) {
			DB::table( self::TABLE )->where( 'id', (string) $id )->delete();
			Storage::disk( $this->disk )->deleteDirectory( $this->directory( (string) $id ) );
		}
	}

	private function directory( string $uploadId ): string {
		// The id is hex from `random_bytes`, but it is interpolated into a path, so
		// it is filtered rather than trusted: 32 hex characters or nothing.
		return self::PREFIX . '/' . \preg_replace( '/[^a-f0-9]/', '', \strtolower( $uploadId ) );
	}

	private function cleanName( string $filename ): string {
		$name = \basename( \trim( $filename ) );

		if ( '' === $name ) {
			$name = 'upload';
		}

		return \substr( $name, 0, self::MAX_NAME );
	}

	private function tenantFrom( array $context ): string {
		$tenant = \trim( (string) ( $context['tenant_id'] ?? '' ) );

		return '' !== $tenant ? \substr( $tenant, 0, 64 ) : 'default';
	}

	private function urlOf( int $attachmentId ): ?string {
		try {
			$stored = $this->store?->getMetadata( $attachmentId );

			return $stored?->publicUrl;
		} catch ( Throwable ) {
			return null;
		}
	}
}
