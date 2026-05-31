<?php
/**
 * Immutable stored file metadata entity.
 *
 * Framework-agnostic representation of a file stored in the
 * media library or file system. Returned by FileStoreInterface.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Entity;

final readonly class StoredFile implements \JsonSerializable {

	/**
	 * @param int                  $id
	 * @param string               $filename       Display filename (e.g., 'photo.png').
	 * @param string               $mimeType       MIME type (e.g., 'image/png').
	 * @param int                  $sizeBytes      File size in bytes.
	 * @param string               $localPath      Absolute filesystem path.
	 * @param string|null          $publicUrl      Publicly accessible URL, if any.
	 * @param array<string, mixed> $metadata       Arbitrary metadata (OpenAI file_id, dimensions, etc.).
	 * @param int                  $ownerId        User who owns the file.
	 * @param \DateTimeImmutable   $createdAt
	 */
	public function __construct(
		public int $id,
		public string $filename,
		public string $mimeType,
		public int $sizeBytes,
		public string $localPath,
		public ?string $publicUrl = null,
		public array $metadata = array(),
		public int $ownerId,
		public \DateTimeImmutable $createdAt,
	) {}

	/**
	 * Whether the file is an image (based on MIME type).
	 */
	public function isImage(): bool {
		return str_starts_with( $this->mimeType, 'image/' );
	}

	/**
	 * Whether the file is a PDF.
	 */
	public function isPdf(): bool {
		return 'application/pdf' === $this->mimeType;
	}

	/**
	 * Get the file extension from the filename.
	 */
	public function getExtension(): string {
		return strtolower( pathinfo( $this->filename, PATHINFO_EXTENSION ) );
	}

	public function jsonSerialize(): array {
		return array(
			'id'         => $this->id,
			'filename'   => $this->filename,
			'mime_type'  => $this->mimeType,
			'size_bytes' => $this->sizeBytes,
			'public_url' => $this->publicUrl,
			'metadata'   => $this->metadata,
			'owner_id'   => $this->ownerId,
			'created_at' => $this->createdAt->format( 'c' ),
		);
	}
}
