<?php
/**
 * Embeddings On-Ingest — auto-generates embeddings for new graph nodes.
 *
 * Replaces the core stub with a full implementation. Subscribes to
 * nvoos_graphify/after_build and processes newly-imported nodes
 * via a background cron queue. Each node's text content is embedded
 * and stored in the nvoos_graphify_embeddings table.
 *
 * @package NvoosGraphifyAi
 * @since   1.0.0
 */

declare(strict_types=1);

namespace NvoosGraphifyAi\Embeddings;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use NvoosGraphify\Graph\Db;
use NvoosGraphify\Settings;

class EmbeddingsOnIngest {

	public const CRON_BATCH = 'nvoos_graphify_ai/embed_batch';

	/**
	 * Max nodes per cron batch to avoid API rate limits.
	 */
	private const BATCH_SIZE = 20;

	public function __construct(
		private readonly EmbeddingService $embeddings,
		private readonly SettingsStoreInterface $settings,
		private readonly ErrorFactoryInterface $errors,
	) {}

	/**
	 * Register WordPress hooks and cron handlers.
	 */
	public function register(): void {
		add_action( 'nvoos_graphify/after_build', array( $this, 'onAfterBuild' ) );
		add_action( self::CRON_BATCH, array( $this, 'processBatch' ) );
	}

	/**
	 * Handle the after-build event: queue embedding generation.
	 *
	 * @return void
	 */
	public function onAfterBuild(): void {
		$graphifySettings = Settings::all();
		if ( empty( $graphifySettings['embeddings_enabled'] ) ) {
			return;
		}

		// Schedule a batch if not already scheduled.
		if ( ! \wp_next_scheduled( self::CRON_BATCH ) ) {
			\wp_schedule_single_event( \time() + 30, self::CRON_BATCH );
		}
	}

	/**
	 * Process a batch of unembedded nodes via cron.
	 *
	 * Reads nodes that have no embedding yet, generates vectors,
	 * and stores them. Self-reschedules if more nodes remain.
	 *
	 * @return void
	 */
	public function processBatch(): void {
		$graphifySettings = Settings::all();

		$model    = $graphifySettings['embeddings_model'] ?? 'text-embedding-3-small';
		$provider = $graphifySettings['ai_default_provider'] ?? 'openai';

		// Find nodes without embeddings.
		$unembedded = $this->getUnembeddedNodes( self::BATCH_SIZE );

		if ( array() === $unembedded ) {
			return; // All done.
		}

		// Gather text content for each node.
		$texts = array();
		$nodes = array();
		foreach ( $unembedded as $node ) {
			$text = $node->label;
			// Include properties if available (more context = better embedding).
			if ( ! empty( $node->properties ) ) {
				$props = \json_decode( $node->properties, true );
				if ( is_array( $props ) ) {
					$excerpt = $props['excerpt'] ?? $props['description'] ?? '';
					if ( '' !== $excerpt ) {
						$text = $node->label . '. ' . $excerpt;
					}
				}
			}
			$texts[] = $text;
			$nodes[] = $node;
		}

		// Generate embeddings in batch.
		$vectors = $this->embeddings->embedBatch( $texts, $provider, $model );

		if ( $this->errors->isError( $vectors ) ) {
			// Reschedule with backoff.
			\wp_schedule_single_event( \time() + 300, self::CRON_BATCH );
			return;
		}

		// Store each vector.
		foreach ( $nodes as $i => $node ) {
			if ( ! isset( $vectors[ $i ] ) ) {
				continue;
			}

			Db::upsertEmbedding(
				array(
					'node_id' => $node->node_id,
					'model'   => $model,
					'dim'     => $vectors[ $i ]['dim'],
					'vector'  => \wp_json_encode( $vectors[ $i ]['vector'] ),
				)
			);
		}

		// Reschedule if there may be more.
		$remaining = $this->getUnembeddedCount();
		if ( $remaining > 0 ) {
			\wp_schedule_single_event( \time() + 10, self::CRON_BATCH );
		}
	}

	/**
	 * Get nodes that have no embedding for the given model.
	 *
	 * @param int $limit Max nodes to return.
	 *
	 * @return array<int, object>  Node rows from the DB.
	 */
	private function getUnembeddedNodes( int $limit ): array {
		global $wpdb;

		$nodesTable      = Db::nodesTable();
		$embeddingsTable = Db::embeddingsTable();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.* FROM {$nodesTable} n
				LEFT JOIN {$embeddingsTable} e ON n.node_id = e.node_id
				WHERE e.id IS NULL
				ORDER BY n.id ASC
				LIMIT %d",
				$limit,
			),
		);
		// phpcs:enable

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Count nodes without embeddings.
	 */
	private function getUnembeddedCount(): int {
		global $wpdb;

		$nodesTable      = Db::nodesTable();
		$embeddingsTable = Db::embeddingsTable();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$nodesTable} n
			LEFT JOIN {$embeddingsTable} e ON n.node_id = e.node_id
			WHERE e.id IS NULL",
		);
		// phpcs:enable

		return (int) $count;
	}
}
