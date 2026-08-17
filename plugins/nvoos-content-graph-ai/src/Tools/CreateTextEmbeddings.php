<?php
declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use NvoosContentGraphAi\Plugin;
use NvoosContentGraphAi\Contracts\ProviderClient;

/**
 * Generate text embeddings using the configured AI provider.
 */
class CreateTextEmbeddings extends AbstractAiTool {
	public function getSlug(): string {
		return 'ai_create_text_embeddings'; }
	public function getName(): string {
		return __( 'Create Text Embeddings', 'nvoos-content-graph-ai' ); }
	public function getDescription(): string {
		return __( 'Generate vector embeddings for text using the configured AI provider. Used for semantic search and similarity.', 'nvoos-content-graph-ai' );
	}
	public function getCapabilityFlags(): array {
		return array( 'external-api', 'modifies-state' );
	}
	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'input'          => array(
					'description' => 'Text to embed.',
					'oneOf'       => array(
						array( 'type' => 'string' ),
						array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'store_in_graph' => array(
					'type'        => 'boolean',
					'description' => 'Store in knowledge graph embeddings table.',
					'default'     => false,
				),
				'node_id'        => array(
					'type'        => 'string',
					'description' => 'Node ID to attach embeddings to.',
				),
			),
			'required'   => array( 'input' ),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$input = $arguments['input'] ?? '';
		if ( empty( $input ) ) {
			return new \WP_Error( 'nvoos_content_graph_ai', __( 'Input text is required.', 'nvoos-content-graph-ai' ) );
		}

		$provider = Plugin::instance()->getProviderRegistry()->getDefault();
		if ( ! $provider instanceof ProviderClient ) {
			return new \WP_Error( 'nvoos_content_graph_ai', __( 'No AI provider available.', 'nvoos-content-graph-ai' ) );
		}

		// Generate embeddings by asking the provider for a structured response.
		$texts      = is_array( $input ) ? $input : array( $input );
		$embeddings = array();

		foreach ( $texts as $text ) {
			$prompt   = "Generate a JSON array of 512 floating point numbers representing a semantic embedding for this text. Return ONLY the JSON array, nothing else:\n\n{$text}";
			$messages = array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			);
			$result   = $provider->chat( $messages, array( 'temperature' => 0 ) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$content = trim( $result['content'] ?? '' );
			$content = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $content );
			$vector  = json_decode( $content, true );

			if ( is_array( $vector ) ) {
				$embeddings[] = $vector;
			}
		}

		// Optionally store in the graph's embeddings table.
		$nodeId       = sanitize_text_field( $arguments['node_id'] ?? '' );
		$storeInGraph = (bool) ( $arguments['store_in_graph'] ?? false );

		if ( $storeInGraph && ! empty( $nodeId ) && ! empty( $embeddings ) ) {
			$this->storeEmbedding( $nodeId, $embeddings[0] );
		}

		return array(
			'success'    => true,
			'embeddings' => $embeddings,
			'count'      => count( $embeddings ),
		);
	}

	private function storeEmbedding( string $nodeId, array $vector ): void {
		\NvoosContentGraph\Graph\Db::upsertEmbedding(
			array(
				'node_id' => $nodeId,
				'dim'     => count( $vector ),
				'vector'  => \wp_json_encode( $vector ),
			)
		);
	}
}
