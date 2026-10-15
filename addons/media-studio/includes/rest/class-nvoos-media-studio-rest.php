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
					'duration'         => array(
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
			'/ai/pipeline',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'ai_pipeline' ),
				'permission_callback' => array( __CLASS__, 'upload_permission' ),
				'args'                => array(
					'attachment_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'profile'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'alt_text'      => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'variant'       => array(
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
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public static function admin_permission( WP_REST_Request $request = null ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return self::check_credential_permission( array( 'manage_options' ), $request );
	}

	/**
	 * Edit_posts gate for read-only AI routes.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public static function edit_posts_permission( WP_REST_Request $request = null ) {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return self::check_credential_permission( array( 'edit_posts' ), $request );
	}

	/**
	 * Upload_files gate for write AI routes.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public static function upload_permission( WP_REST_Request $request = null ) {
		if ( current_user_can( 'upload_files' ) && current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return self::check_credential_permission( array( 'upload_files', 'edit_posts' ), $request );
	}

	/**
	 * Allow a validated assistant credential to satisfy a capability gate.
	 *
	 * An assistant credential (`cred_xxxxx.SECRET`), like a WordPress
	 * application password, authenticates the user who issued it. A valid
	 * credential here is treated as that issuing user: it passes ONLY when
	 * the issuing user genuinely holds every requested capability. This keeps
	 * the headless (standalone SPA) surface usable without ever granting the
	 * credential more than its owner already has.
	 *
	 * @since 0.2.0
	 *
	 * @param string[]          $capabilities Capabilities the issuing user must hold.
	 * @param WP_REST_Request|null $request   REST request (for the Authorization header).
	 * @return bool|WP_Error
	 */
	private static function check_credential_permission( array $capabilities, WP_REST_Request $request = null ) {
		if ( ! class_exists( 'WP_MCP_AI_Credentials' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to access this endpoint.', 'nvoos-media-studio' ), array( 'status' => 403 ) );
		}

		$token = self::extract_credential_token( $request );
		if ( '' === $token || ! WP_MCP_AI_Credentials::is_token_format( $token ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to access this endpoint.', 'nvoos-media-studio' ), array( 'status' => 403 ) );
		}

		$validated = WP_MCP_AI_Credentials::validate_token( $token );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$user_id = isset( $validated['created_by'] ) ? absint( $validated['created_by'] ) : 0;
		if ( ! $user_id ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to access this endpoint.', 'nvoos-media-studio' ), array( 'status' => 403 ) );
		}

		foreach ( $capabilities as $capability ) {
			if ( ! user_can( $user_id, $capability ) ) {
				return new WP_Error( 'forbidden', __( 'You do not have permission to access this endpoint.', 'nvoos-media-studio' ), array( 'status' => 403 ) );
			}
		}

		return true;
	}

	/**
	 * Read an assistant credential from the current REST request headers.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_REST_Request|null $request REST request.
	 * @return string Credential token, or empty string when absent.
	 */
	private static function extract_credential_token( WP_REST_Request $request = null ) {
		$header = $request instanceof WP_REST_Request ? (string) $request->get_header( 'Authorization' ) : '';
		if ( '' === $header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		$token = trim( $header );
		if ( preg_match( '/^Bearer\s+(.*)$/i', $token, $matches ) ) {
			return trim( $matches[1] );
		}
		if ( apply_filters( 'wp_mcp_ai_accept_raw_credential_header', true ) ) {
			return $token;
		}
		return '';
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
			'duration'         => $request->get_param( 'duration' ),
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
	 * Pipeline endpoint — process an attachment into a marketplace profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function ai_pipeline( WP_REST_Request $request ) {
		if ( ! class_exists( 'NV_oOS_Media_Studio_Output_Pipeline' ) ) {
			return new WP_Error( 'nvoos_ms_pipeline_missing', __( 'The output pipeline is not available.', 'nvoos-media-studio' ), array( 'status' => 503 ) );
		}

		$result = NV_oOS_Media_Studio_Output_Pipeline::process(
			$request->get_param( 'attachment_id' ),
			$request->get_param( 'profile' ),
			array(
				'alt_text' => (bool) $request->get_param( 'alt_text' ),
				'variant'  => $request->get_param( 'variant' ),
			),
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
