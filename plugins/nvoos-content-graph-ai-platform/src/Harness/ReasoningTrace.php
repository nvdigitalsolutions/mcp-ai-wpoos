<?php
/**
 * Reasoning Trace — Layer B canonical schema.
 *
 * Defines the structured reasoning trace used by the harness:
 *
 *   assumptions → constraints → plan → intermediate_results → verification → answer
 *
 * The trace is a plain associative array (not an OOP graph) so it can be
 * persisted as post meta (`_wp_mcp_ai_reasoning_trace`) and serialized into
 * JSON for the OTel exporter without surprises. The shape is small enough
 * for an LLM to fill in directly when prompted with the Plan-Then-Solve cue.
 *
 * Helpers also provide a self-consistency vote primitive: given N candidate
 * answers, return the modal answer plus an agreement ratio. This is the
 * cheapest test-time-compute lever from Snell et al. 2024 and the o1 notes.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reasoning trace schema and self-consistency primitive.
 */
class ReasoningTrace {

	/**
	 * Post meta key for persistence.
	 */
	const META_KEY = '_wp_mcp_ai_reasoning_trace';

	/**
	 * Build a fresh, empty trace.
	 *
	 * @return array
	 */
	public static function new_trace() {
		return array(
			'schema_version'       => '1.0',
			'task_class'           => 'general',
			'assumptions'          => array(),
			'constraints'          => array(),
			'plan'                 => array(),
			'intermediate_results' => array(),
			'verification'         => array(),
			'answer'               => '',
			'created_at'           => time(),
		);
	}

	/**
	 * Sanitize a trace structure. Unknown keys are dropped; list fields are
	 * coerced to lists of strings.
	 *
	 * @param mixed $raw Raw input.
	 * @return array
	 */
	public static function sanitize( $raw ) {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$trace                   = self::new_trace();
		$trace['schema_version'] = isset( $raw['schema_version'] ) ? sanitize_text_field( (string) $raw['schema_version'] ) : '1.0';

		if ( isset( $raw['task_class'] ) ) {
			$task_class = sanitize_key( (string) $raw['task_class'] );
			if ( '' !== $task_class ) {
				$trace['task_class'] = $task_class;
			}
		}

		foreach ( array( 'assumptions', 'constraints', 'plan', 'intermediate_results', 'verification' ) as $list_key ) {
			$trace[ $list_key ] = self::coerce_string_list( isset( $raw[ $list_key ] ) ? $raw[ $list_key ] : array() );
		}

		if ( isset( $raw['answer'] ) ) {
			$trace['answer'] = (string) $raw['answer'];
		}

		if ( isset( $raw['created_at'] ) ) {
			$created = (int) $raw['created_at'];
			if ( $created > 0 ) {
				$trace['created_at'] = $created;
			}
		}

		return $trace;
	}

	/**
	 * Coerce a value to a list of trimmed strings. Caps at 50 entries to
	 * prevent unbounded growth from adversarial input.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int,string>
	 */
	private static function coerce_string_list( $value ) {
		if ( is_string( $value ) ) {
			$value = array( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$item = wp_json_encode( $item );
			}
			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
			if ( count( $out ) >= 50 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Persist a trace against a post (typically a chat transcript or a
	 * one-shot run).
	 *
	 * @param int   $post_id Post ID.
	 * @param array $trace   Trace (will be sanitized).
	 * @return bool
	 */
	public static function persist( $post_id, array $trace ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$clean   = self::sanitize( $trace );
		$encoded = wp_json_encode( $clean );
		if ( false === $encoded ) {
			return false;
		}
		return (bool) update_post_meta( $post_id, self::META_KEY, $encoded );
	}

	/**
	 * Read a persisted trace.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function fetch( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return null;
		}
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( empty( $raw ) ) {
			return null;
		}
		return self::sanitize( $raw );
	}

	/**
	 * Self-consistency vote across candidate answers. Implements the
	 * majority-vote primitive from Wang et al. 2022 (Self-Consistency)
	 * and the broader best-of-N + verifier line of work.
	 *
	 * Candidates are normalized (whitespace + case) before counting so trivial
	 * formatting differences don't fragment the vote. Ties are broken in
	 * favour of the first candidate seen.
	 *
	 * @param array<int,string> $candidates Candidate answers.
	 * @return array{
	 *   answer:string,
	 *   agreement:float,
	 *   votes:array<string,int>,
	 *   total:int
	 * } Vote result.
	 */
	public static function self_consistency_vote( array $candidates ) {
		$normalized = array();
		$display    = array();
		foreach ( $candidates as $cand ) {
			$cand = (string) $cand;
			$key  = strtolower( trim( preg_replace( '/\s+/', ' ', $cand ) ) );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $normalized[ $key ] ) ) {
				$normalized[ $key ] = 0;
				$display[ $key ]    = $cand;
			}
			++$normalized[ $key ];
		}

		$total = array_sum( $normalized );
		if ( 0 === $total ) {
			return array(
				'answer'    => '',
				'agreement' => 0.0,
				'votes'     => array(),
				'total'     => 0,
			);
		}

		arsort( $normalized );
		$winner_key = key( $normalized );
		$winner_n   = (int) $normalized[ $winner_key ];

		$votes_for_display = array();
		foreach ( $normalized as $k => $n ) {
			$votes_for_display[ $display[ $k ] ] = (int) $n;
		}

		return array(
			'answer'    => $display[ $winner_key ],
			'agreement' => $total > 0 ? round( $winner_n / $total, 4 ) : 0.0,
			'votes'     => $votes_for_display,
			'total'     => $total,
		);
	}
}
