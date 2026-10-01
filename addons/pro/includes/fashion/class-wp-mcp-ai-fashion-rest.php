<?php
/**
 * Fashion Batch Jobs REST Controller (Pro).
 *
 * Registers the batch surface into the shared Media Studio namespace:
 *   - POST /nvoos-media-studio/v1/ai/jobs
 *   - GET  /nvoos-media-studio/v1/ai/jobs
 *   - GET  /nvoos-media-studio/v1/ai/jobs/<id>
 *   - POST /nvoos-media-studio/v1/ai/jobs/<id>/review
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
 * Fashion batch REST controller.
 */
class WP_MCP_AI_Fashion_REST {

	/**
	 * Shared namespace with the Media Studio addon.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'nvoos-media-studio/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/jobs',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_job' ),
					'permission_callback' => array( __CLASS__, 'write_permission' ),
					'args'                => array(
						'attachment_ids'   => array(
							'required'          => true,
							'type'              => 'array',
							'items'             => array( 'type' => 'integer' ),
							'sanitize_callback' => array( __CLASS__, 'sanitize_id_list' ),
						),
						'transform'        => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'description'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'color'            => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_hex_color',
						),
						'background_style' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'aspect_ratio'     => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'mime_type'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'identity_id'      => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'product_id'       => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'collection_id'    => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'profile'          => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'alt_text'         => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'confirmed'        => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'acknowledged'     => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_jobs' ),
					'permission_callback' => array( __CLASS__, 'read_permission' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/jobs/(?P<job_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_job' ),
				'permission_callback' => array( __CLASS__, 'read_permission' ),
				'args'                => array(
					'job_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/jobs/(?P<job_id>\d+)/review',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'review_job' ),
				'permission_callback' => array( __CLASS__, 'write_permission' ),
				'args'                => array(
					'job_id'        => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'variant_key'   => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'action'        => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'product_id'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'collection_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Sanitize a list of IDs.
	 *
	 * @param array $value Raw value.
	 * @return array
	 */
	public static function sanitize_id_list( $value ) {
		$ids = array();
		foreach ( (array) $value as $id ) {
			// max(0, …) keeps negatives dropped; absint() would flip them positive.
			$id = max( 0, (int) $id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Read permission gate.
	 *
	 * @return bool|WP_Error
	 */
	public static function read_permission() {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return new WP_Error( 'forbidden', __( 'You do not have permission to access this endpoint.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
	}

	/**
	 * Write permission gate.
	 *
	 * @return bool|WP_Error
	 */
	public static function write_permission() {
		if ( current_user_can( 'upload_files' ) && current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return new WP_Error( 'forbidden', __( 'You do not have permission to access this endpoint.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
	}

	/**
	 * Create a batch job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_job( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Fashion_Batch' ) ) {
			return new WP_Error( 'nvoos_ms_batch_unavailable', __( 'The batch system is not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
		}

		$user_id = get_current_user_id();

		// One-time disclosure acknowledgment for face transforms.
		if ( $request->get_param( 'acknowledged' ) && $user_id > 0 && class_exists( 'NV_oOS_Media_Studio_AI_Service' ) ) {
			NV_oOS_Media_Studio_AI_Service::record_ack( $user_id );
		}

		$result = WP_MCP_AI_Fashion_Batch::create_job(
			$request->get_param( 'attachment_ids' ),
			$request->get_param( 'transform' ),
			array(
				'description'      => $request->get_param( 'description' ),
				'color'            => $request->get_param( 'color' ),
				'background_style' => $request->get_param( 'background_style' ),
				'aspect_ratio'     => $request->get_param( 'aspect_ratio' ),
				'mime_type'        => $request->get_param( 'mime_type' ),
				'identity_id'      => $request->get_param( 'identity_id' ),
				'product_id'       => $request->get_param( 'product_id' ),
				'collection_id'    => $request->get_param( 'collection_id' ),
				'profile'          => $request->get_param( 'profile' ),
				'alt_text'         => (bool) $request->get_param( 'alt_text' ),
				'confirmed'        => (bool) $request->get_param( 'confirmed' ),
			),
			$user_id
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * List the current user's jobs.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_jobs() {
		if ( ! class_exists( 'WP_MCP_AI_Fashion_Batch' ) ) {
			return new WP_Error( 'nvoos_ms_batch_unavailable', __( 'The batch system is not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
		}
		return rest_ensure_response( WP_MCP_AI_Fashion_Batch::get_jobs( get_current_user_id() ) );
	}

	/**
	 * Fetch one job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_job( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Fashion_Batch' ) ) {
			return new WP_Error( 'nvoos_ms_batch_unavailable', __( 'The batch system is not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
		}
		$result = WP_MCP_AI_Fashion_Batch::get_job( $request->get_param( 'job_id' ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Review a variant.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function review_job( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Fashion_Batch' ) ) {
			return new WP_Error( 'nvoos_ms_batch_unavailable', __( 'The batch system is not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
		}

		$result = WP_MCP_AI_Fashion_Batch::review_variant(
			$request->get_param( 'job_id' ),
			$request->get_param( 'variant_key' ),
			$request->get_param( 'action' ),
			get_current_user_id(),
			array(
				'product_id'    => $request->get_param( 'product_id' ),
				'collection_id' => $request->get_param( 'collection_id' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}
}
