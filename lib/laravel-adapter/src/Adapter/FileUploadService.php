<?php
/**
 * Laravel adapter: FileUploadService.
 *
 * Validates and stores uploads for AI provider consumption. Files are written
 * to a dedicated media disk (not the general storage disk) because the media
 * library must serve public URLs, and the extension is derived from the
 * sniffed MIME type rather than the client-supplied filename.
 *
 * @package Nvoos\Laravel
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvoos\Core\Domain\Contract\FileUploadServiceInterface;

class FileUploadService implements FileUploadServiceInterface {

	/**
	 * Sniffed MIME → stored extension. Never trust the client's extension.
	 *
	 * @var array<string, string>
	 */
	private const EXTENSIONS = array(
		'image/jpeg'      => 'jpg',
		'image/png'       => 'png',
		'image/gif'       => 'gif',
		'image/webp'      => 'webp',
		'application/pdf' => 'pdf',
		'text/plain'      => 'txt',
		'text/csv'        => 'csv',
		'application/json' => 'json',
		'application/msword' => 'doc',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
	);

	/**
	 * @param string             $disk        Media disk (must be publicly served).
	 * @param int                $maxBytes    Maximum accepted size in bytes.
	 * @param array<int, string> $allowedTypes Allowed MIME types.
	 */
	public function __construct(
		private readonly string $disk = 'public',
		private readonly int $maxBytes = self::DEFAULT_MAX_FILE_SIZE,
		private readonly array $allowedTypes = self::DEFAULT_ALLOWED_TYPES,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function validate( array $file, array $context = array() ): array {
		unset( $context );

		$errors = array();
		$tmp    = (string) ( $file['tmp_name'] ?? '' );
		$size   = (int) ( $file['size'] ?? 0 );
		$error  = (int) ( $file['error'] ?? UPLOAD_ERR_OK );

		if ( UPLOAD_ERR_OK !== $error ) {
			$errors[] = 'Upload failed with error code ' . $error . '.';
		}

		if ( '' === $tmp || ! \is_file( $tmp ) ) {
			$errors[] = 'Uploaded file is missing.';
		} elseif ( $size <= 0 ) {
			$errors[] = 'Uploaded file is empty.';
		} elseif ( $size > $this->maxBytes ) {
			$errors[] = 'File exceeds the maximum size of ' . $this->maxBytes . ' bytes.';
		}

		$mime = \is_file( $tmp ) ? $this->detectMime( $tmp ) : null;

		if ( null === $mime ) {
			$errors[] = 'Unable to determine the file type.';
		} elseif ( ! $this->isMimeTypeAllowed( $mime ) ) {
			$errors[] = 'File type ' . $mime . ' is not allowed.';
		}

		return array(
			'valid'     => array() === $errors,
			'errors'    => $errors,
			'file_info' => array(
				'name'      => \basename( (string) ( $file['name'] ?? 'upload' ) ),
				'mime_type' => $mime,
				'size'      => $size,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function upload( array $file, array $context = array() ): array {
		$check = $this->validate( $file, $context );

		if ( ! $check['valid'] ) {
			return array(
				'success' => false,
				'error'   => \implode( ' ', $check['errors'] ),
			);
		}

		$mime     = (string) $check['file_info']['mime_type'];
		$tmp      = (string) $file['tmp_name'];
		$original = (string) $check['file_info']['name'];
		$base     = Str::slug( \pathinfo( $original, PATHINFO_FILENAME ) );

		if ( '' === $base ) {
			$base = 'upload';
		}

		$filename = $base . '-' . Str::lower( Str::random( 10 ) ) . '.' . ( self::EXTENSIONS[ $mime ] ?? 'bin' );
		$path     = 'media/' . \date( 'Y/m' ) . '/' . $filename;
		$contents = \file_get_contents( $tmp );

		if ( false === $contents ) {
			return array(
				'success' => false,
				'error'   => 'Unable to read the uploaded file.',
			);
		}

		Storage::disk( $this->disk )->put( $path, $contents );

		$now = \now();

		$id = DB::table( 'nvoos_files' )->insertGetId(
			array(
				'path'       => $path,
				'disk'       => $this->disk,
				'filename'   => $filename,
				'mime_type'  => $mime,
				'size_bytes' => (int) $check['file_info']['size'],
				'owner_id'   => isset( $context['user_id'] ) ? (int) $context['user_id'] : null,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		return array(
			'success'       => true,
			'attachment_id' => (int) $id,
			'url'           => $this->publicUrl( $path ),
			'path'          => $path,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function prepareDocument( string $filePath ): array {
		if ( ! \is_file( $filePath ) ) {
			throw new \RuntimeException( 'File not found: ' . $filePath );
		}

		$mime = $this->detectMime( $filePath ) ?? 'application/octet-stream';
		$raw  = (string) \file_get_contents( $filePath );

		$isText = \str_starts_with( $mime, 'text/' ) || 'application/json' === $mime;

		return array(
			'content'  => $isText ? $raw : \base64_encode( $raw ),
			'metadata' => array(
				'name'      => \basename( $filePath ),
				'mime_type' => $mime,
				'size'      => (int) \filesize( $filePath ),
				'encoding'  => $isText ? 'utf-8' : 'base64',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function isMimeTypeAllowed( string $mimeType ): bool {
		return \in_array( $mimeType, $this->allowedTypes, true );
	}

	/**
	 * Resolve a stored path to a URL the console can load.
	 *
	 * The public disk's `url()` is absolute (built from APP_URL); the console
	 * runs on its own origin, so an app-relative path is returned when the URL
	 * belongs to this app. Remote disks (S3, GCS) keep their absolute URL.
	 */
	public function publicUrl( string $path ): string {
		$url    = Storage::disk( $this->disk )->url( $path );
		$appUrl = \rtrim( (string) \config( 'app.url' ), '/' );

		if ( '' !== $appUrl && \str_starts_with( $url, $appUrl ) ) {
			return \substr( $url, \strlen( $appUrl ) );
		}

		return $url;
	}

	private function detectMime( string $path ): ?string {
		if ( \function_exists( 'finfo_open' ) ) {
			$handle = \finfo_open( FILEINFO_MIME_TYPE );
			$mime   = false === $handle ? false : \finfo_file( $handle, $path );

			if ( false !== $handle ) {
				\finfo_close( $handle );
			}

			return \is_string( $mime ) && '' !== $mime ? $mime : null;
		}

		$mime = \function_exists( 'mime_content_type' ) ? \mime_content_type( $path ) : false;

		return \is_string( $mime ) && '' !== $mime ? $mime : null;
	}
}
