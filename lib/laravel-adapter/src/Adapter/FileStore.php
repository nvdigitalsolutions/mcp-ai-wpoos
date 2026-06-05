<?php
/**
 * Laravel adapter: FileStoreInterface implementation.
 *
 * Wraps Laravel's Storage facade (Flysystem — local, S3, GCS, etc.)
 * behind the framework-agnostic FileStoreInterface.
 *
 * Patterned after Flysystem (thephpleague/flysystem) — one interface,
 * multiple filesystem back-ends. The configured disk determines where
 * files are stored.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;
use Illuminate\Support\Facades\Storage;

class FileStore implements FileStoreInterface {

	/**
	 * Laravel filesystem disk name (e.g., 'public', 's3', 'gcs').
	 */
	private string $disk;

	/**
	 * Maximum file size in bytes (default: 100MB).
	 */
	private const DEFAULT_MAX_FILE_SIZE = 104857600;

	/**
	 * @param string $disk  Laravel filesystem disk to use.
	 *                      Defaults to the NVOOS_STORAGE_DISK env or 'public'.
	 */
	public function __construct( string $disk = '' ) {
		$this->disk = '' !== $disk ? $disk : ( env( 'NVOOS_STORAGE_DISK', 'public' ) ?: 'public' );
	}

	/**
	 * Store a file from a local filesystem path.
	 *
	 * The adapter copies the file to the configured storage disk and
	 * returns metadata about the stored file.
	 *
	 * @param string $localPath  Absolute path to the source file on disk.
	 * @param string $filename   Desired display filename.
	 * @param string $mimeType   MIME type (e.g., 'image/png').
	 * @param int    $userId     User who owns the uploaded file.
	 *
	 * @return StoredFile
	 *
	 * @throws ValidationException  When file is missing, too large, or invalid.
	 */
	public function store( string $localPath, string $filename, string $mimeType, int $userId ): StoredFile {
		if ( ! file_exists( $localPath ) ) {
			throw new ValidationException( "Source file does not exist: {$localPath}" );
		}

		$fileSize = filesize( $localPath );
		if ( false === $fileSize ) {
			throw new ValidationException( "Could not determine file size: {$localPath}" );
		}

		$maxSize = self::DEFAULT_MAX_FILE_SIZE;
		if ( $fileSize > $maxSize ) {
			throw new ValidationException(
				sprintf(
					'File size (%s) exceeds maximum allowed (%s).',
					$this->formatBytes( $fileSize ),
					$this->formatBytes( $maxSize ),
				),
			);
		}

		$storage = Storage::disk( $this->disk );

		// Generate a unique path within the storage disk.
		$directory = sprintf( 'oos-files/%s/%s', gmdate( 'Y' ), gmdate( 'm' ) );
		$uniqueFilename = $this->uniqueFilename( $directory, $filename );
		$storagePath    = $directory . '/' . $uniqueFilename;

		// Copy the file to the storage disk.
		$stream = fopen( $localPath, 'rb' );
		if ( false === $stream ) {
			throw new \RuntimeException( "Failed to open source file: {$localPath}" );
		}

		try {
			$stored = $storage->put( $storagePath, $stream, 'public' );
		} finally {
			if ( is_resource( $stream ) ) {
				fclose( $stream );
			}
		}

		if ( ! $stored ) {
			throw new \RuntimeException( "Failed to store file: {$storagePath}" );
		}

		$publicUrl = $storage->url( $storagePath );
		$fullPath  = $storage->path( $storagePath );

		$createdAt = new \DateTimeImmutable();

		return new StoredFile(
			id: $this->hashId( $storagePath ),
			filename: $uniqueFilename,
			mimeType: $mimeType,
			sizeBytes: $fileSize,
			localPath: $fullPath,
			publicUrl: $publicUrl ?: null,
			metadata: array(
				'disk'        => $this->disk,
				'path'        => $storagePath,
				'uploaded_by' => $userId,
			),
			ownerId: $userId,
			createdAt: $createdAt,
		);
	}

	/**
	 * Get the absolute filesystem path for a stored file.
	 *
	 * Only works for local disks; returns null for cloud disks (S3, etc.)
	 * or when the file is not found.
	 *
	 * @param int $fileId  Numeric file identifier.
	 * @return string|null
	 */
	public function getPath( int $fileId ): ?string {
		$storagePath = $this->resolvePath( $fileId );
		if ( null === $storagePath ) {
			return null;
		}

		$storage = Storage::disk( $this->disk );
		if ( ! $storage->exists( $storagePath ) ) {
			return null;
		}

		return $storage->path( $storagePath );
	}

	/**
	 * Get file metadata including size, MIME type, and owner.
	 *
	 * @param int $fileId  Numeric file identifier.
	 * @return StoredFile|null
	 */
	public function getMetadata( int $fileId ): ?StoredFile {
		$storagePath = $this->resolvePath( $fileId );
		if ( null === $storagePath ) {
			return null;
		}

		$storage = Storage::disk( $this->disk );
		if ( ! $storage->exists( $storagePath ) ) {
			return null;
		}

		$mimeType  = $storage->mimeType( $storagePath ) ?: 'application/octet-stream';
		$sizeBytes = $storage->size( $storagePath );
		$publicUrl = $storage->url( $storagePath );
		$fullPath  = $storage->path( $storagePath );
		$filename  = basename( $storagePath );
		$modified  = \DateTimeImmutable::createFromFormat(
			'U',
			(string) $storage->lastModified( $storagePath ),
		) ?: new \DateTimeImmutable();

		return new StoredFile(
			id: $fileId,
			filename: $filename,
			mimeType: $mimeType,
			sizeBytes: $sizeBytes,
			localPath: $fullPath,
			publicUrl: $publicUrl ?: null,
			metadata: array(
				'disk' => $this->disk,
				'path' => $storagePath,
			),
			ownerId: 0,
			createdAt: $modified,
		);
	}

	/**
	 * Check if a user can access a file.
	 *
	 * Laravel Gates/Policies should be configured for file-level access.
	 * This default implementation returns true — override via a custom
	 * Gate for oOS files.
	 *
	 * @param int $fileId  File identifier.
	 * @param int $userId  User ID.
	 * @return bool
	 */
	public function userCanAccess( int $fileId, int $userId ): bool {
		// Default: any authenticated user can access stored files.
		// Gate: Gate::define('oos-file.access', fn ($user, $fileId) => ...)
		if ( function_exists( 'app' ) ) {
			$gate = app( \Illuminate\Contracts\Auth\Access\Gate::class );
			return $gate->allows( 'oos-file.access', array( $userId, $fileId ) );
		}

		return $userId > 0;
	}

	/**
	 * Delete a stored file permanently.
	 *
	 * @param int $fileId  File identifier.
	 *
	 * @throws NotFoundException  When the file does not exist.
	 */
	public function delete( int $fileId ): void {
		$storagePath = $this->resolvePath( $fileId );
		if ( null === $storagePath ) {
			throw new NotFoundException( 'File not found.', 'file', $fileId );
		}

		$storage = Storage::disk( $this->disk );
		if ( ! $storage->exists( $storagePath ) ) {
			throw new NotFoundException( 'File not found on disk.', 'file', $fileId );
		}

		$storage->delete( $storagePath );
	}

	/**
	 * Find files by arbitrary metadata criteria.
	 *
	 * Queries a file_metadata database table for matches. If the table
	 * doesn't exist, returns an empty array. Projects that need metadata
	 * search should run the published migration.
	 *
	 * @param array<string, mixed> $criteria  Key-value pairs to match.
	 * @param int                  $limit     Maximum results (1–100).
	 * @return StoredFile[]
	 */
	public function findByMetadata( array $criteria, int $limit = 50 ): array {
		if ( empty( $criteria ) ) {
			return array();
		}

		// Metadata search requires the nvoos_file_metadata table.
		if ( ! \Illuminate\Support\Facades\Schema::hasTable( 'nvoos_file_metadata' ) ) {
			return array();
		}

		$query = \Illuminate\Support\Facades\DB::table( 'nvoos_file_metadata' );

		foreach ( $criteria as $key => $value ) {
			if ( is_string( $value ) ) {
				$query->where( 'meta_key', $key )
				      ->where( 'meta_value', 'like', "%{$value}%" );
			} else {
				$query->where( 'meta_key', $key )
				      ->where( 'meta_value', $value );
			}
		}

		$rows  = $query->limit( min( 100, max( 1, $limit ) ) )->get();
		$files = array();

		foreach ( $rows as $row ) {
			$fileId = (int) $row->file_id;
			$stored = $this->getMetadata( $fileId );
			if ( null !== $stored ) {
				$files[] = $stored;
			}
		}

		return $files;
	}

	// ─── Private helpers ──────────────────────────────────────────────

	/**
	 * Generate a deterministic numeric ID from a storage path.
	 */
	private function hashId( string $path ): int {
		return abs( crc32( $path ) );
	}

	/**
	 * Resolve a numeric file ID back to a storage path.
	 *
	 * This is a reverse-mapping problem — numeric IDs don't map directly
	 * to Flysystem paths. Production implementations should use a
	 * files database table. For now, this returns null and callers should
	 * use path-based identifiers instead.
	 */
	private function resolvePath( int $fileId ): ?string {
		// If there's a files table, look it up.
		if ( \Illuminate\Support\Facades\Schema::hasTable( 'nvoos_files' ) ) {
			$row = \Illuminate\Support\Facades\DB::table( 'nvoos_files' )
				->where( 'id', $fileId )
				->first();

			if ( $row && isset( $row->path ) ) {
				return $row->path;
			}
		}

		return null;
	}

	/**
	 * Generate a unique filename in a given directory.
	 */
	private function uniqueFilename( string $directory, string $filename ): string {
		$storage = Storage::disk( $this->disk );
		$basename = pathinfo( $filename, PATHINFO_FILENAME );
		$extension = pathinfo( $filename, PATHINFO_EXTENSION );

		$uniqueName = $filename;
		$counter    = 1;

		while ( $storage->exists( $directory . '/' . $uniqueName ) ) {
			$uniqueName = sprintf(
				'%s-%d.%s',
				$basename,
				$counter++,
				$extension,
			);
		}

		return $uniqueName;
	}

	/**
	 * Format bytes to a human-readable string.
	 */
	private function formatBytes( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$index = 0;

		while ( $bytes >= 1024 && $index < count( $units ) - 1 ) {
			$bytes /= 1024;
			$index++;
		}

		return round( $bytes, 1 ) . ' ' . $units[ $index ];
	}
}
