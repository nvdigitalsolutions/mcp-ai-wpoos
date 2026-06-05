<?php
declare(strict_types=1);

namespace NvoosGraphify\Remote;

/**
 * Remote enrichment pipeline stub.
 *
 * Enriches the knowledge graph with nodes and edges from
 * configured remote sources. Full implementation in the
 * `nvoos-graphify-remote` addon.
 *
 * @since 1.0.0
 */
class Enricher {

	/**
	 * Enrich all enabled remote sources.
	 *
	 * @param bool $async Whether to run asynchronously.
	 * @return array{success: bool, nodes: int, edges: int, message: string}
	 */
	public function enrichAll( bool $async = false ): array {
		return array(
			'success' => true,
			'nodes'   => 0,
			'edges'   => 0,
			'message' => 'Remote enrichment not available. Install the nvoos-graphify-remote addon.',
		);
	}

	/**
	 * Sync a single remote source by slug.
	 *
	 * @param string $slug  Source slug.
	 * @param bool   $async Whether to run asynchronously.
	 * @return array{success: bool, nodes: int, edges: int}
	 */
	public function syncSource( string $slug, bool $async = false ): array {
		return array(
			'success' => true,
			'nodes'   => 0,
			'edges'   => 0,
		);
	}
}
