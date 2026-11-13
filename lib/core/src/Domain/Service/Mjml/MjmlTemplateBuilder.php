<?php
/**
 * Builds MJML markup from a component list.
 *
 * Pure string work: components in, markup out. It does **not** compile MJML to
 * HTML — that needs a Node process or a sidecar service and therefore belongs
 * behind an adapter, not in the domain.
 *
 * Extracted from `WP_MCP_AI_MJML_Service::generate_template()` under Proposal
 * 090-D so the Laravel runtime can build templates without the WordPress
 * service.
 *
 * ## Escaping
 *
 * Option values and button text are escaped here. `content` on a `text`
 * component is injected **verbatim** — email bodies are rich text and the
 * caller owns sanitisation, because the allowlist is a platform policy
 * (`wp_kses_post()` in WordPress). The WordPress service sanitises before
 * delegating; a Laravel caller must do the equivalent.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Mjml;

final class MjmlTemplateBuilder {

	/**
	 * Default template options.
	 *
	 * @var array<string, string>
	 */
	public const DEFAULT_OPTIONS = array(
		'title'            => '',
		'preview_text'     => '',
		'font_family'      => 'Arial, sans-serif',
		'background_color' => '#f4f4f4',
		'container_width'  => '600px',
	);

	/**
	 * Build an MJML document from components.
	 *
	 * @param array<int, array<string, mixed>> $components Email components; unknown types are skipped.
	 * @param array<string, mixed>             $options    Template options, merged over the defaults.
	 * @return string MJML markup.
	 */
	public function build( array $components, array $options = array() ): string {
		$options = array_merge( self::DEFAULT_OPTIONS, $options );

		$mjml  = '<mjml>';
		$mjml .= '<mj-head>';

		if ( '' !== (string) $options['title'] ) {
			$mjml .= '<mj-title>' . self::escape( (string) $options['title'] ) . '</mj-title>';
		}

		if ( '' !== (string) $options['preview_text'] ) {
			$mjml .= '<mj-preview>' . self::escape( (string) $options['preview_text'] ) . '</mj-preview>';
		}

		$mjml .= '<mj-attributes>';
		$mjml .= '<mj-all font-family="' . self::escape( (string) $options['font_family'] ) . '" />';
		$mjml .= '<mj-body background-color="' . self::escape( (string) $options['background_color'] ) . '" />';
		$mjml .= '<mj-container background-color="#ffffff" width="' . self::escape( (string) $options['container_width'] ) . '" />';
		$mjml .= '</mj-attributes>';
		$mjml .= '</mj-head>';

		$mjml .= '<mj-body>';
		$mjml .= '<mj-section>';
		$mjml .= '<mj-column>';

		foreach ( $components as $component ) {
			if ( is_array( $component ) ) {
				$mjml .= $this->renderComponent( $component );
			}
		}

		$mjml .= '</mj-column>';
		$mjml .= '</mj-section>';
		$mjml .= '</mj-body>';
		$mjml .= '</mjml>';

		return $mjml;
	}

	/**
	 * Render one component.
	 *
	 * @param array<string, mixed> $component Component data.
	 * @return string MJML fragment, or '' for an unknown type.
	 */
	private function renderComponent( array $component ): string {
		$type       = isset( $component['type'] ) ? (string) $component['type'] : 'text';
		$attributes = isset( $component['attributes'] ) && is_array( $component['attributes'] )
			? self::buildAttributes( $component['attributes'] )
			: '';

		switch ( $type ) {
			case 'text':
				return sprintf(
					'<mj-text%s>%s</mj-text>',
					$attributes,
					isset( $component['content'] ) ? (string) $component['content'] : ''
				);

			case 'button':
				return sprintf(
					'<mj-button%s>%s</mj-button>',
					$attributes,
					isset( $component['text'] ) ? self::escape( (string) $component['text'] ) : 'Click Here'
				);

			case 'image':
				return sprintf( '<mj-image%s />', $attributes );

			case 'divider':
				return sprintf( '<mj-divider%s />', $attributes );

			case 'spacer':
				return sprintf( '<mj-spacer%s />', $attributes );
		}

		return '';
	}

	/**
	 * Serialise an attribute map.
	 *
	 * @param array<string, mixed> $attributes Attribute name => value.
	 */
	private static function buildAttributes( array $attributes ): string {
		if ( array() === $attributes ) {
			return '';
		}

		$rendered = '';

		foreach ( $attributes as $key => $value ) {
			$rendered .= ' ' . self::escape( (string) $key ) . '="' . self::escape( (string) $value ) . '"';
		}

		return $rendered;
	}

	/**
	 * Escape a value for HTML text and attribute context.
	 *
	 * Matches `esc_html()`/`esc_attr()`: quotes encoded, existing entities left
	 * alone so already-escaped content is not double-encoded.
	 */
	private static function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false );
	}
}
