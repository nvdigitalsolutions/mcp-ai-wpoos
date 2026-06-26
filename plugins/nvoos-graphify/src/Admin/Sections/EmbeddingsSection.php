<?php
declare(strict_types=1);

namespace NvoosGraphify\Admin\Sections;

use NvoosGraphify\Admin\RemoteAdmin;
use NvoosGraphify\Admin\Section;

/**
 * Embeddings settings section.
 *
 * Controls vector embedding generation for graph nodes using
 * OpenAI embedding models. Requires the NV oOS Graphify — AI addon.
 *
 * When the AI addon is not active, the entire section is replaced
 * with an upsell card — no dead settings are shown.
 *
 * @since 1.0.0
 */
class EmbeddingsSection extends Section {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'embeddings_section';
	}

	/**
	 * @inheritDoc
	 */
	public function get_title(): string {
		return __( 'Embeddings', 'nvoos-graphify' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_tab(): string {
		return 'embeddings';
	}

	/**
	 * @inheritDoc
	 */
	public function get_priority(): int {
		return 10;
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
		if ( ! $this->isAiAddonActive() ) {
			return array();
		}

		return array(
			'embeddings_enabled' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable Embeddings', 'nvoos-graphify' ),
				'description' => __( 'Generate and store vector embeddings for nodes (requires an API key configured in NV oOS Graphify — AI).', 'nvoos-graphify' ),
			),
			'embeddings_model'   => array(
				'type'        => 'select',
				'label'       => __( 'Embeddings Model', 'nvoos-graphify' ),
				'description' => __( 'OpenAI embedding model used when generating node vectors.', 'nvoos-graphify' ),
				'options'     => array(
					'text-embedding-3-small' => __( 'text-embedding-3-small (recommended)', 'nvoos-graphify' ),
					'text-embedding-3-large' => __( 'text-embedding-3-large (higher quality, slower)', 'nvoos-graphify' ),
					'text-embedding-ada-002' => __( 'text-embedding-ada-002 (legacy)', 'nvoos-graphify' ),
				),
				'default'     => 'text-embedding-3-small',
			),
		);
	}

	/**
	 * Render the section wrapper.
	 *
	 * When the AI addon is active, shows the normal form-table fields
	 * followed by the embeddings index panel.
	 *
	 * When the AI addon is not active, shows an upsell card instead
	 * of dead settings.
	 *
	 * @inheritDoc
	 */
	public function render_wrapper( string $page_slug = '' ): void {
		if ( ! $this->isAiAddonActive() ) {
			$this->renderUpsell();
			return;
		}

		parent::render_wrapper( $page_slug );

		if ( class_exists( 'NvoosGraphify\Admin\RemoteAdmin' ) ) {
			RemoteAdmin::renderEmbeddingsPanel();
		}
	}

	/**
	 * Render an upsell card for the AI addon.
	 *
	 * @return void
	 */
	private function renderUpsell(): void {
		?>
		<h2><?php echo \esc_html( $this->get_title() ); ?></h2>
		<div class="nvoos-graphify-upsell-card" style="background:#f0f6fc;border:1px solid #c5d9ed;border-left:4px solid #0073aa;padding:12px 16px;max-width:700px;">
			<p style="margin:0 0 8px;">
				<strong><?php esc_html_e( 'Vector embeddings require the AI addon', 'nvoos-graphify' ); ?></strong>
			</p>
			<p style="margin:0 0 8px;">
				<?php esc_html_e( 'NV oOS Graphify — AI adds vector embeddings, semantic search, RAG retrieval, and agent memory to your knowledge graph. Once installed, you can generate embeddings for every node using OpenAI, Google Gemini, or any of the 13 supported providers.', 'nvoos-graphify' ); ?>
			</p>
			<p style="margin:0;">
				<a href="https://github.com/nvdigitalsolutions/nvoos-graphify-ai" class="button button-secondary" target="_blank" rel="noopener">
					<?php esc_html_e( 'Get NV oOS Graphify — AI', 'nvoos-graphify' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
