<?php

/**
 * Laravel adapter: FileStore.
 *
 * One registry (`nvoos_files`), one identifier (its auto-increment id), two
 * visibilities — Proposal 162.
 *
 * The previous implementation wrote bytes and returned `crc32($path)` as the id
 * without ever inserting a row, so the id could not be resolved and
 * `upload_file` → `get_file_info` / `delete_file` was broken end to end. It also
 * hard-coded `put(..., 'public')`, so a private file could not exist.
 *
 * What the store promises now:
 *
 *  - **The id is the row's id.** Insert, then return it; `getMetadata()` resolves
 *    the same value. No hashing.
 *  - **Visibility is a property of the object, not the disk name.** A deployment
 *    that repoints the private disk at an S3 bucket must not silently make
 *    existing private files public, so the column decides and the disk follows.
 *  - **The bytes decide the type.** The extension comes from the sniffed MIME
 *    type, never from the caller's filename (OWASP file-upload guidance).
 *  - **The filename is never a path.** The object key ends in a ULID; the display
 *    name lives in the row.
 *  - **Delete removes the row and the bytes.** A row without bytes is a phantom
 *    library entry; bytes without a row are unreachable.
 *
 * @package Nvoos\Laravel
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use DateTimeImmutable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvoos\Core\Domain\Contract\FileDerivativeStoreInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;
use Throwable;

class FileStore implements FileStoreInterface, FileVisibilityStoreInterface, FileDerivativeStoreInterface
{
	/**
	 * Sniffed MIME → [extension, policy group].
	 *
	 * The group decides the size ceiling: a video is not an image, and one cap for
	 * both would either refuse legitimate video or allow a 500 MB PNG.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const TYPES = array(
		'image/jpeg'      => array( 'jpg', 'image' ),
		'image/png'       => array( 'png', 'image' ),
		'image/gif'       => array( 'gif', 'image' ),
		'image/webp'      => array( 'webp', 'image' ),
		'image/avif'      => array( 'avif', 'image' ),
		'application/pdf' => array( 'pdf', 'document' ),
		'text/plain'      => array( 'txt', 'document' ),
		'text/markdown'   => array( 'md', 'document' ),
		'text/csv'        => array( 'csv', 'document' ),
		'application/json' => array( 'json', 'document' ),
		// Subtitles. Both are what libmagic actually reports for the bytes —
		// `application/x-subrip` for an SRT and `text/vtt` for a WebVTT file —
		// so they can be typed without trusting a caller's extension. ASS has no
		// distinct signature (it sniffs as `text/plain`) and is therefore not
		// here; storing one would file it as `.txt`.
		'application/x-subrip' => array( 'srt', 'document' ),
		'text/vtt'             => array( 'vtt', 'document' ),
		'application/msword' => array( 'doc', 'document' ),
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => array( 'docx', 'document' ),
		'application/vnd.ms-excel' => array( 'xls', 'document' ),
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => array( 'xlsx', 'document' ),
		'application/vnd.ms-powerpoint' => array( 'ppt', 'document' ),
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => array( 'pptx', 'document' ),
		'video/mp4'       => array( 'mp4', 'video' ),
		'video/webm'      => array( 'webm', 'video' ),
		'video/quicktime' => array( 'mov', 'video' ),
		'audio/mpeg'      => array( 'mp3', 'audio' ),
		'audio/wav'       => array( 'wav', 'audio' ),
		'audio/x-wav'     => array( 'wav', 'audio' ),
		'audio/ogg'       => array( 'ogg', 'audio' ),
		// What libmagic actually reports for the containers this runtime writes,
		// measured with ffmpeg 8.1.2 output rather than guessed: an M4A sniffs as
		// `audio/x-m4a` (not `audio/mp4`), an ADTS AAC as `audio/x-hx-aac-adts`,
		// and an audio-only WebM still as `video/webm` — which is already above
		// and stores it as `.webm`, the right extension. Without these an
		// `extract_audio` result in m4a or aac could not be stored at all.
		'audio/x-m4a'     => array( 'm4a', 'audio' ),
		'audio/mp4'       => array( 'm4a', 'audio' ),
		'audio/x-hx-aac-adts' => array( 'aac', 'audio' ),
		'audio/aac'       => array( 'aac', 'audio' ),
		'audio/flac'      => array( 'flac', 'audio' ),
		'audio/webm'      => array( 'webm', 'audio' ),
	);

	/**
	 * Per-group ceilings, in bytes. Configuration overrides these; the defaults
	 * are the policy (Proposal 162 §162.4).
	 *
	 * @var array<string, int>
	 */
	public const DEFAULT_LIMITS = array(
		'image'    => 20971520,   // 20 MiB
		'document' => 26214400,   // 25 MiB
		'video'    => 536870912,  // 512 MiB
		'audio'    => 104857600,  // 100 MiB
	);

	/** Used when a caller passes an explicit cap and no group applies. */
	public const DEFAULT_MAX_BYTES = 26214400;

	/**
	 * The sniffed types this registry accepts.
	 *
	 * The authority on what can actually be filed, exposed so an allowlist
	 * elsewhere can be checked against it. A type a caller may pick but this map
	 * refuses is a half-wired feature: validation passes and the write then fails
	 * with "File type is not accepted". `FileUploadAllowlistTest` asserts the
	 * upload allowlist is a subset of this.
	 *
	 * @return list<string>
	 */
	public static function acceptedTypes(): array {
		return \array_keys( self::TYPES );
	}

	private string $publicDisk;

	private string $privateDisk;

	/** @var array<string, int> */
	private array $limits;

	/** @var array<int, string> */
	private array $allowedTypes;

	/**
	 * @param string             $publicDisk   Disk serving `public` files.
	 * @param string             $privateDisk  Disk holding `private` files (outside the webroot).
	 * @param int                $maxBytes     Fallback ceiling when a MIME group has none.
	 * @param array<int, string> $allowedTypes Overrides the built-in allowlist.
	 * @param array<string, int> $limits       Per-group ceilings.
	 */
	public function __construct(
		string $publicDisk = '',
		string $privateDisk = '',
		int $maxBytes = 0,
		array $allowedTypes = array(),
		array $limits = array(),
	) {
		$this->publicDisk  = '' !== $publicDisk ? $publicDisk : (string) ( config( 'oos.files.disks.public' ) ?: 'public' );
		$this->privateDisk = '' !== $privateDisk ? $privateDisk : (string) ( config( 'oos.files.disks.private' ) ?: 'local' );
		$this->limits      = array() !== $limits ? $limits : (array) ( config( 'oos.files.limits' ) ?: self::DEFAULT_LIMITS );
		$this->allowedTypes = array() !== $allowedTypes
			? $allowedTypes
			: \array_keys( self::TYPES );

		if ( $maxBytes > 0 ) {
			$this->limits = array( 'default' => $maxBytes ) + $this->limits;
		}
	}

	// ─── FileStoreInterface ───────────────────────────────────────────

	/**
	 * {@inheritDoc}
	 *
	 * Kept for the existing contract. Hosts that need a private file call
	 * `storeWithVisibility()`; this one is public, which is what the signature
	 * has always meant.
	 */
	public function store( string $localPath, string $filename, string $mimeType, int $userId ): StoredFile {
		return $this->storeWithVisibility(
			$localPath,
			$filename,
			$mimeType,
			$userId,
			self::VISIBILITY_PUBLIC,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function storeWithVisibility(
		string $localPath,
		string $filename,
		string $mimeType,
		int $userId,
		string $visibility,
		?string $tenantId = null,
	): StoredFile {
		if ( ! $this->supportsVisibility( $visibility ) ) {
			throw new ValidationException(
				sprintf( 'Unknown visibility "%s".', $visibility ),
			);
		}

		if ( ! \file_exists( $localPath ) ) {
			throw new ValidationException( "Source file does not exist: {$localPath}" );
		}

		$size = \filesize( $localPath );

		if ( false === $size ) {
			throw new ValidationException( "Could not determine file size: {$localPath}" );
		}

		// The bytes decide the type. The caller's `$mimeType` is a hint that is
		// discarded when it disagrees with the sniff — a "text/plain" filename
		// carrying a PNG is a PNG.
		$sniffed = $this->detectMime( $localPath );

		if ( null === $sniffed || ! isset( self::TYPES[ $sniffed ] ) ) {
			throw new ValidationException(
				sprintf( 'File type is not accepted: %s.', $sniffed ?? 'unknown' ),
			);
		}

		if ( ! \in_array( $sniffed, $this->allowedTypes, true ) ) {
			throw new ValidationException(
				sprintf( 'File type is not allowed here: %s.', $sniffed ),
			);
		}

		$group = self::TYPES[ $sniffed ][1];
		$cap   = (int) ( $this->limits[ $group ] ?? $this->limits['default'] ?? self::DEFAULT_MAX_BYTES );

		if ( $size > $cap ) {
			throw new ValidationException(
				sprintf(
					'A %s may be at most %s; this one is %s.',
					$group,
					$this->formatBytes( $cap ),
					$this->formatBytes( $size ),
				),
			);
		}

		$extension = self::TYPES[ $sniffed ][0];
		$diskName  = self::VISIBILITY_PRIVATE === $visibility ? $this->privateDisk : $this->publicDisk;
		$path      = $this->objectPath( $tenantId, $visibility, $extension );
		$storage   = Storage::disk( $diskName );

		$stream = \fopen( $localPath, 'rb' );

		if ( false === $stream ) {
			throw new \RuntimeException( "Failed to open source file: {$localPath}" );
		}

		try {
			$stored = $storage->put(
				$path,
				$stream,
				array( 'visibility' => self::VISIBILITY_PRIVATE === $visibility ? 'private' : 'public' ),
			);
		} finally {
			if ( \is_resource( $stream ) ) {
				\fclose( $stream );
			}
		}

		if ( ! $stored ) {
			throw new \RuntimeException( "Failed to store file: {$path}" );
		}

		$displayName = $this->displayName( $filename, $extension );
		$checksum    = (string) \hash_file( 'sha256', $localPath );
		$now         = \now();
		$tenant      = $this->tenantSlug( $tenantId );

		[$id, $slug] = $this->insertRow(
			array(
				'path'       => $path,
				'disk'       => $diskName,
				'filename'   => $displayName,
				'tenant_id'  => $tenant,
				'mime_type'  => $sniffed,
				'size_bytes' => (int) $size,
				'visibility' => $visibility,
				'checksum'   => $checksum,
				'metadata'   => null,
				'owner_id'   => $userId > 0 ? $userId : null,
				'created_at' => $now,
				'updated_at' => $now,
			),
			$tenant,
			$filename,
		);

		return new StoredFile(
			id: $id,
			filename: $displayName,
			mimeType: $sniffed,
			sizeBytes: (int) $size,
			localPath: $this->localPathOf( $storage, $path ),
			ownerId: $userId,
			createdAt: new DateTimeImmutable(),
			publicUrl: self::VISIBILITY_PUBLIC === $visibility ? ( $storage->url( $path ) ?: null ) : null,
			metadata: array(
				'disk'       => $diskName,
				'path'       => $path,
				'checksum'   => $checksum,
				'tenant_id'  => $tenant,
				'visibility' => $visibility,
				'slug'       => $slug,
			),
			visibility: $visibility,
			slug: $slug,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getPath( int $fileId ): ?string {
		$row = $this->row( $fileId );

		if ( null === $row ) {
			return null;
		}

		$storage = Storage::disk( (string) $row->disk );

		if ( ! $storage->exists( (string) $row->path ) ) {
			return null;
		}

		return $this->localPathOf( $storage, (string) $row->path ) ?: null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The row is authoritative. The disk is consulted only for `localPath`, and
	 * only when the disk is local.
	 */
	public function getMetadata( int $fileId ): ?StoredFile {
		$row = $this->row( $fileId );

		if ( null === $row ) {
			return null;
		}

		$visibility = (string) ( $row->visibility ?? self::VISIBILITY_PUBLIC );
		$storage    = Storage::disk( (string) $row->disk );
		$url        = null;

		if ( self::VISIBILITY_PUBLIC === $visibility ) {
			try {
				$url = $storage->url( (string) $row->path ) ?: null;
			} catch ( Throwable ) {
				$url = null;
			}
		}

		return new StoredFile(
			id: (int) $row->id,
			filename: (string) $row->filename,
			mimeType: (string) ( $row->mime_type ?? 'application/octet-stream' ),
			sizeBytes: (int) ( $row->size_bytes ?? 0 ),
			localPath: $this->localPathOf( $storage, (string) $row->path ),
			ownerId: (int) ( $row->owner_id ?? 0 ),
			createdAt: $row->created_at
				? ( new DateTimeImmutable( (string) $row->created_at ) )
				: new DateTimeImmutable(),
			publicUrl: $url,
			metadata: $this->decodeMetadata( $row->metadata ?? null ),
			visibility: $visibility,
			slug: (string) ( $row->slug ?? '' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Owner, or the host's `oos-file.access` ability. The ability existed as a
	 * documented extension point but nothing defined it, so this method always
	 * returned false; the host now defines it (Proposal 162 §162.4).
	 */
	public function userCanAccess( int $fileId, int $userId ): bool {
		$row = $this->row( $fileId );

		if ( null === $row ) {
			return false;
		}

		if ( (int) ( $row->owner_id ?? 0 ) === $userId && $userId > 0 ) {
			return true;
		}

		if ( ! \function_exists( 'app' ) || ! $userId > 0 ) {
			return false;
		}

		try {
			return app( Gate::class )->allows( 'oos-file.access', array( $userId, $fileId ) );
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * Bytes and row together. Missing bytes on disk are tolerated — the row is
	 * what callers can still see, and refusing to delete it would leave a phantom
	 * that nothing can remove.
	 */
	public function delete( int $fileId ): void {
		$row = $this->row( $fileId );

		if ( null === $row ) {
			throw new NotFoundException( 'File not found.', 'file', $fileId );
		}

		$storage = Storage::disk( (string) $row->disk );

		if ( $storage->exists( (string) $row->path ) ) {
			$storage->delete( (string) $row->path );
		}

		DB::table( 'nvoos_files' )->where( 'id', $fileId )->delete();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Matched against the `metadata` JSON and the row's own columns. The previous
	 * implementation queried a `nvoos_file_metadata` table that this runtime has
	 * never had, so it always returned an empty array.
	 *
	 * The scan is bounded rather than unbounded: this is a lookup, not a report.
	 */
	public function findByMetadata( array $criteria, int $limit = 50 ): array {
		if ( array() === $criteria || ! Schema::hasTable( 'nvoos_files' ) ) {
			return array();
		}

		$scan = DB::table( 'nvoos_files' )
			->orderByDesc( 'id' )
			->limit( 1000 )
			->get();

		$matches = array();

		foreach ( $scan as $row ) {
			if ( $this->rowMatches( $row, $criteria ) ) {
				$file = $this->getMetadata( (int) $row->id );

				if ( null !== $file ) {
					$matches[] = $file;
				}
			}

			if ( \count( $matches ) >= \min( 100, \max( 1, $limit ) ) ) {
				break;
			}
		}

		return $matches;
	}

	/**
	 * {@inheritDoc}
	 *
	 * A derivative records two metadata keys, so a later reader can find it
	 * without knowing which tool produced it: `derived_from` (the immediate
	 * source id, an integer) and `derived_role`. XMP calls the first
	 * `xmpMM:DerivedFrom`; asset management calls the result a rendition.
	 *
	 * The write merges rather than replaces: a file may already carry sniffed
	 * metadata, and a relationship is not a reason to drop it.
	 */
	public function recordDerivative( int $fileId, int $sourceFileId, string $role ): StoredFile {
		if ( $fileId <= 0 || $sourceFileId <= 0 ) {
			throw new ValidationException( 'Both file ids must be positive.' );
		}

		if ( $fileId === $sourceFileId ) {
			throw new ValidationException( 'A file cannot derive from itself.' );
		}

		if ( ! \in_array( $role, FileDerivativeStoreInterface::ROLES, true ) ) {
			throw new ValidationException(
				sprintf(
					'Unknown derivative role "%s"; expected one of: %s.',
					$role,
					\implode( ', ', FileDerivativeStoreInterface::ROLES ),
				),
			);
		}

		$row = $this->row( $fileId );

		if ( null === $row ) {
			throw new NotFoundException( "There is no stored file with id {$fileId}." );
		}

		if ( null === $this->row( $sourceFileId ) ) {
			throw new NotFoundException( "There is no stored file with id {$sourceFileId}." );
		}

		// The column arrived with the visibility migration; an installation that
		// has not migrated cannot record a relationship, and silently dropping it
		// would make every inventory read "none".
		if ( ! Schema::hasColumn( 'nvoos_files', 'metadata' ) ) {
			throw new ValidationException( 'This installation has no nvoos_files.metadata column; run the migrations.' );
		}

		$metadata = $this->decodeMetadata( $row->metadata ?? null );

		$metadata[ FileDerivativeStoreInterface::META_SOURCE ] = $sourceFileId;
		$metadata[ FileDerivativeStoreInterface::META_ROLE ]   = $role;

		$encoded = \json_encode( $metadata );

		if ( false === $encoded ) {
			throw new ValidationException( 'Could not encode the derivative metadata.' );
		}

		DB::table( 'nvoos_files' )->where( 'id', $fileId )->update(
			array(
				'metadata'   => $encoded,
				'updated_at' => now(),
			),
		);

		$stored = $this->getMetadata( $fileId );

		if ( null === $stored ) {
			throw new NotFoundException( "The stored file with id {$fileId} disappeared while it was being linked." );
		}

		return $stored;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supportsVisibility( string $visibility ): bool {
		return \in_array(
			$visibility,
			array( self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE ),
			true,
		);
	}

	// ─── Private helpers ──────────────────────────────────────────────

	/**
	 * `{tenant}/{visibility}/{Y}/{m}/{ulid}.{ext}`.
	 *
	 * The estate and the visibility are path segments so that (a) lifecycle and
	 * quota rules can be scoped per estate later without a schema change, and
	 * (b) a misconfigured disk cannot produce a private object at a
	 * public-looking key.
	 */
	private function objectPath( ?string $tenantId, string $visibility, string $extension ): string {
		return \sprintf(
			'%s/%s/%s/%s/%s.%s',
			$this->tenantSlug( $tenantId ),
			$visibility,
			\gmdate( 'Y' ),
			\gmdate( 'm' ),
			\strtolower( (string) Str::ulid() ),
			$extension,
		);
	}

	/**
	 * Insert the row, retrying when two uploads race for one slug.
	 *
	 * The unique index on `(tenant_id, slug)` is the authority; the computed slug
	 * is only a guess that is usually right. On a collision the recompute sees the
	 * winner's row and picks the next suffix, which is why the retry is bounded
	 * rather than absent — and why a collision is not surfaced as an upload
	 * failure.
	 *
	 * @param array<string, mixed> $row
	 * @return array{0: int, 1: string} The new id and the slug it took.
	 */
	private function insertRow( array $row, string $tenant, string $originalName ): array {
		$stem = $this->slugStem( $originalName );

		for ( $attempt = 1; ; $attempt++ ) {
			$row['slug'] = $this->uniqueSlug( $tenant, $stem );

			try {
				$id = (int) DB::table( 'nvoos_files' )->insertGetId( $row );

				return array( $id, (string) $row['slug'] );
			} catch ( \Illuminate\Database\QueryException $exception ) {
				// The index is the authority, not the guess. Three losses in a row
				// means this is not a race, so surface the driver's error instead of
				// spinning.
				if ( $attempt >= 3 || ! $this->isUniqueViolation( $exception ) ) {
					throw $exception;
				}
			}
		}
	}

	/**
	 * Did this insert lose to the unique index?
	 */
	private function isUniqueViolation( \Illuminate\Database\QueryException $exception ): bool {
		$message = \strtolower( $exception->getMessage() );

		return '23000' === (string) $exception->getCode()
			|| \str_contains( $message, 'unique' )
			|| \str_contains( $message, 'duplicate entry' );
	}

	/**
	 * A filesystem-safe estate segment.
	 */
	private function tenantSlug( ?string $tenantId ): string {
		$tenant = \strtolower( \trim( (string) $tenantId ) );
		$tenant = (string) \preg_replace( '/[^a-z0-9_-]+/', '-', $tenant );
		$tenant = \trim( $tenant, '-_' );

		return '' !== $tenant ? \substr( $tenant, 0, 64 ) : 'default';
	}

	/**
	 * The display name — the library's title.
	 *
	 * Sanitised the way `sanitize_file_name()` does *except* for case: WordPress
	 * keeps the title's case and lowercases only the slug, and §162.8 splits the
	 * two the same way. The extension is forced to the sniffed one, so a stored
	 * title cannot claim a type the bytes do not have.
	 */
	private function displayName( string $filename, string $extension ): string {
		$base = \pathinfo( \basename( $filename ), PATHINFO_FILENAME );
		$base = (string) \preg_replace( '/[^A-Za-z0-9._-]+/', '-', $base );
		$base = (string) \preg_replace( '/-{2,}/', '-', $base );
		$base = \trim( $base, '-.' );
		$base = \substr( '' !== $base ? $base : 'file', 0, 120 );

		return \rtrim( $base, '-.' ).'.'.$extension;
	}

	/**
	 * The slug stem: lowercase, hyphenated, no extension.
	 *
	 * `wp_unique_post_slug()` is the reference for everything after this — the
	 * stem is the input, uniqueness is applied by `uniqueSlug()`.
	 */
	private function slugStem( string $filename ): string {
		$stem = \pathinfo( \basename( $filename ), PATHINFO_FILENAME );
		$stem = \strtolower( $stem );
		$stem = (string) \preg_replace( '/[^a-z0-9]+/', '-', $stem );
		$stem = \trim( $stem, '-' );

		return \substr( '' !== $stem ? $stem : 'file', 0, 120 );
	}

	/**
	 * The first free slug for this estate — `pixel`, `pixel-2`, `pixel-3` …
	 *
	 * WordPress starts its suffix at 2, and so does this. The search is bounded:
	 * after 99 collisions a short random tail is appended rather than walking an
	 * unbounded loop on a hostile name.
	 */
	private function uniqueSlug( string $tenant, string $stem ): string {
		if ( ! $this->slugTaken( $tenant, $stem ) ) {
			return $stem;
		}

		for ( $suffix = 2; $suffix <= 99; $suffix++ ) {
			$candidate = $stem.'-'.$suffix;

			if ( ! $this->slugTaken( $tenant, $candidate ) ) {
				return $candidate;
			}
		}

		return $stem.'-'.\substr( \strtolower( (string) Str::ulid() ), -6 );
	}

	/**
	 * Is this slug already used in this estate?
	 *
	 * Legacy rows carry a null slug and therefore never collide — they have no
	 * slug to route on, and pretending otherwise would refuse new uploads because
	 * of rows that predate the column.
	 */
	private function slugTaken( string $tenant, string $slug ): bool {
		if ( ! Schema::hasTable( 'nvoos_files' ) || ! Schema::hasColumn( 'nvoos_files', 'slug' ) ) {
			return false;
		}

		return DB::table( 'nvoos_files' )
			->where( 'tenant_id', $tenant )
			->where( 'slug', $slug )
			->exists();
	}

	/**
	 * Sniffed MIME type, or null when the bytes are unreadable.
	 */
	private function detectMime( string $localPath ): ?string {
		if ( ! \function_exists( 'finfo_open' ) ) {
			return null;
		}

		$finfo = \finfo_open( FILEINFO_MIME_TYPE );

		if ( false === $finfo ) {
			return null;
		}

		try {
			$mime = \finfo_file( $finfo, $localPath );
		} finally {
			\finfo_close( $finfo );
		}

		return \is_string( $mime ) && '' !== $mime ? \strtolower( $mime ) : null;
	}

	/**
	 * The absolute path, when the disk is local. Empty for cloud disks.
	 */
	private function localPathOf( mixed $storage, string $path ): string {
		try {
			return (string) $storage->path( $path );
		} catch ( Throwable ) {
			return '';
		}
	}

	/**
	 * One row, or null.
	 */
	private function row( int $fileId ): ?object {
		if ( ! Schema::hasTable( 'nvoos_files' ) ) {
			return null;
		}

		return DB::table( 'nvoos_files' )->where( 'id', $fileId )->first();
	}

	/**
	 * Does a row satisfy every criterion?
	 *
	 * A criterion matches the `metadata` JSON first, then a column of the same
	 * name. String comparisons are case-insensitive "contains"; everything else
	 * is strict.
	 *
	 * @param array<string, mixed> $criteria
	 */
	private function rowMatches( object $row, array $criteria ): bool {
		$metadata = $this->decodeMetadata( $row->metadata ?? null );

		foreach ( $criteria as $key => $expected ) {
			$actual = $metadata[ $key ] ?? ( $row->{$key} ?? null );

			if ( \is_string( $expected ) ) {
				if ( ! \is_scalar( $actual ) || false === \stripos( (string) $actual, $expected ) ) {
					return false;
				}

				continue;
			}

			if ( $actual !== $expected ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeMetadata( mixed $raw ): array {
		if ( ! \is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = \json_decode( $raw, true );

		return \is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Format bytes to a human-readable string.
	 */
	private function formatBytes( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$index = 0;

		while ( $bytes >= 1024 && $index < \count( $units ) - 1 ) {
			$bytes /= 1024;
			$index++;
		}

		return \round( $bytes, 1 ).' '.$units[ $index ];
	}
}
