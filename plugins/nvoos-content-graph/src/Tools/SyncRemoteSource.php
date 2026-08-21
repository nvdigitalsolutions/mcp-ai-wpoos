<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tools;

use function current_user_can;
use function esc_html;
use function sanitize_key;

/**
 * Tool: nvoos_content_graph_sync_remote_source
 *
 * Triggers a synchronisation run for a single configured remote source driver.
 * Requires manage_options capability.
 *
 * @since 1.0.0
 */
class SyncRemoteSource extends AbstractTool {

	/**
	 * {@inheritdoc}
	 */
	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	/** {@inheritdoc} */
	public function getSlug(): string {
		return 'nvoos_content_graph_sync_remote_source';
	}

	/** {@inheritdoc} */
	public function getName(): string {
		return __( 'Sync Remote Source', 'nvoos-content-graph' );
	}

	/** {@inheritdoc} */
	public function getDescription(): string {
		return __( 'Manually triggers a synchronisation run for a named remote source. Requires manage_options capability. In sync mode it returns the enrichment summary directly; in async mode it schedules the sync to run in the background and returns immediately. Use nvoos_content_graph_list_remote_sources first to discover available source slugs.', 'nvoos-content-graph' );
	}

	/** {@inheritdoc} */
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'slug'  => array(
					'type'        => 'string',
					'description' => __( 'Slug of the remote source to sync.', 'nvoos-content-graph' ),
					'maxLength'   => 128,
				),
				'async' => array(
					'type'        => 'boolean',
					'description' => __( 'Run in background (true) or wait for completion (false).', 'nvoos-content-graph' ),
					'default'     => true,
				),
			),
			'required'             => array( 'slug' ),
			'additionalProperties' => false,
		);
	}

	/** {@inheritdoc} */
	public function getCapabilityFlags(): array {
		return array( 'write', 'state-changing', 'external-api' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @param array<string,mixed> $context   Execution context.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'sync_remote_permission_denied',
				__( 'Permission denied.', 'nvoos-content-graph' )
			);
		}

		$slug  = sanitize_key( $arguments['slug'] ?? '' );
		$async = isset( $arguments['async'] ) ? (bool) $arguments['async'] : true;

		if ( empty( $slug ) ) {
			return new \WP_Error(
				'sync_remote_slug_required',
				__( 'slug is required.', 'nvoos-content-graph' )
			);
		}

		// Validate source exists.
		$source = \NvoosContentGraph\Graph\Db::getRemoteSource( $slug );
		if ( ! $source ) {
			return new \WP_Error(
				'sync_remote_source_not_found',
				sprintf(
					/* translators: %s source slug */
					__( 'Remote source not found: %s', 'nvoos-content-graph' ),
					esc_html( $slug )
				)
			);
		}

		// Remote enrichment requires the Enricher class (Phase 7 feature).
		if ( ! class_exists( \NvoosContentGraph\Remote\Enricher::class ) ) {
			return new \WP_Error(
				'sync_remote_enricher_unavailable',
				__( 'Remote enrichment is not available.', 'nvoos-content-graph' )
			);
		}

		$enricher = new \NvoosContentGraph\Remote\Enricher();
		$summary  = $enricher->syncSource( $slug, $async );

		if ( is_wp_error( $summary ) ) {
			return new \WP_Error(
				'sync_remote_sync_failed',
				$summary->get_error_message()
			);
		}

		return array(
			'success' => true,
			'slug'    => $slug,
			'async'   => $async,
			'summary' => $summary,
		);
	}
}
