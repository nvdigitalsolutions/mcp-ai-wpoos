<?php
declare(strict_types=1);

namespace NvoosContentGraph\Frontend;

/**
 * Gutenberg block registration for NV oOS Content Graph.
 *
 * Registers the `nvoos-content-graph/graph` block with server-side
 * rendering that delegates to the shortcode renderer.
 *
 * @since 1.0.0
 */
class Block {

	/**
	 * Register the block on `init`.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'registerBlockType' ) );
	}

	/**
	 * Register the block type with WordPress.
	 *
	 * @return void
	 */
	public function registerBlockType(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			'nvoos-content-graph/graph',
			array(
				'api_version'     => 3,
				'attributes'      => array(
					'mode'            => array(
						'type'    => 'string',
						'default' => 'full',
					),
					'community_id'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'post_id'         => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'height'          => array(
						'type'    => 'string',
						'default' => '600px',
					),
					'max_nodes'       => array(
						'type'    => 'integer',
						'default' => 300,
					),
					// Visual experience attributes. `null` defaults mean
					// "inherit the Appearance settings" — the shortcode only
					// receives explicit values once an inspector control is used.
					'theme'           => array(
						'type'    => 'string',
						'default' => '',
					),
					'color_by'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'show_legend'     => array(
						'type'    => 'boolean',
						'default' => null,
					),
					'show_icons'      => array(
						'type'    => 'boolean',
						'default' => null,
					),
					'show_edges'      => array(
						'type'    => 'boolean',
						'default' => null,
					),
					'edge_style'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'min_label_zoom'  => array(
						'type'    => 'number',
						'default' => null,
					),
					'label_font_size' => array(
						'type'    => 'number',
						'default' => null,
					),
				),
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * Server-side render callback for the Gutenberg block.
	 *
	 * Delegates to the Shortcode renderer.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string HTML output.
	 */
	public function render( array $attributes ): string {
		$shortcode = new Shortcode();

		// Boolean/number block attributes arrive as true/false or floats;
		// the shortcode atts expect '' (inherit) or explicit 1/0 values.
		// `null` means the user never touched the inspector control, so the
		// Appearance settings keep driving the embed.
		foreach ( array( 'show_legend', 'show_icons', 'show_edges' ) as $key ) {
			if ( isset( $attributes[ $key ] ) && null !== $attributes[ $key ] ) {
				$attributes[ $key ] = $attributes[ $key ] ? '1' : '0';
			}
		}
		foreach ( array( 'min_label_zoom', 'label_font_size' ) as $key ) {
			if ( isset( $attributes[ $key ] ) && null !== $attributes[ $key ] ) {
				$attributes[ $key ] = (string) $attributes[ $key ];
			}
		}

		return $shortcode->render( $attributes );
	}
}
