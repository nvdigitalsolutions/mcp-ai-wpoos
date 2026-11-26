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
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileUploadServiceInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Error\ValidationException;

class FileUploadService implements FileUploadServiceInterface {

	/**
	 * Sniffed MIME → stored extension. Never trust the client's extension.
	 *
	 * Used only on the path where no registry is bound; when one is, the store
	 * assigns the extension from its own map. The two lists carry the same types
	 * so a host that switches between them does not change the stored filename.
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
		'audio/mpeg'      => 'mp3',
		'audio/wav'       => 'wav',
		'audio/x-wav'     => 'wav',
		'audio/ogg'       => 'ogg',
		'audio/x-m4a'     => 'm4a',
		'audio/mp4'       => 'm4a',
		'audio/x-hx-aac-adts' => 'aac',
		'audio/aac'       => 'aac',
		'audio/flac'      => 'flac',
		'audio/webm'      => 'webm',
		'video/mp4'       => 'mp4',
		'video/webm'      => 'webm',
		'video/quicktime' => 'mov',
	);

	/**
	 * @param string             $disk        Media disk (must be publicly served).
	 * @param int                $maxBytes    Maximum accepted size in bytes.
	 * @param array<int, string> $allowedTypes Allowed MIME types.
	 * @param FileVisibilityStoreInterface|null $store The file registry, when the host binds one.
	 */
	public function __construct(
		private readonly string $disk = 'public',
		private readonly int $maxBytes = self::DEFAULT_MAX_FILE_SIZE,
		private readonly array $allowedTypes = self::DEFAULT_ALLOWED_TYPES,
		private readonly ?FileVisibilityStoreInterface $store = null,
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

		// Proposal 162 §162.7 — one writer. When the host binds the file registry,
		// the bytes and the row are written there: one id scheme, one path
		// convention, one place that knows what a stored file is. The
		// hand-written path below stays for a host that binds only this service
		// (the package is consumed outside this repository).
		if ( null !== $this->store ) {
			try {
				$stored = $this->store->storeWithVisibility(
					$tmp,
					$original,
					$mime,
					isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
					FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
					$this->tenantFrom( $context ),
				);
			} catch ( ValidationException $exception ) {
				return array(
					'success' => false,
					'error'   => $exception->getMessage(),
				);
			}

			return array(
				'success'       => true,
				'attachment_id' => $stored->id,
				'url'           => $stored->publicUrl,
				'path'          => (string) ( $stored->metadata['path'] ?? '' ),
			);
		}

		$base     = Str::slug( \pathinfo( $original, PATHINFO_FILENAME ) );

		if ( '' === $base ) {
			$base = 'upload';
		}

		$filename = $base . '-' . Str::lower( Str::random( 10 ) ) . '.' . self::extensionFor( $mime );
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
				// The estate that owns this upload. Supplied by the caller rather
				// than read here: this adapter is constructed outside any tenant
				// middleware, the same reason TokenUsage takes it from metadata
				// (Proposal 149 §109, §152 §9.3).
				'tenant_id'  => $this->tenantFrom( $context ),
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
	 * The estate an upload belongs to.
	 *
	 * The caller supplies it in `$context['tenant_id']`; a missing value is the
	 * default estate, which is the only defensible attribution for a row that
	 * records none (Proposal 152 §3 D3). Falls back rather than throwing: an
	 * upload that has already written bytes to the disk must not be failed by
	 * an attribution problem.
	 *
	 * @param array<string, mixed> $context
	 */
	private function tenantFrom( array $context ): string {
		$tenant = trim( (string) ( $context['tenant_id'] ?? '' ) );

		return '' !== $tenant ? substr( $tenant, 0, 64 ) : 'default';
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
	 * The sniff this host trusts, exposed for the resumable path.
	 *
	 * The chunked upload assembles a file on disk and has to ask the same two
	 * questions the single-request path asks — what is it, and is it allowed —
	 * before handing the bytes to the registry. Answering "what is it" twice, with
	 * two sniffs, would let the two paths disagree about the same bytes.
	 *
	 * @return string|null The sniffed type, or null when it cannot be read.
	 */
	public static function detectMimeType( string $path ): ?string {
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

	/**
	 * The extension this host would give a sniffed type.
	 *
	 * Exposed so the allowlist can be checked against it: a type in the allowlist
	 * with no extension here would be stored as `.bin` on the no-registry path,
	 * which is a file no player opens.
	 */
	public static function extensionFor( string $mimeType ): string {
		return self::EXTENSIONS[ $mimeType ] ?? 'bin';
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
		return self::detectMimeType( $path );
	}
}
