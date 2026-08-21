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
					'mode'         => array(
						'type'    => 'string',
						'default' => 'full',
					),
					'community_id' => array(
						'type'    => 'string',
						'default' => '',
					),
					'post_id'      => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'height'       => array(
						'type'    => 'string',
						'default' => '600px',
					),
					'max_nodes'    => array(
						'type'    => 'integer',
						'default' => 300,
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
		return $shortcode->render( $attributes );
	}
}
