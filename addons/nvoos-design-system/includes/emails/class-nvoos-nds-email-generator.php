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
 *   1. nds_email_generation_provider filter (callable returning HTML)
 *   2. nvoos_nds_ai_provider option ('openai' | 'gemini' | 'auto')
 *   3. auto-detect API keys via wp_mcp_ai_get_api_key() when the NV oOS
 *      base plugin is active
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
		'html'     => array( 'lang' => true, 'dir' => true ),
		'head'     => array(),
		'title'    => array(),
		'meta'     => array( 'charset' => true, 'name' => true, 'content' => true, 'http-equiv' => true ),
		'style'    => array( 'type' => true ),
		'body'     => array( 'style' => true, 'bgcolor' => true ),
		'table'    => array( 'role' => true, 'width' => true, 'cellpadding' => true, 'cellspacing' => true, 'border' => true, 'align' => true, 'bgcolor' => true, 'style' => true, 'class' => true ),
		'tbody'    => array(),
		'thead'    => array(),
		'tr'       => array( 'style' => true, 'align' => true, 'valign' => true ),
		'td'       => array( 'style' => true, 'align' => true, 'valign' => true, 'colspan' => true, 'rowspan' => true, 'width' => true, 'height' => true, 'bgcolor' => true, 'class' => true ),
		'th'       => array( 'style' => true, 'align' => true, 'valign' => true, 'colspan' => true, 'rowspan' => true, 'width' => true, 'bgcolor' => true, 'scope' => true ),
		'p'        => array( 'style' => true, 'align' => true, 'class' => true ),
		'div'      => array( 'style' => true, 'align' => true, 'class' => true ),
		'span'     => array( 'style' => true, 'class' => true ),
		'h1'       => array( 'style' => true, 'align' => true ),
		'h2'       => array( 'style' => true, 'align' => true ),
		'h3'       => array( 'style' => true, 'align' => true ),
		'h4'       => array( 'style' => true, 'align' => true ),
		'ul'       => array( 'style' => true ),
		'ol'       => array( 'style' => true ),
		'li'       => array( 'style' => true ),
		'a'        => array( 'href' => true, 'style' => true, 'title' => true, 'target' => true ),
		'img'      => array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'style' => true, 'border' => true ),
		'br'       => array(),
		'hr'       => array( 'style' => true ),
		'strong'   => array(),
		'b'        => array(),
		'em'       => array(),
		'i'        => array(),
		'small'    => array(),
		'center'   => array(),
		'blockquote' => array( 'style' => true ),
		'sup'      => array(),
		'sub'      => array(),
		'u'        => array(),
		'del'      => array(),
		'code'     => array(),
		'pre'      => array( 'style' => true ),
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

		$slug = 'ai-' . strtolower( sanitize_title( wp_generate_uuid4() ) );
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
			. "Output the raw HTML only — no markdown fences, no explanations.";
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
		$callback = apply_filters( 'nds_email_generation_provider', null );
		if ( is_callable( $callback ) ) {
			return call_user_func( $callback, $system_prompt, $user_prompt );
		}

		$provider = (string) get_option( NV_oOS_Design_System_Plugin::AI_PROVIDER_KEY, 'auto' );

		if ( 'auto' === $provider || '' === $provider ) {
			$provider = $this->auto_detect_provider();
		}

		switch ( $provider ) {
			case 'openai':
				return $this->call_openai( $system_prompt, $user_prompt );

			case 'gemini':
				return $this->call_gemini( $system_prompt, $user_prompt );

			default:
				return new WP_Error(
					'nds_email_generate_no_provider',
					__( 'No AI provider is configured. Set an OpenAI or Gemini API key in the NV oOS settings, choose a provider in Design System → Emails, or use the nds_email_generation_provider filter.', 'nvoos-design-system' )
				);
		}
	}

	/**
	 * Auto-detect an available provider from configured API keys.
	 *
	 * Prefers the NV oOS encrypted key store when the base plugin is active.
	 *
	 * @return string 'openai' | 'gemini' | '' (none).
	 */
	private function auto_detect_provider() {
		if ( function_exists( 'wp_mcp_ai_get_api_key' ) ) {
			if ( wp_mcp_ai_get_api_key( 'gemini_api_key' ) ) {
				return 'gemini';
			}
			if ( wp_mcp_ai_get_api_key( 'openai_api_key' ) ) {
				return 'openai';
			}
		}

		return '';
	}

	/**
	 * Call the OpenAI Chat Completions API.
	 *
	 * @param string $system_prompt System prompt.
	 * @param string $user_prompt   User prompt.
	 * @return string|\WP_Error
	 */
	private function call_openai( $system_prompt, $user_prompt ) {
		$api_key = function_exists( 'wp_mcp_ai_get_api_key' )
			? (string) wp_mcp_ai_get_api_key( 'openai_api_key' )
			: '';

		if ( '' === $api_key ) {
			return new WP_Error( 'nds_email_generate_no_key', __( 'No OpenAI API key configured.', 'nvoos-design-system' ) );
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 90,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode(
					array(
						'model'       => 'gpt-4o-mini',
						'temperature' => 0.7,
						'messages'    => array(
							array( 'role' => 'system', 'content' => $system_prompt ),
							array( 'role' => 'user', 'content' => $user_prompt ),
						),
					)
				),
			)
		);

		return $this->parse_provider_response( $response, 'OpenAI' );
	}

	/**
	 * Call the Gemini generateContent API.
	 *
	 * @param string $system_prompt System prompt.
	 * @param string $user_prompt   User prompt.
	 * @return string|\WP_Error
	 */
	private function call_gemini( $system_prompt, $user_prompt ) {
		$api_key = function_exists( 'wp_mcp_ai_get_api_key' )
			? (string) wp_mcp_ai_get_api_key( 'gemini_api_key' )
			: '';

		if ( '' === $api_key ) {
			return new WP_Error( 'nds_email_generate_no_key', __( 'No Gemini API key configured.', 'nvoos-design-system' ) );
		}

		$response = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . rawurlencode( $api_key ),
			array(
				'timeout' => 90,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'contents'         => array(
							array(
								'parts' => array(
									array( 'text' => $system_prompt . "\n\n" . $user_prompt ),
								),
							),
						),
						'generationConfig' => array(
							'temperature' => 0.7,
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'nds_email_generate_provider_error',
				sprintf(
					/* translators: 1: provider name, 2: HTTP status code, 3: response snippet */
					__( '%1$s returned status %2$d: %3$s', 'nvoos-design-system' ),
					'Gemini',
					$code,
					wp_trim_words( wp_strip_all_tags( $body ), 30 )
				)
			);
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['candidates'][0]['content']['parts'] ) ) {
			return new WP_Error( 'nds_email_generate_provider_error', __( 'Gemini returned an unexpected response shape.', 'nvoos-design-system' ) );
		}

		$text = '';
		foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
			if ( isset( $part['text'] ) ) {
				$text .= $part['text'];
			}
		}

		return $text;
	}

	/**
	 * Parse an OpenAI-style response into its text content.
	 *
	 * @param array|\WP_Error $response wp_remote_post() response.
	 * @param string          $provider Provider name for error messages.
	 * @return string|\WP_Error
	 */
	private function parse_provider_response( $response, $provider ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'nds_email_generate_provider_error',
				sprintf(
					/* translators: 1: provider name, 2: HTTP status code, 3: response snippet */
					__( '%1$s returned status %2$d: %3$s', 'nvoos-design-system' ),
					$provider,
					$code,
					wp_trim_words( wp_strip_all_tags( $body ), 30 )
				)
			);
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'nds_email_generate_provider_error', sprintf(
				/* translators: %s: provider name */
				__( '%s returned an unexpected response shape.', 'nvoos-design-system' ),
				$provider
			) );
		}

		return $data['choices'][0]['message']['content'];
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
