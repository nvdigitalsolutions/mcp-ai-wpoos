<?php
declare(strict_types=1);

namespace NvoosGraphify\Admin\Sections;

use NvoosGraphify\Admin\Section;

/**
 * General settings section.
 *
 * Core on/off toggle for the Knowledge Graph addon.
 *
 * @since 1.0.0
 */
class GeneralSection extends Section {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'general_section';
	}

	/**
	 * @inheritDoc
	 */
	public function get_title(): string {
		return __( 'General', 'nvoos-graphify' );
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
		return 10;
	}

	/**
	 * @inheritDoc
	 */
	public function get_fields(): array {
		return array(
			'enabled' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable Graphify', 'nvoos-graphify' ),
				'description' => __( 'Enable the Knowledge Graph addon.', 'nvoos-graphify' ),
			),
		);
	}
}
