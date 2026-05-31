<?php
/**
 * WordPress adapter: FileStoreInterface implementation.
 *
 * Wraps WordPress media/attachment functions (wp_insert_attachment,
 * get_attached_file, wp_upload_dir, get_post_mime_type) behind the
 * framework-agnostic FileStoreInterface.
 *
 * Patterned after Flysystem (thephpleague/flysystem) — one interface,
 * multiple filesystem adapters.
 *
 * @package Oos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Oos\WordPress\Adapter;

use Oos\Core\Domain\Contract\FileStoreInterface;
use Oos\Core\Domain\Entity\StoredFile;
use Oos\Core\Domain\Error\NotFoundException;
use Oos\Core\Domain\Error\ValidationException;

class FileStore implements FileStoreInterface {

	/**
	 * Maximum file size in bytes (default: 100MB).
	 */
	private const DEFAULT_MAX_FILE_SIZE = 104857600;

	public function store( string $localPath, string $filename, string $mimeType, int $userId ): StoredFile {
		if ( ! \file_exists( $localPath ) ) {
			throw new ValidationException( "Source file does not exist: {$localPath}" );
		}

		$fileSize = \filesize( $localPath );
		if ( false === $fileSize ) {
			throw new ValidationException( "Could not determine file size: {$localPath}" );
		}

		$maxSize = (int) \apply_filters( 'wp_mcp_ai_max_upload_bytes', self::DEFAULT_MAX_FILE_SIZE );
		if ( $fileSize > $maxSize ) {
			throw new ValidationException(
				\sprintf(
					'File size (%s) exceeds maximum allowed (%s).',
					\size_format( $fileSize ),
					\size_format( $maxSize ),
				),
			);
		}

		// Use WordPress media handling to create the attachment.
		$uploadDir = \wp_upload_dir();
		if ( ! empty( $uploadDir['error'] ) ) {
			throw new \RuntimeException( $uploadDir['error'] );
		}

		// Ensure unique filename in uploads directory.
		$uniqueFilename = \wp_unique_filename( $uploadDir['path'], $filename );
		$destPath       = $uploadDir['path'] . '/' . $uniqueFilename;

		if ( ! \copy( $localPath, $destPath ) ) {
			throw new \RuntimeException( "Failed to copy file to uploads directory: {$destPath}" );
		}

		// Build attachment metadata for wp_insert_attachment.
		$attachment = array(
			'post_mime_type' => $mimeType,
			'post_title'     => \sanitize_file_name( \pathinfo( $filename, \PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => $userId,
		);

		$attachmentId = \wp_insert_attachment( $attachment, $destPath );

		if ( 0 === $attachmentId || \is_wp_error( $attachmentId ) ) {
			\unlink( $destPath );
			throw new \RuntimeException( 'Failed to create attachment.' );
		}

		// Generate thumbnails and metadata.
		if ( ! \function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = \wp_generate_attachment_metadata( $attachmentId, $destPath );
		\wp_update_attachment_metadata( $attachmentId, $metadata );

		return $this->hydrateStoredFile( $attachmentId );
	}

	public function getPath( int $fileId ): ?string {
		$path = \get_attached_file( $fileId );

		return is_string( $path ) && \file_exists( $path ) ? $path : null;
	}

	public function getMetadata( int $fileId ): ?StoredFile {
		$post = \get_post( $fileId );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return null;
		}

		return $this->hydrateStoredFile( $fileId );
	}

	public function userCanAccess( int $fileId, int $userId ): bool {
		$post = \get_post( $fileId );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		// If the user is the author, grant access.
		if ( (int) $post->post_author === $userId ) {
			return true;
		}

		// Administrators can access any file.
		if ( \user_can( $userId, 'manage_options' ) ) {
			return true;
		}

		// Check the standard WordPress read capability.
		return \user_can( $userId, 'read_post', $fileId );
	}

	public function delete( int $fileId ): void {
		$post = \get_post( $fileId );
		if ( ! $post instanceof \WP_Post ) {
			throw new NotFoundException( 'File not found.', 'attachment', $fileId );
		}

		$result = \wp_delete_attachment( $fileId, true );

		if ( false === $result ) {
			throw new \RuntimeException( "Failed to delete attachment {$fileId}." );
		}
	}

	public function findByMetadata( array $criteria, int $limit = 50 ): array {
		global $wpdb;

		if ( empty( $criteria ) ) {
			return array();
		}

		// Build a meta query using the WordPress postmeta table.
		$conditions = array();
		$values     = array();

		foreach ( $criteria as $metaKey => $metaValue ) {
			$like         = '%' . $wpdb->esc_like( (string) $metaValue ) . '%';
			$conditions[] = $wpdb->prepare(
				'(pm.meta_key = %s AND pm.meta_value LIKE %s)',
				\sanitize_key( $metaKey ),
				$like,
			);
		}

		$whereClause = \implode( ' OR ', $conditions );
		$limit       = \max( 1, \min( 100, $limit ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			"SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'attachment'
             AND ({$whereClause})
             LIMIT {$limit}"
		);
        // phpcs:enable

		if ( ! is_array( $results ) ) {
			return array();
		}

		$files = array();
		foreach ( $results as $fileId ) {
			$storedFile = $this->getMetadata( (int) $fileId );
			if ( null !== $storedFile ) {
				$files[] = $storedFile;
			}
		}

		return $files;
	}

	/**
	 * Convert a WordPress attachment to the framework-agnostic StoredFile.
	 */
	private function hydrateStoredFile( int $attachmentId ): StoredFile {
		$post = \get_post( $attachmentId );
		if ( ! $post instanceof \WP_Post ) {
			throw new NotFoundException( 'Attachment not found.', 'attachment', $attachmentId );
		}

		$mimeType = $post->post_mime_type ?? 'application/octet-stream';
		$filePath = \get_attached_file( $attachmentId );
		$fileSize = is_string( $filePath ) && \file_exists( $filePath )
			? (int) \filesize( $filePath )
			: 0;

		$publicUrl = \wp_get_attachment_url( $attachmentId );
		$filename  = \basename( (string) $filePath );

		// Collect all postmeta as metadata.
		$rawMeta   = \get_post_meta( $attachmentId );
		$cleanMeta = array();
		if ( is_array( $rawMeta ) ) {
			foreach ( $rawMeta as $key => $values ) {
				// Skip WordPress internal meta.
				if ( \str_starts_with( $key, '_wp_' ) ) {
					continue;
				}
				$cleanMeta[ $key ] = is_array( $values ) && 1 === \count( $values )
					? \reset( $values )
					: $values;
			}
		}

		$createdAt = \DateTimeImmutable::createFromFormat(
			'Y-m-d H:i:s',
			$post->post_date_gmt,
			new \DateTimeZone( 'UTC' ),
		) ?: new \DateTimeImmutable();

		return new StoredFile(
			id: $attachmentId,
			filename: $filename,
			mimeType: $mimeType,
			sizeBytes: $fileSize,
			localPath: is_string( $filePath ) ? $filePath : '',
			publicUrl: is_string( $publicUrl ) ? $publicUrl : null,
			metadata: $cleanMeta,
			ownerId: (int) $post->post_author,
			createdAt: $createdAt,
		);
	}
}
