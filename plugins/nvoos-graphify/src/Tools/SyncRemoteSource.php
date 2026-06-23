<?php
declare(strict_types=1);

namespace NvoosGraphify\Tools;

use function current_user_can;
use function esc_html;
use function sanitize_key;

/**
 * Tool: nvoos_graphify_sync_remote_source
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
		return 'nvoos_graphify_sync_remote_source';
	}

	/** {@inheritdoc} */
	public function getName(): string {
		return __( 'Sync Remote Source', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function getDescription(): string {
		return __( 'Manually triggers a synchronisation run for a named remote source. Requires manage_options capability. In sync mode it returns the enrichment summary directly; in async mode it schedules the sync to run in the background and returns immediately. Use nvoos_graphify_list_remote_sources first to discover available source slugs.', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'slug'  => array(
					'type'        => 'string',
					'description' => __( 'Slug of the remote source to sync.', 'nvoos-graphify' ),
					'maxLength'   => 128,
				),
				'async' => array(
					'type'        => 'boolean',
					'description' => __( 'Run in background (true) or wait for completion (false).', 'nvoos-graphify' ),
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
			return array(
				'success' => false,
				'error'   => __( 'Permission denied.', 'nvoos-graphify' ),
			);
		}

		$slug  = sanitize_key( $arguments['slug'] ?? '' );
		$async = isset( $arguments['async'] ) ? (bool) $arguments['async'] : true;

		if ( empty( $slug ) ) {
			return array(
				'success' => false,
				'error'   => __( 'slug is required.', 'nvoos-graphify' ),
			);
		}

		// Validate source exists.
		$source = \NvoosGraphify\Graph\Db::getRemoteSource( $slug );
		if ( ! $source ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s source slug */
					__( 'Remote source not found: %s', 'nvoos-graphify' ),
					esc_html( $slug )
				),
			);
		}

		// Remote enrichment requires the Enricher class (Phase 7 feature).
		if ( ! class_exists( \NvoosGraphify\Remote\Enricher::class ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Remote enrichment is not available.', 'nvoos-graphify' ),
			);
		}

		$enricher = new \NvoosGraphify\Remote\Enricher();
		$summary  = $enricher->syncSource( $slug, $async );

		if ( is_wp_error( $summary ) ) {
			return array(
				'success' => false,
				'error'   => $summary->get_error_message(),
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
