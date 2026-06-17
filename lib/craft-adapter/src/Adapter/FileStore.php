<?php
/**
 * Craft adapter: FileStoreInterface implementation.
 *
 * Wraps Craft's asset volumes behind the framework-agnostic
 * FileStoreInterface. Uses Craft's asset service to create,
 * store, and retrieve files via configured volumes (local, S3, GCS).
 *
 * @package Nvoos\Craft
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Craft\Adapter;

use Craft;
use craft\elements\Asset;
use craft\helpers\Assets;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;

class FileStore implements FileStoreInterface {

	/**
	 * Craft volume handle for file storage (e.g., 'uploads', 's3Assets').
	 */
	private string $volumeHandle;

	/**
	 * Maximum file size in bytes (default: 100MB).
	 */
	private const DEFAULT_MAX_FILE_SIZE = 104857600;

	/**
	 * @param string $volumeHandle  Craft volume handle. Defaults to the first public volume.
	 */
	public function __construct( string $volumeHandle = '' ) {
		$this->volumeHandle = '' !== $volumeHandle ? $volumeHandle : $this->detectDefaultVolume();
	}

	/**
	 * Store a file from a local filesystem path.
	 *
	 * Creates a Craft Asset element attached to the configured volume.
	 *
	 * @param string $localPath  Absolute path to the source file on disk.
	 * @param string $filename   Desired display filename.
	 * @param string $mimeType   MIME type.
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

		if ( $fileSize > self::DEFAULT_MAX_FILE_SIZE ) {
			throw new ValidationException(
				sprintf(
					'File size (%s) exceeds maximum allowed (100MB).',
					$this->formatBytes( $fileSize ),
				),
			);
		}

		$volume = Craft::$app->volumes->getVolumeByHandle( $this->volumeHandle );
		if ( null === $volume ) {
			throw new \RuntimeException( "Volume '{$this->volumeHandle}' not found." );
		}

		// Determine the target folder (default to root of the volume).
		$folderId = Craft::$app->assets->getRootFolderByVolumeId( $volume->id )->id;

		$asset = new Asset();
		$asset->tempFilePath     = $localPath;
		$asset->filename         = Assets::prepareAssetName( $filename );
		$asset->newFolderId      = $folderId;
		$asset->volumeId         = $volume->id;
		$asset->setScenario( Asset::SCENARIO_CREATE );

		if ( ! Craft::$app->elements->saveElement( $asset ) ) {
			$errors = implode( ', ', $asset->getErrorSummary( true ) );
			throw new \RuntimeException( "Failed to store asset: {$errors}" );
		}

		return new StoredFile(
			id: $asset->id,
			filename: $asset->filename,
			mimeType: $asset->getMimeType() ?: $mimeType,
			sizeBytes: (int) $asset->size,
			localPath: $asset->getFs()?->getLocalCopy( $asset->getPath() ) ?? '',
			publicUrl: $asset->getUrl() ?: null,
			metadata: array(
				'volume_handle' => $this->volumeHandle,
				'folder_id'     => $folderId,
				'uploaded_by'   => $userId,
			),
			ownerId: $userId,
			createdAt: $asset->dateCreated instanceof \DateTimeInterface
				? \DateTimeImmutable::createFromInterface( $asset->dateCreated )
				: new \DateTimeImmutable(),
		);
	}

	/**
	 * Get the absolute filesystem path for a stored file.
	 *
	 * @param int $fileId  Asset element ID.
	 * @return string|null
	 */
	public function getPath( int $fileId ): ?string {
		$asset = Craft::$app->assets->getAssetById( $fileId );
		if ( null === $asset ) {
			return null;
		}

		$fs = $asset->getFs();
		if ( null === $fs ) {
			return null;
		}

		return $fs->getLocalCopy( $asset->getPath() ) ?: null;
	}

	/**
	 * Get file metadata including size, MIME type, and owner.
	 *
	 * @param int $fileId  Asset element ID.
	 * @return StoredFile|null
	 */
	public function getMetadata( int $fileId ): ?StoredFile {
		$asset = Craft::$app->assets->getAssetById( $fileId );
		if ( null === $asset ) {
			return null;
		}

		$fs = $asset->getFs();

		return new StoredFile(
			id: $asset->id,
			filename: $asset->filename,
			mimeType: $asset->getMimeType() ?: 'application/octet-stream',
			sizeBytes: (int) ( $asset->size ?? 0 ),
			localPath: $fs?->getLocalCopy( $asset->getPath() ) ?? '',
			publicUrl: $asset->getUrl() ?: null,
			metadata: array(
				'volume_handle' => $asset->getVolume()?->handle,
				'folder_id'     => $asset->folderId,
			),
			ownerId: $asset->uploaderId ?? 0,
			createdAt: $asset->dateCreated instanceof \DateTimeInterface
				? \DateTimeImmutable::createFromInterface( $asset->dateCreated )
				: new \DateTimeImmutable(),
		);
	}

	/**
	 * Check if a user can access a file.
	 *
	 * Delegates to Craft's native asset permission system.
	 *
	 * @param int $fileId  Asset element ID.
	 * @param int $userId  User ID.
	 * @return bool
	 */
	public function userCanAccess( int $fileId, int $userId ): bool {
		$user = Craft::$app->users->getUserById( $userId );
		if ( null === $user ) {
			return false;
		}

		$asset = Craft::$app->assets->getAssetById( $fileId );

		return null !== $asset && $user->can( "viewAsset:{$asset->volumeId}" );
	}

	/**
	 * Delete a stored file permanently.
	 *
	 * @param int $fileId  Asset element ID.
	 *
	 * @throws NotFoundException  When the file does not exist.
	 */
	public function delete( int $fileId ): void {
		$asset = Craft::$app->assets->getAssetById( $fileId );
		if ( null === $asset ) {
			throw new NotFoundException( 'File not found.', 'asset', $fileId );
		}

		Craft::$app->elements->deleteElement( $asset, true );
	}

	/**
	 * Find files by arbitrary metadata criteria.
	 *
	 * Uses Craft's asset query to search by filename, kind, volume, etc.
	 *
	 * @param array<string, mixed> $criteria  Key-value pairs to match.
	 * @param int                  $limit     Maximum results (1–100).
	 * @return StoredFile[]
	 */
	public function findByMetadata( array $criteria, int $limit = 50 ): array {
		if ( empty( $criteria ) ) {
			return array();
		}

		$query = Asset::find()
			->limit( min( 100, max( 1, $limit ) ) );

		if ( isset( $criteria['volume'] ) ) {
			$query->volume( $criteria['volume'] );
		}

		if ( isset( $criteria['kind'] ) ) {
			$query->kind( $criteria['kind'] );
		}

		if ( isset( $criteria['filename'] ) && is_string( $criteria['filename'] ) ) {
			$query->filename( $criteria['filename'] );
		}

		$assets = $query->all();
		$files  = array();

		foreach ( $assets as $asset ) {
			$files[] = $this->hydrateStoredFile( $asset );
		}

		return $files;
	}

	// ─── Private helpers ──────────────────────────────────────────────

	/**
	 * Detect a default volume for file storage.
	 */
	private function detectDefaultVolume(): string {
		$volumes = Craft::$app->volumes->getAllVolumes();

		foreach ( $volumes as $volume ) {
			if ( property_exists( $volume, 'handle' ) ) {
				return $volume->handle;
			}
		}

		return 'uploads';
	}

	/**
	 * Convert a Craft Asset to the framework-agnostic StoredFile.
	 */
	private function hydrateStoredFile( Asset $asset ): StoredFile {
		return new StoredFile(
			id: $asset->id,
			filename: $asset->filename,
			mimeType: $asset->getMimeType() ?: 'application/octet-stream',
			sizeBytes: (int) ( $asset->size ?? 0 ),
			localPath: $asset->getFs()?->getLocalCopy( $asset->getPath() ) ?? '',
			publicUrl: $asset->getUrl() ?: null,
			metadata: array(
				'volume_handle' => $asset->getVolume()?->handle,
			),
			ownerId: $asset->uploaderId ?? 0,
			createdAt: $asset->dateCreated instanceof \DateTimeInterface
				? \DateTimeImmutable::createFromInterface( $asset->dateCreated )
				: new \DateTimeImmutable(),
		);
	}

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
