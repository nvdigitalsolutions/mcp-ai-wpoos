<?php
declare(strict_types=1);

namespace NvoosContentGraph\Memory;

/**
 * Embeddings-on-ingest stub.
 *
 * Automatically generates vector embeddings for newly-imported
 * nodes. Full implementation deferred to the `nvoos-content-graph-ai`
 * addon which provides the embedding generation backend.
 *
 * @since 1.0.0
 */
class EmbeddingsOnIngest {

	/** @var string Cron action for embedding generation. */
	public const CRON_ACTION = 'nvoos_content_graph/embed_node';

	/**
	 * Register the cron handler.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( self::CRON_ACTION, array( __CLASS__, 'processNode' ), 10, 1 );
		}
	}

	/**
	 * Process a single node for embedding generation.
	 *
	 * @param array<string,mixed> $node Node data.
	 * @return void
	 */
	public static function processNode( array $node ): void {
		// Stub — full implementation in the nvoos-content-graph-ai addon.
	}
}
