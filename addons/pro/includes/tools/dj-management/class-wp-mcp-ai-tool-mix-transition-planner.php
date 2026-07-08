<?php
/**
 * Tool for planning track transitions.
 *
 * Allows AI assistants to plan smooth transitions between tracks for DJ mixes.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plans track transitions for DJ mixes.
 */
class WP_MCP_AI_Tool_Mix_Transition_Planner implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'mix_transition_planner';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Mix Transition Planner', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Plans smooth transitions between DJ tracks. Analyzes BPM, key compatibility, and suggests mix points and techniques.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'track_a_id'       => array(
					'type'        => 'integer',
					'description' => __( 'First track ID (current track) (required)', 'mcp-ai-wpoos-pro' ),
				),
				'track_b_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Second track ID (next track) (required)', 'mcp-ai-wpoos-pro' ),
				),
				'transition_style' => array(
					'type'        => 'string',
					'description' => __( 'Preferred transition style (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'smooth', 'quick', 'hard_cut', 'long_blend', 'echo_out' ),
					'default'     => 'smooth',
				),
				'mix_duration'     => array(
					'type'        => 'integer',
					'description' => __( 'Desired mix duration in seconds (optional, defaults to 16)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 4,
					'maximum'     => 60,
					'default'     => 16,
				),
			),
			'required'             => array( 'track_a_id', 'track_b_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['track_a_id'] ) || empty( $arguments['track_b_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Both track IDs are required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$track_a_id = absint( $arguments['track_a_id'] );
		$track_b_id = absint( $arguments['track_b_id'] );

		// Verify tracks exist.
		if ( ! get_post( $track_a_id ) || get_post_type( $track_a_id ) !== 'dj_track' ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid first track ID.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( ! get_post( $track_b_id ) || get_post_type( $track_b_id ) !== 'dj_track' ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid second track ID.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get track metadata.
		$track_a = array(
			'id'     => $track_a_id,
			'title'  => get_post_meta( $track_a_id, '_title', true ),
			'artist' => get_post_meta( $track_a_id, '_artist', true ),
			'bpm'    => floatval( get_post_meta( $track_a_id, '_bpm', true ) ),
			'key'    => get_post_meta( $track_a_id, '_key', true ),
		);

		$track_b = array(
			'id'     => $track_b_id,
			'title'  => get_post_meta( $track_b_id, '_title', true ),
			'artist' => get_post_meta( $track_b_id, '_artist', true ),
			'bpm'    => floatval( get_post_meta( $track_b_id, '_bpm', true ) ),
			'key'    => get_post_meta( $track_b_id, '_key', true ),
		);

		$transition_style = ! empty( $arguments['transition_style'] ) ? sanitize_text_field( $arguments['transition_style'] ) : 'smooth';
		$mix_duration     = ! empty( $arguments['mix_duration'] ) ? absint( $arguments['mix_duration'] ) : 16;

		// Analyze BPM compatibility.
		$bpm_diff  = abs( $track_a['bpm'] - $track_b['bpm'] );
		$bpm_ratio = $track_a['bpm'] > 0 ? $track_b['bpm'] / $track_a['bpm'] : 0;

		$bpm_compatibility = $this->get_bpm_compatibility( $bpm_diff );

		// Analyze key compatibility.
		$key_compatibility = $this->check_key_compatibility( $track_a['key'], $track_b['key'] );

		// Generate transition suggestions.
		$suggestions = $this->generate_transition_suggestions(
			$track_a,
			$track_b,
			$bpm_compatibility,
			$key_compatibility,
			$transition_style,
			$mix_duration
		);

		// Calculate mix points.
		$mix_points = $this->calculate_mix_points( $transition_style, $mix_duration );

		return array(
			'success'         => true,
			'message'         => __( 'Transition plan generated successfully.', 'mcp-ai-wpoos-pro' ),
			'track_a'         => $track_a,
			'track_b'         => $track_b,
			'compatibility'   => array(
				'bpm_difference'    => round( $bpm_diff, 1 ),
				'bpm_ratio'         => round( $bpm_ratio, 3 ),
				'bpm_compatibility' => $bpm_compatibility,
				'key_compatibility' => $key_compatibility,
			),
			'transition_plan' => array(
				'style'            => $transition_style,
				'duration_seconds' => $mix_duration,
				'mix_points'       => $mix_points,
				'suggestions'      => $suggestions,
			),
		);
	}

	/**
	 * Get BPM compatibility rating.
	 *
	 * @param float $bpm_diff BPM difference.
	 * @return array Compatibility info.
	 */
	private function get_bpm_compatibility( $bpm_diff ) {
		if ( $bpm_diff < 2 ) {
			return array(
				'rating'  => 'excellent',
				'message' => __( 'BPMs are very close - perfect for mixing', 'mcp-ai-wpoos-pro' ),
			);
		} elseif ( $bpm_diff < 5 ) {
			return array(
				'rating'  => 'good',
				'message' => __( 'BPMs are compatible - minor tempo adjustment needed', 'mcp-ai-wpoos-pro' ),
			);
		} elseif ( $bpm_diff < 10 ) {
			return array(
				'rating'  => 'moderate',
				'message' => __( 'Moderate BPM difference - use pitch control', 'mcp-ai-wpoos-pro' ),
			);
		} else {
			return array(
				'rating'  => 'challenging',
				'message' => __( 'Large BPM difference - consider transition effects or hard cut', 'mcp-ai-wpoos-pro' ),
			);
		}
	}

	/**
	 * Check key compatibility for harmonic mixing.
	 *
	 * @param string $key_a First track key.
	 * @param string $key_b Second track key.
	 * @return array Compatibility info.
	 */
	private function check_key_compatibility( $key_a, $key_b ) {
		if ( ! $key_a || ! $key_b ) {
			return array(
				'rating'  => 'unknown',
				'message' => __( 'Key information not available', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Simplified key compatibility check.
		if ( $key_a === $key_b ) {
			return array(
				'rating'  => 'perfect',
				'message' => __( 'Same key - perfect harmonic match', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check if keys are in same family (simplified).
		$key_a_root = substr( $key_a, 0, -1 );
		$key_b_root = substr( $key_b, 0, -1 );

		if ( $key_a_root === $key_b_root ) {
			return array(
				'rating'  => 'good',
				'message' => __( 'Related keys - good harmonic compatibility', 'mcp-ai-wpoos-pro' ),
			);
		}

		return array(
			'rating'  => 'moderate',
			'message' => __( 'Different keys - use EQ mixing or transition effects', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Generate transition suggestions.
	 *
	 * @param array  $track_a First track.
	 * @param array  $track_b Second track.
	 * @param array  $bpm_compatibility BPM compatibility.
	 * @param array  $key_compatibility Key compatibility.
	 * @param string $style Transition style.
	 * @param int    $duration Mix duration.
	 * @return array Suggestions.
	 */
	private function generate_transition_suggestions( $track_a, $track_b, $bpm_compatibility, $key_compatibility, $style, $duration ) {
		$suggestions = array();

		// BPM adjustment suggestions.
		if ( 'moderate' === $bpm_compatibility['rating'] || 'challenging' === $bpm_compatibility['rating'] ) {
			$suggestions[] = sprintf(
				/* translators: %s: BPM value */
				__( 'Use pitch control to adjust Track A to approximately %s BPM', 'mcp-ai-wpoos-pro' ),
				$track_b['bpm']
			);
		}

		// EQ suggestions.
		if ( 'perfect' !== $key_compatibility['rating'] ) {
			$suggestions[] = __( 'Use high-pass filter on incoming track to reduce key clash', 'mcp-ai-wpoos-pro' );
			$suggestions[] = __( 'Gradually swap EQ frequencies during transition', 'mcp-ai-wpoos-pro' );
		}

		// Style-specific suggestions.
		switch ( $style ) {
			case 'smooth':
				$suggestions[] = __( 'Start with bassline exchange at 8-bar phrase', 'mcp-ai-wpoos-pro' );
				$suggestions[] = __( 'Gradually increase incoming track volume over mix duration', 'mcp-ai-wpoos-pro' );
				break;
			case 'quick':
				$suggestions[] = __( 'Quick crossfade over 4 bars', 'mcp-ai-wpoos-pro' );
				$suggestions[] = __( 'Cut bass on outgoing track quickly', 'mcp-ai-wpoos-pro' );
				break;
			case 'hard_cut':
				$suggestions[] = __( 'Cut on the 1st beat of a new phrase', 'mcp-ai-wpoos-pro' );
				$suggestions[] = __( 'Consider using a short reverb/delay tail', 'mcp-ai-wpoos-pro' );
				break;
			case 'long_blend':
				$suggestions[] = __( 'Extended blend over 16-32 bars', 'mcp-ai-wpoos-pro' );
				$suggestions[] = __( 'Keep both tracks at similar energy levels', 'mcp-ai-wpoos-pro' );
				break;
			case 'echo_out':
				$suggestions[] = __( 'Apply echo effect to outgoing track', 'mcp-ai-wpoos-pro' );
				$suggestions[] = __( 'Bring in new track as echo fades', 'mcp-ai-wpoos-pro' );
				break;
		}

		return $suggestions;
	}

	/**
	 * Calculate mix points.
	 *
	 * @param string $style Transition style.
	 * @param int    $duration Mix duration in seconds.
	 * @return array Mix points.
	 */
	private function calculate_mix_points( $style, $duration ) {
		$points = array();

		switch ( $style ) {
			case 'smooth':
				$points[] = array(
					'time'       => 0,
					'action'     => __( 'Start bringing in Track B at low volume', 'mcp-ai-wpoos-pro' ),
					'percentage' => 0,
				);
				$points[] = array(
					'time'       => $duration / 4,
					'action'     => __( 'Begin bassline swap', 'mcp-ai-wpoos-pro' ),
					'percentage' => 25,
				);
				$points[] = array(
					'time'       => $duration / 2,
					'action'     => __( 'Both tracks at equal volume', 'mcp-ai-wpoos-pro' ),
					'percentage' => 50,
				);
				$points[] = array(
					'time'       => ( $duration * 3 ) / 4,
					'action'     => __( 'Begin fading out Track A', 'mcp-ai-wpoos-pro' ),
					'percentage' => 75,
				);
				$points[] = array(
					'time'       => $duration,
					'action'     => __( 'Complete transition to Track B', 'mcp-ai-wpoos-pro' ),
					'percentage' => 100,
				);
				break;

			case 'quick':
				$points[] = array(
					'time'       => 0,
					'action'     => __( 'Cue Track B', 'mcp-ai-wpoos-pro' ),
					'percentage' => 0,
				);
				$points[] = array(
					'time'       => $duration / 2,
					'action'     => __( 'Quick crossfade', 'mcp-ai-wpoos-pro' ),
					'percentage' => 50,
				);
				$points[] = array(
					'time'       => $duration,
					'action'     => __( 'Track B fully in', 'mcp-ai-wpoos-pro' ),
					'percentage' => 100,
				);
				break;

			default:
				$points[] = array(
					'time'       => 0,
					'action'     => __( 'Start transition', 'mcp-ai-wpoos-pro' ),
					'percentage' => 0,
				);
				$points[] = array(
					'time'       => $duration,
					'action'     => __( 'Complete transition', 'mcp-ai-wpoos-pro' ),
					'percentage' => 100,
				);
				break;
		}

		return $points;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read' );
	}
}
