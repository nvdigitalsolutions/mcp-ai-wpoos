<?php
/**
 * Algorave Tool — Export MIDI
 *
 * Exports a pattern as a downloadable MIDI file. Converts Strudel/Tone.js
 * note data into standard MIDI format on the server side.
 *
 * @package NV_oOS_Algorave_AI
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports patterns as MIDI files.
 *
 * @since 1.0.0
 */
class NV_oOS_Algorave_Tool_Export_MIDI implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'algorave_export_midi';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export MIDI', 'nvoos-algorave-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Export a music pattern as a MIDI file. Provide note data (note names, durations, velocities) and the tool will generate a downloadable .mid file. Use this when the user wants to save a pattern as MIDI for use in a DAW.', 'nvoos-algorave-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'       => array(
					'type'        => 'string',
					'description' => __( 'Filename for the MIDI export (without extension).', 'nvoos-algorave-ai' ),
					'default'     => 'algorave-pattern',
					'maxLength'   => 200,
				),
				'bpm'        => array(
					'type'        => 'integer',
					'description' => __( 'Tempo in BPM.', 'nvoos-algorave-ai' ),
					'default'     => 120,
					'minimum'     => 20,
					'maximum'     => 300,
				),
				'notes'      => array(
					'type'        => 'array',
					'description' => __( 'Array of note objects to include in the MIDI file.', 'nvoos-algorave-ai' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'note'     => array(
								'type'        => 'string',
								'description' => __( 'MIDI note name (e.g. "C4", "Eb3") or MIDI number (0-127).', 'nvoos-algorave-ai' ),
							),
							'time'     => array(
								'type'        => 'number',
								'description' => __( 'Start time in beats from the beginning.', 'nvoos-algorave-ai' ),
							),
							'duration' => array(
								'type'        => 'number',
								'description' => __( 'Duration in beats.', 'nvoos-algorave-ai' ),
								'default'     => 1,
							),
							'velocity' => array(
								'type'        => 'integer',
								'description' => __( 'Velocity (0-127).', 'nvoos-algorave-ai' ),
								'default'     => 100,
								'minimum'     => 0,
								'maximum'     => 127,
							),
							'channel'  => array(
								'type'        => 'integer',
								'description' => __( 'MIDI channel (0-15).', 'nvoos-algorave-ai' ),
								'default'     => 0,
								'minimum'     => 0,
								'maximum'     => 15,
							),
						),
					),
				),
				'pattern_id' => array(
					'type'        => 'integer',
					'description' => __( 'Optional: ID of a saved pattern to export.', 'nvoos-algorave-ai' ),
				),
			),
			'required'             => array( 'notes' ),
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
		$notes = $arguments['notes'] ?? array();

		if ( empty( $notes ) || ! is_array( $notes ) ) {
			return array(
				'success' => false,
				'error'   => __( 'At least one note is required to generate a MIDI file.', 'nvoos-algorave-ai' ),
			);
		}

		$name = sanitize_file_name( $arguments['name'] ?? 'algorave-pattern' );
		$bpm  = isset( $arguments['bpm'] ) ? max( 20, min( 300, absint( $arguments['bpm'] ) ) ) : 120;

		// Generate MIDI binary data.
		$midi_data = $this->generate_midi( $notes, $bpm );

		if ( is_wp_error( $midi_data ) ) {
			return array(
				'success' => false,
				'error'   => $midi_data->get_error_message(),
			);
		}

		// Save to uploads directory.
		$upload_dir = wp_upload_dir();
		$subdir     = '/algorave-midi';
		$dir        = $upload_dir['basedir'] . $subdir;

		if ( ! wp_mkdir_p( $dir ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Could not create MIDI export directory.', 'nvoos-algorave-ai' ),
			);
		}

		$filename = $name . '-' . time() . '.mid';
		$filepath = $dir . '/' . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $filepath, $midi_data );

		if ( false === $written ) {
			return array(
				'success' => false,
				'error'   => __( 'Failed to write MIDI file.', 'nvoos-algorave-ai' ),
			);
		}

		$url = $upload_dir['baseurl'] . $subdir . '/' . $filename;

		return array(
			'success'    => true,
			'url'        => esc_url( $url ),
			'filename'   => $filename,
			'note_count' => count( $notes ),
			'bpm'        => $bpm,
			'message'    => sprintf(
				/* translators: 1: number of notes, 2: download URL */
				__( 'MIDI file exported with %1$d notes. Download: %2$s', 'nvoos-algorave-ai' ),
				count( $notes ),
				$url
			),
		);
	}

	/**
	 * Generate a Standard MIDI File (SMF) from note data.
	 *
	 * Builds a minimal Type 0 MIDI file with the given notes.
	 *
	 * @param array $notes Array of note objects.
	 * @param int   $bpm   Tempo in BPM.
	 * @return string|WP_Error Binary MIDI data or error.
	 */
	private function generate_midi( $notes, $bpm ) {
		$ticks_per_beat = 480;

		// Build track events.
		$events = array();

		// Tempo meta event.
		$microseconds_per_beat = intval( 60000000 / $bpm );
		$events[]              = array(
			'time' => 0,
			'data' => pack( 'C', 0x00 ) . "\xFF\x51\x03" . $this->pack_24bit( $microseconds_per_beat ),
		);

		// Note events.
		foreach ( $notes as $note_data ) {
			$midi_note = $this->note_name_to_midi( $note_data['note'] ?? 'C4' );
			$velocity  = isset( $note_data['velocity'] ) ? max( 0, min( 127, absint( $note_data['velocity'] ) ) ) : 100;
			$channel   = isset( $note_data['channel'] ) ? max( 0, min( 15, absint( $note_data['channel'] ) ) ) : 0;
			$time      = isset( $note_data['time'] ) ? floatval( $note_data['time'] ) : 0;
			$duration  = isset( $note_data['duration'] ) ? floatval( $note_data['duration'] ) : 1;

			$start_tick = intval( $time * $ticks_per_beat );
			$end_tick   = intval( ( $time + $duration ) * $ticks_per_beat );

			// Note On.
			$events[] = array(
				'time' => $start_tick,
				'data' => pack( 'CCC', 0x90 | $channel, $midi_note, $velocity ),
			);

			// Note Off.
			$events[] = array(
				'time' => $end_tick,
				'data' => pack( 'CCC', 0x80 | $channel, $midi_note, 0 ),
			);
		}

		// Sort by time.
		usort(
			$events,
			function ( $a, $b ) {
				return $a['time'] - $b['time'];
			}
		);

		// Build track data with delta times.
		$track_data = '';
		$last_tick  = 0;

		foreach ( $events as $event ) {
			$delta       = max( 0, $event['time'] - $last_tick );
			$track_data .= $this->encode_variable_length( $delta ) . $event['data'];
			$last_tick   = $event['time'];
		}

		// End of track.
		$track_data .= "\x00\xFF\x2F\x00";

		// Build MIDI file.
		$header  = 'MThd';
		$header .= pack( 'N', 6 ); // Header length.
		$header .= pack( 'nnn', 0, 1, $ticks_per_beat ); // Format 0, 1 track.

		$track  = 'MTrk';
		$track .= pack( 'N', strlen( $track_data ) );
		$track .= $track_data;

		return $header . $track;
	}

	/**
	 * Convert a note name to MIDI number.
	 *
	 * @param string $note Note name (e.g. "C4", "Eb3") or numeric MIDI value.
	 * @return int MIDI note number (0-127).
	 */
	private function note_name_to_midi( $note ) {
		if ( is_numeric( $note ) ) {
			return max( 0, min( 127, intval( $note ) ) );
		}

		$note_map = array(
			'C' => 0,
			'D' => 2,
			'E' => 4,
			'F' => 5,
			'G' => 7,
			'A' => 9,
			'B' => 11,
		);

		$note = trim( $note );

		if ( ! preg_match( '/^([A-Ga-g])(#|b)?(\d+)$/i', $note, $matches ) ) {
			return 60; // Default to middle C.
		}

		$letter = strtoupper( $matches[1] );
		$base   = $note_map[ $letter ] ?? 0;
		$octave = intval( $matches[3] );

		if ( '#' === ( $matches[2] ?? '' ) ) {
			++$base;
		} elseif ( 'b' === ( $matches[2] ?? '' ) ) {
			--$base;
		}

		return max( 0, min( 127, ( $octave + 1 ) * 12 + $base ) );
	}

	/**
	 * Encode a variable-length quantity for MIDI.
	 *
	 * @param int $value Value to encode.
	 * @return string Binary encoded bytes.
	 */
	private function encode_variable_length( $value ) {
		$value  = max( 0, intval( $value ) );
		$result = chr( $value & 0x7F );

		$value >>= 7;
		while ( $value > 0 ) {
			$result  = chr( ( $value & 0x7F ) | 0x80 ) . $result;
			$value >>= 7;
		}

		return $result;
	}

	/**
	 * Pack a 24-bit big-endian integer.
	 *
	 * @param int $value 24-bit value.
	 * @return string 3 bytes.
	 */
	private function pack_24bit( $value ) {
		return pack( 'CCC', ( $value >> 16 ) & 0xFF, ( $value >> 8 ) & 0xFF, $value & 0xFF );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'write', 'local-only' );
	}
}
