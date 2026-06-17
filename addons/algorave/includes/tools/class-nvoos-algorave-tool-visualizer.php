<?php
/**
 * Algorave Tool — Visualizer
 *
 * Controls the audio visualization mode in the browser.
 * Supports waveform, spectrum, and other visualization types.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controls the browser-side audio visualizer.
 *
 * @since 1.0.0
 */
class NV_oOS_Algorave_Tool_Visualizer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'algorave_visualizer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Audio Visualizer', 'nvoos-algorave' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Control the audio visualizer in the browser. Change visualization mode (waveform, spectrum, bars, circular, particles, scope, spectrogram, lissajous), adjust colors, toggle fullscreen, or turn the visualizer on/off. Use this when the user wants to change how the audio is displayed visually during live coding.', 'nvoos-algorave' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'           => array(
					'type'        => 'string',
					'description' => __( 'Visualizer action to perform.', 'nvoos-algorave' ),
					'enum'        => array( 'set_mode', 'set_color', 'toggle', 'fullscreen' ),
				),
				'mode'             => array(
					'type'        => 'string',
					'description' => __( 'Visualization mode (used with "set_mode" action).', 'nvoos-algorave' ),
					'enum'        => array( 'waveform', 'spectrum', 'bars', 'circular', 'particles', 'scope', 'spectrogram', 'lissajous' ),
				),
				'color'            => array(
					'type'        => 'string',
					'description' => __( 'Primary color for the visualization (hex code or CSS color name, used with "set_color" action).', 'nvoos-algorave' ),
					'maxLength'   => 50,
				),
				'background_color' => array(
					'type'        => 'string',
					'description' => __( 'Background color (hex code or CSS color name).', 'nvoos-algorave' ),
					'maxLength'   => 50,
				),
				'enabled'          => array(
					'type'        => 'boolean',
					'description' => __( 'Turn visualizer on or off (used with "toggle" action).', 'nvoos-algorave' ),
				),
			),
			'required'             => array( 'action' ),
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
		$action = sanitize_text_field( $arguments['action'] ?? '' );

		$valid_actions = array( 'set_mode', 'set_color', 'toggle', 'fullscreen' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid action. Use: set_mode, set_color, toggle, or fullscreen.', 'nvoos-algorave' ),
			);
		}

		$result = array(
			'success'          => true,
			'action'           => $action,
			'_browser_command' => true,
		);

		switch ( $action ) {
			case 'set_mode':
				$mode        = sanitize_text_field( $arguments['mode'] ?? 'waveform' );
				$valid_modes = array( 'waveform', 'spectrum', 'bars', 'circular', 'particles', 'scope', 'spectrogram', 'lissajous' );
				if ( ! in_array( $mode, $valid_modes, true ) ) {
					return array(
						'success' => false,
						'error'   => __( 'Invalid mode. Use: waveform, spectrum, bars, circular, particles, scope, spectrogram, or lissajous.', 'nvoos-algorave' ),
					);
				}
				$result['mode']    = $mode;
				$result['message'] = sprintf(
					/* translators: %s: visualization mode name */
					__( 'Visualizer mode set to "%s".', 'nvoos-algorave' ),
					$mode
				);
				break;

			case 'set_color':
				$color                      = sanitize_text_field( $arguments['color'] ?? '#00ff88' );
				$bg_color                   = sanitize_text_field( $arguments['background_color'] ?? '#000000' );
				$result['color']            = $color;
				$result['background_color'] = $bg_color;
				$result['message']          = sprintf(
					/* translators: 1: foreground color, 2: background color */
					__( 'Visualizer colors updated: foreground %1$s, background %2$s.', 'nvoos-algorave' ),
					$color,
					$bg_color
				);
				break;

			case 'toggle':
				$enabled           = ! empty( $arguments['enabled'] );
				$result['enabled'] = $enabled;
				$result['message'] = $enabled
					? __( 'Visualizer enabled.', 'nvoos-algorave' )
					: __( 'Visualizer disabled.', 'nvoos-algorave' );
				break;

			case 'fullscreen':
				$result['fullscreen'] = true;
				$result['message']    = __( 'Toggling fullscreen visualizer mode.', 'nvoos-algorave' );
				break;
		}

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'local-only', 'idempotent' );
	}
}
