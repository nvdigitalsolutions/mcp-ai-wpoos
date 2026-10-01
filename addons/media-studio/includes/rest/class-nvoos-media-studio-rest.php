<?php
/**
 * NV oOS Media Studio — REST API Controller
 *
 * @package NV_oOS_Media_Studio
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for the NV oOS Media Studio addon.
 *
 * Surfaces:
 *  - `/health`               — liveness (manage_options).
 *  - `/ai/capabilities`      — provider/transform/settings introspection (edit_posts).
 *  - `/ai/presets`           — fashion presets (edit_posts).
 *  - `/ai/models`            — identity library (edit_posts; Pro fills it).
 *  - `/ai/generate`          — run one transform on one attachment (upload_files).
 *  - `/ai/import`            — register an attachment as editor source (upload_files).
 *  - `/ai/export`            — persist a canvas/dataURL into the Media Library (upload_files).
 *
 * @since 0.1.0
 */
class NV_oOS_Media_Studio_REST {

	/**
	 * REST namespace.
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
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'health' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/capabilities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ai_capabilities' ),
				'permission_callback' => array( __CLASS__, 'edit_posts_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/presets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ai_presets' ),
				'permission_callback' => array( __CLASS__, 'edit_posts_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/models',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ai_models' ),
				'permission_callback' => array( __CLASS__, 'edit_posts_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'ai_generate' ),
				'permission_callback' => array( __CLASS__, 'upload_permission' ),
				'args'                => array(
					'attachment_id'    => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
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
					'count'            => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'seed'             => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
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
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'ai_import' ),
				'permission_callback' => array( __CLASS__, 'upload_permission' ),
				'args'                => array(
					'attachment_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/export',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'ai_export' ),
				'permission_callback' => array( __CLASS__, 'upload_permission' ),
				'args'                => array(
					'data_url'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => array( __CLASS__, 'sanitize_data_url' ),
					),
					'file_name'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_file_name',
					),
					'title'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'ai_generated' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'transform'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'prompt'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * Sanitize callback for the export data URL (reject non-image schemes).
	 *
	 * @param string $value Raw value.
	 * @return string Sanitized data URL or empty string.
	 */
	public static function sanitize_data_url( $value ) {
		$value = trim( (string) $value );
		if ( 0 !== stripos( $value, 'data:image/' ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Manage_options gate.
	 *
	 * @return bool|WP_Error
	 */
	public static function admin_permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'forbidden', __( 'You do not have permission to access this endpoint.', 'nvoos-media-studio' ), array( 'status' => 403 ) );
	}

	/**
	 * Edit_posts gate for read-only AI routes.
	 *
	 * @return bool|WP_Error
	 */
	public static function edit_posts_permission() {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return new WP_Error( 'forbidden', __( 'You do not have permission to access this endpoint.', 'nvoos-media-studio' ), array( 'status' => 403 ) );
	}

	/**
	 * Upload_files gate for write AI routes.
	 *
	 * @return bool|WP_Error
	 */
	public static function upload_permission() {
		if ( current_user_can( 'upload_files' ) && current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return new WP_Error( 'forbidden', __( 'You do not have permission to access this endpoint.', 'nvoos-media-studio' ), array( 'status' => 403 ) );
	}

	/**
	 * Health endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public static function health() {
		return rest_ensure_response(
			array(
				'status'  => 'ok',
				'version' => defined( 'NVOOS_MEDIA_STUDIO_VERSION' ) ? NVOOS_MEDIA_STUDIO_VERSION : 'unknown',
			)
		);
	}

	/**
	 * AI capabilities endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public static function ai_capabilities() {
		if ( ! class_exists( 'NV_oOS_Media_Studio_AI_Service' ) ) {
			return new WP_Error( 'nvoos_ms_service_missing', __( 'AI service is not available.', 'nvoos-media-studio' ), array( 'status' => 503 ) );
		}
		return rest_ensure_response( NV_oOS_Media_Studio_AI_Service::get_capabilities() );
	}

	/**
	 * Fashion presets endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public static function ai_presets() {
		if ( ! class_exists( 'NV_oOS_Media_Studio_AI_Service' ) ) {
			return new WP_Error( 'nvoos_ms_service_missing', __( 'AI service is not available.', 'nvoos-media-studio' ), array( 'status' => 503 ) );
		}
		return rest_ensure_response( NV_oOS_Media_Studio_AI_Service::get_presets() );
	}

	/**
	 * Identity models endpoint (empty in base; Pro fills via the filter).
	 *
	 * @return WP_REST_Response
	 */
	public static function ai_models() {
		/**
		 * Filter the identity library exposed to the SPA (Pro CPT query).
		 *
		 * @param array $models Model list.
		 */
		$models = apply_filters( 'nvoos_media_studio_models', array() );
		return rest_ensure_response( $models );
	}

	/**
	 * Generate endpoint — one transform on one attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function ai_generate( WP_REST_Request $request ) {
		if ( ! class_exists( 'NV_oOS_Media_Studio_AI_Service' ) ) {
			return new WP_Error( 'nvoos_ms_service_missing', __( 'AI service is not available.', 'nvoos-media-studio' ), array( 'status' => 503 ) );
		}

		$user_id = get_current_user_id();

		// The one-time acknowledgment arrives with the same request that
		// proceeds, so record it before the consent gate runs.
		if ( $request->get_param( 'acknowledged' ) && $user_id > 0 ) {
			NV_oOS_Media_Studio_AI_Service::record_ack( $user_id );
		}

		$args = array(
			'description'      => $request->get_param( 'description' ),
			'color'            => $request->get_param( 'color' ),
			'background_style' => $request->get_param( 'background_style' ),
			'aspect_ratio'     => $request->get_param( 'aspect_ratio' ),
			'mime_type'        => $request->get_param( 'mime_type' ),
			'identity_id'      => $request->get_param( 'identity_id' ),
			'count'            => $request->get_param( 'count' ),
			'seed'             => $request->get_param( 'seed' ),
			'confirmed'        => (bool) $request->get_param( 'confirmed' ),
		);

		$result = NV_oOS_Media_Studio_AI_Service::execute_transform(
			$request->get_param( 'transform' ),
			$request->get_param( 'attachment_id' ),
			$args,
			$user_id
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Import endpoint — register an attachment as an editor source.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function ai_import( WP_REST_Request $request ) {
		if ( ! class_exists( 'NV_oOS_Media_Studio_AI_Service' ) ) {
			return new WP_Error( 'nvoos_ms_service_missing', __( 'AI service is not available.', 'nvoos-media-studio' ), array( 'status' => 503 ) );
		}

		$result = NV_oOS_Media_Studio_AI_Service::import_attachment(
			$request->get_param( 'attachment_id' ),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Export endpoint — persist a canvas/dataURL into the Media Library.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function ai_export( WP_REST_Request $request ) {
		if ( ! class_exists( 'NV_oOS_Media_Studio_AI_Service' ) ) {
			return new WP_Error( 'nvoos_ms_service_missing', __( 'AI service is not available.', 'nvoos-media-studio' ), array( 'status' => 503 ) );
		}

		$args = array(
			'file_name'    => $request->get_param( 'file_name' ),
			'title'        => $request->get_param( 'title' ),
			'ai_generated' => (bool) $request->get_param( 'ai_generated' ),
			'transform'    => $request->get_param( 'transform' ),
			'prompt'       => $request->get_param( 'prompt' ),
		);

		$result = NV_oOS_Media_Studio_AI_Service::export_image(
			$request->get_param( 'data_url' ),
			$args,
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
