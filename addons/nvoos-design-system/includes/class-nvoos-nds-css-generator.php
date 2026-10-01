<?php
/**
 * NV oOS Design System — CSS Generator
 *
 * Compiles the token registry into CSS output. Supports two modes:
 *   1. Standard — plain `:root { }` block with custom properties
 *   2. Typed   — `@property` declarations + `:root` block, enabling
 *      browser type-checking, DevTools integration, and animation of
 *      custom properties.
 *
 * Also generates accessibility media query blocks for:
 *   - prefers-color-scheme (dark mode)
 *   - prefers-reduced-motion
 *   - prefers-contrast (high contrast)
 *
 * When legacy aliases are enabled, `--cds-*` copies of every token are
 * emitted alongside `--nds-*` so existing Crocoblock builds keep working
 * after the rename (transition release feature, removed in v1.0.0).
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSS compiler for the NV oOS Design System.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_CSS_Generator {

	/**
	 * Token registry instance.
	 *
	 * @var NV_oOS_Design_System_Token_Registry
	 */
	private $registry;

	/**
	 * Whether to generate typed @property declarations.
	 *
	 * When true, each token gets an @property block with syntax validation.
	 * Requires modern browser support (Chrome 85+, Firefox 128+, Safari 16.4+).
	 *
	 * @var bool
	 */
	private $use_typed_properties;

	/**
	 * Whether to emit legacy `--cds-*` aliases.
	 *
	 * @var bool
	 */
	private $include_legacy_aliases;

	/**
	 * Map NDS token types to CSS @property syntax values.
	 *
	 * @var array<string, string>
	 */
	private $property_syntax_map = array(
		'color'      => '<color>',
		'size'       => '<length> | <percentage>',
		'font'       => '<string> | <custom-ident>',
		'shadow'     => '<string>',
		'transition' => '<time> | <string>',
	);

	/**
	 * Constructor.
	 *
	 * @param NV_oOS_Design_System_Token_Registry $registry                Token registry.
	 * @param bool                                $use_typed_properties    Whether to output @property blocks.
	 * @param bool                                $include_legacy_aliases  Whether to output `--cds-*` aliases.
	 */
	public function __construct( $registry, $use_typed_properties = false, $include_legacy_aliases = true ) {
		$this->registry                = $registry;
		$this->use_typed_properties    = $use_typed_properties;
		$this->include_legacy_aliases  = $include_legacy_aliases;
	}

	/**
	 * Generate the complete CSS output.
	 *
	 * @return string CSS block with optional @property declarations.
	 */
	public function generate() {
		$tokens = $this->registry->get_all();

		if ( empty( $tokens ) ) {
			return '';
		}

		$css = '';

		// 1. @property declarations (opt-in).
		if ( $this->use_typed_properties ) {
			$css .= $this->generate_property_blocks( $tokens );
		}

		// 2. Base :root block.
		$css .= $this->build_root_block( $tokens );

		// 3. Legacy `--cds-*` alias block (transition release).
		if ( $this->include_legacy_aliases ) {
			$css .= $this->build_legacy_alias_block( $tokens );
		}

		// 4. Accessibility media query blocks.
		$css .= $this->generate_a11y_blocks();

		return $css;
	}

	/**
	 * Generate a <style> tag with the compiled CSS.
	 *
	 * @return string HTML <style> element.
	 */
	public function generate_style_tag() {
		$css = $this->generate();
		if ( '' === $css ) {
			return '';
		}

		return sprintf(
			'<style id="nds-tokens">%s</style>',
			$css
		);
	}

	// -----------------------------------------------------------------------
	// @property generation.
	// -----------------------------------------------------------------------

	/**
	 * Generate @property blocks for all tokens.
	 *
	 * @param array<string, NV_oOS_Design_System_Data_Token> $tokens All tokens.
	 * @return string CSS @property declarations.
	 */
	private function generate_property_blocks( $tokens ) {
		$blocks = '';

		foreach ( $tokens as $token ) {
			$syntax   = $this->get_property_syntax( $token->type );
			$initial  = esc_html( $token->default );
			$css_var  = esc_html( $token->css_var() );
			$inherits = 'true';

			$blocks .= sprintf(
				"@property %s {\n  syntax: '%s';\n  inherits: %s;\n  initial-value: %s;\n}\n",
				$css_var,
				$syntax,
				$inherits,
				$initial
			);
		}

		return $blocks;
	}

	/**
	 * Get the CSS @property syntax string for a token type.
	 *
	 * @param string $type NDS token type.
	 * @return string CSS syntax value.
	 */
	private function get_property_syntax( $type ) {
		return isset( $this->property_syntax_map[ $type ] )
			? $this->property_syntax_map[ $type ]
			: '*';
	}

	// -----------------------------------------------------------------------
	// :root block.
	// -----------------------------------------------------------------------

	/**
	 * Build the :root CSS block from token values.
	 *
	 * @param array<string, NV_oOS_Design_System_Data_Token> $tokens All tokens.
	 * @return string CSS :root block.
	 */
	private function build_root_block( $tokens ) {
		$lines = array( ':root{' );

		foreach ( $tokens as $token ) {
			$lines[] = sprintf(
				'%s:%s;',
				esc_html( $token->css_var() ),
				esc_html( $token->value )
			);
		}

		$lines[] = '}';

		return implode( '', $lines );
	}

	/**
	 * Build a :root block of legacy `--cds-*` aliases.
	 *
	 * Aliases reference the `--nds-*` variables so there is a single source
	 * of truth; changing a token cascades to both prefixes.
	 *
	 * @param array<string, NV_oOS_Design_System_Data_Token> $tokens All tokens.
	 * @return string CSS :root alias block.
	 */
	private function build_legacy_alias_block( $tokens ) {
		$lines = array( ':root{' );

		foreach ( $tokens as $token ) {
			$legacy_var = str_replace( '--nds-', '--cds-', $token->css_var() );
			$lines[]    = sprintf(
				'%s:var(%s);',
				esc_html( $legacy_var ),
				esc_html( $token->css_var() )
			);
		}

		$lines[] = '}';

		return implode( '', $lines );
	}

	// -----------------------------------------------------------------------
	// Accessibility media query blocks.
	// -----------------------------------------------------------------------

	/**
	 * Generate accessibility-related media query blocks.
	 *
	 * Covers:
	 *   - prefers-reduced-motion: reduce
	 *   - prefers-color-scheme: dark (if dark mode tokens exist)
	 *   - prefers-contrast: high / more
	 *
	 * @return string CSS media query blocks.
	 */
	private function generate_a11y_blocks() {
		$css = '';

		$css .= $this->build_reduced_motion_block();
		$css .= $this->build_dark_mode_block();
		$css .= $this->build_high_contrast_block();

		return $css;
	}

	/**
	 * Build @media (prefers-reduced-motion: reduce) block.
	 *
	 * Disables all transition/animation tokens when the user prefers reduced motion.
	 *
	 * @return string
	 */
	private function build_reduced_motion_block() {
		$css  = '@media (prefers-reduced-motion:reduce){';
		$css .= ':root{';
		$css .= '--nds-transition-fast:0ms;';
		$css .= '--nds-transition-normal:0ms;';
		if ( $this->include_legacy_aliases ) {
			$css .= '--cds-transition-fast:0ms;';
			$css .= '--cds-transition-normal:0ms;';
		}
		$css .= '}}';

		return $css;
	}

	/**
	 * Build @media (prefers-color-scheme: dark) block.
	 *
	 * Uses the "dark" token variants if they exist (postfixed with _dark).
	 * Falls back gracefully if no dark tokens are configured.
	 *
	 * @return string
	 */
	private function build_dark_mode_block() {
		$dark_tokens = array();

		foreach ( $this->registry->get_all() as $token ) {
			// Check if there's a corresponding dark-mode token value configured.
			$dark_id = $token->id . '_dark';
			$dark    = $this->registry->get( $dark_id );

			if ( $dark && $dark->value !== $dark->default ) {
				$dark_tokens[ $token->css_var() ] = $dark->value;
			}
		}

		if ( empty( $dark_tokens ) ) {
			return '';
		}

		$css  = '@media (prefers-color-scheme:dark){';
		$css .= ':root{';

		foreach ( $dark_tokens as $var => $value ) {
			$css .= sprintf( '%s:%s;', esc_html( $var ), esc_html( $value ) );
			if ( $this->include_legacy_aliases ) {
				$css .= sprintf( '%s:%s;', esc_html( str_replace( '--nds-', '--cds-', $var ) ), esc_html( $value ) );
			}
		}

		$css .= '}}';

		return $css;
	}

	/**
	 * Build @media (prefers-contrast: high) block.
	 *
	 * Uses high-contrast token variants (postfixed with _hc).
	 *
	 * @return string
	 */
	private function build_high_contrast_block() {
		$hc_tokens = array();

		foreach ( $this->registry->get_all() as $token ) {
			$hc_id = $token->id . '_hc';
			$hc    = $this->registry->get( $hc_id );

			if ( $hc && $hc->value !== $hc->default ) {
				$hc_tokens[ $token->css_var() ] = $hc->value;
			}
		}

		if ( empty( $hc_tokens ) ) {
			return '';
		}

		$css  = '@media (prefers-contrast:high),@media (prefers-contrast:more){';
		$css .= ':root{';

		foreach ( $hc_tokens as $var => $value ) {
			$css .= sprintf( '%s:%s;', esc_html( $var ), esc_html( $value ) );
			if ( $this->include_legacy_aliases ) {
				$css .= sprintf( '%s:%s;', esc_html( str_replace( '--nds-', '--cds-', $var ) ), esc_html( $value ) );
			}
		}

		$css .= '}}';

		return $css;
	}
}
