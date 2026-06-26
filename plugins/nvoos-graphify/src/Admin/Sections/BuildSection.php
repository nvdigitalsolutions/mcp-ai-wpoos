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
 * When the NV oOS Graphify — AI addon is not active, AI-dependent
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
	 * Whether the AI addon is installed and active.
	 *
	 * @return bool
	 */
	private function isAiAddonActive(): bool {
		return class_exists( 'NvoosGraphifyAi\Plugin' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_fields(): array {
		$fields = array(
			'incremental_builds' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Incremental Builds', 'nvoos-graphify' ),
				'description' => __( 'Only process content modified since last build.', 'nvoos-graphify' ),
			),
			'auto_rebuild'       => array(
				'type'        => 'checkbox',
				'label'       => __( 'Auto-Rebuild on Save', 'nvoos-graphify' ),
				'description' => __( 'Trigger an incremental rebuild whenever a post is published or updated.', 'nvoos-graphify' ),
			),
			'rebuild_schedule'   => array(
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
		);

		// AI-dependent fields — only shown when the AI addon is active.
		if ( $this->isAiAddonActive() ) {
			$fields['semantic_extraction'] = array(
				'type'        => 'checkbox',
				'label'       => __( 'Semantic Extraction', 'nvoos-graphify' ),
				'description' => __( 'Use AI to extract named entities and topics from content.', 'nvoos-graphify' ),
			);
			$fields['openai_api_key']      = array(
				'type'        => 'password',
				'label'       => __( 'OpenAI API Key (optional)', 'nvoos-graphify' ),
				'description' => __( 'Used as fallback when the oOS AI provider is not available. Leave blank to use the global oOS key.', 'nvoos-graphify' ),
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
		<div class="nvoos-graphify-upsell-card" style="background:#f0f6fc;border:1px solid #c5d9ed;border-left:4px solid #0073aa;padding:12px 16px;margin-top:16px;max-width:700px;">
			<p style="margin:0 0 8px;">
				<strong><?php esc_html_e( 'Unlock AI-powered features', 'nvoos-graphify' ); ?></strong>
			</p>
			<p style="margin:0 0 8px;">
				<?php esc_html_e( 'Install the NV oOS Graphify — AI addon to enable semantic extraction, AI chat, embeddings, and agent memory for your knowledge graph. Supports 13 AI providers with a single API key.', 'nvoos-graphify' ); ?>
			</p>
			<p style="margin:0;">
				<a href="https://github.com/nvdigitalsolutions/nvoos-graphify-ai" class="button button-secondary" target="_blank" rel="noopener">
					<?php esc_html_e( 'Learn more about NV oOS Graphify — AI', 'nvoos-graphify' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
