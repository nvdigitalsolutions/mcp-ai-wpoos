<?php
/**
 * NV oOS LibreChat — Shortcode
 *
 * @package NV_oOS_LibreChat
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode handler.
 *
 * @since 0.1.0
 */
class NV_oOS_LibreChat_Shortcode {

	const SHORTCODE = 'nvoos_librechat';

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'assistant_id' => '0',
				'theme'        => 'dark',
				'height'       => '',
				'guest'        => '0',
			),
			$atts,
			self::SHORTCODE
		);

		$can_render = apply_filters( 'nvoos_librechat_can_render', true, $atts );
		if ( ! $can_render ) {
			return '';
		}

		$settings = NV_oOS_LibreChat_Plugin::get_settings();

		$config = array(
			'assistantId' => absint( $atts['assistant_id'] ) ? absint( $atts['assistant_id'] ) : absint( $settings['default_assistant_id'] ),
			'theme'       => in_array( $atts['theme'], array( 'auto', 'light', 'dark' ), true ) ? $atts['theme'] : $settings['theme'],
			'height'      => sanitize_text_field( $atts['height'] ),
			'guest'       => ! empty( $atts['guest'] ) && '0' !== (string) $atts['guest'],
			'features'    => array(
				'codeInterpreter' => (bool) $settings['enable_code_interpreter'],
				'webSearch'       => (bool) $settings['enable_web_search'],
				'speech'          => (bool) $settings['enable_speech'],
				'artifacts'       => (bool) $settings['enable_artifacts'],
			),
		);

		self::enqueue_assets( $config );

		$config_json = wp_json_encode( $config );
		if ( false === $config_json ) {
			$config_json = '{}';
		}

		$height_style = '' !== $config['height'] ? 'height:' . esc_attr( $config['height'] ) . ';' : '';

		return sprintf(
			'<div class="nvoos-librechat-root" role="application" aria-label="%1$s" data-config="%2$s" style="%3$s"></div>',
			esc_attr__( 'AI Chat', 'nvoos-librechat' ),
			esc_attr( $config_json ),
			$height_style
		);
	}

	/**
	 * Enqueue the SPA bundle.
	 *
	 * @param array $config Per-instance config.
	 * @return void
	 */
	public static function enqueue_assets( $config ) {
		$settings = NV_oOS_LibreChat_Plugin::get_settings();

		$js_path  = NVOOS_LIBRECHAT_PATH . 'assets/dist/librechat.js';
		$css_path = NVOOS_LIBRECHAT_PATH . 'assets/dist/librechat.css';
		$js_ver   = file_exists( $js_path ) ? filemtime( $js_path ) : NVOOS_LIBRECHAT_VERSION;
		$css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : NVOOS_LIBRECHAT_VERSION;

		wp_register_style(
			'nvoos-librechat',
			NVOOS_LIBRECHAT_URL . 'assets/dist/librechat.css',
			array(),
			$css_ver
		);
		wp_register_script(
			'nvoos-librechat',
			NVOOS_LIBRECHAT_URL . 'assets/dist/librechat.js',
			array( 'wp-i18n' ),
			$js_ver,
			true
		);
		wp_set_script_translations(
			'nvoos-librechat',
			'nvoos-librechat',
			NVOOS_LIBRECHAT_PATH . 'languages'
		);

		$base_namespace = defined( 'WP_MCP_AI_REST::REST_NAMESPACE' )
			? WP_MCP_AI_REST::REST_NAMESPACE
			: 'mcp-ai/v1';

		wp_localize_script(
			'nvoos-librechat',
			'NVOOS_LIBRECHAT',
			array(
				'apiUrl'    => esc_url_raw( rest_url( NV_oOS_LibreChat_REST::NAMESPACE ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'config'    => $config,
				'endpoints' => array(
					'chat'             => esc_url_raw( rest_url( $base_namespace . '/chat-client' ) ),
					'transcripts'      => esc_url_raw( rest_url( $base_namespace . '/chat-transcripts' ) ),
					'memory'           => esc_url_raw( rest_url( $base_namespace . '/chat-memory' ) ),
					'codeExecute'      => esc_url_raw( rest_url( NV_oOS_LibreChat_REST::NAMESPACE . '/code/execute' ) ),
					'codeResult'       => esc_url_raw( rest_url( NV_oOS_LibreChat_REST::NAMESPACE . '/code/result' ) ),
					'speechTranscribe' => esc_url_raw( rest_url( NV_oOS_LibreChat_REST::NAMESPACE . '/speech/transcribe' ) ),
					'speechSynthesize' => esc_url_raw( rest_url( NV_oOS_LibreChat_REST::NAMESPACE . '/speech/synthesize' ) ),
				),
				'settings'  => array(
					'theme'                  => $settings['theme'],
					'codeInterpreterTimeout' => absint( $settings['code_interpreter_timeout'] ),
					'maxExecutionsPerHour'   => absint( $settings['max_executions_per_hour'] ),
				),
			)
		);
		wp_enqueue_style( 'nvoos-librechat' );
		wp_enqueue_script( 'nvoos-librechat' );
	}
}
