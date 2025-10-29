<?php
/**
 * Shared text formatting helpers for Elementor widgets.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides helper methods for preparing rich text content in Elementor widgets.
 */
trait WP_MCP_AI_Elementor_Text_Formatting {
    /**
     * Prepare a block of text for display.
     *
     * Applies basic sanitisation and paragraph formatting so that multi-line
     * content entered in Elementor is rendered with predictable spacing.
     *
     * @param string $content Raw content entered in the Elementor control.
     *
     * @return string Sanitised and formatted HTML. Empty string when there is
     *                no content to display.
     */
    protected function format_text_block( $content ) {
        if ( ! is_string( $content ) ) {
            return '';
        }

        $content = trim( $content );

        if ( '' === $content ) {
            return '';
        }

        $clean_content = wp_kses_post( $content );
        $formatted     = wpautop( $clean_content );

        if ( ! is_string( $formatted ) || '' === trim( $formatted ) ) {
            return '';
        }

        $formatted = wp_kses_post( $formatted );

        return apply_filters( 'wp_mcp_ai_elementor_formatted_text', $formatted, $content, $this );
    }

    /**
     * Prepare inline text for display.
     *
     * Normalises whitespace and escapes content so single-line strings that
     * include dynamic values read cleanly within inline containers.
     *
     * @param string $content Raw content entered in the Elementor control.
     *
     * @return string Sanitised string ready for inline output. Empty string
     *                when there is no content to display.
     */
    protected function format_text_inline( $content ) {
        if ( ! is_string( $content ) ) {
            return '';
        }

        $content = trim( $content );

        if ( '' === $content ) {
            return '';
        }

        $normalised = preg_replace( '/\s+/u', ' ', $content );

        if ( ! is_string( $normalised ) || '' === $normalised ) {
            return '';
        }

        $sanitised = esc_html( $normalised );

        return apply_filters( 'wp_mcp_ai_elementor_inline_text', $sanitised, $content, $this );
    }
}
