<?php
/**
 * Laravel adapter: a JSON-file profession overlay, tenant-scoped.
 *
 * WHY A FILE STORE EXISTS AT ALL
 *
 * The readiness manifest is shared between runtimes, so its `save_profession`
 * row cannot be `ready` unless *every* host that uses the manifest can build
 * `SaveProfessionTool` — and that tool needs `ProfessionWriteInterface`. A
 * library host has no database, so the alternative to this class was a
 * readiness model that tolerates "servable, but only on hosts that bind X".
 * That erodes the one gate that makes a hollow tool impossible to ship, so the
 * package carries a real store instead: durable, tenant-scoped, and honest
 * about what it is.
 *
 * The app does not use this class. It rebinds `ProfessionOverlayInterface` to
 * `App\Support\Professions\ProfessionOverlayStore`, which keeps the rows in
 * `nvoos_professions` for concurrency, queryability and the same tenancy rule
 * every other estate-owned model follows. This store is the MIT default for a
 * host that has none.
 *
 * STORAGE SHAPE
 *
 * One document per estate at `{overlay_path}/{tenant}.json`:
 *
 *     { "professions": { "lawyer": { …record… } } }
 *
 * The `professions` envelope is the same one the base catalogue documents use,
 * so a file can be copied into a catalogue directory (or out of one) without a
 * translation step. Records are stored in the normalised shape the base
 * repository returns, not the raw WordPress shape, because every reader of a
 * record already speaks that shape.
 *
 * The path is a real directory rather than a Laravel disk on purpose: this store
 * is the fallback for a host whose filesystem is *unknown*. Local Flysystem —
 * and therefore `Storage::fake()` — reaches for `fileinfo` when it constructs
 * its mime-type detector, and a minimal PHP build may not have that extension.
 * A store that cannot be constructed is a skip at boot, which is exactly the
 * failure a `ready` manifest row must not have. A plain document write needs
 * neither a disk configuration nor an extension.
 *
 * COPY-ON-WRITE
 *
 * A save for a slug the overlay does not hold yet is seeded from the base
 * record before the caller's fields are applied, so omitting `description`
 * keeps the base description rather than blanking it. See
 * {@see OverlayProfessionRepository} for the consequence: the estate takes
 * ownership of that row at the first edit.
 *
 * A NOTE ON THE VERSION
 *
 * `overlayVersion()` hashes the file's bytes. That is deliberate: the merge
 * decorator appends it to the base version to form the cache key, so any edit
 * must change it, and a content hash cannot miss an edit the way a counter in
 * a second file could. The memo is per instance; the binding is transient, so a
 * long-lived Octane worker does not pin a pre-write version.
 *
 * @package Nvoos\Laravel
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Profession;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Nvoos\Core\Domain\Contract\ProfessionOverlayInterface;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
use Nvoos\Core\Domain\Contract\ProfessionWriteInterface;
use Nvoos\Laravel\Adapter\ProfessionRepository;
use RuntimeException;
use Throwable;

final class JsonProfessionOverlay implements ProfessionOverlayInterface, ProfessionWriteInterface {

	/**
	 * The estate used when the host supplies no resolver.
	 *
	 * Matches the base tenants this runtime falls back to (`Assistant::
	 * DEFAULT_TENANT` in the app); a single-tenant host therefore writes to the
	 * same estate it reads.
	 */
	private const DEFAULT_TENANT = 'default';

	/**
	 * Raw document, memoised for this instance.
	 */
	private ?string $raw = null;

	/**
	 * Decoded records, memoised for this instance.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $records = null;

	/**
	 * @param ProfessionRepositoryInterface $base   Base catalogue, read for copy-on-write seeding.
	 * @param Repository                    $config Host configuration (`oos.professions.*`).
	 * @param Closure():string|null         $tenant Reads the current estate at call time.
	 */
	public function __construct(
		private readonly ProfessionRepositoryInterface $base,
		private readonly Repository $config,
		private readonly ?Closure $tenant = null,
	) {
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function overlays(): array {
		if ( null !== $this->records ) {
			return $this->records;
		}

		$decoded = json_decode( $this->document(), true );
		$rows    = is_array( $decoded ) && isset( $decoded['professions'] ) && is_array( $decoded['professions'] )
			? $decoded['professions']
			: array();

		$this->records = array();

		foreach ( $rows as $slug => $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$key = ProfessionRepository::key( (string) $slug );

			if ( '' === $key ) {
				continue;
			}

			$this->records[ $key ] = $this->normalise( $key, $record );
		}

		return $this->records;
	}

	/**
	 * {@inheritdoc}
	 */
	public function overlayVersion(): string {
		$raw = $this->document();

		return '' === $raw
			? 'ov-empty'
			: 'ov-' . substr( hash( 'sha256', $raw ), 0, 16 );
	}

	/**
	 * {@inheritdoc}
	 *
	 * The field rules mirror `SaveProfessionTool`: only keys the caller sent are
	 * written, so an update that mentions `title` alone leaves the rest of the
	 * record intact. An explicit empty string is a deliberate clear, which is the
	 * same affordance the WordPress CPT gives.
	 *
	 * @param array<string, mixed> $data Profession fields.
	 *
	 * @return array{saved: bool, action?: string, profession?: array<string, mixed>, error?: string}
	 */
	public function save( array $data ): array {
		$slug  = ProfessionRepository::key( (string) ( $data['slug'] ?? '' ) );
		$title = trim( (string) ( $data['title'] ?? '' ) );

		if ( '' === $slug || '' === $title ) {
			return array(
				'saved' => false,
				'error' => 'slug and title are required.',
			);
		}

		$records = $this->overlays();
		$action  = isset( $records[ $slug ] ) ? 'updated' : 'created';
		$record  = $records[ $slug ] ?? $this->seed( $slug );

		$record['slug'] = $slug;
		$record['name'] = $title;

		foreach ( array( 'description', 'role_description', 'knowledge_base' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$record[ $key ] = (string) $data[ $key ];
			}
		}

		if ( array_key_exists( 'category', $data ) ) {
			$category           = ProfessionRepository::key( (string) $data['category'] );
			$record['category'] = '' === $category ? 'other' : $category;
		}

		foreach ( array( 'expertise', 'warnings', 'default_tools' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$record[ $key ] = ProfessionRepository::stringList( $data[ $key ] );
			}
		}

		$record           = $this->normalise( $slug, $record );
		$records[ $slug ] = $record;

		try {
			$this->write( $records );
		} catch ( Throwable $exception ) {
			return array(
				'saved' => false,
				'error' => 'The profession overlay could not be written: ' . $exception->getMessage(),
			);
		}

		// The write is the one event that invalidates the memo. Dropping both
		// fields makes the next read re-read the document, so the caller sees
		// its own write and the cache key has already changed.
		$this->raw     = null;
		$this->records = null;

		return array(
			'saved'      => true,
			'action'     => $action,
			'profession' => $record,
		);
	}

	/**
	 * Persist the record set, write-then-rename so a reader never sees half a
	 * document.
	 *
	 * @param array<string, array<string, mixed>> $records Records to write.
	 *
	 * @throws RuntimeException When the directory or the document cannot be written.
	 */
	private function write( array $records ): void {
		$path = $this->path();
		$dir  = \dirname( $path );

		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0775, true ) && ! is_dir( $dir ) ) {
			throw new RuntimeException( 'Could not create ' . $dir );
		}

		$json = (string) json_encode(
			array( 'professions' => $records ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		$temp = @tempnam( $dir, 'professions' );

		if ( false === $temp ) {
			throw new RuntimeException( 'Could not create a temporary file in ' . $dir );
		}

		if ( false === @file_put_contents( $temp, $json ) ) {
			@unlink( $temp );

			throw new RuntimeException( 'Could not write ' . $temp );
		}

		if ( ! @rename( $temp, $path ) ) {
			@unlink( $temp );

			throw new RuntimeException( 'Could not replace ' . $path );
		}
	}

	/**
	 * A blank record, seeded from the base tier when it holds the slug.
	 *
	 * @return array<string, mixed>
	 */
	private function seed( string $slug ): array {
		$found = $this->base->getBySlug( $slug );

		if ( ! empty( $found['found'] ) && is_array( $found['profession'] ?? null ) ) {
			return $this->normalise( $slug, $found['profession'] );
		}

		return $this->blank( $slug );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function blank( string $slug ): array {
		return array(
			'id'               => 0,
			'slug'             => $slug,
			'name'             => $slug,
			'description'      => '',
			'category'         => 'other',
			'role_description' => '',
			'expertise'        => array(),
			'warnings'         => array(),
			'knowledge_base'   => '',
			'default_tools'    => array(),
		);
	}

	/**
	 * Coerce a record into the shape every reader of the catalogue expects.
	 *
	 * The base adapter does the same coercion when it loads a JSON document, so
	 * a hand-edited overlay file is held to the same standard as a catalogue
	 * file rather than reaching a page with the wrong types.
	 *
	 * @param array<string, mixed> $record Candidate record.
	 *
	 * @return array<string, mixed>
	 */
	private function normalise( string $slug, array $record ): array {
		$category = ProfessionRepository::key( (string) ( $record['category'] ?? 'other' ) );

		return array(
			'id'               => (int) ( $record['id'] ?? 0 ),
			'slug'             => $slug,
			'name'             => (string) ( $record['name'] ?? ( $record['title'] ?? $slug ) ),
			'description'      => (string) ( $record['description'] ?? '' ),
			'category'         => '' === $category ? 'other' : $category,
			'role_description' => (string) ( $record['role_description'] ?? '' ),
			'expertise'        => ProfessionRepository::stringList( $record['expertise'] ?? array() ),
			'warnings'         => ProfessionRepository::stringList( $record['warnings'] ?? array() ),
			'knowledge_base'   => (string) ( $record['knowledge_base'] ?? '' ),
			'default_tools'    => ProfessionRepository::stringList( $record['default_tools'] ?? array() ),
		);
	}

	/**
	 * The estate this store speaks for, read at call time.
	 */
	private function tenantId(): string {
		$tenant = null === $this->tenant ? self::DEFAULT_TENANT : (string) ( $this->tenant )();

		return '' === $tenant ? self::DEFAULT_TENANT : $tenant;
	}

	/**
	 * Document path for this estate.
	 *
	 * Defaults under `storage/`, which every Laravel host has and no deployment
	 * serves publicly. A host that wants the overlay backed up or committed
	 * points the config at its own directory.
	 */
	private function path(): string {
		$configured = (string) $this->config->get( 'oos.professions.overlay_path', '' );
		$base       = '' === $configured ? storage_path( 'app/nvoos/professions' ) : $configured;

		return rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR . $this->tenantId() . '.json';
	}

	/**
	 * Raw document, or an empty string when the estate has written nothing.
	 *
	 * A read failure is treated as "no overlay" rather than an exception: the
	 * base catalogue is still serviceable, and a broken optional tier must not
	 * take the read path down with it.
	 */
	private function document(): string {
		if ( null !== $this->raw ) {
			return $this->raw;
		}

		$path = $this->path();

		$this->raw = is_file( $path ) && is_readable( $path )
			? (string) @file_get_contents( $path )
			: '';

		return $this->raw;
	}
}
