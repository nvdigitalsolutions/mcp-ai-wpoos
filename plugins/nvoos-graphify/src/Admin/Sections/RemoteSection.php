<?php
declare(strict_types=1);

namespace NvoosGraphify\Admin\Sections;

use NvoosGraphify\Admin\RemoteAdmin;
use NvoosGraphify\Admin\Section;

/**
 * Remote enrichment settings section.
 *
 * Renders the remote enrichment configuration fields (enabled, budget,
 * async) plus the full Remote Sources management UI below them — driver
 * cards, configured sources table, and the add-source modal.
 *
 * @since 1.0.0
 */
class RemoteSection extends Section {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'remote_section';
	}

	/**
	 * @inheritDoc
	 */
	public function get_title(): string {
		return __( 'Remote Enrichment', 'nvoos-graphify' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_tab(): string {
		return 'remote';
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
			'remote_enrich_enabled' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable Remote Enrichment', 'nvoos-graphify' ),
				'description' => __( 'Enrich graph nodes from configured remote sources during each build.', 'nvoos-graphify' ),
			),
			'remote_enrich_budget'  => array(
				'type'        => 'number',
				'label'       => __( 'Enrichment Budget (nodes/run)', 'nvoos-graphify' ),
				'description' => __( 'Maximum nodes to enrich per build run (1–500). Prevents long-running builds.', 'nvoos-graphify' ),
				'min'         => 1,
				'max'         => 500,
				'default'     => 50,
			),
			'remote_enrich_async'   => array(
				'type'        => 'checkbox',
				'label'       => __( 'Async Enrichment', 'nvoos-graphify' ),
				'description' => __( 'Run enrichment in the background via WP-Cron (recommended for large sites).', 'nvoos-graphify' ),
			),
		);
	}

	/**
	 * Render the section wrapper, then the Remote Sources management
	 * UI outside the form-table.
	 *
	 * @inheritDoc
	 */
	public function render_wrapper( string $page_slug = '' ): void {
		parent::render_wrapper( $page_slug );

		if ( class_exists( 'NvoosGraphify\Admin\RemoteAdmin' ) ) {
			RemoteAdmin::renderTab();
		}
	}
}
