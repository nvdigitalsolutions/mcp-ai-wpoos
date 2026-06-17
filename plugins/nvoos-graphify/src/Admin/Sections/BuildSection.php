<?php
declare(strict_types=1);

namespace NvoosGraphify\Admin\Sections;

use NvoosGraphify\Admin\Section;

/**
 * Build settings section.
 *
 * Controls how the knowledge graph is constructed — AI extraction,
 * incremental processing, auto-rebuild triggers, and scheduling.
 *
 * @since 1.0.0
 */
class BuildSection extends Section {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'build_section';
	}

	/**
	 * @inheritDoc
	 */
	public function get_title(): string {
		return __( 'Build', 'nvoos-graphify' );
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
		return 20;
	}

	/**
	 * @inheritDoc
	 */
	public function get_fields(): array {
		return array(
			'semantic_extraction' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Semantic Extraction', 'nvoos-graphify' ),
				'description' => __( 'Use AI to extract named entities and topics from content.', 'nvoos-graphify' ),
			),
			'incremental_builds'  => array(
				'type'        => 'checkbox',
				'label'       => __( 'Incremental Builds', 'nvoos-graphify' ),
				'description' => __( 'Only process content modified since last build.', 'nvoos-graphify' ),
			),
			'auto_rebuild'        => array(
				'type'        => 'checkbox',
				'label'       => __( 'Auto-Rebuild on Save', 'nvoos-graphify' ),
				'description' => __( 'Trigger an incremental rebuild whenever a post is published or updated.', 'nvoos-graphify' ),
			),
			'rebuild_schedule'    => array(
				'type'        => 'select',
				'label'       => __( 'Scheduled Rebuild', 'nvoos-graphify' ),
				'description' => __( 'Choose how often the graph should be rebuilt.', 'nvoos-graphify' ),
				'options'     => array(
					'hourly'     => __( 'Hourly', 'nvoos-graphify' ),
					'twicedaily' => __( 'Twice Daily', 'nvoos-graphify' ),
					'daily'      => __( 'Daily', 'nvoos-graphify' ),
					'weekly'     => __( 'Weekly', 'nvoos-graphify' ),
				),
				'default'     => 'weekly',
			),
			'openai_api_key'      => array(
				'type'        => 'password',
				'label'       => __( 'OpenAI API Key (optional)', 'nvoos-graphify' ),
				'description' => __( 'Used as fallback when the oOS AI provider is not available. Leave blank to use the global oOS key.', 'nvoos-graphify' ),
			),
		);
	}
}
