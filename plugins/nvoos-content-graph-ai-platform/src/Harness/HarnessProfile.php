<?php
/**
 * Harness Profile — per-assistant configuration for the LLM harnessing subsystem.
 *
 * Stores the opt-in configuration that turns each harness layer (Prompt, Reasoning,
 * Tool routing, Retrieval, Self-Refine, Memory scoping, Evaluation) on or off for a
 * given assistant. The default profile keeps every layer disabled so the change is
 * behaviour-preserving until a site administrator opts an assistant in.
 *
 * Storage: post meta `_wp_mcp_ai_harness_profile` on the assistant CPT, JSON-encoded.
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
 * Per-assistant harness profile.
 */
class HarnessProfile {

	/**
	 * Post meta key used for persistence.
	 */
	const META_KEY = '_wp_mcp_ai_harness_profile';

	/**
	 * Hard upper bound on best-of-N samples regardless of profile setting.
	 */
	const MAX_REASONING_SAMPLES = 8;

	/**
	 * Hard upper bound on synchronous self-refine iterations regardless of profile setting.
	 */
	const MAX_REFINE_ITERATIONS = 4;

	/**
	 * Default cost ceiling per request in USD when the profile does not specify one.
	 */
	const DEFAULT_COST_CEILING_USD = 1.0;

	/**
	 * Build the canonical "off" profile. Every layer disabled.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'          => false,
			'cues'             => array(),
			'reasoning'        => array(
				'enabled'   => false,
				'n_samples' => 1,
				'max_iters' => 1,
			),
			'tools'            => array(
				'router'         => 'fixed',
				'preset_weights' => array(),
			),
			'retrieval'        => array(
				'enabled'           => false,
				'k'                 => 5,
				'require_citations' => false,
			),
			'refine'           => array(
				'enabled'   => false,
				'max_iters' => 1,
			),
			'memory'           => array(
				'scoped'     => false,
				'task_class' => 'general',
				'pii_filter' => true,
			),
			'guardrails'       => array(
				'enabled'        => false,
				'strictness'     => 'medium',
				'mode'           => 'warn',
				'allowed_topics' => array(),
			),
			'necessity_gate'   => array(
				'enabled'                           => false,
				'strictness'                        => 'medium',
				'auto_skip'                         => true,
				'require_approval_for_irreversible' => true,
			),
			'output_guard'     => array(
				'enabled'    => false,
				'mode'       => 'warn',
				'strictness' => 'medium',
			),
			'citation_verify'  => array(
				'enabled'             => false,
				'min_similarity'      => 0.3,
				'annotate_unverified' => true,
			),
			'evals_enabled'    => array(),
			'verifiers'        => array(),
			'trace_capture'    => array(
				'enabled'        => false,
				'retention_runs' => 50,
			),
			'cost_ceiling_usd' => self::DEFAULT_COST_CEILING_USD,
		);
	}

	/**
	 * Sanitize and clamp an arbitrary input array to a valid profile.
	 *
	 * @param mixed $raw Raw input (array, JSON string, or anything else).
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

		$out = self::defaults();

		if ( isset( $raw['enabled'] ) ) {
			$out['enabled'] = (bool) $raw['enabled'];
		}

		if ( isset( $raw['cues'] ) && is_array( $raw['cues'] ) ) {
			$cues = array();
			foreach ( $raw['cues'] as $cue ) {
				$slug = sanitize_key( (string) $cue );
				if ( '' !== $slug ) {
					$cues[] = $slug;
				}
			}
			$out['cues'] = array_values( array_unique( $cues ) );
		}

		if ( isset( $raw['reasoning'] ) && is_array( $raw['reasoning'] ) ) {
			$r                             = $raw['reasoning'];
			$out['reasoning']['enabled']   = ! empty( $r['enabled'] );
			$out['reasoning']['n_samples'] = self::clamp_int(
				isset( $r['n_samples'] ) ? $r['n_samples'] : 1,
				1,
				self::MAX_REASONING_SAMPLES
			);
			$out['reasoning']['max_iters'] = self::clamp_int(
				isset( $r['max_iters'] ) ? $r['max_iters'] : 1,
				1,
				self::MAX_REFINE_ITERATIONS
			);
		}

		if ( isset( $raw['tools'] ) && is_array( $raw['tools'] ) ) {
			$router                 = isset( $raw['tools']['router'] ) ? sanitize_key( (string) $raw['tools']['router'] ) : 'fixed';
			$out['tools']['router'] = in_array( $router, array( 'fixed', 'scored' ), true ) ? $router : 'fixed';

			// Layer C preset-weights matrix: preset_slug → float weight, clamped to [-5, 5].
			// Preset slugs are taken at face value (sanitize_key only) — the canonical
			// list lives in WP_MCP_AI_Tool_Presets_Helper, but profiles may reference
			// presets that have been hidden or come from a Pro addon. Unknown slugs
			// simply have no effect at scoring time.
			if ( isset( $raw['tools']['preset_weights'] ) && is_array( $raw['tools']['preset_weights'] ) ) {
				$weights = array();
				foreach ( $raw['tools']['preset_weights'] as $preset_slug => $weight ) {
					$slug = sanitize_key( (string) $preset_slug );
					if ( '' === $slug ) {
						continue;
					}
					$value = (float) $weight;
					if ( $value < -5.0 ) {
						$value = -5.0;
					}
					if ( $value > 5.0 ) {
						$value = 5.0;
					}
					if ( 0.0 === $value ) {
						// Drop zero entries to keep the stored profile compact.
						continue;
					}
					$weights[ $slug ] = $value;
				}
				$out['tools']['preset_weights'] = $weights;
			}
		}

		if ( isset( $raw['retrieval'] ) && is_array( $raw['retrieval'] ) ) {
			$rt                                    = $raw['retrieval'];
			$out['retrieval']['enabled']           = ! empty( $rt['enabled'] );
			$out['retrieval']['k']                 = self::clamp_int( isset( $rt['k'] ) ? $rt['k'] : 5, 1, 50 );
			$out['retrieval']['require_citations'] = ! empty( $rt['require_citations'] );
		}

		if ( isset( $raw['refine'] ) && is_array( $raw['refine'] ) ) {
			$rf                         = $raw['refine'];
			$out['refine']['enabled']   = ! empty( $rf['enabled'] );
			$out['refine']['max_iters'] = self::clamp_int(
				isset( $rf['max_iters'] ) ? $rf['max_iters'] : 1,
				1,
				self::MAX_REFINE_ITERATIONS
			);
		}

		if ( isset( $raw['memory'] ) && is_array( $raw['memory'] ) ) {
			$m                           = $raw['memory'];
			$out['memory']['scoped']     = ! empty( $m['scoped'] );
			$out['memory']['task_class'] = isset( $m['task_class'] ) ? sanitize_key( (string) $m['task_class'] ) : 'general';
			if ( '' === $out['memory']['task_class'] ) {
				$out['memory']['task_class'] = 'general';
			}
			$out['memory']['pii_filter'] = isset( $m['pii_filter'] ) ? (bool) $m['pii_filter'] : true;
		}

		if ( isset( $raw['guardrails'] ) && is_array( $raw['guardrails'] ) ) {
			$g                               = $raw['guardrails'];
			$out['guardrails']['enabled']    = ! empty( $g['enabled'] );
			$out['guardrails']['strictness'] = isset( $g['strictness'] ) && in_array( (string) $g['strictness'], array( 'low', 'medium', 'high' ), true )
				? (string) $g['strictness']
				: 'medium';
			$out['guardrails']['mode']       = isset( $g['mode'] ) && in_array( (string) $g['mode'], array( 'block', 'warn', 'log' ), true )
				? (string) $g['mode']
				: 'warn';
			if ( isset( $g['allowed_topics'] ) && is_array( $g['allowed_topics'] ) ) {
				$topics = array();
				foreach ( $g['allowed_topics'] as $topic ) {
					$topic = trim( sanitize_text_field( (string) $topic ) );
					if ( '' !== $topic ) {
						$topics[] = $topic;
					}
				}
				$out['guardrails']['allowed_topics'] = array_values( array_unique( $topics ) );
			}
		}

		if ( isset( $raw['trace_capture'] ) && is_array( $raw['trace_capture'] ) ) {
			$tc                                     = $raw['trace_capture'];
			$out['trace_capture']['enabled']        = ! empty( $tc['enabled'] );
			$out['trace_capture']['retention_runs'] = self::clamp_int(
				isset( $tc['retention_runs'] ) ? $tc['retention_runs'] : 50,
				10,
				200
			);
		}

		if ( isset( $raw['evals_enabled'] ) && is_array( $raw['evals_enabled'] ) ) {
			$evals = array();
			foreach ( $raw['evals_enabled'] as $eval_slug ) {
				$slug = sanitize_key( (string) $eval_slug );
				if ( '' !== $slug ) {
					$evals[] = $slug;
				}
			}
			$out['evals_enabled'] = array_values( array_unique( $evals ) );
		}

		if ( isset( $raw['verifiers'] ) && is_array( $raw['verifiers'] ) ) {
			$verifiers = array();
			foreach ( $raw['verifiers'] as $verifier_slug ) {
				$slug = sanitize_key( (string) $verifier_slug );
				if ( '' !== $slug ) {
					$verifiers[] = $slug;
				}
			}
			$out['verifiers'] = array_values( array_unique( $verifiers ) );
		}

		if ( isset( $raw['cost_ceiling_usd'] ) ) {
			$ceiling = (float) $raw['cost_ceiling_usd'];
			if ( $ceiling < 0 ) {
				$ceiling = 0.0;
			}
			if ( $ceiling > 1000.0 ) {
				$ceiling = 1000.0;
			}
			$out['cost_ceiling_usd'] = $ceiling;
		}

		return $out;
	}

	/**
	 * Clamp an integer into a range.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Lower bound (inclusive).
	 * @param int   $max   Upper bound (inclusive).
	 * @return int
	 */
	private static function clamp_int( $value, $min, $max ) {
		$value = (int) $value;
		if ( $value < $min ) {
			return $min;
		}
		if ( $value > $max ) {
			return $max;
		}
		return $value;
	}

	/**
	 * Load the profile for a given assistant.
	 *
	 * @param int $assistant_id Assistant post ID. Use 0 for the global default.
	 * @return array Sanitized profile.
	 */
	public static function get( $assistant_id ) {
		$assistant_id = (int) $assistant_id;
		$raw          = '';

		if ( $assistant_id > 0 ) {
			$raw = get_post_meta( $assistant_id, self::META_KEY, true );
		}

		if ( empty( $raw ) ) {
			$global = get_option( 'wp_mcp_ai_harness_profile_default', '' );
			if ( ! empty( $global ) ) {
				$raw = $global;
			}
		}

		if ( empty( $raw ) ) {
			$profile = self::defaults();
		} else {
			$profile = self::sanitize( $raw );
		}

		/**
		 * Filter the resolved harness profile for an assistant.
		 *
		 * @param array $profile      Sanitized profile.
		 * @param int   $assistant_id Assistant post ID (0 = global).
		 */
		return apply_filters( 'wp_mcp_ai_harness_profile', $profile, $assistant_id );
	}

	/**
	 * Persist the profile for an assistant.
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $profile      Profile to save (will be sanitized).
	 * @return bool True on success, false on failure or insufficient permissions.
	 */
	public static function save( $assistant_id, array $profile ) {
		$assistant_id = (int) $assistant_id;
		if ( $assistant_id <= 0 ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $assistant_id ) ) {
			return false;
		}

		$clean   = self::sanitize( $profile );
		$encoded = wp_json_encode( $clean );
		if ( false === $encoded ) {
			return false;
		}

		return (bool) update_post_meta( $assistant_id, self::META_KEY, $encoded );
	}

	/**
	 * Convenience: is a particular harness layer enabled for an assistant?
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $layer        One of: prompt, reasoning, tool_router, retrieval, refine, memory, guardrails.
	 * @return bool
	 */
	public static function is_layer_enabled( $assistant_id, $layer ) {
		$profile = self::get( $assistant_id );

		if ( empty( $profile['enabled'] ) ) {
			return false;
		}

		switch ( $layer ) {
			case 'prompt':
				return ! empty( $profile['cues'] );
			case 'reasoning':
				return ! empty( $profile['reasoning']['enabled'] );
			case 'tool_router':
				return isset( $profile['tools']['router'] ) && 'scored' === $profile['tools']['router'];
			case 'retrieval':
				return ! empty( $profile['retrieval']['enabled'] );
			case 'refine':
				return ! empty( $profile['refine']['enabled'] );
			case 'memory':
				return ! empty( $profile['memory']['scoped'] );
			case 'guardrails':
				return ! empty( $profile['guardrails']['enabled'] );
			default:
				return false;
		}
	}
}
