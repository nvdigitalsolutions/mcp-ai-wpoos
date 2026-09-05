<?php
declare(strict_types=1);

namespace NvoosContentGraph\Admin\Sections;

use NvoosContentGraph\Admin\Section;

use function preg_match;

/**
 * Display settings section.
 *
 * Controls front-end rendering — Schema.org injection, related
 * content widgets, and the interactive graph explorer.
 *
 * @since 1.0.0
 */
class DisplaySection extends Section {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'display_section';
	}

	/**
	 * @inheritDoc
	 */
	public function get_title(): string {
		return __( 'Display', 'nvoos-content-graph' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_tab(): string {
		return 'general';
	}

	/**
	 * @inheritDoc
	 */
	public function get_priority(): int {
		return 30;
	}

	/**
	 * @inheritDoc
	 *
	 * Additionally validates the CSS height against a strict length
	 * format so arbitrary values can never reach the inline style.
	 */
	public function sanitize( array $input ): array {
		$out = parent::sanitize( $input );

		if ( isset( $out['cytoscape_height'] ) ) {
			$height = (string) $out['cytoscape_height'];
			if ( '' === $height || ! preg_match( '/^\d+(?:\.\d+)?(?:px|%|vh|vw|em|rem)$/', $height ) ) {
				$out['cytoscape_height'] = '600px';
			}
		}

		return $out;
	}

	/**
	 * @inheritDoc
	 */
	public function get_fields(): array {
		return array(
			'schema_injection'  => array(
				'type'        => 'checkbox',
				'label'       => __( 'Schema.org Injection', 'nvoos-content-graph' ),
				'description' => __( 'Inject Schema.org JSON-LD (about, relatedLink) on singular views.', 'nvoos-content-graph' ),
			),
			'related_content'   => array(
				'type'        => 'checkbox',
				'label'       => __( 'Related Content Widget', 'nvoos-content-graph' ),
				'description' => __( 'Append a Related Content list from graph neighbors below singular post content.', 'nvoos-content-graph' ),
			),
			'cytoscape_height'  => array(
				'type'        => 'text',
				'label'       => __( 'Graph Explorer Height', 'nvoos-content-graph' ),
				'description' => __( 'CSS height for the graph explorer (e.g. 600px, 80vh).', 'nvoos-content-graph' ),
				'default'     => '600px',
			),
			'max_display_nodes' => array(
				'type'        => 'number',
				'label'       => __( 'Max Display Nodes', 'nvoos-content-graph' ),
				'description' => __( 'Maximum nodes to render in the graph explorer. Lower values improve browser performance.', 'nvoos-content-graph' ),
				'min'         => 50,
				'max'         => 2000,
				'default'     => 300,
			),
		);
	}
}
