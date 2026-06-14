<?php
/**
 * NV oOS Chat SPA — Shortcode
 *
 * @package NV_oOS_Chat_Spa
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
class NV_oOS_Chat_Spa_Shortcode {

	const SHORTCODE = 'nvoos_chat_spa';

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
				'assistant_id' => '',
				'theme'        => 'auto',
				'height'       => '',
				'guest'        => '0',
			),
			$atts,
			self::SHORTCODE
		);

		$can_render = apply_filters( 'nvoos_chat_spa_can_render', true, $atts );
		if ( ! $can_render ) {
			return '';
		}

		$config = array(
			'assistantId' => absint( $atts['assistant_id'] ),
			'theme'       => in_array( $atts['theme'], array( 'auto', 'light', 'dark' ), true ) ? $atts['theme'] : 'auto',
			'height'      => sanitize_text_field( $atts['height'] ),
			'guest'       => ! empty( $atts['guest'] ) && '0' !== (string) $atts['guest'],
		);

		self::enqueue_assets( $config );

		$config_json = wp_json_encode( $config );
		if ( false === $config_json ) {
			$config_json = '{}';
		}

		$height_attr = '' !== $config['height'] ? 'height:' . $config['height'] . ';' : '';

		return sprintf(
			'<div class="nvoos-chat-spa-root" role="application" aria-label="%1$s" data-config="%2$s" style="%3$s"></div>',
			esc_attr__( 'Chat', 'nvoos-chat-spa' ),
			esc_attr( $config_json ),
			esc_attr( $height_attr )
		);
	}

	/**
	 * Enqueue the SPA bundle.
	 *
	 * @param array $config Per-instance config.
	 * @return void
	 */
	public static function enqueue_assets( $config ) {
		wp_register_style(
			'nvoos-chat-spa',
			NVOOS_CHAT_SPA_URL . 'assets/dist/chat-spa.css',
			array(),
			NVOOS_CHAT_SPA_VERSION
		);
		wp_register_script(
			'nvoos-chat-spa',
			NVOOS_CHAT_SPA_URL . 'assets/dist/chat-spa.js',
			array( 'wp-i18n' ),
			NVOOS_CHAT_SPA_VERSION,
			true
		);
		wp_set_script_translations(
			'nvoos-chat-spa',
			'nvoos-chat-spa',
			NVOOS_CHAT_SPA_PATH . 'languages'
		);
		wp_localize_script(
			'nvoos-chat-spa',
			'NVOOS_CHAT_SPA',
			array(
				'apiUrl'        => esc_url_raw( rest_url( NV_oOS_Chat_Spa_REST::REST_NAMESPACE ) ),
				'proApi'        => esc_url_raw( rest_url( 'mcp-ai-pro/v1' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'config'        => $config,

				/*
					 * Endpoint map — the SPA POSTs to these existing NV oOS chat REST
					 * routes via its custom fetch + SSE → AI SDK Data Stream adapter.
					 * No new chat endpoints are introduced; the WordPress PHP layer
					 * remains the AI provider gateway and orchestrator.
					 */
					'endpoints' => array(
						'chat'        => esc_url_raw( rest_url( 'mcp-ai/v1/chat' ) ),
						'chatClient'  => esc_url_raw( rest_url( 'mcp-ai/v1/chat-client' ) ),
						'transcripts' => esc_url_raw( rest_url( 'mcp-ai/v1/chat-transcripts' ) ),
						'memory'      => esc_url_raw( rest_url( 'mcp-ai/v1/chat-memory' ) ),
						'threads'     => esc_url_raw( rest_url( 'mcp-ai/v1/threads' ) ),
						'approvals'   => current_user_can( 'manage_options' )
							? esc_url_raw( rest_url( 'mcp-ai/v1/approvals' ) )
							: '',
					),
			)
		);
		wp_enqueue_style( 'nvoos-chat-spa' );
		wp_enqueue_script( 'nvoos-chat-spa' );
	}
}
