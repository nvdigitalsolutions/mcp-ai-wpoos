<?php
/**
 * Annotation store — Proposal 091, Track E.
 *
 * Captures human ratings/comments on chat turns, keyed by transcript ID and
 * turn index (decision D5: stored as post meta on the transcript), and
 * exports them as an eval dataset consumable by `run_assistant_eval`.
 *
 * The store is transcript-storage agnostic: callers pass a numeric
 * transcript ID, and the dataset export joins nothing — each row carries
 * enough coordinates (transcript id + turn) for the consumer to pull the
 * full exchange from whichever transcript store the site uses.
 *
 * @package WP_MCP_AI
 * @since   1.2.13
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Annotation store.
 *
 * @since 1.2.13
 */
class WP_MCP_AI_Annotation_Store {

	/**
	 * Meta key holding the JSON-encoded annotation list.
	 *
	 * @since 1.2.13
	 * @var string
	 */
	const META_KEY = '_wp_mcp_ai_annotations';

	/**
	 * Maximum retained annotations per transcript.
	 *
	 * @since 1.2.13
	 * @var int
	 */
	const MAX_ENTRIES = 500;

	/**
	 * Record (or update) an annotation for a transcript turn.
	 *
	 * Rating convention: 1 = positive, -1 = negative, 0 = neutral.
	 * Re-recording the same turn overwrites the previous annotation.
	 *
	 * @since 1.2.13
	 *
	 * @param int    $transcript_id Transcript post ID.
	 * @param int    $turn_index    Zero-based turn index within the transcript.
	 * @param int    $rating        Rating: 1, 0, or -1.
	 * @param string $comment       Optional free-text comment.
	 * @return array|WP_Error Stored entry, or WP_Error on invalid input.
	 */
	public static function record( int $transcript_id, int $turn_index, int $rating, string $comment = '' ) {
		if ( $transcript_id <= 0 || null === get_post( $transcript_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_annotation_invalid_transcript',
				__( 'Transcript not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		if ( ! in_array( $rating, array( -1, 0, 1 ), true ) ) {
			return new WP_Error(
				'wp_mcp_ai_annotation_invalid_rating',
				__( 'Rating must be 1, 0, or -1.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$entries = self::get_annotations( $transcript_id );
		$entry   = array(
			'turn'    => $turn_index,
			'rating'  => $rating,
			'comment' => sanitize_textarea_field( $comment ),
			'created' => time(),
			'author'  => get_current_user_id(),
		);

		// Same-turn annotations overwrite.
		$updated = false;
		foreach ( $entries as $index => $existing ) {
			if ( $existing['turn'] === $turn_index ) {
				$entries[ $index ] = $entry;
				$updated           = true;
				break;
			}
		}
		if ( ! $updated ) {
			$entries[] = $entry;
		}

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, count( $entries ) - self::MAX_ENTRIES );
		}

		// wp_slash() the JSON: update_metadata() unslashes on write.
		update_post_meta( $transcript_id, self::META_KEY, wp_slash( wp_json_encode( $entries ) ) );

		/**
		 * Fires after an annotation is recorded.
		 *
		 * @since 1.2.13
		 *
		 * @param int   $transcript_id Transcript post ID.
		 * @param array $entry         The stored annotation entry.
		 */
		do_action( 'wp_mcp_ai_annotation_recorded', $transcript_id, $entry );

		return $entry;
	}

	/**
	 * Get all annotations for a transcript.
	 *
	 * @since 1.2.13
	 *
	 * @param int $transcript_id Transcript post ID.
	 * @return array[] Annotation entries.
	 */
	public static function get_annotations( int $transcript_id ): array {
		$raw  = get_post_meta( $transcript_id, self::META_KEY, true );
		$list = json_decode( (string) $raw, true );
		if ( ! is_array( $list ) ) {
			return array();
		}

		$clean = array();
		foreach ( $list as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['turn'] ) ) {
				continue;
			}
			$clean[] = array(
				'turn'    => absint( $entry['turn'] ),
				'rating'  => isset( $entry['rating'] ) ? (int) $entry['rating'] : 0,
				'comment' => isset( $entry['comment'] ) ? (string) $entry['comment'] : '',
				'created' => isset( $entry['created'] ) ? absint( $entry['created'] ) : 0,
				'author'  => isset( $entry['author'] ) ? absint( $entry['author'] ) : 0,
			);
		}

		return $clean;
	}

	/**
	 * Export annotations as an eval dataset.
	 *
	 * Scans all posts carrying the annotation meta. When `$assistant_id` is
	 * given, only transcripts whose `_wp_mcp_ai_assistant_id` meta matches
	 * are included (transcripts without that meta are skipped).
	 *
	 * @since 1.2.13
	 *
	 * @param int $assistant_id Optional assistant filter.
	 * @return array[] Dataset rows: { transcript_id, turn, rating, comment, created, author }.
	 */
	public static function export_dataset( int $assistant_id = 0 ): array {
		$post_ids = get_posts(
			array(
				'fields'         => 'ids',
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 2000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded annotation export scan (dataset cap).
				'meta_key'       => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded export scan.
			)
		);

		$rows = array();
		foreach ( $post_ids as $post_id ) {
			if ( $assistant_id > 0 ) {
				$owner = absint( get_post_meta( $post_id, '_wp_mcp_ai_assistant_id', true ) );
				if ( $owner !== $assistant_id ) {
					continue;
				}
			}

			foreach ( self::get_annotations( $post_id ) as $entry ) {
				$rows[] = array_merge(
					array( 'transcript_id' => $post_id ),
					$entry
				);
			}
		}

		return $rows;
	}
}
