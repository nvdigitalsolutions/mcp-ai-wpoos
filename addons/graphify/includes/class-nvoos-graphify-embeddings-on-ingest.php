<?php
/**
 * NV oOS Graphify — Embeddings-on-Ingest Helper
 *
 * Bridges remote-source ingestion to the existing embeddings backend so
 * newly-imported nodes get a vector embedding generated automatically —
 * but asynchronously, via a single WP-Cron action, so that ingestion
 * loops never block on a remote OpenAI call.
 *
 * Behaviour:
 *   - `enqueue_for_node()` schedules a one-off `wp_mcp_ai_graphify_embed_node`
 *     action ~immediately. Successive calls for the same node are merged
 *     by WP-Cron because the args signature is identical.
 *   - The cron callback (`process_node`) generates and stores the
 *     embedding via `NV_oOS_Graphify_Embeddings::generate_and_store()`,
 *     reusing the model from settings.
 *   - `auto_enqueue_remote_nodes()` is the policy gate: it inspects the
 *     `embed_on_ingest` and `embeddings_enabled` settings and is the
 *     single function the enricher calls per ingested node.
 *   - `should_skip()` filters out nodes that don't have meaningful text
 *     to embed (empty label and empty properties).
 *
 * @package NV_oOS_Graphify
 * @since   0.7.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Embeddings-on-ingest helper.
 *
 * @since 0.7.8
 */
class NV_oOS_Graphify_Embeddings_On_Ingest {

	/**
	 * Cron action used to process a single node embedding.
	 *
	 * @var string
	 */
	const CRON_ACTION = 'wp_mcp_ai_graphify_embed_node';

	/**
	 * Maximum text length (chars) to send to the embeddings API.
	 *
	 * @var int
	 */
	const MAX_TEXT_LEN = 8000;

	/**
	 * Register the cron handler. Called once at plugin bootstrap.
	 *
	 * @return void
	 */
	public static function register() {
		if ( function_exists( 'add_action' ) ) {
			add_action( self::CRON_ACTION, array( __CLASS__, 'process_node' ), 10, 1 );
		}
	}

	/**
	 * Best-effort: enqueue an embedding job for an ingested node array,
	 * honouring the `embed_on_ingest` and `embeddings_enabled` settings.
	 *
	 * @param array $node Node array as returned by a driver's fetch_nodes.
	 * @return bool True when scheduled, false when skipped or disabled.
	 */
	public static function auto_enqueue_remote_nodes( array $node ) {
		if ( ! self::is_enabled() ) {
			return false;
		}
		if ( self::should_skip( $node ) ) {
			return false;
		}
		$node_id = isset( $node['node_id'] ) ? (string) $node['node_id'] : '';
		if ( '' === $node_id ) {
			return false;
		}
		return self::enqueue_for_node( $node_id, self::extract_text( $node ) );
	}

	/**
	 * Schedule a single embedding job. Idempotent — wp_schedule_single_event()
	 * de-duplicates on identical args within 10 minutes.
	 *
	 * @param string $node_id Node identifier.
	 * @param string $text    Pre-extracted text to embed.
	 * @return bool True when scheduled.
	 */
	public static function enqueue_for_node( $node_id, $text ) {
		$node_id = (string) $node_id;
		$text    = self::truncate( (string) $text );
		if ( '' === $node_id || '' === $text ) {
			return false;
		}
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return false;
		}
		$args = array( $node_id );
		// Allow consumers (tests, custom orchestration) to short-circuit
		// scheduling and run inline instead.
		$bypass = apply_filters( 'nvoos_graphify_embeddings_enqueue_bypass', false, $node_id, $text );
		if ( true === $bypass ) {
			self::process_node( $node_id );
			return true;
		}
		// Stash the text in a transient keyed by node so the cron worker
		// has it without serialising potentially-large payloads in the
		// cron schedule.
		set_transient( self::transient_key( $node_id ), $text, HOUR_IN_SECONDS );
		$scheduled = wp_schedule_single_event( time() - 1, self::CRON_ACTION, $args );
		if ( false !== $scheduled && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		return false !== $scheduled;
	}

	/**
	 * Cron worker: generate and store the embedding for a single node.
	 *
	 * @param string $node_id Node identifier.
	 * @return bool
	 */
	public static function process_node( $node_id ) {
		$node_id = (string) $node_id;
		if ( '' === $node_id ) {
			return false;
		}
		if ( ! class_exists( 'NV_oOS_Graphify_Embeddings' ) ) {
			return false;
		}
		$key  = self::transient_key( $node_id );
		$text = function_exists( 'get_transient' ) ? get_transient( $key ) : '';
		if ( false === $text || '' === (string) $text ) {
			// Fall back to the node's stored label/properties.
			if ( class_exists( 'NV_oOS_Graphify_DB' ) && method_exists( 'NV_oOS_Graphify_DB', 'get_node' ) ) {
				$node = NV_oOS_Graphify_DB::get_node( $node_id );
				if ( $node ) {
					$text = self::extract_text(
						array(
							'label'      => isset( $node->label ) ? $node->label : '',
							'properties' => isset( $node->properties ) ? (array) $node->properties : array(),
						)
					);
				}
			}
		}
		if ( '' === (string) $text ) {
			return false;
		}
		$ok = NV_oOS_Graphify_Embeddings::generate_and_store( $node_id, (string) $text );
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $key );
		}
		return (bool) $ok;
	}

	/**
	 * True when the embed-on-ingest path is enabled in settings.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( ! class_exists( 'NV_oOS_Graphify' ) || ! method_exists( 'NV_oOS_Graphify', 'get_settings' ) ) {
			return false;
		}
		$s = NV_oOS_Graphify::get_settings();
		if ( empty( $s['embeddings_enabled'] ) ) {
			return false;
		}
		// `embed_on_ingest` defaults true once `embeddings_enabled` is on,
		// so existing sites pick up the behaviour without re-configuring;
		// the setting still gates this when the admin opts out.
		return ! isset( $s['embed_on_ingest'] ) || ! empty( $s['embed_on_ingest'] );
	}

	/**
	 * True for nodes that have no embeddable text.
	 *
	 * @param array $node Node array.
	 * @return bool
	 */
	public static function should_skip( array $node ) {
		return '' === self::extract_text( $node );
	}

	/**
	 * Build a single string of the node's most embedding-relevant fields.
	 *
	 * @param array $node Node array.
	 * @return string
	 */
	public static function extract_text( array $node ) {
		$parts = array();
		if ( ! empty( $node['label'] ) ) {
			$parts[] = (string) $node['label'];
		}
		if ( ! empty( $node['type'] ) ) {
			$parts[] = '(' . (string) $node['type'] . ')';
		}
		if ( ! empty( $node['properties'] ) && is_array( $node['properties'] ) ) {
			foreach ( array( 'description', 'summary', 'excerpt', 'content', 'body', 'title' ) as $key ) {
				if ( ! empty( $node['properties'][ $key ] ) && is_string( $node['properties'][ $key ] ) ) {
					$parts[] = $node['properties'][ $key ];
				}
			}
		}
		$text = trim( implode( ' ', $parts ) );
		// Collapse whitespace.
		$text = preg_replace( '/\s+/', ' ', $text );
		return (string) $text;
	}

	/**
	 * Truncate to MAX_TEXT_LEN, on a UTF-8-safe boundary if available.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function truncate( $text ) {
		$text = (string) $text;
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text ) > self::MAX_TEXT_LEN ) {
				$text = mb_substr( $text, 0, self::MAX_TEXT_LEN );
			}
			return $text;
		}
		if ( strlen( $text ) > self::MAX_TEXT_LEN ) {
			$text = substr( $text, 0, self::MAX_TEXT_LEN );
		}
		return $text;
	}

	/**
	 * Transient key for a node's pending embedding text.
	 *
	 * @param string $node_id Node identifier.
	 * @return string
	 */
	private static function transient_key( $node_id ) {
		return 'nvoos_gx_emb_' . substr( md5( (string) $node_id ), 0, 24 );
	}
}
