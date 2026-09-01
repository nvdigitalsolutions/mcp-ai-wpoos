<?php
/**
 * Chat bubble block for the Content Graph AI addon.
 *
 * Aligned port of the base plugin's `mcp-ai-wpoos/chat-bubble` block: a
 * floating trigger button + hidden chat panel shell (position / size /
 * animation / tooltip / panel title / colors as CSS custom properties)
 * embedding the ecosystem chat widget
 * (`[nvoos_content_graph_chat]`, Wave D-UI-1b). Class names use the
 * ecosystem's `nvoos-cg-bubble` prefix so monolith installs can run both
 * bubbles side by side without style collisions (documented deviation).
 *
 * The base bubble defers chat initialisation until first open (its
 * chat.js bundle is heavy); the CG widget is small enough to initialise
 * eagerly while hidden (documented simplification).
 *
 * @package NvoosContentGraphAi\Blocks
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `nvoos-content-graph-ai/chat-bubble` block.
 *
 * @since 1.1.0
 */
class ChatBubbleBlock {

	/**
	 * Block name.
	 */
	const BLOCK_NAME = 'nvoos-content-graph-ai/chat-bubble';

	/**
	 * Block metadata (title/icon/category/attributes).
	 *
	 * @return array
	 */
	public static function metadata(): array {
		return array(
			'apiVersion' => 3,
			'title'      => __( 'AI Chat Bubble', 'nvoos-content-graph-ai' ),
			'category'   => 'nvoos-content-graph-ai',
			'icon'       => 'format-status',
			'description' => __( 'Display a floating chat bubble that opens an AI chat panel powered by NV oOS Content Graph.', 'nvoos-content-graph-ai' ),
			'keywords'   => array( 'ai', 'chat', 'bubble', 'floating', 'widget' ),
			'attributes' => array(
				'assistantId'     => array(
					'type'    => 'number',
					'default' => 0,
				),
				'allowGuests'     => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'provider'        => array(
					'type'    => 'string',
					'default' => '',
				),
				'model'           => array(
					'type'    => 'string',
					'default' => '',
				),
				'bubblePosition'  => array(
					'type'    => 'string',
					'default' => 'bottom-right',
					'enum'    => array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' ),
				),
				'bubbleSize'      => array(
					'type'    => 'string',
					'default' => 'medium',
					'enum'    => array( 'small', 'medium', 'large' ),
				),
				'bubbleAnimation' => array(
					'type'    => 'string',
					'default' => 'bounce',
					'enum'    => array( 'bounce', 'pulse', 'none' ),
				),
				'bubbleTooltip'   => array(
					'type'    => 'string',
					'default' => '',
				),
				'panelTitle'      => array(
					'type'    => 'string',
					'default' => 'Chat with AI',
				),
				'panelWidth'      => array(
					'type'    => 'number',
					'default' => 400,
				),
				'panelHeight'     => array(
					'type'    => 'number',
					'default' => 550,
				),
				'autoOpenDelay'   => array(
					'type'    => 'number',
					'default' => 0,
				),
				'rememberState'   => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'notificationBadge' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'bubbleColor'     => array(
					'type'    => 'string',
					'default' => '#4f46e5',
				),
				'bubbleTextColor' => array(
					'type'    => 'string',
					'default' => '#ffffff',
				),
				'headerBackground' => array(
					'type'    => 'string',
					'default' => '',
				),
				'headerTextColor' => array(
					'type'    => 'string',
					'default' => '#ffffff',
				),
			),
			'supports'   => array(
				'anchor' => true,
				'html'   => false,
			),
		);
	}

	/**
	 * Server-side render callback.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Inner block content (unused).
	 * @param WP_Block $block      Block instance.
	 * @return string Rendered block HTML.
	 */
	public static function render( array $attributes, string $content, \WP_Block $block ): string {
		unset( $content, $block );

		// Chat attributes.
		$assistant_id = isset( $attributes['assistantId'] ) ? absint( $attributes['assistantId'] ) : 0;
		$allow_guests = ! empty( $attributes['allowGuests'] );
		$provider     = isset( $attributes['provider'] ) ? sanitize_text_field( (string) $attributes['provider'] ) : '';
		$model        = isset( $attributes['model'] ) ? sanitize_text_field( (string) $attributes['model'] ) : '';

		// Bubble appearance.
		$bubble_position  = isset( $attributes['bubblePosition'] ) ? sanitize_key( $attributes['bubblePosition'] ) : 'bottom-right';
		$bubble_size      = isset( $attributes['bubbleSize'] ) ? sanitize_key( $attributes['bubbleSize'] ) : 'medium';
		$bubble_animation = isset( $attributes['bubbleAnimation'] ) ? sanitize_key( $attributes['bubbleAnimation'] ) : 'bounce';
		$bubble_tooltip   = isset( $attributes['bubbleTooltip'] ) ? trim( sanitize_text_field( (string) $attributes['bubbleTooltip'] ) ) : '';

		// Panel settings.
		$panel_title  = isset( $attributes['panelTitle'] ) ? sanitize_text_field( (string) $attributes['panelTitle'] ) : __( 'Chat with AI', 'nvoos-content-graph-ai' );
		$panel_width  = isset( $attributes['panelWidth'] ) ? absint( $attributes['panelWidth'] ) : 400;
		$panel_height = isset( $attributes['panelHeight'] ) ? absint( $attributes['panelHeight'] ) : 550;

		// Behavior.
		$auto_open_delay    = isset( $attributes['autoOpenDelay'] ) ? absint( $attributes['autoOpenDelay'] ) : 0;
		$remember_state     = ! empty( $attributes['rememberState'] );
		$notification_badge = ! empty( $attributes['notificationBadge'] );

		// Colors.
		$bubble_color      = isset( $attributes['bubbleColor'] ) ? sanitize_hex_color( $attributes['bubbleColor'] ) : '#4f46e5';
		$bubble_text_color = isset( $attributes['bubbleTextColor'] ) ? sanitize_hex_color( $attributes['bubbleTextColor'] ) : '#ffffff';
		$header_background = isset( $attributes['headerBackground'] ) ? sanitize_hex_color( $attributes['headerBackground'] ) : '';
		$header_text_color = isset( $attributes['headerTextColor'] ) ? sanitize_hex_color( $attributes['headerTextColor'] ) : '#ffffff';

		wp_enqueue_script( 'nvoos-content-graph-ai-chat-bubble' );
		wp_enqueue_style( 'nvoos-content-graph-ai-chat-bubble-style' );

		// Shortcode attributes for the embedded widget.
		$shortcode_atts = array();
		if ( $assistant_id ) {
			$shortcode_atts[] = 'assistant="' . $assistant_id . '"';
		}
		if ( $allow_guests ) {
			$shortcode_atts[] = 'allow_guests="true"';
		}
		if ( '' !== $provider ) {
			$shortcode_atts[] = 'provider="' . esc_attr( $provider ) . '"';
		}
		if ( '' !== $model ) {
			$shortcode_atts[] = 'model="' . esc_attr( $model ) . '"';
		}
		$shortcode = '[nvoos_content_graph_chat ' . implode( ' ', $shortcode_atts ) . ']';

		// CSS custom properties.
		$css_vars = array();

		if ( '#4f46e5' !== $bubble_color && '' !== $bubble_color ) {
			$css_vars[] = '--nvoos-cg-bubble-color:' . $bubble_color;
		}
		if ( '#ffffff' !== $bubble_text_color && '' !== $bubble_text_color ) {
			$css_vars[] = '--nvoos-cg-bubble-text-color:' . $bubble_text_color;
		}
		if ( '' !== $header_background ) {
			$css_vars[] = '--nvoos-cg-bubble-header-background:' . $header_background;
		}
		if ( '#ffffff' !== $header_text_color && '' !== $header_text_color ) {
			$css_vars[] = '--nvoos-cg-bubble-header-text-color:' . $header_text_color;
		}
		if ( 400 !== $panel_width ) {
			$css_vars[] = '--nvoos-cg-bubble-panel-width:' . $panel_width . 'px';
		}
		if ( 550 !== $panel_height ) {
			$css_vars[] = '--nvoos-cg-bubble-panel-height:' . $panel_height . 'px';
		}

		$css_vars_string = implode( ';', $css_vars );

		// Classes + data attributes.
		$bubble_id = 'nvoos-cg-bubble-' . wp_unique_id();

		$classes  = 'nvoos-cg-bubble';
		$classes .= ' nvoos-cg-bubble--' . $bubble_position;
		$classes .= ' nvoos-cg-bubble--' . $bubble_size;
		if ( 'none' !== $bubble_animation ) {
			$classes .= ' nvoos-cg-bubble--' . $bubble_animation;
		}

		$data_attrs  = 'data-bubble-id="' . esc_attr( $bubble_id ) . '"';
		$data_attrs .= ' data-auto-open-delay="' . esc_attr( (string) $auto_open_delay ) . '"';
		$data_attrs .= ' data-remember-state="' . esc_attr( $remember_state ? 'true' : 'false' ) . '"';
		$data_attrs .= ' data-notification-badge="' . esc_attr( $notification_badge ? 'true' : 'false' ) . '"';

		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class' => 'wp-block-nvoos-content-graph-ai-chat-bubble',
			)
		);

		$html  = '<div ' . $wrapper_attributes . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitised by get_block_wrapper_attributes().
		$html .= '<div class="' . esc_attr( $classes ) . '" ' . $data_attrs . ' style="' . esc_attr( $css_vars_string ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $data_attrs built with esc_attr(); $css_vars_string built from sanitize_hex_color() + absint().

		$html .= '<button class="nvoos-cg-bubble__trigger" aria-expanded="false" aria-label="' . esc_attr__( 'Open chat', 'nvoos-content-graph-ai' ) . '">';
		$html .= '<span class="nvoos-cg-bubble__trigger-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>';
		$html .= '<span class="nvoos-cg-bubble__trigger-close-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span>';
		$html .= '<span class="nvoos-cg-bubble__badge" aria-hidden="true"' . ( $notification_badge ? '' : ' hidden' ) . '></span>';
		$html .= '</button>';

		if ( '' !== $bubble_tooltip ) {
			$html .= '<span class="nvoos-cg-bubble__tooltip">' . esc_html( $bubble_tooltip ) . '</span>';
		}

		$html .= '<div class="nvoos-cg-bubble__panel" role="dialog" aria-label="' . esc_attr( $panel_title ) . '" aria-hidden="true" inert>';
		$html .= '<div class="nvoos-cg-bubble__panel-header">';
		$html .= '<span class="nvoos-cg-bubble__panel-title">' . esc_html( $panel_title ) . '</span>';
		$html .= '<button class="nvoos-cg-bubble__panel-close" aria-label="' . esc_attr__( 'Close chat', 'nvoos-content-graph-ai' ) . '">';
		$html .= '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
		$html .= '</button>';
		$html .= '</div>';

		$html .= '<div class="nvoos-cg-bubble__panel-body">';
		$html .= do_shortcode( $shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Widget output escapes every value internally.
		$html .= '</div>';

		$html .= '</div>'; // Panel.
		$html .= '</div>'; // Bubble.
		$html .= '</div>'; // Wrapper.

		return $html;
	}
}
