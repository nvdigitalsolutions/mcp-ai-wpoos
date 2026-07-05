<?php
/**
 * NV oOS Crocoblock DS — CSS Generator
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
 * @package NV_oOS_Crocoblock_DS
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSS compiler for the Crocoblock Design System.
 *
 * @since 0.1.0
 */
class NV_oOS_Crocoblock_DS_CSS_Generator {

	/**
	 * Token registry instance.
	 *
	 * @var NV_oOS_Crocoblock_DS_Token_Registry
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
	 * Constructor.
	 *
	 * @param NV_oOS_Crocoblock_DS_Token_Registry $registry             Token registry.
	 * @param bool                                $use_typed_properties  Whether to output @property blocks.
	 */
	public function __construct( $registry, $use_typed_properties = false ) {
		$this->registry             = $registry;
		$this->use_typed_properties = $use_typed_properties;
	}

	/**
	 * Map CDS token types to CSS @property syntax values.
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

		// 3. Accessibility media query blocks.
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
			'<style id="cds-tokens">%s</style>',
			$css
		);
	}

	// -----------------------------------------------------------------------
	// @property generation.
	// -----------------------------------------------------------------------

	/**
	 * Generate @property blocks for all tokens.
	 *
	 * @param array<string, NV_oOS_Crocoblock_DS_Data_Token> $tokens All tokens.
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
	 * @param string $type CDS token type.
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
	 * @param array<string, NV_oOS_Crocoblock_DS_Data_Token> $tokens All tokens.
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
		$css .= '--cds-transition-fast:0ms;';
		$css .= '--cds-transition-normal:0ms;';
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
		}

		$css .= '}}';

		return $css;
	}
}
