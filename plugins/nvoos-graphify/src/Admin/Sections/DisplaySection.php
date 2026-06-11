<?php
declare(strict_types=1);

namespace NvoosGraphify\Admin\Sections;

use NvoosGraphify\Admin\Section;

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
		return __( 'Display', 'nvoos-graphify' );
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
	 */
	public function get_fields(): array {
		return array(
			'schema_injection'  => array(
				'type'        => 'checkbox',
				'label'       => __( 'Schema.org Injection', 'nvoos-graphify' ),
				'description' => __( 'Inject Schema.org JSON-LD (about, relatedLink) on singular views.', 'nvoos-graphify' ),
			),
			'related_content'   => array(
				'type'        => 'checkbox',
				'label'       => __( 'Related Content Widget', 'nvoos-graphify' ),
				'description' => __( 'Append a Related Content list from graph neighbors below singular post content.', 'nvoos-graphify' ),
			),
			'cytoscape_height'  => array(
				'type'        => 'text',
				'label'       => __( 'Graph Explorer Height', 'nvoos-graphify' ),
				'description' => __( 'CSS height for the graph explorer (e.g. 600px, 80vh).', 'nvoos-graphify' ),
				'default'     => '600px',
			),
			'max_display_nodes' => array(
				'type'        => 'number',
				'label'       => __( 'Max Display Nodes', 'nvoos-graphify' ),
				'description' => __( 'Maximum nodes to render in the graph explorer. Lower values improve browser performance.', 'nvoos-graphify' ),
				'min'         => 50,
				'max'         => 2000,
				'default'     => 300,
			),
		);
	}
}
