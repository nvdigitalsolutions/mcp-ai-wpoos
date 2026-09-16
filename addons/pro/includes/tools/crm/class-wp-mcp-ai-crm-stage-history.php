<?php
/**
 * CRM Toolkit Deal Stage History
 *
 * Machine-readable stage transition ledger for CRM deals.
 *
 * Every stage change is appended to the deal's `stage_history` meta as an
 * ordered entry shaped `{ from, to, at, source }` (ISO 8601 UTC timestamps).
 * This makes pipeline analytics (funnel, time-in-stage, source attribution)
 * queryable per record without parsing the global audit log, and powers the
 * undo path in the move_deal_stage tool.
 *
 * Inspired by the status-transition model in JobNavigator
 * (github.com/vesaias/JobNavigator), where applications keep a
 * `status_transitions` list with an actor `source` on the record itself.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deal stage history ledger.
 *
 * @since 3.2.0
 */
class WP_MCP_AI_CRM_Stage_History {

	/**
	 * Meta key holding the ordered transition list (no leading underscore so
	 * the data-store get_item() exposes it).
	 *
	 * @var string
	 */
	const META_HISTORY = 'stage_history';

	/**
	 * Meta key holding the ISO 8601 UTC timestamp of the last real stage change.
	 * Drives time-in-stage / stalled-deal analytics (ageing signal).
	 *
	 * @var string
	 */
	const META_CHANGED_AT = 'stage_changed_at';

	/**
	 * Maximum number of retained transitions per deal (rolling window).
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 200;

	/**
	 * Valid transition source slugs.
	 *
	 * @var string[]
	 */
	const SOURCES = array(
		'tool',
		'agent',
		'workflow',
		'manual',
		'email_reply',
		'bulk',
	);

	/**
	 * Validate a transition source slug.
	 *
	 * @param string $source Source slug.
	 * @return string Valid source or 'tool' fallback.
	 */
	public static function sanitize_source( $source ) {
		$source = sanitize_key( (string) $source );
		return in_array( $source, self::SOURCES, true ) ? $source : 'tool';
	}

	/**
	 * Append a transition entry to a deal's stage history.
	 *
	 * @param int    $deal_id Deal post ID.
	 * @param string $from    Previous stage slug (empty string or null for the seed entry).
	 * @param string $to      New stage slug.
	 * @param string $source  Transition source (see SOURCES).
	 * @return bool True on success.
	 */
	public static function record( $deal_id, $from, $to, $source = 'tool' ) {
		$deal_id = absint( $deal_id );
		if ( ! $deal_id || 'mcp_ai_deal' !== get_post_type( $deal_id ) ) {
			return false;
		}

		$history = self::get_history( $deal_id );

		$entry = array(
			'from'   => sanitize_key( (string) $from ),
			'to'     => sanitize_key( (string) $to ),
			'at'     => gmdate( 'c' ),
			'source' => self::sanitize_source( $source ),
		);

		$history[] = $entry;

		// Rolling window: keep the most recent MAX_ENTRIES entries.
		$count = count( $history );
		if ( $count > self::MAX_ENTRIES ) {
			$history = array_slice( $history, $count - self::MAX_ENTRIES );
		}

		update_post_meta( $deal_id, self::META_HISTORY, $history );
		update_post_meta( $deal_id, self::META_CHANGED_AT, $entry['at'] );

		/**
		 * Fires after a stage transition has been appended to a deal's history.
		 *
		 * @since 3.2.0
		 *
		 * @param int   $deal_id Deal post ID.
		 * @param array $entry   The recorded transition entry.
		 */
		do_action( 'wp_mcp_ai_crm_deal_stage_history_recorded', $deal_id, $entry );

		return true;
	}

	/**
	 * Read a deal's stage history, oldest first.
	 *
	 * @param int $deal_id Deal post ID.
	 * @return array Transition entries.
	 */
	public static function get_history( $deal_id ) {
		$deal_id = absint( $deal_id );
		if ( ! $deal_id ) {
			return array();
		}

		$history = get_post_meta( $deal_id, self::META_HISTORY, true );
		return is_array( $history ) ? array_values( $history ) : array();
	}

	/**
	 * Undo the last stage transition.
	 *
	 * Pops the newest entry, restores `stage_changed_at` from the new tail
	 * (or deletes it when the ledger empties), and returns the entry so the
	 * caller can restore the previous stage. Does not record a new transition —
	 * an undo must not fabricate forward-looking history.
	 *
	 * @param int $deal_id Deal post ID.
	 * @return array|null The popped entry, or null when there is no history.
	 */
	public static function undo_last( $deal_id ) {
		$deal_id = absint( $deal_id );
		if ( ! $deal_id || 'mcp_ai_deal' !== get_post_type( $deal_id ) ) {
			return null;
		}

		$history = self::get_history( $deal_id );
		if ( empty( $history ) ) {
			return null;
		}

		$popped = array_pop( $history );
		update_post_meta( $deal_id, self::META_HISTORY, $history );

		if ( ! empty( $history ) ) {
			$tail       = end( $history );
			$changed_at = isset( $tail['at'] ) ? $tail['at'] : '';
			update_post_meta( $deal_id, self::META_CHANGED_AT, $changed_at );
		} else {
			delete_post_meta( $deal_id, self::META_CHANGED_AT );
		}

		return $popped;
	}

	/**
	 * Seconds spent in the current stage.
	 *
	 * Falls back to the deal post date when `stage_changed_at` is missing so
	 * legacy records (created before this feature) still yield an age.
	 *
	 * @param int $deal_id Deal post ID.
	 * @return int|null Seconds in the current stage, or null when the deal does not exist.
	 */
	public static function time_in_stage( $deal_id ) {
		$deal_id = absint( $deal_id );
		if ( ! $deal_id || 'mcp_ai_deal' !== get_post_type( $deal_id ) ) {
			return null;
		}

		$changed_at = get_post_meta( $deal_id, self::META_CHANGED_AT, true );
		if ( empty( $changed_at ) ) {
			$post       = get_post( $deal_id );
			$changed_at = $post ? $post->post_date_gmt : '';
		}
		if ( empty( $changed_at ) ) {
			return null;
		}

		$changed = strtotime( $changed_at );
		if ( false === $changed ) {
			return null;
		}

		return max( 0, time() - $changed );
	}

	/**
	 * Resolve the next open pipeline stage after the given one.
	 *
	 * Uses the stage registry's `order` values so custom pipelines keep
	 * working; closed stages are never returned.
	 *
	 * @param string $current Current stage slug.
	 * @return string|null Next open stage slug, or null when there is none.
	 */
	public static function next_open_stage( $current ) {
		if ( ! class_exists( 'WP_MCP_AI_CRM_Pipeline_Stages' ) ) {
			return null;
		}

		$current = sanitize_key( (string) $current );
		$stages  = WP_MCP_AI_CRM_Pipeline_Stages::get_stages();

		$current_order = null;
		if ( isset( $stages[ $current ] ) ) {
			$current_order = isset( $stages[ $current ]['order'] ) ? (int) $stages[ $current ]['order'] : null;
		}

		$best_slug  = null;
		$best_order = PHP_INT_MAX;
		foreach ( $stages as $slug => $stage ) {
			if ( ! empty( $stage['is_won'] ) || ! empty( $stage['is_lost'] ) ) {
				continue;
			}
			$order = isset( $stage['order'] ) ? (int) $stage['order'] : 0;
			if ( null === $current_order ) {
				// Unknown current stage: fall back to the first open stage.
				if ( $order < $best_order ) {
					$best_slug  = $slug;
					$best_order = $order;
				}
				continue;
			}
			if ( $order > $current_order && $order < $best_order ) {
				$best_slug  = $slug;
				$best_order = $order;
			}
		}

		return $best_slug;
	}
}
