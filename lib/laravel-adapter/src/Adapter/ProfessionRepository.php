<?php
/**
 * Laravel adapter: ProfessionRepositoryInterface implementation.
 *
 * Professions are reference data: code-owned, versioned and read-only. The
 * catalogue is a directory of JSON documents with the same shape the WordPress
 * plugin seeds its profession CPT from (`professions: [...]`), so a deployment
 * can point `oos.professions.path` at any licensed catalogue.
 *
 * Return shapes mirror WP_MCP_AI_Profession_Service:
 *  - getAll() / getByCategory()  slug => title
 *  - getBySlug()                 ['found' => bool, 'profession' => array]
 *  - getRecommendedTools()       string[]
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Contracts\Config\Repository;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;

class ProfessionRepository implements ProfessionRepositoryInterface {

	/**
	 * Catalogue directory.
	 */
	private string $path;

	/**
	 * Slug => normalised profession record.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $catalogue = null;

	/**
	 * Resolved catalogue version, memoised for the instance.
	 */
	private ?string $version = null;

	public function __construct( Repository $config ) {
		$this->path = (string) $config->get(
			'oos.professions.path',
			\dirname( __DIR__, 2 ) . '/resources/professions'
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, string> Slug => title, ordered by title.
	 */
	public function getAll(): array {
		return $this->titles( $this->catalogue() );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array{found: bool, profession?: array<string, mixed>}
	 */
	public function getBySlug( string $slug ): array {
		$key = $this->key( $slug );

		if ( '' === $key || ! isset( $this->catalogue()[ $key ] ) ) {
			return array( 'found' => false );
		}

		return array(
			'found'      => true,
			'profession' => $this->catalogue()[ $key ],
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, string> Slug => title, ordered by title.
	 */
	public function getByCategory( string $category ): array {
		$needle = $this->key( $category );
		$match  = array();

		foreach ( $this->catalogue() as $slug => $profession ) {
			if ( $needle === $profession['category'] ) {
				$match[ $slug ] = $profession;
			}
		}

		return $this->titles( $match );
	}

	/**
	 * {@inheritdoc}
	 *
	 * The WordPress adapter also returns empty arrays here: the legacy
	 * knowledge-base loader exposes no per-profession entry point. The inline
	 * knowledge markdown is available from getBySlug()['profession']
	 * ['knowledge_base'].
	 *
	 * @return array{knowledge: array, playbook: array}
	 */
	public function loadKnowledgeBase( string $slug ): array {
		unset( $slug );

		return array(
			'knowledge' => array(),
			'playbook'  => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string[]
	 */
	public function getRecommendedTools( string $slug ): array {
		$key = $this->key( $slug );

		if ( '' === $key || ! isset( $this->catalogue()[ $key ] ) ) {
			return array();
		}

		return $this->catalogue()[ $key ]['default_tools'];
	}

	/**
	 * {@inheritdoc}
	 *
	 * Grouped category => profession records, ordered by category slug. The
	 * WordPress adapter returns [] because the legacy service has no
	 * get_categories() method; this runtime implements the contract.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function getCategories(): array {
		$categories = array();

		foreach ( $this->catalogue() as $slug => $profession ) {
			$categories[ $profession['category'] ][ $slug ] = $profession;
		}

		ksort( $categories );

		return $categories;
	}

	/**
	 * {@inheritdoc}
	 *
	 * The manifest's `catalogue_version` is the contract: deployments that edit
	 * a catalogue must bump it. A catalogue without a manifest falls back to a
	 * content fingerprint, so an edit is still visible without a purge — it just
	 * costs a directory stat per request instead of being operator-controlled.
	 */
	public function catalogueVersion(): string {
		if ( null !== $this->version ) {
			return $this->version;
		}

		$file = $this->path . '/manifest.json';

		if ( is_readable( $file ) ) {
			$raw = @file_get_contents( $file );
			$doc = false === $raw ? null : json_decode( $raw, true );

			if ( is_array( $doc ) && isset( $doc['catalogue_version'] ) && is_scalar( $doc['catalogue_version'] ) ) {
				return $this->version = (string) $doc['catalogue_version'];
			}
		}

		return $this->version = $this->fingerprint();
	}

	/**
	 * Content fingerprint for a catalogue without a manifest.
	 *
	 * Filename, size and mtime per document: cheap (a stat per file, no reads)
	 * and changes whenever a document is added, removed or rewritten.
	 */
	private function fingerprint(): string {
		$files = glob( $this->path . '/*.json' );
		$files = false === $files ? array() : $files;

		sort( $files );

		$parts = array();

		foreach ( $files as $file ) {
			$parts[] = basename( $file )
				. ':' . (string) @filesize( $file )
				. ':' . (string) @filemtime( $file );
		}

		return 'fp-' . substr(
			hash( 'sha256', $this->path . '|' . implode( '|', $parts ) ),
			0,
			16
		);
	}

	/**
	 * Load every JSON document in the catalogue directory.
	 *
	 * A document without a `professions` array (e.g. manifest.json) is ignored,
	 * so deployment catalogues that use a different grouping still load.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function catalogue(): array {
		if ( null !== $this->catalogue ) {
			return $this->catalogue;
		}

		$this->catalogue = array();

		$files = glob( $this->path . '/*.json' );

		if ( false === $files ) {
			return $this->catalogue;
		}

		sort( $files );

		foreach ( $files as $file ) {
			$raw = @file_get_contents( $file );

			if ( false === $raw ) {
				continue;
			}

			$doc = json_decode( $raw, true );

			if ( ! is_array( $doc ) || ! isset( $doc['professions'] ) || ! is_array( $doc['professions'] ) ) {
				continue;
			}

			foreach ( $doc['professions'] as $profession ) {
				if ( ! is_array( $profession ) || empty( $profession['slug'] ) ) {
					continue;
				}

				$slug = $this->key( (string) $profession['slug'] );

				if ( '' === $slug ) {
					continue;
				}

				$title = (string) ( $profession['title'] ?? $slug );

				$this->catalogue[ $slug ] = array(
					'id'               => 0,
					'slug'             => $slug,
					'name'             => $title,
					'description'      => (string) ( $profession['description'] ?? '' ),
					'category'         => $this->key( (string) ( $profession['category'] ?? 'other' ) ),
					'role_description' => (string) ( $profession['role_description'] ?? '' ),
					'expertise'        => $this->stringList( $profession['expertise'] ?? array() ),
					'warnings'         => $this->stringList( $profession['warnings'] ?? array() ),
					'knowledge_base'   => (string) ( $profession['knowledge_base'] ?? '' ),
					'default_tools'    => $this->stringList( $profession['default_tools'] ?? array() ),
				);
			}
		}

		return $this->catalogue;
	}

	/**
	 * Map a catalogue slice to slug => title, ordered by title.
	 *
	 * @param array<string, array<string, mixed>> $professions Profession records.
	 * @return array<string, string>
	 */
	private function titles( array $professions ): array {
		uasort(
			$professions,
			static fn ( array $a, array $b ): int => strcasecmp( (string) $a['name'], (string) $b['name'] )
		);

		$titles = array();

		foreach ( $professions as $slug => $profession ) {
			$titles[ $slug ] = (string) $profession['name'];
		}

		return $titles;
	}

	/**
	 * Normalise a slug the way WordPress's sanitize_key() does.
	 */
	private function key( string $value ): string {
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $value ) );
	}

	/**
	 * @param mixed $value Candidate list.
	 * @return string[]
	 */
	private function stringList( mixed $value ): array {
		return array_values(
			array_filter(
				(array) $value,
				static fn ( mixed $item ): bool => is_string( $item ) && '' !== $item
			)
		);
	}
}
