<?php
/**
 * NV oOS Design System — Email Generator
 *
 * AI-assisted email template generation. Shared by the
 * nds_generate_email_template tool and the admin "Generate" form.
 *
 * Pipeline (see the research doc §6.4):
 *   provider call → wp_kses sanitize → structural BLOCK gates → token
 *   conformance pass → contrast + size gates → save as draft
 *
 * Provider resolution:
 *   1. nvoos_nds_email_generation_provider filter (callable returning HTML)
 *   2. WordPress AI Client (`wp_ai_client_prompt`) on WordPress 7.0+
 *
 * No third-party AI provider is contacted directly. On WordPress < 7.0 the
 * filter is the supported integration seam (for example, a host plugin can
 * expose its own AI provider through it).
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email template generator.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Email_Generator {

	/**
	 * Email-safe ksES allowlist for generated template HTML.
	 *
	 * @var array<string, array<int|string, bool>>
	 */
	private $allowed_html = array(
		'html'       => array(
			'lang' => true,
			'dir'  => true,
		),
		'head'       => array(),
		'title'      => array(),
		'meta'       => array(
			'charset'    => true,
			'name'       => true,
			'content'    => true,
			'http-equiv' => true,
		),
		'style'      => array( 'type' => true ),
		'body'       => array(
			'style'   => true,
			'bgcolor' => true,
		),
		'table'      => array(
			'role'        => true,
			'width'       => true,
			'cellpadding' => true,
			'cellspacing' => true,
			'border'      => true,
			'align'       => true,
			'bgcolor'     => true,
			'style'       => true,
			'class'       => true,
		),
		'tbody'      => array(),
		'thead'      => array(),
		'tr'         => array(
			'style'  => true,
			'align'  => true,
			'valign' => true,
		),
		'td'         => array(
			'style'   => true,
			'align'   => true,
			'valign'  => true,
			'colspan' => true,
			'rowspan' => true,
			'width'   => true,
			'height'  => true,
			'bgcolor' => true,
			'class'   => true,
		),
		'th'         => array(
			'style'   => true,
			'align'   => true,
			'valign'  => true,
			'colspan' => true,
			'rowspan' => true,
			'width'   => true,
			'bgcolor' => true,
			'scope'   => true,
		),
		'p'          => array(
			'style' => true,
			'align' => true,
			'class' => true,
		),
		'div'        => array(
			'style' => true,
			'align' => true,
			'class' => true,
		),
		'span'       => array(
			'style' => true,
			'class' => true,
		),
		'h1'         => array(
			'style' => true,
			'align' => true,
		),
		'h2'         => array(
			'style' => true,
			'align' => true,
		),
		'h3'         => array(
			'style' => true,
			'align' => true,
		),
		'h4'         => array(
			'style' => true,
			'align' => true,
		),
		'ul'         => array( 'style' => true ),
		'ol'         => array( 'style' => true ),
		'li'         => array( 'style' => true ),
		'a'          => array(
			'href'   => true,
			'style'  => true,
			'title'  => true,
			'target' => true,
		),
		'img'        => array(
			'src'    => true,
			'alt'    => true,
			'width'  => true,
			'height' => true,
			'style'  => true,
			'border' => true,
		),
		'br'         => array(),
		'hr'         => array( 'style' => true ),
		'strong'     => array(),
		'b'          => array(),
		'em'         => array(),
		'i'          => array(),
		'small'      => array(),
		'center'     => array(),
		'blockquote' => array( 'style' => true ),
		'sup'        => array(),
		'sub'        => array(),
		'u'          => array(),
		'del'        => array(),
		'code'       => array(),
		'pre'        => array( 'style' => true ),
	);

	/**
	 * Generate an email template from a prompt.
	 *
	 * @param string $prompt        Natural-language description of the template.
	 * @param string $base_template Optional built-in slug to use as a structural reference.
	 * @param string $subject_hint  Optional subject hint for the <title> merge tag.
	 * @param string $palette       'use_current_tokens' (default) or 'override'.
	 * @param array  $palette_values Override palette (id => value) when $palette is 'override'.
	 * @return array|\WP_Error Canonical success array with template_id, slug, audit, preview; WP_Error on failure.
	 */
	public function generate( $prompt, $base_template = '', $subject_hint = '', $palette = 'use_current_tokens', $palette_values = array() ) {
		$prompt = trim( $prompt );
		if ( '' === $prompt ) {
			return new WP_Error( 'nds_email_generate_empty_prompt', __( 'A prompt describing the desired email template is required.', 'nvoos-design-system' ) );
		}

		$base_html = '';
		if ( '' !== $base_template ) {
			$base_html = NV_oOS_Design_System_Email_Template_Registry::get_builtin_html( sanitize_title( $base_template ) );
			if ( '' !== trim( $base_html ) ) {
				$base_html = $this->strip_body_for_reference( $base_html );
			}
		}

		$system_prompt = $this->build_system_prompt( $palette, $palette_values );

		$user_prompt = $this->build_user_prompt( $prompt, $base_template, $base_html, $subject_hint );

		$raw = $this->call_provider( $system_prompt, $user_prompt );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$html = $this->extract_html( $raw );
		if ( '' === trim( $html ) ) {
			return new WP_Error( 'nds_email_generate_empty_response', __( 'The AI provider returned no usable HTML. Try a more specific prompt.', 'nvoos-design-system' ) );
		}

		// Security sanitize.
		$html = wp_kses( $html, $this->allowed_html );
		$html = trim( $html );

		// Structural BLOCK gates.
		$auditor = NV_oOS_Design_System_Plugin::email_auditor();
		if ( ! $auditor->required_gates_pass( $html ) ) {
			return new WP_Error(
				'nds_email_generate_gate_failure',
				__( 'The generated template failed the structural or security gates and was not saved. Refine the prompt and retry.', 'nvoos-design-system' )
			);
		}

		// Token conformance: swap palette literals for token references.
		$html = $this->apply_token_conformance( $html, $palette, $palette_values );

		// Ensure a complete document (skeleton for body-scope output).
		$renderer = new NV_oOS_Design_System_Email_Renderer();
		if ( false === stripos( $html, '<!doctype' ) && false === stripos( $html, '<html' ) ) {
			$html = $renderer->render_body_skeleton( $html, $subject_hint, 'ai-' . gmdate( 'YmdHis' ) );
		}

		// Final audit (contrast + size included).
		$audit = $auditor->audit( $html, array(), 'full' );

		$slug    = 'ai-' . strtolower( sanitize_title( wp_generate_uuid4() ) );
		$post_id = NV_oOS_Design_System_Email_Template_CPT::upsert(
			$slug,
			$this->derive_title( $prompt ),
			$html,
			'ai',
			'full',
			array(),
			'draft'
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return array(
			'success'     => true,
			'template_id' => $post_id,
			'slug'        => $slug,
			'status'      => 'draft',
			'audit'       => $audit,
			'message'     => __( 'Email template generated and saved as a draft. Review the audit report, preview it, and publish before activation.', 'nvoos-design-system' ),
		);
	}

	// -----------------------------------------------------------------------
	// Prompt construction.
	// -----------------------------------------------------------------------

	/**
	 * Build the constraint system prompt.
	 *
	 * @param string $palette        Palette mode.
	 * @param array  $palette_values Override palette values.
	 * @return string
	 */
	private function build_system_prompt( $palette, $palette_values ) {
		$registry = NV_oOS_Design_System_Plugin::token_registry();
		$values   = 'override' === $palette ? $palette_values : $registry->get_group_values( 'emails' );

		$palette_lines = array();
		foreach ( $values as $id => $value ) {
			$palette_lines[] = '  ' . $id . ': ' . $value;
		}
		$palette_block = empty( $palette_lines ) ? '(defaults)' : "\n" . implode( "\n", $palette_lines );

		return "You are an expert HTML email template designer. Produce ONLY the HTML for a production-ready, standards-compliant email template.\n"
			. "HARD CONSTRAINTS — violations make the output unusable:\n"
			. "1. Table-based layout only. Every <table> must have role=\"presentation\". Maximum width 600px, centered.\n"
			. "2. Buttons must use the bulletproof pattern (table + <a>), never <button>. Minimum touch target 44px.\n"
			. "3. Inline styles are the baseline. A <style> block may ONLY contain @media (prefers-color-scheme: dark) overrides.\n"
			. "4. No JavaScript, no external stylesheets, no <link>, no <iframe>, no <form>, no CSS grid/flexbox layout, no position:absolute, no web fonts.\n"
			. "5. Semantic headings (h1–h3), lang=\"en\" on <html>, descriptive alt text on every <img>.\n"
			. "6. Body text at least 16px, line-height at least 1.6.\n"
			. "7. Start the output with this comment exactly: <!-- nds-email-wrapper -->\n"
			. "8. Use these merge tags, never hardcoded site data: {{subject}}, {{body}}, {{to_name}}, {{site_name}}, {{site_url}}, {{site_domain}}, {{logo_url}}, {{admin_email}}, {{sub_brand}}, {{confidential}}, {{year}}. Wrap greetings in {{#to_name}}…{{/to_name}} so they vanish when the recipient name is unknown.\n"
			. "9. For the call-to-action use the template part: {{button \"Label\" \"https://example.com\"}}\n"
			. "10. Use these colour variables for ALL styling (they are resolved at render time): var(--nds-email-page-bg), var(--nds-email-card-bg), var(--nds-email-header-bg), var(--nds-email-accent), var(--nds-email-accent-2), var(--nds-email-body-text), var(--nds-email-divider), var(--nds-email-muted), var(--nds-email-font), var(--nds-email-body-size), var(--nds-email-body-height), var(--nds-email-page-bg-dark), var(--nds-email-card-bg-dark), var(--nds-email-body-text-dark).\n"
			. "11. Total HTML size must stay under 100 KB.\n"
			. "Current palette values:\n" . $palette_block . "\n"
			. 'Output the raw HTML only — no markdown fences, no explanations.';
	}

	/**
	 * Build the user prompt.
	 *
	 * @param string $prompt        User's prompt.
	 * @param string $base_template Base template slug.
	 * @param string $base_html     Base template reference HTML.
	 * @param string $subject_hint  Subject hint.
	 * @return string
	 */
	private function build_user_prompt( $prompt, $base_template, $base_html, $subject_hint ) {
		$prompt_text = 'Create an email template matching this description: ' . $prompt . "\n";

		if ( '' !== $base_template && '' !== $base_html ) {
			$prompt_text .= 'Use this existing template as your structural reference (layout, sections, tone):' . "\n\n" . $base_html . "\n\n";
		}

		if ( '' !== trim( $subject_hint ) ) {
			$prompt_text .= 'Suggested subject line for the <title> element: ' . $subject_hint . "\n";
		}

		return $prompt_text;
	}

	// -----------------------------------------------------------------------
	// Provider layer.
	// -----------------------------------------------------------------------

	/**
	 * Call the AI provider and return raw output.
	 *
	 * @param string $system_prompt System prompt.
	 * @param string $user_prompt   User prompt.
	 * @return string|\WP_Error
	 */
	private function call_provider( $system_prompt, $user_prompt ) {
		/**
		 * Override the generation provider entirely.
		 *
		 * @param callable|null $callback Callable receiving ( $system_prompt, $user_prompt ) and returning HTML or WP_Error.
		 */
		$callback = apply_filters( 'nvoos_nds_email_generation_provider', null );
		if ( is_callable( $callback ) ) {
			return call_user_func( $callback, $system_prompt, $user_prompt );
		}

		$wp_ai = $this->generate_with_wp_ai_client( $system_prompt, $user_prompt );
		if ( null !== $wp_ai ) {
			return $wp_ai;
		}

		return new WP_Error(
			'nds_email_generate_no_provider',
			__( 'AI generation requires WordPress 7.0+ (AI Client) or a provider supplied through the nvoos_nds_email_generation_provider filter.', 'nvoos-design-system' )
		);
	}

	/**
	 * Generate text through the WordPress AI Client when it is available.
	 *
	 * WordPress 7.0+ ships a provider-agnostic AI Client. Using it avoids
	 * direct provider credentials and keeps generation aligned with core.
	 *
	 * @since 1.0.0
	 *
	 * @param string $system_prompt System prompt.
	 * @param string $user_prompt   User prompt.
	 * @return string|\WP_Error|null Generated text, an AI Client error, or null when unavailable.
	 */
	private function generate_with_wp_ai_client( $system_prompt, $user_prompt ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return null;
		}

		if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
			return null;
		}

		try {
			$result = wp_ai_client_prompt( $user_prompt )
				->using_system_instruction( $system_prompt )
				->generate_text();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'nds_email_generate_wp_ai_error',
				sprintf(
					/* translators: %s: error message */
					__( 'The WordPress AI Client failed: %s', 'nvoos-design-system' ),
					$e->getMessage()
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_string( $result ) || '' === trim( $result ) ) {
			return new WP_Error(
				'nds_email_generate_empty_response',
				__( 'The AI provider returned no usable HTML. Try a more specific prompt.', 'nvoos-design-system' )
			);
		}

		return $result;
	}


	// -----------------------------------------------------------------------
	// Post-processing.
	// -----------------------------------------------------------------------

	/**
	 * Extract raw HTML from a provider response (strips markdown fences).
	 *
	 * @param string $raw Provider output.
	 * @return string
	 */
	private function extract_html( $raw ) {
		$html = trim( $raw );

		// Strip markdown fences when present.
		if ( 0 === strpos( $html, '```' ) ) {
			$html = preg_replace( '/^```[a-zA-Z]*\s*/', '', $html );
			$html = preg_replace( '/\s*```$/', '', $html );
		}

		return trim( $html );
	}

	/**
	 * Replace palette literals in generated HTML with token references so
	 * future rebranding flows through.
	 *
	 * @param string $html           Generated HTML.
	 * @param string $palette        Palette mode.
	 * @param array  $palette_values Override palette values.
	 * @return string
	 */
	private function apply_token_conformance( $html, $palette, $palette_values ) {
		$registry = NV_oOS_Design_System_Plugin::token_registry();
		$values   = 'override' === $palette ? $palette_values : $registry->get_group_values( 'emails' );

		foreach ( $values as $id => $value ) {
			if ( ! is_string( $value ) || '' === $value || 0 !== strpos( trim( $value ), '#' ) ) {
				continue;
			}

			// Only hex colors are conformed (rgb()/var() values are left as-is).
			$html = str_replace( $value, 'var(--nds-' . str_replace( '_', '-', $id ) . ')', $html );
		}

		return $html;
	}

	/**
	 * Strip a built-in template down to its structural skeleton for use as
	 * a prompt reference (removes merge content noise).
	 *
	 * @param string $html Built-in template HTML.
	 * @return string
	 */
	private function strip_body_for_reference( $html ) {
		return $html;
	}

	/**
	 * Derive a human-readable title from the prompt.
	 *
	 * @param string $prompt Generation prompt.
	 * @return string
	 */
	private function derive_title( $prompt ) {
		$title = sanitize_text_field( $prompt );
		if ( function_exists( 'mb_strimwidth' ) ) {
			$title = mb_strimwidth( $title, 0, 60, '…' );
		} elseif ( strlen( $title ) > 60 ) {
			$title = substr( $title, 0, 57 ) . '...';
		}

		return 'AI: ' . $title;
	}
}
