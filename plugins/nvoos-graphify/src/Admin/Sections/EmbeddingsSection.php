<?php
declare(strict_types=1);

namespace NvoosGraphify\Admin\Sections;

use NvoosGraphify\Admin\Section;

/**
 * Embeddings settings section.
 *
 * Controls vector embedding generation for graph nodes using
 * OpenAI embedding models.
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
	 * @inheritDoc
	 */
	public function get_fields(): array {
		return array(
			'embeddings_enabled' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable Embeddings', 'nvoos-graphify' ),
				'description' => __( 'Generate and store vector embeddings for nodes (requires OpenAI API key).', 'nvoos-graphify' ),
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
}
