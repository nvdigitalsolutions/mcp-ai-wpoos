<?php
declare(strict_types=1);

namespace NvoosContentGraph\Frontend;

use NvoosContentGraph\Settings;
use NvoosContentGraph\Schema;
use function absint;
use function apply_filters;
use function array_merge;
use function esc_attr;
use function esc_url_raw;
use function in_array;
use function max;
use function min;
use function rest_url;
use function sanitize_key;
use function sanitize_text_field;
use function shortcode_atts;
use function str_replace;
use function wp_add_inline_script;
use function wp_create_nonce;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_json_encode;
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
				'mode'            => 'full',
				'community_id'    => '',
				'post_id'         => 0,
				'height'          => '600px',
				'max_nodes'       => 300,
				// Visual experience atts (defaults come from Appearance settings).
				'theme'           => '',
				'color_by'        => '',
				'show_legend'     => '',
				'show_icons'      => '',
				'show_edges'      => '',
				'edge_style'      => '',
				'min_label_zoom'  => '',
				'label_font_size' => '',
			),
			(array) $atts,
			'nvoos_content_graph'
		);

		$mode        = sanitize_key( $atts['mode'] );
		$communityId = sanitize_text_field( $atts['community_id'] );
		$postId      = absint( $atts['post_id'] );
		$height      = sanitize_text_field( $atts['height'] );
		$maxNodes    = max( 10, min( 2000, absint( $atts['max_nodes'] ) ) );

		// Visual overrides for this embed. Empty att values inherit the
		// Appearance settings; explicit values are sanitized and passed through.
		$visualOverrides = array();

		$theme = sanitize_key( $atts['theme'] );
		if ( in_array( $theme, array( 'dark', 'light', 'auto', 'admin' ), true ) ) {
			$visualOverrides['theme'] = $theme;
		}

		$colorBy = sanitize_key( $atts['color_by'] );
		if ( in_array( $colorBy, array( 'type', 'community', 'degree', 'monochrome' ), true ) ) {
			$visualOverrides['color_by'] = $colorBy;
		}

		$edgeStyle = sanitize_key( $atts['edge_style'] );
		if ( in_array( $edgeStyle, array( 'plain', 'arrows', 'tapered', 'density', 'auto' ), true ) ) {
			$visualOverrides['edge_style'] = $edgeStyle;
		}

		if ( '' !== $atts['show_legend'] ) {
			$visualOverrides['show_legend'] = ! empty( $atts['show_legend'] );
		}
		if ( '' !== $atts['show_icons'] ) {
			$visualOverrides['show_icons'] = ! empty( $atts['show_icons'] );
		}
		if ( '' !== $atts['show_edges'] ) {
			$visualOverrides['show_edges'] = ! empty( $atts['show_edges'] );
		}
		if ( '' !== $atts['min_label_zoom'] ) {
			$visualOverrides['min_label_zoom'] = max( 0.0, min( 1.0, (float) $atts['min_label_zoom'] ) );
		}
		if ( '' !== $atts['label_font_size'] ) {
			$visualOverrides['label_font_size'] = max( 9, min( 16, absint( $atts['label_font_size'] ) ) );
		}

		$visual = \NvoosContentGraph\Visual\Tokens::visual_config( Settings::all() );
		$visual = array_merge( $visual, $visualOverrides );
		$visual = apply_filters( Schema::FILTER_VISUAL_CONFIG, $visual, $atts );

		$containerId = 'nvoos-content-graph-' . wp_unique_id();

		// Enqueue Cytoscape.js + layout extensions (vendored).
		// Handles are prefixed 'nvoos-content-graph-' to avoid collisions with
		// other plugins that enqueue cytoscape under the bare 'cytoscape' handle.
		$vendorUrl = NVOOS_CONTENT_GRAPH_URL . 'assets/vendor/';
		wp_enqueue_script( 'nvoos-content-graph-layout-base', $vendorUrl . 'layout-base/layout-base.js', array(), NVOOS_CONTENT_GRAPH_VERSION, true );
		wp_enqueue_script( 'nvoos-content-graph-cose-base', $vendorUrl . 'cose-base/cose-base.js', array( 'nvoos-content-graph-layout-base' ), NVOOS_CONTENT_GRAPH_VERSION, true );
		wp_enqueue_script( 'nvoos-content-graph-cytoscape', $vendorUrl . 'cytoscape/cytoscape.min.js', array(), NVOOS_CONTENT_GRAPH_VERSION, true );
		wp_enqueue_script(
			'nvoos-content-graph-cytoscape-fcose',
			$vendorUrl . 'cytoscape-fcose/cytoscape-fcose.js',
			array( 'nvoos-content-graph-cytoscape', 'nvoos-content-graph-cose-base' ),
			NVOOS_CONTENT_GRAPH_VERSION,
			true
		);

		// Visual experience system (icon glyphs + theme engine).
		wp_enqueue_script(
			'nvoos-content-graph-icons',
			NVOOS_CONTENT_GRAPH_URL . 'assets/js/content-graph-icons.js',
			array(),
			NVOOS_CONTENT_GRAPH_VERSION,
			true
		);
		wp_enqueue_script(
			'nvoos-content-graph-theme',
			NVOOS_CONTENT_GRAPH_URL . 'assets/js/content-graph-theme.js',
			array( 'nvoos-content-graph-icons' ),
			NVOOS_CONTENT_GRAPH_VERSION,
			true
		);

		wp_enqueue_script(
			'nvoos-content-graph-frontend',
			NVOOS_CONTENT_GRAPH_URL . 'assets/js/content-graph-frontend.js',
			array( 'jquery', 'nvoos-content-graph-cytoscape', 'nvoos-content-graph-cytoscape-fcose', 'nvoos-content-graph-theme' ),
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
					'visual'       => $visual,
				)
			) . ';',
			'before'
		);

		return '<div id="' . esc_attr( $containerId ) . '" class="nvoos-content-graph-embed" style="height:' . esc_attr( $height ) . ';"></div>';
	}
}
