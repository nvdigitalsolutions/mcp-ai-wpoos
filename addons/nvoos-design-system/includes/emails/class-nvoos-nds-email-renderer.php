<?php
/**
 * NV oOS Design System — Email Renderer
 *
 * Renders an email template for a given context:
 *   - resolves `var(--nds-email-*)` references to concrete token values
 *     (email clients do not support CSS custom properties)
 *   - substitutes merge tags ({{subject}}, {{body}}, {{logo_url}}, …)
 *   - expands the {{button "label" "url"}} template part into a
 *     bulletproof CTA (table + <a> + VML fallback for Outlook)
 *   - guarantees the nds-email-wrapper sentinel is present
 *
 * Escaping follows the two-gate rule: context values are escaped at exit,
 * and the {{body}} merge tag (pre-sanitised HTML) is inserted raw.
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email template renderer.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Email_Renderer {

	/**
	 * Sentinel marker that identifies a wrapped email and prevents
	 * double-wrapping. Format: <!-- nds-email-wrapper:{slug} -->
	 *
	 * @var string
	 */
	const SENTINEL_PREFIX = '<!-- nds-email-wrapper:';

	/**
	 * Placeholder used to defer {{body}} insertion until after all other
	 * merge tags have been substituted (so merge-tag-looking text inside
	 * the body is never re-processed).
	 *
	 * @var string
	 */
	const BODY_PLACEHOLDER = "\x1ANDX\x1ABODY\x1ANDX\x1A";

	/**
	 * Render a template with the given context.
	 *
	 * @param string                          $template_html Template HTML.
	 * @param array<string, string>           $context       Merge context (subject, body, to_name, site_name, site_url, site_domain, logo_url, admin_email, sub_brand, confidential, year).
	 * @param string                          $slug          Template slug (for the sentinel).
	 * @return string Rendered HTML.
	 */
	public function render( $template_html, $context = array(), $slug = 'custom' ) {
		$defaults = array(
			'subject'      => '',
			'body'         => '',
			'to_name'      => '',
			'site_name'    => '',
			'site_url'     => '',
			'site_domain'  => '',
			'logo_url'     => '',
			'admin_email'  => '',
			'sub_brand'    => '',
			'confidential' => '',
			'year'         => '',
		);

		$context = wp_parse_args( $context, $defaults );

		$html = $this->resolve_tokens( $template_html );

		// Defer body insertion.
		$html = str_replace( '{{body}}', self::BODY_PLACEHOLDER, $html );

		$html = str_replace(
			array(
				'{{subject}}',
				'{{to_name}}',
				'{{site_name}}',
				'{{site_url}}',
				'{{site_domain}}',
				'{{logo_url}}',
				'{{admin_email}}',
				'{{sub_brand}}',
				'{{confidential}}',
				'{{year}}',
			),
			array(
				esc_html( $context['subject'] ),
				esc_html( $context['to_name'] ),
				esc_html( $context['site_name'] ),
				esc_url( $context['site_url'] ),
				esc_html( $context['site_domain'] ),
				esc_url( $context['logo_url'] ),
				esc_html( $context['admin_email'] ),
				esc_html( $context['sub_brand'] ),
				esc_html( $context['confidential'] ),
				esc_html( $context['year'] ),
			),
			$html
		);

		// Template parts.
		$html = preg_replace_callback(
			'/\{\{button\s+"([^"]*)"\s+"([^"]*)"\}\}/',
			array( $this, 'render_button_part' ),
			$html
		);

		// Insert the (pre-sanitised) body last.
		$html = str_replace( self::BODY_PLACEHOLDER, $context['body'], $html );

		return $this->ensure_sentinel( $html, $slug );
	}

	/**
	 * Wrap body-only HTML in a minimal accessible skeleton.
	 *
	 * Used for body-scope templates (including AI-generated ones) so every
	 * outgoing email is a complete, standards-compliant document.
	 *
	 * @param string $body    Pre-sanitised body HTML.
	 * @param string $subject Email subject.
	 * @param string $slug    Template slug.
	 * @return string Full HTML document.
	 */
	public function render_body_skeleton( $body, $subject = '', $slug = 'custom' ) {
		$skeleton  = $this->resolve_tokens( '<!-- nds-email-wrapper:' . sanitize_title( $slug ) . ' -->' );
		$skeleton .= '<!DOCTYPE html>';
		$skeleton .= '<html lang="en">';
		$skeleton .= '<head>';
		$skeleton .= '<meta charset="utf-8">';
		$skeleton .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
		$skeleton .= '<meta name="color-scheme" content="light dark">';
		$skeleton .= '<title>' . esc_html( $subject ) . '</title>';
		$skeleton .= '<style>@media (prefers-color-scheme:dark){.nds-page{background-color:' . esc_html( $this->token_value( 'email_page_bg_dark' ) ) . '!important}.nds-card{background-color:' . esc_html( $this->token_value( 'email_card_bg_dark' ) ) . '!important}.nds-body{color:' . esc_html( $this->token_value( 'email_body_text_dark' ) ) . '!important}}</style>';
		$skeleton .= '</head>';
		$skeleton .= '<body style="margin:0;padding:0;background-color:' . esc_html( $this->token_value( 'email_page_bg' ) ) . ';font-family:' . esc_attr( $this->token_value( 'email_font' ) ) . ';">';
		$skeleton .= '<table class="nds-page" width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="background-color:' . esc_html( $this->token_value( 'email_page_bg' ) ) . ';">';
		$skeleton .= '<tr><td align="center" style="padding:40px 16px 48px;">';
		$skeleton .= '<table class="nds-card" width="600" cellpadding="0" cellspacing="0" border="0" role="presentation" style="max-width:600px;width:100%;background-color:' . esc_html( $this->token_value( 'email_card_bg' ) ) . ';">';
		$skeleton .= '<tr><td class="nds-body" style="padding:48px;font-family:' . esc_attr( $this->token_value( 'email_font' ) ) . ';font-size:' . esc_html( $this->token_value( 'email_body_size' ) ) . ';line-height:' . esc_html( $this->token_value( 'email_body_height' ) ) . ';color:' . esc_html( $this->token_value( 'email_body_text' ) ) . ';">';
		$skeleton .= $body;
		$skeleton .= '</td></tr>';
		$skeleton .= '</table>';
		$skeleton .= '</td></tr>';
		$skeleton .= '</table>';
		$skeleton .= '</body></html>';

		return $skeleton;
	}

	/**
	 * Ensure the sentinel marker is present in a rendered template.
	 *
	 * @param string $html Rendered HTML.
	 * @param string $slug Template slug.
	 * @return string
	 */
	public function ensure_sentinel( $html, $slug ) {
		if ( false !== strpos( $html, self::SENTINEL_PREFIX ) ) {
			return $html;
		}

		return self::SENTINEL_PREFIX . sanitize_title( $slug ) . ' -->' . $html;
	}

	/**
	 * Resolve every `var(--nds-email-*)` reference in a template to the
	 * concrete token value.
	 *
	 * @param string $html Template HTML.
	 * @return string HTML with resolved values.
	 */
	public function resolve_tokens( $html ) {
		$registry = NV_oOS_Design_System_Plugin::token_registry();
		$values   = $registry->get_group_values( 'emails' );

		foreach ( $values as $id => $value ) {
			$html = str_replace( 'var(--nds-' . str_replace( '_', '-', $id ) . ')', $value, $html );
		}

		return $html;
	}

	/**
	 * Get the current value of an email-group token.
	 *
	 * @param string $id Token ID (without the group prefix).
	 * @return string
	 */
	private function token_value( $id ) {
		$registry = NV_oOS_Design_System_Plugin::token_registry();
		$token    = $registry->get( $id );
		return $token ? $token->value : '';
	}

	/**
	 * Expand the {{button "label" "url"}} part into a bulletproof CTA.
	 *
	 * @param array $matches Regex matches: 1 = label, 2 = URL.
	 * @return string Button HTML.
	 */
	private function render_button_part( $matches ) {
		$label = esc_html( $matches[1] );
		// The URL may already carry merge-tag output (e.g. {{site_url}}),
		// which is entity-escaped at substitution time; decode before the
		// final esc_url() so entities are not double-escaped.
		$url = esc_url( html_entity_decode( $matches[2], ENT_QUOTES ) );

		$bg     = esc_html( $this->token_value( 'email_accent' ) );
		$text   = esc_html( $this->token_value( 'email_card_bg' ) );
		$font   = esc_attr( $this->token_value( 'email_font' ) );
		$radius = '4px';

		// Bulletproof button: table + <a>, VML fallback for Outlook.
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">'
			. '<tr><td align="center" bgcolor="' . $bg . '" style="background-color:' . $bg . ';border-radius:' . $radius . ';">'
			. '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $url . '" style="height:48px;v-text-anchor:middle;width:220px;" arcsize="10%" stroke="f" fillcolor="' . $bg . '"><w:anchorlock/><center><![endif]-->'
			. '<a href="' . $url . '" style="display:inline-block;padding:14px 32px;font-family:' . $font . ';font-size:16px;font-weight:bold;color:' . $text . ';text-decoration:none;border-radius:' . $radius . ';">' . $label . '</a>'
			. '<!--[if mso]></center></v:roundrect><![endif]-->'
			. '</td></tr></table>';
	}
}
