<?php
declare(strict_types=1);

namespace NvoosContentGraph\Frontend;

use NvoosContentGraph\Graph\Db;
use NvoosContentGraph\Settings;
use NvoosContentGraph\Schema;
use function shortcode_atts;
use function str_replace;
use function wp_add_inline_script;
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
			'nvoos_content_graph'
		);

		$mode        = sanitize_key( $atts['mode'] );
		$communityId = sanitize_text_field( $atts['community_id'] );
		$postId      = absint( $atts['post_id'] );
		$height      = sanitize_text_field( $atts['height'] );
		$maxNodes    = max( 10, min( 2000, absint( $atts['max_nodes'] ) ) );

		$containerId = 'nvoos-content-graph-' . wp_unique_id();

		// Enqueue Cytoscape.js + layout extensions (vendored).
		// Handles are prefixed 'nvoos-content-graph-' to avoid collisions with
		// other plugins that enqueue cytoscape under the bare 'cytoscape' handle.
		$vendorUrl = NVOOS_CONTENT_GRAPH_URL . 'assets/vendor/';
		wp_enqueue_script( 'nvoos-content-graph-layout-base', $vendorUrl . 'layout-base/layout-base.js', array(), NVOOS_CONTENT_GRAPH_VERSION, true );
		wp_enqueue_script( 'nvoos-content-graph-cose-base', $vendorUrl . 'cose-base/cose-base.js', array( 'nvoos-content-graph-layout-base' ), NVOOS_CONTENT_GRAPH_VERSION, true );
		wp_enqueue_script( 'nvoos-content-graph-cytoscape', $vendorUrl . 'cytoscape/cytoscape.min.js', array(), NVOOS_CONTENT_GRAPH_VERSION, true );
		wp_enqueue_script( 'nvoos-content-graph-cytoscape-fcose', $vendorUrl . 'cytoscape-fcose/cytoscape-fcose.js', array( 'nvoos-content-graph-cytoscape', 'nvoos-content-graph-cose-base' ), NVOOS_CONTENT_GRAPH_VERSION, true );

		wp_enqueue_script(
			'nvoos-content-graph-frontend',
			NVOOS_CONTENT_GRAPH_URL . 'assets/js/content-graph-frontend.js',
			array( 'jquery', 'nvoos-content-graph-cytoscape', 'nvoos-content-graph-cytoscape-fcose' ),
			NVOOS_CONTENT_GRAPH_VERSION,
			true
		);
		wp_enqueue_style(
			'nvoos-content-graph-frontend',
			NVOOS_CONTENT_GRAPH_URL . 'assets/css/content-graph-frontend.css',
			array(),
			NVOOS_CONTENT_GRAPH_VERSION
		);

		// Expose the config under the exact global the frontend JS expects:
		// nvoosContentGraphData_<container_id_with_underscores>.
		$dataKey = 'nvoosContentGraphData_' . str_replace( '-', '_', $containerId );

		wp_add_inline_script(
			'nvoos-content-graph-frontend',
			'window.' . $dataKey . ' = ' . wp_json_encode(
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

		return '<div id="' . esc_attr( $containerId ) . '" class="nvoos-content-graph-embed" style="height:' . esc_attr( $height ) . ';"></div>';
	}
}
