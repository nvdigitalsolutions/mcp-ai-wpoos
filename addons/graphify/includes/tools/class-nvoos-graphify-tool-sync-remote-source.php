<?php
/**
 * Graphify Tool — Sync Remote Source
 *
 * Triggers a synchronisation run for a single configured remote source driver.
 * Requires manage_options capability.
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool: graphify_sync_remote_source
 *
 * @since 0.6.0
 */
class NV_oOS_Graphify_Tool_Sync_Remote_Source implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'graphify_sync_remote_source';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Sync Remote Source', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Manually triggers a synchronisation run for a named remote source. Requires manage_options capability. In sync mode it returns the enrichment summary directly; in async mode it schedules the sync to run in the background and returns immediately. Use graphify_list_remote_sources first to discover available source slugs.', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
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
	public function get_capability_flags() {
		return array( 'write', 'state-changing', 'external-api' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
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
		$source = NV_oOS_Graphify_DB::get_remote_source( $slug );
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

		$enricher = new NV_oOS_Graphify_Remote_Enricher();
		$summary  = $enricher->sync_source( $slug, $async );

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
