<?php
/**
 * Frontend chat widget shortcode for the Content Graph AI addon.
 *
 * Implements the `[nvoos_content_graph_chat]` widget per
 * `CHAT-SHORTCODE-PLAN.md`: a lean, framework-free chat widget speaking
 * the same REST + SSE contract as the admin tester and the Pro SPA-v2
 * (POST `/nvoos-content-graph/v1/ai/chat` with SSE streaming). The base
 * plugin's `[mcp_ai_chat]` widget (`assets/js/chat.js`) stays with the
 * base hub in monolith installs — this is the ecosystem's aligned,
 * lighter implementation (documented deviation: not a byte-port of the
 * base chat.js bundle).
 *
 * Guest access (Wave D-UI-1a): with `allow_guests="true"` and an
 * `assistant` resolved to a published assistant post, the widget issues
 * a guest token via `GuestToken` and injects it into the frontend config;
 * the widget sends it back as the `X-WP-MCP-AI-Guest` header.
 *
 * @package NvoosContentGraphAi\Frontend
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Frontend;

use NvoosContentGraphAi\Chat\GuestToken;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and configures the frontend chat widget.
 *
 * @since 1.1.0
 */
class ChatShortcode {

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE = 'nvoos_content_graph_chat';

	/**
	 * Frontend script handle.
	 */
	const SCRIPT_HANDLE = 'nvoos-content-graph-ai-chat-frontend';

	/**
	 * Frontend style handle.
	 */
	const STYLE_HANDLE = 'nvoos-content-graph-ai-chat-frontend';

	/**
	 * Assistant post type slug (byte-identical to the base plugin).
	 */
	const POST_TYPE = 'mcp_ai_assistant';

	/**
	 * Register the shortcode.
	 *
	 * Registered in both install modes: the tag is ecosystem-specific and
	 * never collides with the base plugin's `[mcp_ai_chat]`.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * Render the chat widget.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string Widget markup.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'assistant'    => '',      // Assistant post ID or slug (guest-token scope).
				'allow_guests' => 'false', // Issue a guest token for logged-out visitors.
				'provider'     => '',      // Force a specific provider.
				'model'        => '',      // Force a specific model.
				'height'       => '500px', // Chat container height.
				'show_cost'    => '1',     // Show the cost badge.
				'placeholder'  => '',      // Custom input placeholder.
			),
			$atts,
			self::SHORTCODE
		);

		$container_id = 'nvoos-content-graph-chat-' . wp_unique_id();

		$this->enqueue_assets();

		$assistant_id = $this->resolve_assistant_id( (string) $atts['assistant'] );
		$allow_guests = wp_validate_boolean( $atts['allow_guests'] );

		$guest_token = '';
		if ( $allow_guests && $assistant_id ) {
			$guest_token = GuestToken::generate_guest_token( $assistant_id );
		}

		$config = array(
			'container'   => $container_id,
			'restUrl'     => rest_url( 'nvoos-content-graph/v1' ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'guestToken'  => is_string( $guest_token ) ? $guest_token : '',
			'provider'    => sanitize_text_field( (string) $atts['provider'] ),
			'model'       => sanitize_text_field( (string) $atts['model'] ),
			'showCost'    => wp_validate_boolean( $atts['show_cost'] ),
			'placeholder' => sanitize_text_field( (string) $atts['placeholder'] ),
			'i18n'        => array(
				'send'       => __( 'Send', 'nvoos-content-graph-ai' ),
				'thinking'   => __( 'Thinking…', 'nvoos-content-graph-ai' ),
				'error'      => __( 'Something went wrong.', 'nvoos-content-graph-ai' ),
				'toolsUsed'  => __( 'Tools used', 'nvoos-content-graph-ai' ),
				'cost'       => __( 'Cost', 'nvoos-content-graph-ai' ),
				'clear'      => __( 'Clear', 'nvoos-content-graph-ai' ),
				'graphQuery' => __( 'Queried your knowledge graph', 'nvoos-content-graph-ai' ),
				'noProvider' => __( 'No AI provider configured. Set an API key in NV Content Graph → AI Providers.', 'nvoos-content-graph-ai' ),
			),
		);

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.NvoosContentGraphChat = window.NvoosContentGraphChat || [];' .
			'window.NvoosContentGraphChat.push( ' .
			wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) .
			' );',
			'before'
		);

		return sprintf(
			'<div id="%s" class="nvoos-content-graph-chat-widget" style="height:%s"></div>',
			esc_attr( $container_id ),
			esc_attr( (string) $atts['height'] )
		);
	}

	/**
	 * Enqueue the widget styles and scripts.
	 *
	 * @return void
	 */
	protected function enqueue_assets(): void {
		wp_enqueue_style(
			self::STYLE_HANDLE,
			NVOOS_CONTENT_GRAPH_AI_URL . 'assets/css/content-graph-ai-chat.css',
			array(),
			NVOOS_CONTENT_GRAPH_AI_VERSION
		);

		wp_enqueue_script(
			'nvoos-content-graph-ai-sse',
			NVOOS_CONTENT_GRAPH_AI_URL . 'assets/js/content-graph-ai-sse.js',
			array(),
			NVOOS_CONTENT_GRAPH_AI_VERSION,
			true
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			NVOOS_CONTENT_GRAPH_AI_URL . 'assets/js/content-graph-ai-chat-frontend.js',
			array( 'nvoos-content-graph-ai-sse' ),
			NVOOS_CONTENT_GRAPH_AI_VERSION,
			true
		);
	}

	/**
	 * Resolve an assistant attribute (ID or slug) to a post ID.
	 *
	 * Mirrors the base shortcode's resolver subset relevant to the widget:
	 * numeric IDs and post slugs against the assistant post type. Returns
	 * 0 when nothing resolves.
	 *
	 * @param string $assistant Raw attribute value.
	 * @return int
	 */
	protected function resolve_assistant_id( string $assistant ): int {
		$assistant = trim( $assistant );
		if ( '' === $assistant ) {
			return 0;
		}

		$maybe_id = absint( $assistant );
		if ( $maybe_id ) {
			$post = get_post( $maybe_id );
			if ( $post && self::POST_TYPE === $post->post_type ) {
				return $maybe_id;
			}
		}

		$slug_candidates = array( $assistant );
		$sanitized       = sanitize_title( $assistant );
		if ( $sanitized && $sanitized !== $assistant ) {
			$slug_candidates[] = $sanitized;
		}

		foreach ( array_unique( $slug_candidates ) as $slug ) {
			$post = get_page_by_path( $slug, OBJECT, self::POST_TYPE );
			if ( $post ) {
				return (int) $post->ID;
			}
		}

		return 0;
	}
}
