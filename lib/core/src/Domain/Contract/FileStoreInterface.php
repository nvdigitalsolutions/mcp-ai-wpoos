<?php
/**
 * File store contract for the oOS AI orchestration core.
 *
 * Abstracts file upload, storage, retrieval, and access control so that
 * tools and services never depend on WordPress media functions or any
 * other framework's file system.
 *
 * Patterned after Flysystem (thephpleague/flysystem) — the canonical
 * PHP Ports & Adapters implementation for file storage.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Nvoos\Core\Domain\Entity\StoredFile;

interface FileStoreInterface {

	/**
	 * Store a file from a local filesystem path.
	 *
	 * The adapter is responsible for moving/copying the file to its
	 * permanent storage location and registering it in the media library.
	 *
	 * @param string $localPath  Absolute path to the source file on disk.
	 * @param string $filename   Desired display filename.
	 * @param string $mimeType   MIME type (e.g., 'image/png', 'application/pdf').
	 * @param int    $userId     User who owns the uploaded file.
	 *
	 * @return StoredFile  Metadata for the stored file.
	 *
	 * @throws \Nvoos\Core\Domain\Error\ValidationException  When file type/size is invalid.
	 */
	public function store( string $localPath, string $filename, string $mimeType, int $userId ): StoredFile;

	/**
	 * Get the absolute filesystem path for a stored file.
	 *
	 * @return string|null  Null if file not found.
	 */
	public function getPath( int $fileId ): ?string;

	/**
	 * Get file metadata including size, MIME type, dimensions, and owner.
	 */
	public function getMetadata( int $fileId ): ?StoredFile;

	/**
	 * Check if a user can access a file.
	 */
	public function userCanAccess( int $fileId, int $userId ): bool;

	/**
	 * Delete a stored file permanently.
	 */
	public function delete( int $fileId ): void;

	/**
	 * Find files by arbitrary metadata criteria.
	 *
	 * Used to look up files by external identifiers such as an OpenAI
	 * file_id stored in attachment metadata.
	 *
	 * @param array<string, mixed> $criteria  Key-value pairs to match.
	 * @param int                  $limit     Maximum results to return.
	 *
	 * @return StoredFile[]
	 */
	public function findByMetadata( array $criteria, int $limit = 50 ): array;
}
