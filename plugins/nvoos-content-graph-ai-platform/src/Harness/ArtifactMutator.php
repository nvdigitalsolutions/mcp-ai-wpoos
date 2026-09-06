<?php
/**
 * Artifact Mutators — LLM-driven mutation strategies for artifact variants.
 *
 * Implements the three Darwinian mutator strategies from Imbue's evolver as
 * standalone services that operate on the artifact population (Phase C):
 *
 *   - failure_driven: the parent artifact plus the concrete failure cases it
 *     produced are shown to the mutator LLM, which proposes a targeted fix.
 *   - with_learning_log: additionally receives past mutations from the
 *     lineage neighborhood as (diff, score-delta) pairs, so it can build on
 *     what worked and avoid re-trying what regressed.
 *   - crossover: two or more parents are combined into one artifact.
 *
 * All mutators share a single JSON response contract and a provider-style
 * callable so they can be driven by any provider client (or a test stub):
 *
 *   function ( array $messages, array $options ): array|WP_Error
 *
 * The self-referential mutation-prompt evolution (Promptbreeder) is deferred:
 * it needs its own population of mutation prompts and lands after the
 * admission gate (Phase E) validates the loop end-to-end.
 *
 * @package WP_MCP_AI
 * @subpackage WP_MCP_AI/includes/harness
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @reference Imbue (2026). "LLM-based Evolution as a Universal Optimizer."
 *   https://imbue.com/blog/2026-02-27-darwinian-evolver
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artifact Mutator class.
 *
 * @since 1.9.0
 */
class ArtifactMutator {

	/**
	 * Failure-driven mutation kind.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const KIND_FAILURE_DRIVEN = 'failure_driven';

	/**
	 * Learning-log-aware mutation kind.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const KIND_LEARNING_LOG = 'learning_log';

	/**
	 * Crossover mutation kind.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const KIND_CROSSOVER = 'crossover';

	/**
	 * Default mutation temperature (more creative than verification's 0.0).
	 *
	 * @since 1.9.0
	 * @var   float
	 */
	const DEFAULT_TEMPERATURE = 0.7;

	/**
	 * Maximum lines of input for the exact line diff (O(n·m) safety cap).
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const MAX_DIFF_INPUT_LINES = 400;

	/**
	 * Mutate a parent artifact against its failure cases.
	 *
	 * @since 1.9.0
	 *
	 * @param callable $llm_callable Provider-style callable.
	 * @param array    $context      Mutation context: `parent` (population
	 *                               entry), optional `failure_cases` (replay
	 *                               failure records), optional `options`.
	 * @return array|WP_Error Mutation envelope, or WP_Error.
	 */
	public static function failure_driven( $llm_callable, array $context ) {
		if ( empty( $context['parent'] ) || ! is_array( $context['parent'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_mutator_no_parent',
				__( 'A parent artifact is required for mutation.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$messages = self::build_failure_messages( $context );

		return self::call_and_parse( $llm_callable, $messages, self::KIND_FAILURE_DRIVEN, $context );
	}

	/**
	 * Mutate a parent artifact using failure cases plus the lineage learning log.
	 *
	 * @since 1.9.0
	 *
	 * @param callable $llm_callable Provider-style callable.
	 * @param array    $context      Mutation context: `parent`, optional
	 *                               `failure_cases`, `learning_log` entries.
	 * @return array|WP_Error Mutation envelope, or WP_Error.
	 */
	public static function with_learning_log( $llm_callable, array $context ) {
		if ( empty( $context['parent'] ) || ! is_array( $context['parent'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_mutator_no_parent',
				__( 'A parent artifact is required for mutation.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$messages = self::build_failure_messages( $context, true );

		return self::call_and_parse( $llm_callable, $messages, self::KIND_LEARNING_LOG, $context );
	}

	/**
	 * Combine two or more parent artifacts into one.
	 *
	 * @since 1.9.0
	 *
	 * @param callable $llm_callable Provider-style callable.
	 * @param array    $context      Mutation context: `parents` (2+ population
	 *                               entries).
	 * @return array|WP_Error Mutation envelope, or WP_Error.
	 */
	public static function crossover( $llm_callable, array $context ) {
		$parents = isset( $context['parents'] ) && is_array( $context['parents'] ) ? $context['parents'] : array();
		$parents = array_values( array_filter( $parents, 'is_array' ) );
		if ( count( $parents ) < 2 ) {
			return new \WP_Error(
				'wp_mcp_ai_mutator_crossover_needs_parents',
				__( 'Crossover requires at least two parent artifacts.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$parts  = array();
		$hashes = array();
		foreach ( $parents as $parent ) {
			$hash = isset( $parent['hash'] ) ? sanitize_key( (string) $parent['hash'] ) : '';
			if ( '' !== $hash ) {
				$hashes[] = $hash;
			}
			$parts[] = __( '--- Parent artifact ---', 'nvoos-content-graph-ai-platform' );
			$parts[] = self::artifact_to_text( isset( $parent['artifact'] ) ? $parent['artifact'] : array() );
			if ( '' !== $hash ) {
				$parts[] = sprintf(
					/* translators: %s: parent hash */
					__( '(parent hash: %s)', 'nvoos-content-graph-ai-platform' ),
					$hash
				);
			}
		}

		$parts[] = '';
		$parts[] = __( 'Combine the best ideas from the parent artifacts above into a single improved artifact. Return ONLY a JSON object with keys "prompt" (the combined prompt text) and "change_summary" (one sentence describing the combination).', 'nvoos-content-graph-ai-platform' );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => __( 'You are an artifact crossover mutator for an AI agent evolution loop. You recombine independently discovered improvements from multiple parent artifacts into one coherent artifact. Your output must be valid JSON only — no commentary, no markdown code fences.', 'nvoos-content-graph-ai-platform' ),
			),
			array(
				'role'    => 'user',
				'content' => implode( "\n", $parts ),
			),
		);

		$result = self::call_and_parse( $llm_callable, $messages, self::KIND_CROSSOVER, $context );
		if ( ! is_wp_error( $result ) ) {
			$result['parent_hashes'] = $hashes;
			$result['diff']          = '';
		}

		return $result;
	}

	/**
	 * Produce a compact line diff between two artifact payloads.
	 *
	 * Change-only diff (no context lines): `+` for added lines, `-` for
	 * removed lines, truncated symmetrically when longer than `$max_lines`.
	 * Oversized inputs fall back to a one-line size summary.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $parent_artifact Parent artifact payload.
	 * @param mixed $child           Child artifact payload.
	 * @param int   $max_lines       Maximum output lines. Default 60.
	 * @return string Diff text ('' when identical).
	 */
	public static function diff_artifacts( $parent_artifact, $child, $max_lines = 60 ) {
		$max_lines = max( 10, (int) $max_lines );

		$old = self::artifact_to_text( $parent_artifact );
		$new = self::artifact_to_text( $child );

		if ( $old === $new ) {
			return '';
		}

		$old_lines = explode( "\n", $old );
		$new_lines = explode( "\n", $new );

		if ( count( $old_lines ) > self::MAX_DIFF_INPUT_LINES || count( $new_lines ) > self::MAX_DIFF_INPUT_LINES ) {
			return sprintf(
				/* translators: 1: parent line count, 2: child line count */
				__( 'Large change: %1$d → %2$d lines.', 'nvoos-content-graph-ai-platform' ),
				count( $old_lines ),
				count( $new_lines )
			);
		}

		$diff = self::line_diff( $old_lines, $new_lines );

		if ( count( $diff ) > $max_lines ) {
			$head = array_slice( $diff, 0, (int) floor( $max_lines / 2 ) );
			$tail = array_slice( $diff, -1 * (int) ceil( $max_lines / 2 ) );
			$diff = array_merge(
				$head,
				array(
					sprintf(
						/* translators: %d: number of omitted changed lines */
						__( '… %d more changed lines …', 'nvoos-content-graph-ai-platform' ),
						count( $diff ) - $max_lines
					),
				),
				$tail
			);
		}

		return implode( "\n", $diff );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the failure-driven (and learning-log) mutation messages.
	 *
	 * @since 1.9.0
	 *
	 * @param array $context            Mutation context.
	 * @param bool  $include_learning   Whether to include learning-log entries.
	 * @return array Message list.
	 */
	private static function build_failure_messages( $context, $include_learning = false ) {
		$parent = $context['parent'];
		$parts  = array();

		$parts[] = __( 'Current parent artifact:', 'nvoos-content-graph-ai-platform' );
		$parts[] = '---';
		$parts[] = self::artifact_to_text( isset( $parent['artifact'] ) ? $parent['artifact'] : array() );
		$parts[] = '---';
		$parts[] = '';

		$failures = isset( $context['failure_cases'] ) && is_array( $context['failure_cases'] ) ? $context['failure_cases'] : array();
		if ( empty( $failures ) ) {
			$parts[] = __( 'No specific failure cases were supplied; propose a general quality improvement.', 'nvoos-content-graph-ai-platform' );
		} else {
			$parts[] = __( 'Failure cases this artifact produced (fix these):', 'nvoos-content-graph-ai-platform' );
			foreach ( $failures as $failure ) {
				if ( ! is_array( $failure ) ) {
					continue;
				}
				$parts[] = '- ' . self::failure_to_text( $failure );
			}
		}
		$parts[] = '';

		if ( $include_learning ) {
			$log = isset( $context['learning_log'] ) && is_array( $context['learning_log'] ) ? $context['learning_log'] : array();
			if ( empty( $log ) ) {
				$parts[] = __( 'No learning-log entries were available for this lineage.', 'nvoos-content-graph-ai-platform' );
			} else {
				$parts[] = __( 'Learning log (past mutations in this lineage and their score impact — build on improvements, do NOT repeat regressions):', 'nvoos-content-graph-ai-platform' );
				foreach ( $log as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}
					$delta   = isset( $entry['score_delta'] ) ? (float) $entry['score_delta'] : 0.0;
					$parts[] = sprintf(
						/* translators: %1$s: score delta with sign, %2$s: change description */
						__( 'Score delta %1$s — %2$s', 'nvoos-content-graph-ai-platform' ),
						sprintf( '%+.3f', $delta ),
						self::log_entry_to_text( $entry )
					);
				}
			}
			$parts[] = '';
		}

		$parts[] = __( 'Propose an improved artifact that addresses the failures above. Return ONLY a JSON object with keys "prompt" (the improved prompt text) and "change_summary" (one sentence describing what changed and why).', 'nvoos-content-graph-ai-platform' );

		return array(
			array(
				'role'    => 'system',
				'content' => __( 'You are an artifact mutator for an AI agent evolution loop. You improve artifacts through targeted, minimal edits driven by concrete failure evidence. Your output must be valid JSON only — no commentary, no markdown code fences.', 'nvoos-content-graph-ai-platform' ),
			),
			array(
				'role'    => 'user',
				'content' => implode( "\n", $parts ),
			),
		);
	}

	/**
	 * Invoke the LLM callable, parse the JSON mutation response, and build the
	 * canonical mutation envelope.
	 *
	 * @since 1.9.0
	 *
	 * @param callable $llm_callable Provider-style callable.
	 * @param array    $messages     Messages.
	 * @param string   $kind         Mutation kind.
	 * @param array    $context      Mutation context.
	 * @return array|WP_Error Mutation envelope, or WP_Error.
	 */
	private static function call_and_parse( $llm_callable, $messages, $kind, $context ) {
		if ( ! is_callable( $llm_callable ) ) {
			return new \WP_Error(
				'wp_mcp_ai_mutator_not_callable',
				__( 'The mutator LLM callable is not callable.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Filters the mutation temperature.
		 *
		 * @since 1.9.0
		 *
		 * @param float  $temperature Mutation temperature. Default 0.7.
		 * @param string $kind        Mutation kind.
		 */
		$temperature = (float) apply_filters( 'wp_mcp_ai_artifact_mutator_temperature', self::DEFAULT_TEMPERATURE, $kind );

		$options = array( 'temperature' => $temperature );
		if ( isset( $context['options']['model'] ) && '' !== (string) $context['options']['model'] ) {
			$options['model'] = (string) $context['options']['model'];
		}

		$response = call_user_func( $llm_callable, $messages, $options );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$content = self::extract_content( $response );
		$parsed  = self::parse_json( $content );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$prompt = isset( $parsed['prompt'] ) ? self::scrub( (string) $parsed['prompt'] ) : '';
		if ( '' === trim( $prompt ) ) {
			return new \WP_Error(
				'wp_mcp_ai_mutator_empty_prompt',
				__( 'The mutator returned an empty prompt.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$change_summary = isset( $parsed['change_summary'] ) ? self::scrub( (string) $parsed['change_summary'] ) : '';

		$parent_hashes = array();
		$diff          = '';
		if ( isset( $context['parent'] ) && is_array( $context['parent'] ) ) {
			$hash = isset( $context['parent']['hash'] ) ? sanitize_key( (string) $context['parent']['hash'] ) : '';
			if ( '' !== $hash ) {
				$parent_hashes[] = $hash;
			}
			$diff = self::diff_artifacts(
				isset( $context['parent']['artifact'] ) ? $context['parent']['artifact'] : array(),
				array( 'prompt' => $prompt )
			);
		}

		return array(
			'success'        => true,
			'kind'           => $kind,
			'parent_hashes'  => $parent_hashes,
			'artifact_type'  => 'prompt',
			'artifact'       => array( 'prompt' => $prompt ),
			'change_summary' => $change_summary,
			'diff'           => $diff,
			'meta'           => array(
				'model'       => isset( $options['model'] ) ? $options['model'] : '',
				'temperature' => $temperature,
			),
		);
	}

	/**
	 * Extract plain-text content from a provider-style response.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $response Response payload.
	 * @return string Content text.
	 */
	private static function extract_content( $response ) {
		if ( isset( $response['content'] ) ) {
			return (string) $response['content'];
		}
		if ( isset( $response['choices'][0]['message']['content'] ) ) {
			return (string) $response['choices'][0]['message']['content'];
		}

		return '';
	}

	/**
	 * Parse a JSON mutation response, tolerating markdown code fences.
	 *
	 * @since 1.9.0
	 *
	 * @param string $content Raw LLM response text.
	 * @return array|WP_Error Parsed array, or WP_Error.
	 */
	private static function parse_json( $content ) {
		$content = trim( (string) $content );

		if ( '' === $content ) {
			return new \WP_Error(
				'wp_mcp_ai_mutator_empty_response',
				__( 'The mutator returned empty content.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( preg_match( '/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $content, $matches ) ) {
			$content = $matches[1];
		}

		$parsed = json_decode( $content, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error(
				'wp_mcp_ai_mutator_invalid_response',
				sprintf(
					/* translators: %s: JSON error message */
					__( 'Failed to parse the mutator response: %s', 'nvoos-content-graph-ai-platform' ),
					json_last_error_msg()
				)
			);
		}

		if ( ! is_array( $parsed ) ) {
			return new \WP_Error(
				'wp_mcp_ai_mutator_invalid_response',
				__( 'The mutator response is not a JSON object.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $parsed;
	}

	/**
	 * Render an artifact payload as diffable text.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $artifact Artifact payload.
	 * @return string Text representation.
	 */
	private static function artifact_to_text( $artifact ) {
		if ( is_string( $artifact ) ) {
			return $artifact;
		}

		if ( is_array( $artifact ) ) {
			foreach ( array( 'prompt', 'instructions', 'code' ) as $key ) {
				if ( isset( $artifact[ $key ] ) && is_string( $artifact[ $key ] ) ) {
					return $artifact[ $key ];
				}
			}
			return (string) wp_json_encode( $artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}

		return (string) $artifact;
	}

	/**
	 * Render a failure record as a single text line.
	 *
	 * @since 1.9.0
	 *
	 * @param array $failure Failure record.
	 * @return string Text line.
	 */
	private static function failure_to_text( $failure ) {
		$tool  = isset( $failure['tool_slug'] ) ? (string) $failure['tool_slug'] : '';
		$error = isset( $failure['error'] ) ? (string) $failure['error'] : '';

		// Replay eval cases carry input arrays instead of flat records.
		if ( '' === $tool && isset( $failure['input'] ) && is_array( $failure['input'] ) ) {
			$tool  = isset( $failure['input']['tool_slug'] ) ? (string) $failure['input']['tool_slug'] : '';
			$error = isset( $failure['input']['error'] ) ? (string) $failure['input']['error'] : '';
		}

		return sprintf(
			/* translators: 1: tool slug, 2: error text */
			__( 'Tool "%1$s" failed: %2$s', 'nvoos-content-graph-ai-platform' ),
			'' !== $tool ? $tool : __( '(unknown)', 'nvoos-content-graph-ai-platform' ),
			'' !== $error ? $error : __( '(unknown error)', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Render a learning-log entry as text.
	 *
	 * @since 1.9.0
	 *
	 * @param array $entry Learning-log entry.
	 * @return string Text.
	 */
	private static function log_entry_to_text( $entry ) {
		$summary = isset( $entry['change_summary'] ) ? trim( (string) $entry['change_summary'] ) : '';
		if ( '' === $summary && isset( $entry['diff'] ) && '' !== trim( (string) $entry['diff'] ) ) {
			$summary = trim( (string) $entry['diff'] );
		}

		return '' !== $summary ? $summary : __( '(no description)', 'nvoos-content-graph-ai-platform' );
	}

	/**
	 * Compute a change-only line diff between two line arrays.
	 *
	 * @since 1.9.0
	 *
	 * @param array $old_lines Parent lines.
	 * @param array $new_lines Child lines.
	 * @return array Diff lines ('+ '/'− ' prefixed).
	 */
	private static function line_diff( $old_lines, $new_lines ) {
		$n = count( $old_lines );
		$m = count( $new_lines );

		$lcs = array();
		for ( $i = 0; $i <= $n; $i++ ) {
			$lcs[ $i ] = array_fill( 0, $m + 1, 0 );
		}

		for ( $i = 1; $i <= $n; $i++ ) {
			for ( $j = 1; $j <= $m; $j++ ) {
				if ( $old_lines[ $i - 1 ] === $new_lines[ $j - 1 ] ) {
					$lcs[ $i ][ $j ] = $lcs[ $i - 1 ][ $j - 1 ] + 1;
				} else {
					$lcs[ $i ][ $j ] = max( $lcs[ $i - 1 ][ $j ], $lcs[ $i ][ $j - 1 ] );
				}
			}
		}

		$diff = array();
		$i    = $n;
		$j    = $m;
		while ( $i > 0 || $j > 0 ) {
			if ( $i > 0 && $j > 0 && $old_lines[ $i - 1 ] === $new_lines[ $j - 1 ] ) {
				--$i;
				--$j;
			} elseif ( $j > 0 && ( 0 === $i || $lcs[ $i ][ $j - 1 ] >= $lcs[ $i - 1 ][ $j ] ) ) {
				array_unshift( $diff, '+ ' . $new_lines[ $j - 1 ] );
				--$j;
			} else {
				array_unshift( $diff, '- ' . $old_lines[ $i - 1 ] );
				--$i;
			}
		}

		return $diff;
	}

	/**
	 * PII-scrub LLM-generated text (graceful when the filter is absent).
	 *
	 * @since 1.9.0
	 *
	 * @param string $text Raw text.
	 * @return string Scrubbed text.
	 */
	private static function scrub( $text ) {
		$text = (string) $text;

		if ( '' === $text || ! class_exists( __NAMESPACE__ . '\\PiiFilter' ) ) {
			return $text;
		}

		$scrubbed = PiiFilter::scrub( $text );
		if ( is_array( $scrubbed ) && isset( $scrubbed['text'] ) && is_string( $scrubbed['text'] ) ) {
			return $scrubbed['text'];
		}

		return $text;
	}
}
