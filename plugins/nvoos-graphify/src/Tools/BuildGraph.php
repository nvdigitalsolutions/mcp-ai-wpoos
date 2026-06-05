<?php
declare(strict_types=1);

namespace NvoosGraphify\Tools;

use function absint;
use function get_current_user_id;
use function user_can;
use function __;

/**
 * Tool: nvoos_graphify_build_graph
 *
 * Triggers a full or incremental knowledge graph build pipeline.
 *
 * @since 1.0.0
 */
class BuildGraph extends AbstractTool {

	/**
	 * {@inheritdoc}
	 */
	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getSlug(): string {
		return 'nvoos_graphify_build_graph';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getName(): string {
		return __( 'Build Knowledge Graph', 'nvoos-graphify' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDescription(): string {
		return __( 'Build or rebuild the WordPress knowledge graph. Detects published posts, taxonomy terms, users, and media; extracts structural links (internal links, taxonomies, authorship) and optionally AI-powered semantic entities and topics. Returns a summary of nodes and edges created. Use incremental=true to only process content changed since the last build.', 'nvoos-graphify' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'incremental'    => array(
					'type'        => 'boolean',
					'description' => __( 'Only process content modified since the last build. Faster for routine updates. Default: false.', 'nvoos-graphify' ),
					'default'     => false,
				),
				'semantic'       => array(
					'type'        => 'boolean',
					'description' => __( 'Run AI-powered semantic entity and topic extraction. Requires an AI provider. Default: true.', 'nvoos-graphify' ),
					'default'     => true,
				),
				'async_semantic' => array(
					'type'        => 'boolean',
					'description' => __( 'Dispatch semantic extraction to WP Cron (non-blocking). Default: false.', 'nvoos-graphify' ),
					'default'     => false,
				),
				'reset'          => array(
					'type'        => 'boolean',
					'description' => __( 'Truncate existing graph before building. Only applies when incremental=false. Default: false.', 'nvoos-graphify' ),
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCapabilityFlags(): array {
		return array( 'write', 'state-changing', 'async', 'long-running', 'performance-impact' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @param array<string,mixed> $context   Execution context.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'graphify_forbidden', __( 'Building the knowledge graph requires administrator access.', 'nvoos-graphify' ) );
		}

		$result = \NvoosGraphify\Graph\Builder::build(
			array(
				'incremental'    => ! empty( $arguments['incremental'] ),
				'semantic'       => ! isset( $arguments['semantic'] ) || ! empty( $arguments['semantic'] ),
				'async_semantic' => ! empty( $arguments['async_semantic'] ),
				'reset'          => ! empty( $arguments['reset'] ),
			)
		);

		return $result;
	}
}
