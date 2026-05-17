<?php
/**
 * NV oOS Graphify — Vector Embeddings
 *
 * Handles storage, retrieval, and similarity search for float-vector embeddings
 * associated with knowledge-graph nodes.
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vector embedding storage and retrieval for graph nodes.
 *
 * @since 0.6.0
 */
// phpcs:ignore PEAR.NamingConventions.ValidClassName.Invalid,Squiz.Commenting.ClassComment.Missing -- NV_oOS intentional branding; consistent with all other addon classes.
class NV_oOS_Graphify_Embeddings {

	/**
	 * Default embedding model.
	 *
	 * @var string
	 */
	const DEFAULT_MODEL = 'text-embedding-3-small';

	/**
	 * Minimum cosine similarity threshold for search results.
	 *
	 * @var float
	 */
	const SIMILARITY_THRESHOLD = 0.5;

	/**
	 * Store a float vector for a node in the embeddings table.
	 *
	 * @since 0.6.0
	 *
	 * @param string  $node_id Node identifier.
	 * @param float[] $vector  Float array (embedding vector).
	 * @param string  $model   Model identifier.
	 * @return bool True on success.
	 */
	public static function store( $node_id, array $vector, $model = self::DEFAULT_MODEL ) {
		if ( empty( $node_id ) || empty( $vector ) ) {
			return false;
		}

		$node_id = sanitize_text_field( $node_id );
		$model   = sanitize_text_field( $model );
		$dim     = count( $vector );

		// Pack as float32 binary.
		$binary = '';
		foreach ( $vector as $v ) {
			$binary .= pack( 'f', (float) $v );
		}

		$result = NV_oOS_Graphify_DB::upsert_embedding(
			array(
				'node_id' => $node_id,
				'model'   => $model,
				'dim'     => $dim,
				'vector'  => $binary,
			)
		);

		return false !== $result;
	}

	/**
	 * Retrieve and unpack a stored embedding for a node.
	 *
	 * @since 0.6.0
	 *
	 * @param string $node_id Node identifier.
	 * @param string $model   Model identifier.
	 * @return float[]|null Float array or null if not found.
	 */
	public static function get( $node_id, $model = self::DEFAULT_MODEL ) {
		$row = NV_oOS_Graphify_DB::get_embedding( sanitize_text_field( $node_id ), sanitize_text_field( $model ) );
		if ( ! $row || empty( $row->vector ) ) {
			return null;
		}

		return self::unpack_vector( $row->vector );
	}

	/**
	 * Compute cosine similarity between two float vectors.
	 *
	 * @since 0.6.0
	 *
	 * @param float[] $a First vector.
	 * @param float[] $b Second vector.
	 * @return float Cosine similarity in range -1..1, or 0 on error.
	 */
	public static function cosine_similarity( array $a, array $b ) {
		$len = count( $a );
		if ( count( $b ) !== $len || 0 === $len ) {
			return 0.0;
		}

		$dot    = 0.0;
		$norm_a = 0.0;
		$norm_b = 0.0;

		for ( $i = 0; $i < $len; $i++ ) {
			$ai      = (float) $a[ $i ];
			$bi      = (float) $b[ $i ];
			$dot    += $ai * $bi;
			$norm_a += $ai * $ai;
			$norm_b += $bi * $bi;
		}

		$denominator = sqrt( $norm_a ) * sqrt( $norm_b );
		if ( $denominator < 1.0e-10 ) {
			return 0.0;
		}

		return (float) ( $dot / $denominator );
	}

	/**
	 * Search stored embeddings for the closest matches to a query vector.
	 *
	 * @since 0.6.0
	 *
	 * @param float[] $query_vector Query embedding vector.
	 * @param int     $limit        Number of top results to return.
	 * @param string  $model        Model identifier.
	 * @return array Node IDs ordered by descending similarity: [['node_id'=>string,'score'=>float], ...]
	 */
	public static function search( array $query_vector, $limit = 10, $model = self::DEFAULT_MODEL ) {
		$rows = NV_oOS_Graphify_DB::get_all_embeddings( sanitize_text_field( $model ) );
		if ( empty( $rows ) ) {
			return array();
		}

		$scores = array();
		foreach ( $rows as $row ) {
			if ( empty( $row->vector ) ) {
				continue;
			}
			$vec = self::unpack_vector( $row->vector );
			if ( null === $vec ) {
				continue;
			}
			$score = self::cosine_similarity( $query_vector, $vec );
			if ( $score < self::SIMILARITY_THRESHOLD ) {
				continue;
			}
			$scores[] = array(
				'node_id' => $row->node_id,
				'score'   => $score,
			);
		}

		// Sort descending.
		usort(
			$scores,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $scores, 0, absint( $limit ) );
	}

	/**
	 * Generate an embedding for $text and store it for $node_id.
	 *
	 * Uses the oOS AI provider function if available, otherwise falls back
	 * to the OpenAI embeddings API directly.
	 *
	 * @since 0.6.0
	 *
	 * @param string $node_id Node identifier.
	 * @param string $text    Text to embed.
	 * @return bool True on success.
	 */
	public static function generate_and_store( $node_id, $text ) {
		$text = sanitize_textarea_field( $text );
		if ( empty( $text ) || empty( $node_id ) ) {
			return false;
		}

		$settings = NV_oOS_Graphify::get_settings();
		$model    = isset( $settings['embeddings_model'] ) ? $settings['embeddings_model'] : self::DEFAULT_MODEL;

		$vector = null;

		// Try oOS built-in embedding function.
		if ( function_exists( 'wp_mcp_ai_get_embedding' ) ) {
			$result = wp_mcp_ai_get_embedding( $text, $model );
			if ( is_array( $result ) && ! empty( $result ) ) {
				$vector = $result;
			}
		}

		// Fallback: OpenAI embeddings API.
		if ( null === $vector ) {
			$api_key = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';
			// NV oOS stores the OpenAI key inside the `wp_mcp_ai_settings` array,
			// not as a top-level option, so check that location before falling back
			// to the legacy top-level option name.
			if ( empty( $api_key ) ) {
				$nvoos_settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : get_option( 'wp_mcp_ai_settings', array() );
				if ( is_array( $nvoos_settings ) && ! empty( $nvoos_settings['openai_api_key'] ) ) {
					$api_key = sanitize_text_field( $nvoos_settings['openai_api_key'] );
				}
			}
			if ( empty( $api_key ) && function_exists( 'wp_mcp_ai_get_option' ) ) {
				$api_key = wp_mcp_ai_get_option( 'openai_api_key', '' );
			}
			if ( empty( $api_key ) ) {
				return false;
			}

			$response = wp_remote_post(
				'https://api.openai.com/v1/embeddings',
				array(
					'timeout' => 30,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'model' => $model,
							'input' => $text,
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $data['data'][0]['embedding'] ) && is_array( $data['data'][0]['embedding'] ) ) {
				$vector = $data['data'][0]['embedding'];
			}
		}

		if ( null === $vector || empty( $vector ) ) {
			return false;
		}

		return self::store( $node_id, $vector, $model );
	}

	/**
	 * Reindex all nodes by generating and storing embeddings for up to $limit nodes.
	 *
	 * Schedules the next batch via WP Cron if more nodes remain.
	 *
	 * @since 0.6.0
	 *
	 * @param string $model Model identifier.
	 * @param int    $limit Max nodes to process per run.
	 * @return array Summary: ['processed'=>int,'remaining'=>bool]
	 */
	public static function reindex_all( $model = self::DEFAULT_MODEL, $limit = 50 ) {
		$settings = NV_oOS_Graphify::get_settings();
		if ( empty( $settings['embeddings_enabled'] ) ) {
			return array(
				'processed' => 0,
				'remaining' => false,
			);
		}

		$offset = (int) get_option( 'nvoos_graphify_reindex_offset', 0 );
		$nodes  = NV_oOS_Graphify_DB::list_nodes(
			array(
				'order_by' => 'updated_at',
				'order'    => 'ASC',
				'limit'    => $limit,
				'offset'   => $offset,
			)
		);

		if ( empty( $nodes ) ) {
			delete_option( 'nvoos_graphify_reindex_offset' );
			return array(
				'processed' => 0,
				'remaining' => false,
			);
		}

		$processed = 0;
		$failed    = 0;
		foreach ( $nodes as $node ) {
			$text = $node->label;
			if ( ! empty( $node->properties ) ) {
				$props = is_string( $node->properties ) ? json_decode( $node->properties, true ) : (array) $node->properties;
				if ( ! empty( $props['excerpt'] ) ) {
					$text .= ' ' . $props['excerpt'];
				}
			}
			$ok = self::generate_and_store( $node->node_id, $text );
			if ( $ok ) {
				++$processed;
			} else {
				++$failed;
			}
		}

		$total_seen = $processed + $failed;
		$remaining  = $total_seen >= $limit;
		if ( $remaining ) {
			update_option( 'nvoos_graphify_reindex_offset', $offset + $total_seen );
			wp_schedule_single_event( time() + 60, 'nvoos_graphify_cron_reindex_embeddings' );
		} else {
			delete_option( 'nvoos_graphify_reindex_offset' );
		}

		return array(
			'processed' => $processed,
			'failed'    => $failed,
			'remaining' => $remaining,
		);
	}

	/**
	 * Unpack a binary float32 blob into a float array.
	 *
	 * @since 0.6.0
	 *
	 * @param string $binary Packed binary string.
	 * @return float[]|null Float array or null on error.
	 */
	private static function unpack_vector( $binary ) {
		if ( empty( $binary ) ) {
			return null;
		}
		$len      = strlen( $binary );
		$count    = (int) ( $len / 4 );
		$unpacked = unpack( 'f' . $count, $binary );
		if ( false === $unpacked || empty( $unpacked ) ) {
			return null;
		}
		return array_values( $unpacked );
	}
}
