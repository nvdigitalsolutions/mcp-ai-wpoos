<?php
declare(strict_types=1);

namespace NvoosContentGraph\Admin\Sections;

use NvoosContentGraph\Admin\Section;

/**
 * Build settings section.
 *
 * Controls how the knowledge graph is constructed — AI extraction,
 * incremental processing, auto-rebuild triggers, and scheduling.
 *
 * When the NV oOS Content Graph — AI addon is not active, AI-dependent
 * fields (semantic_extraction, openai_api_key) are replaced with an
 * upsell card so no dead settings are shown.
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
		return __( 'Build', 'nvoos-content-graph' );
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
	 * Whether the AI addon is installed and active.
	 *
	 * @return bool
	 */
	private function isAiAddonActive(): bool {
		return class_exists( 'NvoosContentGraphAi\Plugin' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_fields(): array {
		$fields = array(
			'incremental_builds' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Incremental Builds', 'nvoos-content-graph' ),
				'description' => __( 'Only process content modified since last build.', 'nvoos-content-graph' ),
			),
			'auto_rebuild'       => array(
				'type'        => 'checkbox',
				'label'       => __( 'Auto-Rebuild on Save', 'nvoos-content-graph' ),
				'description' => __( 'Trigger an incremental rebuild whenever a post is published or updated.', 'nvoos-content-graph' ),
			),
			'rebuild_schedule'   => array(
				'type'        => 'select',
				'label'       => __( 'Scheduled Rebuild', 'nvoos-content-graph' ),
				'description' => __( 'Choose how often the graph should be rebuilt.', 'nvoos-content-graph' ),
				'options'     => array(
					'hourly'     => __( 'Hourly', 'nvoos-content-graph' ),
					'twicedaily' => __( 'Twice Daily', 'nvoos-content-graph' ),
					'daily'      => __( 'Daily', 'nvoos-content-graph' ),
					'weekly'     => __( 'Weekly', 'nvoos-content-graph' ),
					'never'      => __( 'Never (disable scheduled rebuilds)', 'nvoos-content-graph' ),
				),
				'default'     => 'weekly',
			),
		);

		// AI-dependent fields — only shown when the AI addon is active.
		if ( $this->isAiAddonActive() ) {
			$fields['semantic_extraction'] = array(
				'type'        => 'checkbox',
				'label'       => __( 'Semantic Extraction', 'nvoos-content-graph' ),
				'description' => __( 'Use AI to extract named entities and topics from content.', 'nvoos-content-graph' ),
			);
			$fields['openai_api_key']      = array(
				'type'        => 'password',
				'label'       => __( 'OpenAI API Key (optional)', 'nvoos-content-graph' ),
				'description' => __( 'Used as fallback when the oOS AI provider is not available. Leave blank to use the global oOS key.', 'nvoos-content-graph' ),
			);
		}

		return $fields;
	}

	/**
	 * @inheritDoc
	 */
	public function render_wrapper( string $page_slug = '' ): void {
		parent::render_wrapper( $page_slug );

		if ( ! $this->isAiAddonActive() ) {
			$this->renderUpsell();
		}
	}

	/**
	 * Render an upsell card for the AI addon.
	 *
	 * Shown at the bottom of the Build section when the AI addon
	 * is not active, pointing users to the features they're missing.
	 *
	 * @return void
	 */
	private function renderUpsell(): void {
		?>
		<div class="nvoos-content-graph-upsell-card" style="background:#f0f6fc;border:1px solid #c5d9ed;border-left:4px solid #0073aa;padding:12px 16px;margin-top:16px;max-width:700px;">
			<p style="margin:0 0 8px;">
				<strong><?php esc_html_e( 'Unlock AI-powered features', 'nvoos-content-graph' ); ?></strong>
			</p>
			<p style="margin:0 0 8px;">
				<?php esc_html_e( 'Install the NV oOS Content Graph — AI addon to enable semantic extraction, AI chat, embeddings, and agent memory for your knowledge graph. Supports 13 AI providers with a single API key.', 'nvoos-content-graph' ); ?>
			</p>
			<p style="margin:0;">
				<button type="button" class="button button-primary nvoos-content-graph-buy-ai">
					<?php esc_html_e( 'Get NV oOS Content Graph — AI', 'nvoos-content-graph' ); ?>
				</button>
				<a href="https://github.com/nvdigitalsolutions/nvoos-content-graph-ai" class="button button-link" target="_blank" rel="noopener">
					<?php esc_html_e( 'Learn more', 'nvoos-content-graph' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
