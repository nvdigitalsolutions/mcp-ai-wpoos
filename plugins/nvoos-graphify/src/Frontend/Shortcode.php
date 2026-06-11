<?php
declare(strict_types=1);

namespace NvoosGraphify\Frontend;

use NvoosGraphify\Graph\Db;
use NvoosGraphify\Settings;
use NvoosGraphify\Schema;
use function shortcode_atts;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_unique_id;

/**
 * `[nvoos_graph]` shortcode.
 *
 * Embeds an interactive Cytoscape.js knowledge graph viewer on
 * any post or page. Supports full, community, and ego modes.
 *
 * @since 1.0.0
 */
class Shortcode {

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'nvoos_graph', array( $this, 'render' ) );
	}

	/**
	 * Render the `[nvoos_graph]` shortcode.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'mode'         => 'full',
				'community_id' => '',
				'post_id'      => 0,
				'height'       => '600px',
				'max_nodes'    => 300,
			),
			(array) $atts,
			'nvoos_graphify'
		);

		$mode        = sanitize_key( $atts['mode'] );
		$communityId = sanitize_text_field( $atts['community_id'] );
		$postId      = absint( $atts['post_id'] );
		$height      = sanitize_text_field( $atts['height'] );
		$maxNodes    = max( 10, min( 2000, absint( $atts['max_nodes'] ) ) );

		$containerId = 'nvoos-graphify-' . wp_unique_id();

		// Enqueue Cytoscape.js + layout extensions (vendored).
		$vendorUrl = NVOOS_GRAPHIFY_URL . 'assets/vendor/';
		wp_enqueue_script( 'cytoscape-layout-base', $vendorUrl . 'layout-base/layout-base.js', array(), NVOOS_GRAPHIFY_VERSION, true );
		wp_enqueue_script( 'cytoscape-cose-base', $vendorUrl . 'cose-base/cose-base.js', array( 'cytoscape-layout-base' ), NVOOS_GRAPHIFY_VERSION, true );
		wp_enqueue_script( 'cytoscape', $vendorUrl . 'cytoscape/cytoscape.min.js', array(), NVOOS_GRAPHIFY_VERSION, true );
		wp_enqueue_script( 'cytoscape-fcose', $vendorUrl . 'cytoscape-fcose/cytoscape-fcose.js', array( 'cytoscape', 'cytoscape-cose-base' ), NVOOS_GRAPHIFY_VERSION, true );

		wp_enqueue_script(
			'nvoos-graphify-frontend',
			NVOOS_GRAPHIFY_URL . 'assets/js/graphify-frontend.js',
			array( 'jquery', 'cytoscape', 'cytoscape-fcose' ),
			NVOOS_GRAPHIFY_VERSION,
			true
		);
		wp_enqueue_style(
			'nvoos-graphify-frontend',
			NVOOS_GRAPHIFY_URL . 'assets/css/graphify-frontend.css',
			array(),
			NVOOS_GRAPHIFY_VERSION
		);

		wp_add_inline_script(
			'nvoos-graphify-frontend',
			'window.nvoosGraphifyData' . wp_json_encode( $containerId ) . ' = ' . wp_json_encode(
				array(
					'container'    => $containerId,
					'mode'         => $mode,
					'community_id' => $communityId,
					'post_id'      => $postId,
					'max_nodes'    => $maxNodes,
					'rest_url'     => esc_url_raw( rest_url( Schema::REST_NAMESPACE ) ),
					'nonce'        => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);

		return '<div id="' . esc_attr( $containerId ) . '" class="nvoos-graphify-embed" style="height:' . esc_attr( $height ) . ';"></div>';
	}
}
