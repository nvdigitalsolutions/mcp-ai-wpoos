<?php
/**
 * Contract for reading an OKF bundle.
 *
 * Framework-neutral: bundles are addressed through a BundleRootInterface, and
 * failures surface as OkfReadException so callers branch on a stable code
 * rather than on a platform error type.
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

interface OkfReaderInterface {

	/**
	 * The bundle root this reader is bound to.
	 */
	public function bundleRoot(): BundleRootInterface;

	/**
	 * Read a single concept.
	 *
	 * @return array{concept_id: string, frontmatter: array<string, mixed>, body: string}
	 * @throws OkfReadException When the concept is missing, unreadable, unparseable, or has no frontmatter.
	 */
	public function getConcept( string $conceptId ): array;

	/**
	 * List a directory, preferring its `index.md`.
	 *
	 * @return array<int, array{title: string, path: string, description: string}>
	 * @throws OkfReadException When the directory does not exist.
	 */
	public function browse( string $path = '' ): array;

	/**
	 * Walk the link graph from a concept.
	 *
	 * @return array<string, mixed>
	 */
	public function traverse( string $conceptId, int $maxDepth = 2 ): array;

	/**
	 * Advisory trust tier derived from the `verified` entries.
	 *
	 * @param array<string, mixed> $frontmatter Parsed frontmatter.
	 * @return string `human-reviewed`, `machine-confirmed`, or `unverified`.
	 */
	public function getTrustTier( array $frontmatter ): string;

	/**
	 * Whether the concept is past its `stale_after` date.
	 *
	 * @param array<string, mixed> $frontmatter Parsed frontmatter.
	 */
	public function isStale( array $frontmatter ): bool;

	/**
	 * Search the bundle by type, tag, status, trust tier, and staleness.
	 *
	 * @param array<string, mixed> $criteria Search criteria.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( array $criteria = array() ): array;

	/**
	 * Unique `type` values in the bundle, sorted.
	 *
	 * @return array<int, string>
	 */
	public function getTypes(): array;

	/**
	 * Convert an absolute file path to a concept ID.
	 */
	public function fileToConceptId( string $path ): string;

	/**
	 * Report bundle-internal links that do not resolve.
	 *
	 * @return array<int, array{concept_id: string, target: string, resolved: string}>
	 */
	public function findBrokenLinks( string $conceptId ): array;

	/**
	 * Resolve the concept IDs a body links to.
	 *
	 * @return array<int, string>
	 */
	public function conceptLinks( string $body, string $conceptId ): array;

	/**
	 * Drop the per-instance concept cache.
	 */
	public function clearCache(): void;
}
