<?php
/**
 * Algorave Tool — Sample Manager
 *
 * Manages audio samples for use in algorave patterns.
 * Supports browsing, uploading, and categorizing samples
 * via the WordPress media library.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages audio samples in the WordPress media library.
 *
 * @since 1.0.0
 */
class NV_oOS_Algorave_Tool_Sample_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'algorave_manage_samples';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Audio Samples', 'nvoos-algorave' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Browse, search, and manage audio samples for use in algorave patterns. Use this when the user wants to find available drum samples, synth sounds, or any audio files in the sample library. Supports searching by name and filtering by category.', 'nvoos-algorave' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'   => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: "browse" to list samples, "search" to find by name.', 'nvoos-algorave' ),
					'enum'        => array( 'browse', 'search' ),
					'default'     => 'browse',
				),
				'search'   => array(
					'type'        => 'string',
					'description' => __( 'Search term (used with "search" action).', 'nvoos-algorave' ),
					'maxLength'   => 200,
				),
				'category' => array(
					'type'        => 'string',
					'description' => __( 'Filter by sample category slug (e.g. "kicks", "snares", "synths", "pads").', 'nvoos-algorave' ),
					'maxLength'   => 100,
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => __( 'Number of samples to return (max 50).', 'nvoos-algorave' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 50,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$per_page = isset( $arguments['per_page'] ) ? max( 1, min( 50, absint( $arguments['per_page'] ) ) ) : 20;

		$query_args = array(
			'posts_per_page' => $per_page,
		);

		if ( ! empty( $arguments['search'] ) ) {
			$query_args['search'] = sanitize_text_field( $arguments['search'] );
		}

		if ( ! empty( $arguments['category'] ) ) {
			$query_args['category'] = sanitize_text_field( $arguments['category'] );
		}

		$result = NV_oOS_Algorave_Sample_Library::browse( $query_args );

		if ( empty( $result['samples'] ) ) {
			return array(
				'success' => true,
				'samples' => array(),
				'total'   => 0,
				'message' => __( 'No audio samples found. Upload .wav, .mp3, .ogg, or .flac files to the WordPress media library to use them in patterns.', 'nvoos-algorave' ),
			);
		}

		return array(
			'success' => true,
			'samples' => $result['samples'],
			'total'   => $result['total'],
			'message' => sprintf(
				/* translators: 1: number shown, 2: total number */
				__( 'Found %1$d of %2$d audio samples.', 'nvoos-algorave' ),
				count( $result['samples'] ),
				$result['total']
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'cacheable' );
	}
}
