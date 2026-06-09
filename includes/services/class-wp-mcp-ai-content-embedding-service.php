<?php
/**
 * Content Embedding Service — Auto-embed WordPress content.
 *
 * Automatically generates and stores embedding vectors for WordPress content
 * (posts, pages, products, any CPT) when published or updated. Integrates
 * with {@see WP_MCP_AI_Content_Embedding_Store} and
 * {@see WP_MCP_AI_Vector_Context_Service}.
 *
 * @package WP_MCP_AI
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-embedding service for WordPress content.
 *
 * Hooks into post save/delete to keep the content embedding store current.
 * Supports batch reindexing via Action Scheduler or WP-Cron.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_Content_Embedding_Service {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Maximum text length sent to the embedding API.
	 *
	 * @var int
	 */
	const MAX_EMBED_TEXT_LENGTH = 8000;

	/**
	 * Default batch size for reindexing.
	 *
	 * @var int
	 */
	const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Action Scheduler hook for batch reindexing.
	 *
	 * @var string
	 */
	const BATCH_HOOK = 'wp_mcp_ai_embed_content_batch';

	/**
	 * Action Scheduler group.
	 *
	 * @var string
	 */
	const AS_GROUP = 'wp_mcp_ai_embeddings';

	/**
	 * Get singleton instance.
	 *
	 * @since 1.9.0
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 1.9.0
	 */
	private function __construct() {
		// Hooks registered via register_hooks().
	}

	/**
	 * Register WordPress hooks.
	 *
	 * Must be called once during bootstrap (via content-embedding-init.php).
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'save_post', array( $this, 'on_post_save' ), 20, 3 );
		add_action( 'delete_post', array( $this, 'on_post_delete' ), 10, 1 );
		add_action( self::BATCH_HOOK, array( $this, 'process_batch' ), 10, 2 );
	}

	/**
	 * Handle post save — generate and store embedding.
	 *
	 * Skips autosaves, revisions, and non-published posts. Only processes
	 * public post types.
	 *
	 * @since 1.9.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public function on_post_save( $post_id, $post, $update ) {
		// Skip autosaves and revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Only process published content.
		if ( 'publish' !== $post->post_status ) {
			// If unpublishing, remove the embedding.
			if ( $update ) {
				$this->delete_post_embedding( $post_id );
			}
			return;
		}

		// Only process public post types.
		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) {
			return;
		}

		// Build the text payload.
		$text = $this->build_post_text( $post );
		if ( '' === $text ) {
			return;
		}

		$this->embed_post( $post_id, $text );
	}

	/**
	 * Handle post deletion — remove stored embedding.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_post_delete( $post_id ) {
		$this->delete_post_embedding( $post_id );
	}

	/**
	 * Generate and store an embedding for a post.
	 *
	 * Lazy-loads the vector service and content embedding store. Skips if
	 * a fresh embedding already exists (content_hash match).
	 *
	 * @since 1.9.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $text    Text to embed.
	 * @return bool True on success.
	 */
	public function embed_post( $post_id, $text ) {
		if ( ! class_exists( 'WP_MCP_AI_Vector_Context_Service' ) ) {
			return false;
		}

		if ( ! class_exists( 'WP_MCP_AI_Content_Embedding_Store' ) ) {
			return false;
		}

		try {
			$svc = WP_MCP_AI_Vector_Context_Service::get_instance();

			$provider = $svc->get_embedding_provider();
			if ( is_wp_error( $provider ) ) {
				return false;
			}

			$provider_id = $provider->get_id();
			$model       = $provider->get_model();

			// Skip if a fresh embedding already exists.
			if ( WP_MCP_AI_Content_Embedding_Store::is_fresh( $post_id, $provider_id, $model, $text ) ) {
				return true;
			}

			$vector = $svc->embed_context( $text );
			if ( is_wp_error( $vector ) || ! is_array( $vector ) ) {
				return false;
			}

			return WP_MCP_AI_Content_Embedding_Store::store(
				$post_id,
				$vector,
				$provider_id,
				$model,
				$text
			);
		} catch ( \Throwable $e ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to embed post.',
					array(
						'post_id' => $post_id,
						'error'   => $e->getMessage(),
					)
				);
			}
			return false;
		}
	}

	/**
	 * Delete stored embeddings for a post.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function delete_post_embedding( $post_id ) {
		if ( class_exists( 'WP_MCP_AI_Content_Embedding_Store' ) ) {
			WP_MCP_AI_Content_Embedding_Store::delete( $post_id );
		}
	}

	/**
	 * Reindex all published content.
	 *
	 * Uses Action Scheduler when available; falls back to synchronous
	 * batch processing via WP-Cron.
	 *
	 * @since 1.9.0
	 *
	 * @param string $post_type  Post type to reindex (empty = all public types).
	 * @param int    $batch_size Number of posts per batch.
	 * @return array{processed: int, remaining: bool, total: int}
	 */
	public function reindex_all( $post_type = '', $batch_size = self::DEFAULT_BATCH_SIZE ) {
		$batch_size = max( 1, absint( $batch_size ) );

		// Count total posts.
		$post_types = '' !== $post_type
			? array( sanitize_key( $post_type ) )
			: get_post_types( array( 'public' => true ) );

		$total = 0;
		foreach ( $post_types as $pt ) {
			$counts = wp_count_posts( $pt );
			if ( isset( $counts->publish ) ) {
				$total += (int) $counts->publish;
			}
		}

		if ( 0 === $total ) {
			return array(
				'processed' => 0,
				'remaining' => false,
				'total'     => 0,
			);
		}

		// Enqueue Action Scheduler job when available.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::BATCH_HOOK,
				array(
					'offset'     => 0,
					'post_types' => $post_types,
					'batch_size' => $batch_size,
				),
				self::AS_GROUP
			);

			return array(
				'processed' => 0,
				'remaining' => true,
				'total'     => $total,
			);
		}

		// Synchronous fallback: process first batch now.
		$result = $this->process_batch( 0, $post_types, $batch_size );

		return array(
			'processed' => $result['processed'],
			'remaining' => $result['remaining'],
			'total'     => $total,
		);
	}

	/**
	 * Process one batch of the reindex job (Action Scheduler callback).
	 *
	 * @since 1.9.0
	 *
	 * @param int   $offset     Starting offset.
	 * @param array $post_types Post type slugs to index.
	 * @param int   $batch_size Number of posts per batch.
	 * @return array{processed: int, remaining: bool}
	 */
	public function process_batch( $offset, $post_types, $batch_size = 0 ) {
		$offset     = absint( $offset );
		$batch_size = $batch_size > 0 ? absint( $batch_size ) : self::DEFAULT_BATCH_SIZE;

		if ( ! is_array( $post_types ) || empty( $post_types ) ) {
			$post_types = get_post_types( array( 'public' => true ) );
		}

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'all',
			)
		);

		$processed = 0;
		foreach ( $posts as $post ) {
			$text = $this->build_post_text( $post );
			if ( '' === $text ) {
				continue;
			}
			if ( $this->embed_post( $post->ID, $text ) ) {
				++$processed;
			}
		}

		$remaining = count( $posts ) >= $batch_size;

		if ( $remaining && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + 30,
				self::BATCH_HOOK,
				array(
					'offset'     => $offset + $batch_size,
					'post_types' => $post_types,
					'batch_size' => $batch_size,
				),
				self::AS_GROUP
			);
		}

		return array(
			'processed' => $processed,
			'remaining' => $remaining,
		);
	}

	/**
	 * Build the text payload for a post to embed.
	 *
	 * Concatenates title, excerpt, and stripped content body, then
	 * truncates to the max length.
	 *
	 * @since 1.9.0
	 *
	 * @param WP_Post $post Post object.
	 * @return string Text ready for embedding.
	 */
	private function build_post_text( $post ) {
		$parts = array();

		if ( ! empty( $post->post_title ) ) {
			$parts[] = $post->post_title;
		}

		if ( ! empty( $post->post_excerpt ) ) {
			$parts[] = wp_strip_all_tags( $post->post_excerpt );
		}

		if ( ! empty( $post->post_content ) ) {
			$parts[] = wp_strip_all_tags( $post->post_content );
		}

		$text = implode( ' ', $parts );
		$text = trim( $text );

		// Truncate to a reasonable length for the embedding API.
		if ( strlen( $text ) > self::MAX_EMBED_TEXT_LENGTH ) {
			$text = substr( $text, 0, self::MAX_EMBED_TEXT_LENGTH );
		}

		return $text;
	}
}
