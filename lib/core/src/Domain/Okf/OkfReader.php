<?php
/**
 * Reads an OKF bundle through a BundleRootInterface.
 *
 * Behaviour is pinned by the shared reader corpus in `tests/fixtures/okf/reader/`,
 * which this class and the WordPress implementation both replay. Link
 * resolution follows OKF v0.2 §6.1: a leading `/` is bundle-absolute, anything
 * else resolves against the concept's own directory, and external URIs are not
 * part of the graph.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Okf;

use Nvoos\Core\Domain\Contract\BundleRootInterface;

final class OkfReader implements OkfReaderInterface {

	/**
	 * Filenames reserved by the format; never treated as concepts.
	 */
	private const RESERVED_FILENAMES = array( 'index.md', 'log.md' );

	/**
	 * Parsed concepts, keyed by concept ID.
	 *
	 * @var array<string, array{frontmatter: array<string, mixed>, body: string}>
	 */
	private array $cache = array();

	private readonly OkfParserInterface $parser;

	/**
	 * @param BundleRootInterface  $root   Storage the bundle lives in.
	 * @param OkfParserInterface|null $parser Frontmatter parser; defaults to the core one.
	 */
	public function __construct(
		private readonly BundleRootInterface $root,
		?OkfParserInterface $parser = null,
	) {
		$this->parser = $parser ?? new OkfParser();
	}

	/**
	 * {@inheritDoc}
	 */
	public function bundleRoot(): BundleRootInterface {
		return $this->root;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getConcept( string $conceptId ): array {
		$conceptId = $this->normalizeConceptId( $conceptId );

		if ( isset( $this->cache[ $conceptId ] ) ) {
			return array_merge( array( 'concept_id' => $conceptId ), $this->cache[ $conceptId ] );
		}

		$path = $this->resolveFilePath( $conceptId );
		$raw  = $this->readFile( $path );

		try {
			$parsed = $this->parser->parse( $raw );
		} catch ( OkfParseException $exception ) {
			throw new OkfReadException( $exception->errorCode, $exception->getMessage(), $exception );
		}

		if ( null === $parsed ) {
			throw new OkfReadException(
				'okf_no_frontmatter',
				sprintf( 'Concept "%s" has no YAML frontmatter block.', $conceptId )
			);
		}

		$this->cache[ $conceptId ] = array(
			'frontmatter' => $parsed->frontmatter,
			'body'        => $parsed->body,
		);

		return array(
			'concept_id'  => $conceptId,
			'frontmatter' => $parsed->frontmatter,
			'body'        => $parsed->body,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function browse( string $path = '' ): array {
		$dirPath = $this->root->root();

		if ( '' !== $path ) {
			$dirPath .= '/' . ltrim( $path, '/' );
		}

		if ( ! is_dir( $dirPath ) ) {
			throw new OkfReadException( 'okf_not_found', sprintf( 'OKF directory not found: %s', $path ) );
		}

		$indexPath = $dirPath . '/index.md';

		if ( file_exists( $indexPath ) ) {
			return $this->parseIndexContent( $this->readFile( $indexPath ) );
		}

		return $this->scanDirectory( $dirPath, $path );
	}

	/**
	 * {@inheritDoc}
	 */
	public function traverse( string $conceptId, int $maxDepth = 2 ): array {
		$visited = array();
		$depth   = max( 1, min( 5, abs( $maxDepth ) ) );

		return $this->traverseRecursive( $conceptId, $depth, $visited ) ?? array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function getTrustTier( array $frontmatter ): string {
		if ( ! isset( $frontmatter['verified'] ) || ! is_array( $frontmatter['verified'] ) ) {
			return 'unverified';
		}

		foreach ( $frontmatter['verified'] as $verification ) {
			if ( ! is_array( $verification ) ) {
				continue;
			}

			$by = isset( $verification['by'] ) ? (string) $verification['by'] : '';

			if ( str_starts_with( $by, 'human:' ) ) {
				return 'human-reviewed';
			}
		}

		return 'machine-confirmed';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isStale( array $frontmatter ): bool {
		if ( empty( $frontmatter['stale_after'] ) ) {
			return false;
		}

		$deadline = strtotime( (string) $frontmatter['stale_after'] );

		return false !== $deadline && $deadline < time();
	}

	/**
	 * {@inheritDoc}
	 */
	public function search( array $criteria = array() ): array {
		$wantType     = isset( $criteria['type'] ) ? $this->sanitizeText( $criteria['type'] ) : null;
		$wantTag      = isset( $criteria['tag'] ) ? $this->sanitizeText( $criteria['tag'] ) : null;
		$wantStatus   = isset( $criteria['status'] ) ? $this->sanitizeText( $criteria['status'] ) : null;
		$wantTier     = isset( $criteria['trust_tier'] ) ? $this->sanitizeText( $criteria['trust_tier'] ) : null;
		$includeStale = isset( $criteria['include_stale'] ) ? (bool) $criteria['include_stale'] : true;

		$results = array();

		foreach ( $this->findAllConceptFiles( $this->root->root() ) as $filePath ) {
			$conceptId = $this->fileToConceptId( $filePath );

			try {
				$concept = $this->getConcept( $conceptId );
			} catch ( OkfReadException ) {
				continue;
			}

			$frontmatter = $concept['frontmatter'];

			if ( null !== $wantType ) {
				$conceptType = isset( $frontmatter['type'] ) ? $frontmatter['type'] : '';
				if ( strtolower( (string) $conceptType ) !== strtolower( (string) $wantType ) ) {
					continue;
				}
			}

			if ( null !== $wantTag ) {
				$tags = isset( $frontmatter['tags'] ) ? $frontmatter['tags'] : array();

				if ( ! is_array( $tags ) ) {
					$tags = array( $tags );
				}

				$found = false;

				foreach ( $tags as $tag ) {
					if ( strtolower( (string) $tag ) === strtolower( (string) $wantTag ) ) {
						$found = true;
						break;
					}
				}

				if ( ! $found ) {
					continue;
				}
			}

			if ( null !== $wantStatus ) {
				$conceptStatus = isset( $frontmatter['status'] ) ? $frontmatter['status'] : 'stable';
				if ( strtolower( (string) $conceptStatus ) !== strtolower( (string) $wantStatus ) ) {
					continue;
				}
			}

			if ( null !== $wantTier && $this->getTrustTier( $frontmatter ) !== $wantTier ) {
				continue;
			}

			if ( ! $includeStale && $this->isStale( $frontmatter ) ) {
				continue;
			}

			$results[] = array(
				'concept_id'  => $conceptId,
				'type'        => isset( $frontmatter['type'] ) ? $frontmatter['type'] : '',
				'title'       => isset( $frontmatter['title'] ) ? $frontmatter['title'] : '',
				'description' => isset( $frontmatter['description'] ) ? $frontmatter['description'] : '',
				'tags'        => isset( $frontmatter['tags'] ) ? $frontmatter['tags'] : array(),
				'status'      => isset( $frontmatter['status'] ) ? $frontmatter['status'] : 'stable',
				'trust_tier'  => $this->getTrustTier( $frontmatter ),
				'stale'       => $this->isStale( $frontmatter ),
			);
		}

		return $results;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getTypes(): array {
		$types = array();

		foreach ( $this->findAllConceptFiles( $this->root->root() ) as $filePath ) {
			try {
				$concept = $this->getConcept( $this->fileToConceptId( $filePath ) );
			} catch ( OkfReadException ) {
				continue;
			}

			if ( ! isset( $concept['frontmatter']['type'] ) ) {
				continue;
			}

			$type = $concept['frontmatter']['type'];

			if ( ! in_array( $type, $types, true ) ) {
				$types[] = $type;
			}
		}

		sort( $types );

		return $types;
	}

	/**
	 * {@inheritDoc}
	 */
	public function fileToConceptId( string $path ): string {
		$relative = str_replace(
			$this->normalizePath( $this->root->root() ) . '/',
			'',
			$this->normalizePath( $path )
		);

		if ( str_ends_with( $relative, '.md' ) ) {
			$relative = substr( $relative, 0, -3 );
		}

		return $relative;
	}

	/**
	 * {@inheritDoc}
	 */
	public function findBrokenLinks( string $conceptId ): array {
		try {
			$concept = $this->getConcept( $conceptId );
		} catch ( OkfReadException ) {
			return array();
		}

		$targets = array();

		if ( preg_match_all( '/\[([^\]]*)\]\(([^)]+\.md)(#[^)]*)?\)/', $concept['body'], $matches ) ) {
			$targets = $matches[2];
		}

		$broken = array();

		foreach ( $targets as $target ) {
			$resolved = $this->resolveLinkTarget( $conceptId, $target );

			if ( '' === $resolved ) {
				continue;
			}

			if ( ! $this->root->contains( $this->root->pathFor( $resolved ) ) ) {
				continue;
			}

			if ( ! file_exists( $this->root->pathFor( $resolved ) ) ) {
				$broken[] = array(
					'concept_id' => $conceptId,
					'target'     => $target,
					'resolved'   => $resolved,
				);
			}
		}

		return $broken;
	}

	/**
	 * {@inheritDoc}
	 */
	public function conceptLinks( string $body, string $conceptId ): array {
		$links = array();

		if ( preg_match_all( '/\[([^\]]*)\]\(([^)]+\.md)\)/', $body, $matches ) ) {
			foreach ( $matches[2] as $linkPath ) {
				$linkPath = (string) preg_replace( '/#.*$/', '', $linkPath );
				$resolved = $this->resolveLinkTarget( $conceptId, $linkPath );

				if ( '' === $resolved ) {
					continue;
				}

				$links[] = $resolved;
			}
		}

		return array_unique( $links );
	}

	/**
	 * {@inheritDoc}
	 */
	public function clearCache(): void {
		$this->cache = array();
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Walk the graph, depth-first, skipping cycles.
	 *
	 * @param string   $conceptId Concept to read.
	 * @param int      $depth     Remaining depth.
	 * @param string[] $visited   Concept IDs already expanded.
	 * @return array<string, mixed>|null Null when the node is skipped (cycle or depth exhausted).
	 */
	private function traverseRecursive( string $conceptId, int $depth, array &$visited ): ?array {
		if ( in_array( $conceptId, $visited, true ) || $depth < 0 ) {
			return null;
		}

		$visited[] = $conceptId;

		try {
			$concept = $this->getConcept( $conceptId );
		} catch ( OkfReadException $exception ) {
			return array(
				'concept_id' => $conceptId,
				'error'      => $exception->getMessage(),
				'links'      => array(),
			);
		}

		$concept['links'] = array();

		if ( $depth > 0 ) {
			foreach ( $this->conceptLinks( $concept['body'], $conceptId ) as $linkedId ) {
				$child = $this->traverseRecursive( $linkedId, $depth - 1, $visited );

				if ( null !== $child ) {
					$concept['links'][] = $child;
				}
			}
		}

		return $concept;
	}

	/**
	 * Strip the `.md` suffix, surrounding slashes and a leading `./`.
	 */
	private function normalizeConceptId( string $conceptId ): string {
		$conceptId = trim( $conceptId, '/' );

		if ( str_ends_with( $conceptId, '.md' ) ) {
			$conceptId = substr( $conceptId, 0, -3 );
		}

		while ( str_starts_with( $conceptId, './' ) ) {
			$conceptId = substr( $conceptId, 2 );
		}

		return $conceptId;
	}

	/**
	 * Resolve a concept ID to an absolute path, enforcing containment.
	 *
	 * @throws OkfReadException When the path escapes the bundle or the file is absent.
	 */
	private function resolveFilePath( string $conceptId ): string {
		$path = $this->root->pathFor( ltrim( $conceptId, '/' ) );

		if ( ! $this->root->contains( $path ) ) {
			throw new OkfReadException( 'okf_path_traversal', 'Concept path escapes the bundle root.' );
		}

		if ( ! file_exists( $path ) ) {
			throw new OkfReadException( 'okf_not_found', sprintf( 'Concept not found: %s', $conceptId ) );
		}

		return $path;
	}

	/**
	 * Read a file, mapping failures onto stable codes.
	 *
	 * @throws OkfReadException When the file is unreadable.
	 */
	private function readFile( string $path ): string {
		if ( ! is_readable( $path ) ) {
			throw new OkfReadException(
				'okf_unreadable',
				sprintf( 'OKF file is not readable: %s', basename( $path ) )
			);
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read; no remote URL involved.

		if ( false === $content ) {
			throw new OkfReadException(
				'okf_read_error',
				sprintf( 'Failed to read OKF file: %s', basename( $path ) )
			);
		}

		return $content;
	}

	/**
	 * Resolve a link target against the concept that contains it.
	 *
	 * @return string Resolved concept ID, or '' for an external target.
	 */
	private function resolveLinkTarget( string $conceptId, string $target ): string {
		$target = trim( $target );

		if ( str_starts_with( $target, '/' ) ) {
			return $this->normalizeConceptId( $target );
		}

		// External URI schemes are out of scope for bundle link resolution.
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $target ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $target ) ) {
			return '';
		}

		$base = dirname( $conceptId );

		if ( '.' === $base ) {
			$base = '';
		}

		$resolved = $this->normalizeConceptId( $base . '/' . $target );
		$resolved = (string) preg_replace( '#^(?:\.\./)+#', '', $resolved );

		return ltrim( $resolved, '/' );
	}

	/**
	 * Parse an `index.md` into browse entries.
	 *
	 * @return array<int, array{title: string, path: string, description: string}>
	 */
	private function parseIndexContent( string $content ): array {
		$entries = array();

		foreach ( explode( "\n", $content ) as $line ) {
			$line = trim( $line );

			if ( preg_match( '/^\*?\s*\[([^\]]+)\]\(([^)]+)\)\s*-?\s*(.*)$/', $line, $matches ) ) {
				$entries[] = array(
					'title'       => trim( $matches[1] ),
					'path'        => trim( $matches[2] ),
					'description' => trim( $matches[3] ),
				);
			}
		}

		return $entries;
	}

	/**
	 * Fallback listing when a directory has no index.
	 *
	 * @return array<int, array{title: string, path: string, description: string}>
	 */
	private function scanDirectory( string $dirPath, string $relPath ): array {
		$entries = array();
		$items   = scandir( $dirPath );

		if ( false === $items ) {
			return $entries;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$fullPath = $dirPath . '/' . $item;
			$relItem  = ( '' === $relPath ) ? $item : $relPath . '/' . $item;

			if ( is_dir( $fullPath ) ) {
				$entries[] = array(
					'title'       => basename( $item ),
					'path'        => $relItem . '/',
					'description' => '',
				);

				continue;
			}

			if ( ! str_ends_with( $item, '.md' ) || in_array( $item, self::RESERVED_FILENAMES, true ) ) {
				continue;
			}

			$title       = $item;
			$description = '';

			try {
				$concept = $this->getConcept( $this->fileToConceptId( $fullPath ) );
				$title   = isset( $concept['frontmatter']['title'] ) ? $concept['frontmatter']['title'] : $item;
				$description = isset( $concept['frontmatter']['description'] ) ? $concept['frontmatter']['description'] : '';
			} catch ( OkfReadException ) {
				// Unparseable concepts are still listed by filename.
			}

			$entries[] = array(
				'title'       => $title,
				'path'        => $relItem,
				'description' => $description,
			);
		}

		return $entries;
	}

	/**
	 * Every concept file under a directory, skipping reserved filenames.
	 *
	 * @return array<int, string>
	 */
	private function findAllConceptFiles( string $dir ): array {
		$files = array();

		if ( ! is_dir( $dir ) ) {
			return $files;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $fileInfo ) {
			if ( ! $fileInfo->isFile() ) {
				continue;
			}

			$filename = $fileInfo->getFilename();

			if ( ! str_ends_with( $filename, '.md' ) ) {
				continue;
			}

			if ( in_array( $filename, self::RESERVED_FILENAMES, true ) ) {
				continue;
			}

			$files[] = $this->normalizePath( $fileInfo->getPathname() );
		}

		return $files;
	}

	/**
	 * Normalise separators to forward slashes.
	 */
	private function normalizePath( string $path ): string {
		$path = str_replace( '\\', '/', $path );

		return (string) preg_replace( '|(?<=.)/+|', '/', $path );
	}

	/**
	 * Trim a search criterion the way `sanitize_text_field()` would.
	 *
	 * @param mixed $value Raw criterion.
	 */
	private function sanitizeText( mixed $value ): string {
		$value = strip_tags( (string) $value );
		$value = (string) preg_replace( '/[\r\n\t ]+/', ' ', $value );

		return trim( $value );
	}
}
