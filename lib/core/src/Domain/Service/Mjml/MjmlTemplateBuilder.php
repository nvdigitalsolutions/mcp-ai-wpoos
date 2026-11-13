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
	 * URL schemes allowed in generated links.
	 *
	 * Mirrors the WordPress default for `esc_url()` so a template rendered here
	 * cannot smuggle a `javascript:` or `data:` payload into an email client.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_URL_SCHEMES = array(
		'http',
		'https',
		'ftp',
		'ftps',
		'mailto',
		'news',
		'irc',
		'gopher',
		'nntp',
		'feed',
		'telnet',
		'mms',
		'rtsp',
		'svn',
		'tel',
		'fax',
		'xmpp',
		'webcal',
		'urn',
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
	 * Prepend a branding header (logo row) to an MJML document.
	 *
	 * Ported from `WP_MCP_AI_Tool_Generate_Email_Template::add_branding_to_mjml()`
	 * under Proposal 090-D, so the WordPress tool and the core tool emit the same
	 * markup. The header is only added when `logo_url` carries a usable value:
	 * `empty()` semantics are kept (so `"0"` means "no logo"), and non-scalar
	 * values are skipped rather than triggering an array-to-string conversion.
	 *
	 * @param string               $mjml     MJML document.
	 * @param array<string, mixed> $branding Branding options; only `logo_url` and
	 *                                       `company_name` are read here.
	 * @return string MJML document with the header inserted after `<mj-body>`.
	 */
	public function withBrandingHeader( string $mjml, array $branding ): string {
		if ( ! isset( $branding['logo_url'] ) || ! is_scalar( $branding['logo_url'] ) ) {
			return $mjml;
		}

		$logo_url = (string) $branding['logo_url'];

		// `empty()` semantics, matching the WordPress original: "0" and 0 are
		// treated as "no logo" rather than rendered as a broken image.
		if ( '' === $logo_url || '0' === $logo_url ) {
			return $mjml;
		}

		$company_name = isset( $branding['company_name'] ) && is_scalar( $branding['company_name'] )
			? (string) $branding['company_name']
			: 'Logo';

		$section  = '<mj-section background-color="#ffffff" padding="20px 0">';
		$section .= '<mj-column>';
		$section .= '<mj-image src="' . self::url( $logo_url ) . '" alt="' . self::escape( $company_name ) . '" width="150px" align="center" />';
		$section .= '</mj-column>';
		$section .= '</mj-section>';

		return str_replace( '<mj-body>', '<mj-body>' . $section, $mjml );
	}

	/**
	 * Append a footer to an MJML document.
	 *
	 * Ported from `WP_MCP_AI_Tool_Generate_Email_Template::add_footer_to_mjml()`
	 * under Proposal 090-D. The footer always renders the dark section; the
	 * company block and the link row are conditional.
	 *
	 * @param string                             $mjml        MJML document.
	 * @param array<int, array<string, mixed>>   $footerLinks Links as `array( 'text' => ..., 'url' => ... )`;
	 *                                                        entries missing either key are skipped.
	 * @param array<string, mixed>               $branding    Optional `company_name` / `company_address`.
	 * @return string MJML document with the footer inserted before `</mj-body>`.
	 */
	public function withFooter( string $mjml, array $footerLinks, array $branding = array() ): string {
		$footer  = '<mj-section background-color="#333333" padding="20px">';
		$footer .= '<mj-column>';

		if ( isset( $branding['company_name'] ) && is_scalar( $branding['company_name'] ) ) {
			$footer .= '<mj-text color="#ffffff" align="center" font-size="12px">';
			$footer .= '<strong>' . self::escape( (string) $branding['company_name'] ) . '</strong>';

			if ( isset( $branding['company_address'] ) && is_scalar( $branding['company_address'] ) ) {
				$footer .= '<br />' . self::escape( (string) $branding['company_address'] );
			}

			$footer .= '</mj-text>';
		}

		if ( array() !== $footerLinks ) {
			$footer .= '<mj-text color="#ffffff" align="center" font-size="12px">';

			$rendered = array();

			foreach ( $footerLinks as $link ) {
				if (
					! is_array( $link )
					|| ! isset( $link['text'], $link['url'] )
					|| ! is_scalar( $link['text'] )
					|| ! is_scalar( $link['url'] )
				) {
					continue;
				}

				$rendered[] = '<a href="' . self::url( (string) $link['url'] ) . '" style="color: #ffffff;">'
					. self::escape( (string) $link['text'] ) . '</a>';
			}

			$footer .= implode( ' | ', $rendered );
			$footer .= '</mj-text>';
		}

		$footer .= '</mj-column>';
		$footer .= '</mj-section>';

		return str_replace( '</mj-body>', $footer . '</mj-body>', $mjml );
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

	/**
	 * Normalise a URL for an attribute, matching WordPress `esc_url()`.
	 *
	 * Email clients are a hostile rendering environment, so this is a security
	 * boundary rather than cosmetics. The behaviour was captured from a live
	 * `esc_url()` before the port:
	 *
	 *   https://example.com/a?b=1&c=2  =>  https://example.com/a?b=1&#038;c=2
	 *   https://example.com/a b        =>  https://example.com/a%20b
	 *   javascript:alert(1)            =>  ''
	 *   http://ex.com/'quote"          =>  http://ex.com/&#039;quote
	 *   HTTPS://Example.COM/Path       =>  https://Example.COM/Path
	 *
	 * Differences from `esc_url()` are limited to its filter hook, which a pure
	 * domain helper cannot offer.
	 */
	private static function url( string $url ): string {
		$url = str_replace( ' ', '%20', ltrim( $url ) );
		$url = (string) preg_replace( '/[^a-z0-9\-~+_.?#=!&;,:\/%@$|*\'()\[\]\x80-\xff]/i', '', $url );

		if ( '' === $url ) {
			return '';
		}

		if ( preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $url, $matches ) ) {
			$scheme = strtolower( $matches[1] );

			// Unknown scheme: a hostile or mistyped link. Drop the value rather
			// than emit something the client might still follow.
			if ( ! in_array( $scheme, self::ALLOWED_URL_SCHEMES, true ) ) {
				return '';
			}

			$url = $scheme . substr( $url, strlen( $matches[1] ) );
		}

		$url = (string) preg_replace( '/&(?!amp;|#[0-9]+;|#x[0-9a-f]+;)/i', '&amp;', $url );
		$url = str_replace( '&amp;', '&#038;', $url );

		return str_replace( "'", '&#039;', $url );
	}
}
