<?php
/**
 * Tool for analyzing track BPM and key.
 *
 * Allows AI assistants to analyze or record BPM and musical key for tracks.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyzes track BPM and musical key.
 */
class WP_MCP_AI_Tool_Analyze_Track_BPM implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'analyze_track_bpm';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze Track BPM', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes or records BPM (beats per minute) and musical key for DJ tracks. Helps with harmonic mixing and tempo matching.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'track_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Track ID to analyze (required)', 'mcp-ai-wpoos-pro' ),
				),
				'bpm'            => array(
					'type'        => 'number',
					'description' => __( 'Beats per minute (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 300,
				),
				'key'            => array(
					'type'        => 'string',
					'description' => __( 'Musical key in Camelot or standard notation (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 10,
				),
				'energy_level'   => array(
					'type'        => 'integer',
					'description' => __( 'Energy level 1-10 (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 10,
				),
				'time_signature' => array(
					'type'        => 'string',
					'description' => __( 'Time signature (optional, defaults to "4/4")', 'mcp-ai-wpoos-pro' ),
					'default'     => '4/4',
				),
				'detected_by'    => array(
					'type'        => 'string',
					'description' => __( 'Detection method (manual, software, AI) (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'manual', 'software', 'ai' ),
					'default'     => 'manual',
				),
			),
			'required'             => array( 'track_id', 'bpm' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments, array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['track_id'] ) || empty( $arguments['bpm'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Track ID and BPM are required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$track_id = absint( $arguments['track_id'] );

		// Verify track exists.
		if ( ! get_post( $track_id ) || get_post_type( $track_id ) !== 'dj_track' ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid track ID.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Sanitize inputs.
		$bpm            = floatval( $arguments['bpm'] );
		$key            = ! empty( $arguments['key'] ) ? sanitize_text_field( $arguments['key'] ) : '';
		$energy_level   = ! empty( $arguments['energy_level'] ) ? absint( $arguments['energy_level'] ) : 0;
		$time_signature = ! empty( $arguments['time_signature'] ) ? sanitize_text_field( $arguments['time_signature'] ) : '4/4';
		$detected_by    = ! empty( $arguments['detected_by'] ) ? sanitize_text_field( $arguments['detected_by'] ) : 'manual';

		// Validate BPM range.
		if ( $bpm < 1 || $bpm > 300 ) {
			return array(
				'success' => false,
				'error'   => __( 'BPM must be between 1 and 300.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Store analysis data.
		update_post_meta( $track_id, '_bpm', $bpm );
		update_post_meta( $track_id, '_key', $key );
		update_post_meta( $track_id, '_energy_level', $energy_level );
		update_post_meta( $track_id, '_time_signature', $time_signature );
		update_post_meta( $track_id, '_detected_by', $detected_by );
		update_post_meta( $track_id, '_analysis_date', current_time( 'mysql' ) );

		// Calculate tempo category.
		$tempo_category = $this->get_tempo_category( $bpm );
		update_post_meta( $track_id, '_tempo_category', $tempo_category );

		// Get compatible keys for harmonic mixing.
		$compatible_keys = $key ? $this->get_compatible_keys( $key ) : array();

		$track_title = get_the_title( $track_id );

		return array(
			'success'  => true,
			'message'  => sprintf(
				/* translators: 1: track title, 2: BPM */
				__( 'BPM analysis completed for "%1$s". BPM: %2$s', 'mcp-ai-wpoos-pro' ),
				$track_title,
				$bpm
			),
			'track_id' => $track_id,
			'analysis' => array(
				'bpm'             => $bpm,
				'key'             => $key,
				'energy_level'    => $energy_level,
				'time_signature'  => $time_signature,
				'tempo_category'  => $tempo_category,
				'detected_by'     => $detected_by,
				'compatible_keys' => $compatible_keys,
			),
		);
	}

	/**
	 * Get tempo category based on BPM.
	 *
	 * @param float $bpm BPM value.
	 * @return string Tempo category.
	 */
	private function get_tempo_category( $bpm ) {
		if ( $bpm < 60 ) {
			return 'very_slow';
		} elseif ( $bpm < 90 ) {
			return 'slow';
		} elseif ( $bpm < 120 ) {
			return 'moderate';
		} elseif ( $bpm < 140 ) {
			return 'upbeat';
		} elseif ( $bpm < 160 ) {
			return 'fast';
		} else {
			return 'very_fast';
		}
	}

	/**
	 * Get compatible keys for harmonic mixing using Camelot Wheel.
	 *
	 * @param string $key Musical key.
	 * @return array Compatible keys.
	 */
	private function get_compatible_keys( $key ) {
		// Simplified Camelot Wheel compatibility.
		$camelot_wheel = array(
			'1A'  => array( '12A', '1A', '2A', '1B' ),
			'2A'  => array( '1A', '2A', '3A', '2B' ),
			'3A'  => array( '2A', '3A', '4A', '3B' ),
			'4A'  => array( '3A', '4A', '5A', '4B' ),
			'5A'  => array( '4A', '5A', '6A', '5B' ),
			'6A'  => array( '5A', '6A', '7A', '6B' ),
			'7A'  => array( '6A', '7A', '8A', '7B' ),
			'8A'  => array( '7A', '8A', '9A', '8B' ),
			'9A'  => array( '8A', '9A', '10A', '9B' ),
			'10A' => array( '9A', '10A', '11A', '10B' ),
			'11A' => array( '10A', '11A', '12A', '11B' ),
			'12A' => array( '11A', '12A', '1A', '12B' ),
		);

		$key = strtoupper( $key );
		return isset( $camelot_wheel[ $key ] ) ? $camelot_wheel[ $key ] : array();
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
	public function get_flag_capabilities() {
		return array( 'write' );
	}
}
