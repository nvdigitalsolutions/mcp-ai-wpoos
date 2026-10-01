<?php
/**
 * Fashion Studio — Batch job tool (Pro, Phase 5).
 *
 * Assistant-facing CRUD over the Pro batch/review queue
 * (`WP_MCP_AI_Fashion_Batch`). Actions: create, get, list, review.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage fashion batch jobs from the assistant.
 */
class WP_MCP_AI_Tool_Fashion_Batch_Job implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Whether this tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'NV_oOS_Media_Studio_AI_Service' );
	}

	/**
	 * Why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The NV oOS Media Studio addon is not active.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fashion_batch_job';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Fashion Batch Job', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates, lists, and reviews AI fashion batch jobs: queue multiple product images through a transform, inspect variants, and approve, reject, or re-roll each one.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Running one transform across many product images and reviewing every generated variant before it ships to the store.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'A single image; use the individual fashion_* transform tools. Editing identity records; use fashion_identity_manage.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'fashion_onmodel_generate', 'fashion_packshot', 'fashion_identity_manage' ),
			'notes'           => __( 'Review gates (cost, consent) apply per job. Approving a variant can run the marketplace output pipeline when a profile is set.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'           => array(
					'type'        => 'string',
					'enum'        => array( 'create', 'get', 'list', 'review' ),
					'description' => __( 'Operation to perform.', 'mcp-ai-wpoos-pro' ),
				),
				'attachment_ids'   => array(
					'type'        => 'array',
					'description' => __( 'Source attachment IDs to transform (create; max 100).', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'transform'        => array(
					'type'        => 'string',
					'description' => __( 'Transform slug for the job, e.g. on-model, packshot, recolor, video.', 'mcp-ai-wpoos-pro' ),
				),
				'description'      => array(
					'type'        => 'string',
					'description' => __( 'Optional guidance for the transform.', 'mcp-ai-wpoos-pro' ),
				),
				'color'            => array(
					'type'        => 'string',
					'description' => __( 'Target color for recolor jobs (hex).', 'mcp-ai-wpoos-pro' ),
				),
				'background_style' => array(
					'type'        => 'string',
					'enum'        => array( 'studio', 'lifestyle', 'gradient', 'editorial' ),
					'description' => __( 'Background style for background jobs.', 'mcp-ai-wpoos-pro' ),
				),
				'aspect_ratio'     => array(
					'type'        => 'string',
					'enum'        => array( 'auto', 'square', 'portrait', 'landscape', 'story', 'widescreen' ),
					'description' => __( 'Output aspect ratio.', 'mcp-ai-wpoos-pro' ),
				),
				'identity_id'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Managed identity model for identity transforms.', 'mcp-ai-wpoos-pro' ),
				),
				'product_id'       => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'WooCommerce product to attach approved variants to.', 'mcp-ai-wpoos-pro' ),
				),
				'collection_id'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Media collection to add approved variants to.', 'mcp-ai-wpoos-pro' ),
				),
				'profile'          => array(
					'type'        => 'string',
					'description' => __( 'Marketplace output profile to run on approval (amazon, woocommerce, social, web).', 'mcp-ai-wpoos-pro' ),
				),
				'alt_text'         => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Generate alt text during pipeline processing.', 'mcp-ai-wpoos-pro' ),
				),
				'confirmed'        => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Set true to proceed when a cost-review gate is triggered.', 'mcp-ai-wpoos-pro' ),
				),
				'acknowledged'     => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Set true to record the one-time AI disclosure acknowledgment for face transforms.', 'mcp-ai-wpoos-pro' ),
				),
				'job_id'           => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Batch job ID (get, review).', 'mcp-ai-wpoos-pro' ),
				),
				'variant_key'      => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Variant key to review (review).', 'mcp-ai-wpoos-pro' ),
				),
				'decision'         => array(
					'type'        => 'string',
					'enum'        => array( 'approve', 'reject', 'reroll' ),
					'description' => __( 'Review decision (review).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Canonical envelope or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! class_exists( 'NV_oOS_Media_Studio_AI_Service' ) || ! class_exists( 'WP_MCP_AI_Fashion_Batch' ) ) {
			return new WP_Error(
				'nvoos_ms_service_missing',
				__( 'The Media Studio fashion pipeline is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';
		if ( ! in_array( $action, array( 'create', 'get', 'list', 'review' ), true ) ) {
			return new WP_Error(
				'nvoos_ms_invalid_action',
				__( 'Provide a valid action: create, get, list, or review.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Object-level escalation: creating jobs writes media.
		if ( 'create' === $action && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'nvoos_ms_forbidden',
				__( 'Creating batch jobs requires the upload_files capability.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// Gate 1 — sanitize at entry.
		$args = array(
			'description'      => isset( $arguments['description'] ) ? sanitize_textarea_field( (string) $arguments['description'] ) : '',
			'color'            => isset( $arguments['color'] ) ? sanitize_hex_color( $arguments['color'] ) : '',
			'background_style' => isset( $arguments['background_style'] ) ? sanitize_key( $arguments['background_style'] ) : '',
			'aspect_ratio'     => isset( $arguments['aspect_ratio'] ) ? sanitize_key( $arguments['aspect_ratio'] ) : '',
			'identity_id'      => isset( $arguments['identity_id'] ) ? absint( $arguments['identity_id'] ) : 0,
			'product_id'       => isset( $arguments['product_id'] ) ? absint( $arguments['product_id'] ) : 0,
			'collection_id'    => isset( $arguments['collection_id'] ) ? absint( $arguments['collection_id'] ) : 0,
			'profile'          => isset( $arguments['profile'] ) ? sanitize_key( $arguments['profile'] ) : '',
			'alt_text'         => isset( $arguments['alt_text'] ) ? (bool) $arguments['alt_text'] : true,
			'confirmed'        => ! empty( $arguments['confirmed'] ),
			'acknowledged'     => ! empty( $arguments['acknowledged'] ),
		);

		if ( ! empty( $args['acknowledged'] ) && $user_id > 0 ) {
			NV_oOS_Media_Studio_AI_Service::record_ack( $user_id );
		}

		switch ( $action ) {
			case 'create':
				$transform = isset( $arguments['transform'] ) ? sanitize_key( $arguments['transform'] ) : '';
				$ids       = isset( $arguments['attachment_ids'] ) ? (array) $arguments['attachment_ids'] : array();
				$result    = WP_MCP_AI_Fashion_Batch::create_job( $ids, $transform, $args, $user_id );
				break;
			case 'get':
				$job_id = isset( $arguments['job_id'] ) ? absint( $arguments['job_id'] ) : 0;
				if ( $job_id < 1 ) {
					return new WP_Error( 'nvoos_ms_invalid_job', __( 'Provide the batch job ID.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
				}
				$result = WP_MCP_AI_Fashion_Batch::get_job( $job_id, $user_id );
				break;
			case 'list':
				$result = array(
					'jobs' => WP_MCP_AI_Fashion_Batch::get_jobs( $user_id ),
				);
				break;
			case 'review':
				$job_id      = isset( $arguments['job_id'] ) ? absint( $arguments['job_id'] ) : 0;
				$variant_key = isset( $arguments['variant_key'] ) ? absint( $arguments['variant_key'] ) : 0;
				$decision    = isset( $arguments['decision'] ) ? sanitize_key( $arguments['decision'] ) : '';
				if ( $job_id < 1 ) {
					return new WP_Error( 'nvoos_ms_invalid_job', __( 'Provide the batch job ID.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
				}
				$options = array(
					'product_id'    => $args['product_id'],
					'collection_id' => $args['collection_id'],
				);
				$result  = WP_MCP_AI_Fashion_Batch::review_variant( $job_id, $variant_key, $decision, $user_id, $options );
				break;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Gate 2 — the batch payloads escape URLs and text at the source.
		return $result;
	}
}
