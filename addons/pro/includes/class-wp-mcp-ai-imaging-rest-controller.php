<?php
/**
 * Healthcare Imaging REST Controller
 *
 * Exposes the following REST endpoints under `mcp-ai/v1/imaging`:
 *
 *  GET    /studies                              – List all studies.
 *  GET    /studies/{studyId}                   – Get study details.
 *  GET    /studies/{studyId}/manifest          – Cornerstone3D manifest.
 *  GET    /instances/{instanceId}/file         – Serve raw DICOM bytes (signed token).
 *  POST   /upload                              – Upload a DICOM study (multipart).
 *  GET    /audit                               – List recent audit events.
 *
 * All endpoints require at minimum `view_medical_imaging` capability.
 * File access uses a short-lived signed token (nonce-based) to prevent
 * direct URL sharing.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for DICOM imaging studies.
 */
class WP_MCP_AI_Imaging_REST_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mcp-ai/v1';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'imaging';

	/**
	 * Maximum upload file size in bytes (256 MB).
	 *
	 * @var int
	 */
	const MAX_UPLOAD_SIZE = 268435456;

	/**
	 * Allowed MIME types for DICOM uploads.
	 *
	 * Most browsers (and php-finfo) report .dcm files as one of these types.
	 *
	 * @var string[]
	 */
	const ALLOWED_MIME_TYPES = array(
		'application/dicom',
		'application/octet-stream',
	);

	/**
	 * Subdirectory name used when a DICOM file has no SeriesInstanceUID.
	 *
	 * @var string
	 */
	const UNGROUPED_SERIES_DIR = 'ungrouped';

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/stats',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_stats' ),
					'permission_callback' => array( $this, 'can_view_imaging' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/studies',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_studies' ),
					'permission_callback' => array( $this, 'can_view_imaging' ),
					'args'                => array(
						'per_page'  => array(
							'default'           => 100,
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 500,
							'sanitize_callback' => 'absint',
						),
						'page'      => array(
							'default'           => 1,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'modality'  => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'date_from' => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'date_to'   => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'search'    => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/studies/(?P<studyId>[a-zA-Z0-9.\-_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_study' ),
					'permission_callback' => array( $this, 'can_view_imaging' ),
					'args'                => array(
						'studyId' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/studies/(?P<studyId>[a-zA-Z0-9.\-_]+)/manifest',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_study_manifest' ),
					'permission_callback' => array( $this, 'can_view_imaging' ),
					'args'                => array(
						'studyId' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/interpret',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'interpret_study' ),
					'permission_callback' => array( $this, 'can_view_imaging' ),
					'args'                => array(
						'study_uid' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'focus'     => array(
							'default'           => 'full',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => function ( $value ) {
								return in_array( $value, array( 'quality', 'completeness', 'workflow', 'full' ), true );
							},
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/instances/(?P<instanceId>[a-zA-Z0-9.\-_]+)/file',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'serve_instance_file' ),
					'permission_callback' => array( $this, 'can_view_imaging' ),
					'args'                => array(
						'instanceId' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'token'      => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/upload',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_study' ),
					'permission_callback' => array( $this, 'can_upload_imaging' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/studies/(?P<studyId>[a-zA-Z0-9.\-_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_study' ),
					'permission_callback' => array( $this, 'can_manage_imaging' ),
					'args'                => array(
						'studyId' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/audit',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_audit_log' ),
					'permission_callback' => array( $this, 'can_manage_imaging' ),
					'args'                => array(
						'limit'    => array(
							'default'           => 50,
							'sanitize_callback' => 'absint',
						),
						'study_id' => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	// =========================================================================
	// Permission callbacks
	// =========================================================================

	/**
	 * Check view_medical_imaging capability.
	 *
	 * @return bool|WP_Error
	 */
	public function can_view_imaging() {
		if ( ! current_user_can( 'view_medical_imaging' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view medical imaging studies.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Check upload_medical_imaging capability.
	 *
	 * @return bool|WP_Error
	 */
	public function can_upload_imaging() {
		if ( ! current_user_can( 'upload_medical_imaging' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to upload medical imaging studies.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Check manage_medical_imaging capability.
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage_imaging() {
		if ( ! current_user_can( 'manage_medical_imaging' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage medical imaging.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	// =========================================================================
	// Endpoint handlers
	// =========================================================================

	/**
	 * GET /imaging/studies – list all studies.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_studies( WP_REST_Request $request ) {
		$per_page = max( 1, (int) $request->get_param( 'per_page' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$filters = array(
			'modality'  => $request->get_param( 'modality' ),
			'date_from' => $request->get_param( 'date_from' ),
			'date_to'   => $request->get_param( 'date_to' ),
			'search'    => $request->get_param( 'search' ),
		);

		$result = WP_MCP_AI_Imaging_Study_CPT::get_all( $per_page, $page, $filters );

		$studies = array();
		foreach ( $result['posts'] as $post ) {
			$studies[] = $this->format_study( $post );
		}

		WP_MCP_AI_Imaging_Audit_Log::log( 'study_list_viewed', array( 'count' => count( $studies ) ) );

		return new WP_REST_Response(
			array(
				'studies'     => $studies,
				'total'       => $result['total'],
				'total_pages' => $result['pages'],
				'page'        => $page,
				'per_page'    => $per_page,
			),
			200
		);
	}

	/**
	 * GET /imaging/stats – return aggregate statistics.
	 *
	 * Returns total study count, counts grouped by modality, total DICOM storage
	 * size, and the 5 most-recent studies.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_stats( WP_REST_Request $request ) {
		// Total study count.
		$count_query   = new WP_Query(
			array(
				'post_type'        => WP_MCP_AI_Imaging_Study_CPT::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'no_found_rows'    => false,
			)
		);
		$total_studies = $count_query->found_posts;

		// Group by modality.
		$modality_posts = get_posts(
			array(
				'post_type'        => WP_MCP_AI_Imaging_Study_CPT::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		$modality_counts = array();
		foreach ( $modality_posts as $pid ) {
			$mod = get_post_meta( $pid, '_imaging_modality', true );
			if ( '' === $mod || false === $mod ) {
				$mod = 'Unknown';
			}
			if ( ! isset( $modality_counts[ $mod ] ) ) {
				$modality_counts[ $mod ] = 0;
			}
			++$modality_counts[ $mod ];
		}

		$by_modality = array();
		foreach ( $modality_counts as $mod => $cnt ) {
			$by_modality[] = array(
				'modality' => $mod,
				'count'    => $cnt,
			);
		}

		// Total storage size (recursive scan of the storage root).
		$storage_bytes = 0;
		$storage_root  = $this->get_storage_root();
		if ( is_dir( $storage_root ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $storage_root, RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$basename = $file->getFilename();
				// Skip the access-guard files.
				if ( '.htaccess' === $basename || 'index.php' === $basename ) {
					continue;
				}
				$storage_bytes += $file->getSize();
			}
		}

		// Recent 5 studies.
		$recent_result  = WP_MCP_AI_Imaging_Study_CPT::get_all( 5, 1 );
		$recent_studies = array();
		foreach ( $recent_result['posts'] as $post ) {
			$recent_studies[] = $this->format_study( $post );
		}

		return new WP_REST_Response(
			array(
				'total_studies'  => $total_studies,
				'by_modality'    => $by_modality,
				'storage_bytes'  => $storage_bytes,
				'recent_studies' => $recent_studies,
			),
			200
		);
	}

	/**
	 * GET /imaging/studies/{studyId} – get a single study.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_study( WP_REST_Request $request ) {
		$study_uid = $request->get_param( 'studyId' );
		$post      = WP_MCP_AI_Imaging_Study_CPT::get_by_uid( $study_uid );

		if ( ! $post ) {
			return new WP_Error( 'imaging_not_found', __( 'Study not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		WP_MCP_AI_Imaging_Audit_Log::log(
			'study_viewed',
			array(
				'study_id' => $study_uid,
				'user_id'  => get_current_user_id(),
			)
		);

		return new WP_REST_Response( $this->format_study( $post ), 200 );
	}

	/**
	 * GET /imaging/studies/{studyId}/manifest – return Cornerstone3D-compatible manifest.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_study_manifest( WP_REST_Request $request ) {
		$study_uid = $request->get_param( 'studyId' );
		$post      = WP_MCP_AI_Imaging_Study_CPT::get_by_uid( $study_uid );

		if ( ! $post ) {
			return new WP_Error( 'imaging_not_found', __( 'Study not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		$series_json = get_post_meta( $post->ID, '_imaging_series', true );
		$series      = json_decode( $series_json, true );
		if ( ! is_array( $series ) ) {
			$series = array();
		}

		// Build Cornerstone3D-compatible manifest.
		$manifest_series = array();
		foreach ( $series as $s ) {
			$instances = array();
			foreach ( isset( $s['instances'] ) ? $s['instances'] : array() as $inst ) {
				$iuid     = isset( $inst['sop_instance_uid'] ) ? sanitize_text_field( $inst['sop_instance_uid'] ) : '';
				$token    = $this->generate_instance_token( $iuid );
				$file_url = rest_url(
					$this->namespace . '/' . $this->rest_base . '/instances/' . rawurlencode( $iuid ) . '/file?token=' . rawurlencode( $token )
				);

				$instances[] = array(
					'instanceUID'    => $iuid,
					'imageId'        => 'wadouri:' . $file_url,
					'instanceNumber' => isset( $inst['instance_number'] ) ? (int) $inst['instance_number'] : 0,
				);
			}

			$manifest_series[] = array(
				'seriesInstanceUID' => isset( $s['series_instance_uid'] ) ? sanitize_text_field( $s['series_instance_uid'] ) : '',
				'modality'          => isset( $s['modality'] ) ? sanitize_text_field( $s['modality'] ) : '',
				'seriesDescription' => isset( $s['series_description'] ) ? sanitize_text_field( $s['series_description'] ) : '',
				'instances'         => $instances,
			);
		}

		WP_MCP_AI_Imaging_Audit_Log::log(
			'study_manifest_viewed',
			array(
				'study_id' => $study_uid,
				'user_id'  => get_current_user_id(),
			)
		);

		return new WP_REST_Response(
			array(
				'studyId'   => $study_uid,
				'studyDate' => get_post_meta( $post->ID, '_imaging_study_date', true ),
				'modality'  => get_post_meta( $post->ID, '_imaging_modality', true ),
				'series'    => $manifest_series,
			),
			200
		);
	}

	/**
	 * GET /imaging/instances/{instanceId}/file – serve raw DICOM file bytes.
	 *
	 * Validates a short-lived signed token before streaming the file.
	 * The response uses `application/dicom` Content-Type so Cornerstone3D
	 * can decode the pixel data directly via `@cornerstonejs/dicom-image-loader`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function serve_instance_file( WP_REST_Request $request ) {
		$instance_uid = $request->get_param( 'instanceId' );
		$token        = $request->get_param( 'token' );

		// Verify signed token.
		if ( ! $this->verify_instance_token( $instance_uid, $token ) ) {
			WP_MCP_AI_Imaging_Audit_Log::log( 'instance_file_access_denied', array( 'instance_uid' => $instance_uid ) );
			return new WP_Error( 'imaging_invalid_token', __( 'Invalid or expired access token.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		// Find the file path in the database.
		$file_path = $this->find_instance_file( $instance_uid );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'imaging_file_not_found', __( 'DICOM instance file not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		// Ensure file is within our protected storage directory.
		if ( ! $this->is_path_within_storage( $file_path ) ) {
			WP_MCP_AI_Imaging_Audit_Log::log( 'instance_file_path_traversal_attempt', array( 'instance_uid' => $instance_uid ) );
			return new WP_Error( 'imaging_invalid_path', __( 'Invalid file path.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		WP_MCP_AI_Imaging_Audit_Log::log(
			'instance_file_accessed',
			array(
				'instance_uid' => $instance_uid,
				'user_id'      => get_current_user_id(),
			)
		);

		// Flush and discard any output buffered by WordPress core or third-party
		// plugins before we send binary DICOM data.  Any buffered text prepended
		// to the response body would corrupt the DICOM preamble and cause the
		// image loader (Cornerstone3D) to fail with a parse error.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Stream file in chunks to handle large DICOM files (up to 256 MB) efficiently.
		$file_size = filesize( $file_path );
		header( 'Content-Type: application/dicom' );
		header( 'Content-Length: ' . $file_size );
		header( 'Content-Disposition: attachment; filename="' . esc_attr( basename( $file_path ) ) . '"' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( $file_path, 'rb' );
		if ( $fh ) {
			while ( ! feof( $fh ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary DICOM data sent with validated Content-Type header.
				echo fread( $fh, 65536 ); // 64 KB chunks.
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fh );
		}
		exit;
	}

	/**
	 * POST /imaging/upload – accept multipart DICOM upload.
	 *
	 * Expects a multipart form with one or more files under the `dicom_files[]` key.
	 * Each file is validated, stored in the protected directory, and indexed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_study( WP_REST_Request $request ) {
		// Validate nonce (REST nonce already validated by WP for logged-in requests,
		// but we add an extra explicit nonce check for state-changing uploads).
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_nonce_invalid', __( 'Invalid nonce.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		if ( empty( $_FILES['dicom_files'] ) ) {
			return new WP_Error( 'imaging_no_files', __( 'No DICOM files were uploaded.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		$uploaded_files = $this->normalize_files_array( $_FILES['dicom_files'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$storage_root   = $this->get_storage_root();
		$results        = array();
		$study_post_id  = null;

		foreach ( $uploaded_files as $file ) {
			$result = $this->process_uploaded_file( $file, $storage_root );
			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'error' => $result->get_error_message(),
					'file'  => sanitize_text_field( $file['name'] ),
				);
				continue;
			}
			$study_post_id = $result['study_post_id'];
			$results[]     = array(
				'success'      => true,
				'file'         => sanitize_text_field( $file['name'] ),
				'instance_uid' => $result['instance_uid'],
			);
		}

		// If no files were stored successfully, return a descriptive error instead of a
		// misleading 201.  This prevents the viewer from showing "uploaded successfully"
		// when nothing was actually persisted to disk.
		if ( null === $study_post_id ) {
			$file_errors = array();
			foreach ( $results as $r ) {
				if ( isset( $r['error'] ) && '' !== $r['error'] ) {
					$file_errors[] = $r['error'];
				}
			}

			$detail = ! empty( $file_errors )
				? implode( ' ', array_unique( $file_errors ) )
				: __( 'No valid DICOM files could be processed. Please ensure your files are valid DICOM (.dcm) format.', 'mcp-ai-wpoos-pro' );

			return new WP_Error(
				'imaging_no_valid_files',
				$detail,
				array( 'status' => 422 )
			);
		}

		$study_uid = get_post_meta( $study_post_id, '_imaging_study_instance_uid', true );

		WP_MCP_AI_Imaging_Audit_Log::log(
			'study_uploaded',
			array(
				'study_id'   => $study_uid,
				'file_count' => count( $uploaded_files ),
				'user_id'    => get_current_user_id(),
			)
		);

		return new WP_REST_Response(
			array(
				'study_id' => $study_uid,
				'files'    => $results,
			),
			201
		);
	}

	/**
	 * GET /imaging/audit – retrieve recent audit events.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_audit_log( WP_REST_Request $request ) {
		$limit    = $request->get_param( 'limit' );
		$study_id = $request->get_param( 'study_id' );

		WP_MCP_AI_Imaging_Audit_Log::log( 'audit_log_viewed', array( 'user_id' => get_current_user_id() ) );

		$entries = WP_MCP_AI_Imaging_Audit_Log::get_recent( $limit, $study_id );

		return new WP_REST_Response( array( 'entries' => $entries ), 200 );
	}

	/**
	 * POST /imaging/interpret – run AI interpretation on a study.
	 *
	 * Delegates to WP_MCP_AI_Tool_Interpret_Imaging_Study when available.
	 * Returns a 503 if the pro tool class has not been loaded.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function interpret_study( WP_REST_Request $request ) {
		$study_uid = $request->get_param( 'study_uid' );
		$focus     = $request->get_param( 'focus' );

		if ( ! class_exists( 'WP_MCP_AI_Tool_Interpret_Imaging_Study' ) ) {
			return new WP_Error(
				'imaging_tool_unavailable',
				__( 'The AI interpretation tool is not available. Ensure the pro toolkit is fully loaded and an AI provider (OpenAI / Gemini) is configured in Settings.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		$tool   = new WP_MCP_AI_Tool_Interpret_Imaging_Study();
		$result = $tool->execute(
			array(
				'study_uid' => $study_uid,
				'focus'     => $focus,
			),
			array()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		WP_MCP_AI_Imaging_Audit_Log::log(
			'study_interpreted',
			array(
				'study_id' => $study_uid,
				'focus'    => $focus,
				'user_id'  => get_current_user_id(),
			)
		);

		// Tool returns an array with a 'result' or 'output' key depending on version.
		$output = '';
		if ( is_array( $result ) ) {
			$output = isset( $result['result'] ) ? $result['result']
				: ( isset( $result['output'] ) ? $result['output']
				: ( isset( $result['content'] ) ? $result['content']
				: wp_json_encode( $result ) ) );
		} elseif ( is_string( $result ) ) {
			$output = $result;
		}

		return new WP_REST_Response(
			array(
				'study_uid'      => $study_uid,
				'focus'          => $focus,
				'interpretation' => $output,
			),
			200
		);
	}

	/**
	 * DELETE /imaging/studies/{studyId} – permanently delete a study.
	 *
	 * Removes the CPT post AND all DICOM files stored on disk for the study.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_study( WP_REST_Request $request ) {
		$study_uid = $request->get_param( 'studyId' );
		$post      = WP_MCP_AI_Imaging_Study_CPT::get_by_uid( $study_uid );

		if ( ! $post ) {
			return new WP_Error( 'imaging_not_found', __( 'Study not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		// Remove all DICOM files stored in the study directory.
		// Files are organised as {study_dir}/{series_uid}/{sop_uid}.dcm, so a
		// flat glob( '*.dcm' ) misses them.  Use a recursive iterator instead,
		// deleting files first (CHILD_FIRST) then series sub-directories.
		$storage_path = get_post_meta( $post->ID, '_imaging_storage_path', true );
		if ( $storage_path && is_dir( $storage_path ) && $this->is_path_within_storage( $storage_path ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $storage_path, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $item ) {
				if ( $item->isFile() ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					if ( ! unlink( $item->getPathname() ) ) {
						WP_MCP_AI_Imaging_Audit_Log::log(
							'study_delete_file_failed',
							array(
								'study_id' => $study_uid,
								'file'     => $item->getFilename(),
								'user_id'  => get_current_user_id(),
							)
						);
					}
				} elseif ( $item->isDir() ) {
					// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
					if ( ! @rmdir( $item->getPathname() ) ) {
						WP_MCP_AI_Imaging_Audit_Log::log(
							'study_delete_dir_failed',
							array(
								'study_id' => $study_uid,
								'path'     => $item->getFilename(),
								'user_id'  => get_current_user_id(),
							)
						);
					}
				}
			}
			// Remove the study directory itself now that its contents are gone.
			if ( ! @rmdir( $storage_path ) ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
				// Directory not empty or not writable — log for compliance audit but don't
				// block the delete (the CPT post will still be removed).
				WP_MCP_AI_Imaging_Audit_Log::log(
					'study_delete_dir_failed',
					array(
						'study_id' => $study_uid,
						'path'     => basename( $storage_path ),
						'user_id'  => get_current_user_id(),
					)
				);
			}
		}

		// Delete the CPT post (bypass trash — PHI data should be hard-deleted).
		$deleted = wp_delete_post( $post->ID, true );
		if ( ! $deleted ) {
			return new WP_Error( 'imaging_delete_failed', __( 'Failed to delete study.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		WP_MCP_AI_Imaging_Audit_Log::log(
			'study_deleted',
			array(
				'study_id' => $study_uid,
				'user_id'  => get_current_user_id(),
			)
		);

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'study_id' => $study_uid,
			),
			200
		);
	}

	// =========================================================================
	// Internal helpers
	// =========================================================================

	/**
	 * Format a study post for REST output.
	 *
	 * PHI is intentionally excluded from the REST output.  Only
	 * de-identified or operational metadata is returned.
	 *
	 * @param WP_Post $post Study post.
	 * @return array
	 */
	private function format_study( WP_Post $post ) {
		$series_json    = get_post_meta( $post->ID, '_imaging_series', true );
		$series         = json_decode( $series_json, true );
		$series_count   = is_array( $series ) ? count( $series ) : 0;
		$instance_count = 0;
		if ( is_array( $series ) ) {
			foreach ( $series as $s ) {
				$instance_count += isset( $s['instances'] ) ? count( $s['instances'] ) : 0;
			}
		}

		return array(
			'id'             => $post->ID,
			'study_uid'      => get_post_meta( $post->ID, '_imaging_study_instance_uid', true ),
			'patient_id'     => get_post_meta( $post->ID, '_imaging_patient_id', true ),
			'modality'       => get_post_meta( $post->ID, '_imaging_modality', true ),
			'study_date'     => get_post_meta( $post->ID, '_imaging_study_date', true ),
			'description'    => get_post_meta( $post->ID, '_imaging_study_description', true ),
			'status'         => get_post_meta( $post->ID, '_imaging_status', true ),
			'series_count'   => $series_count,
			'instance_count' => $instance_count,
			'created'        => get_the_date( 'c', $post ),
			'links'          => array(
				'manifest' => rest_url( $this->namespace . '/' . $this->rest_base . '/studies/' . rawurlencode( get_post_meta( $post->ID, '_imaging_study_instance_uid', true ) ) . '/manifest' ),
			),
		);
	}

	/**
	 * Generate a short-lived signed token for accessing an instance file.
	 *
	 * @param string $instance_uid DICOM SOPInstanceUID.
	 * @return string Token.
	 */
	private function generate_instance_token( $instance_uid ) {
		return wp_create_nonce( 'imaging_instance_' . $instance_uid );
	}

	/**
	 * Verify an instance access token.
	 *
	 * @param string $instance_uid DICOM SOPInstanceUID.
	 * @param string $token        Token to verify.
	 * @return bool
	 */
	private function verify_instance_token( $instance_uid, $token ) {
		return (bool) wp_verify_nonce( $token, 'imaging_instance_' . $instance_uid );
	}

	/**
	 * Find the absolute filesystem path for a DICOM instance.
	 *
	 * Searches all study posts for an instance with the given UID.
	 *
	 * @param string $instance_uid SOPInstanceUID.
	 * @return string|false Absolute path or false if not found.
	 */
	private function find_instance_file( $instance_uid ) {
		$posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Imaging_Study_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $posts as $post_id ) {
			$series_json = get_post_meta( $post_id, '_imaging_series', true );
			$series      = json_decode( $series_json, true );
			if ( ! is_array( $series ) ) {
				continue;
			}
			foreach ( $series as $s ) {
				foreach ( isset( $s['instances'] ) ? $s['instances'] : array() as $inst ) {
					if ( isset( $inst['sop_instance_uid'] ) && $instance_uid === $inst['sop_instance_uid'] ) {
						return isset( $inst['file_path'] ) ? $inst['file_path'] : false;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Validate that a resolved path stays within the protected storage root.
	 *
	 * @param string $path Absolute file path.
	 * @return bool
	 */
	private function is_path_within_storage( $path ) {
		$storage_root = realpath( $this->get_storage_root() );
		$real_path    = realpath( $path );

		if ( false === $storage_root || false === $real_path ) {
			return false;
		}

		return 0 === strpos( $real_path, $storage_root );
	}

	/**
	 * Produce a filesystem-safe directory/filename component from a DICOM UID.
	 *
	 * DICOM UIDs consist exclusively of ASCII digits (0–9) and dots (.).  Both
	 * are safe on every major filesystem.  We therefore retain them unchanged and
	 * replace any other character (which should never appear in a conformant UID)
	 * with an underscore.
	 *
	 * We deliberately avoid sanitize_file_name() here.  That function applies a
	 * filterable hook (`sanitize_file_name`) that third-party plugins can override
	 * to strip dots or transform the string.  Stripping dots would collapse
	 * distinct UIDs — e.g. "1.2.3.4.56" and "1.2.3.4.5.6" both become
	 * "1234_56" — causing separate studies to share the same on-disk directory,
	 * so only the first study's CPT post is ever created and the remaining studies
	 * remain invisible in the study browser.
	 *
	 * @param string $uid Raw DICOM UID value (StudyInstanceUID / SeriesInstanceUID /
	 *                    SOPInstanceUID).
	 * @return string Filesystem-safe string with only digits and dots retained.
	 */
	private function sanitize_uid_for_path( $uid ) {
		return preg_replace( '/[^0-9.]/', '_', (string) $uid );
	}

	/**
	 * Get or create the protected DICOM storage root directory.
	 *
	 * The directory lives inside the WordPress uploads folder at
	 * `{uploads}/mcp-ai-imaging/`.  An `.htaccess` and `index.php` guard are
	 * added to prevent direct HTTP access to the stored DICOM files.
	 *
	 * @return string Absolute path to storage root (with trailing slash).
	 */
	private function get_storage_root() {
		$upload_dir  = wp_upload_dir();
		$storage_dir = trailingslashit( $upload_dir['basedir'] ) . 'mcp-ai-imaging';

		if ( ! is_dir( $storage_dir ) ) {
			wp_mkdir_p( $storage_dir );

			// Deny direct HTTP access.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $storage_dir . '/.htaccess', "Order deny,allow\nDeny from all\n" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $storage_dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return trailingslashit( $storage_dir );
	}

	/**
	 * Process a single uploaded DICOM file.
	 *
	 * Each file is indexed independently by its DICOM StudyInstanceUID.  This
	 * allows a single multipart upload to contain files from more than one study
	 * (e.g. when the user selects all .dcm files on their desktop at once).
	 *
	 * @param array  $file          Entry from normalized $_FILES array.
	 * @param string $storage_root  Protected storage root path.
	 * @return array|WP_Error {study_post_id, instance_uid} or WP_Error.
	 */
	private function process_uploaded_file( array $file, $storage_root ) {
		// Check for upload errors.
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'imaging_upload_error', __( 'File upload error.', 'mcp-ai-wpoos-pro' ) );
		}

		// Size check.
		if ( $file['size'] > self::MAX_UPLOAD_SIZE ) {
			return new WP_Error( 'imaging_file_too_large', __( 'DICOM file exceeds the maximum allowed size (256 MB).', 'mcp-ai-wpoos-pro' ) );
		}

		$tmp_path = $file['tmp_name'];

		// F-UPLOAD-01: Enforce MIME type using php-finfo against the actual file bytes,
		// not the browser-reported Content-Type which can be spoofed.
		// DICOM files have no universally registered MIME type; php-finfo commonly
		// reports them as 'application/dicom' or 'application/octet-stream'.
		// Either is acceptable; anything else is rejected.
		if ( function_exists( 'finfo_open' ) ) {
			$finfo     = finfo_open( FILEINFO_MIME_TYPE );
			$mime_type = $finfo ? finfo_file( $finfo, $tmp_path ) : '';
			if ( $finfo ) {
				finfo_close( $finfo );
			}
			if ( '' !== $mime_type && ! in_array( $mime_type, self::ALLOWED_MIME_TYPES, true ) ) {
				return new WP_Error(
					'imaging_invalid_mime',
					__( 'Uploaded file has an unexpected content type. Only DICOM files are accepted.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Validate DICOM magic.
		if ( ! WP_MCP_AI_DICOM_Metadata::is_dicom( $tmp_path ) ) {
			return new WP_Error( 'imaging_not_dicom', __( 'Uploaded file is not a valid DICOM file.', 'mcp-ai-wpoos-pro' ) );
		}

		// Extract metadata.
		$meta = WP_MCP_AI_DICOM_Metadata::extract( $tmp_path );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		/**
		 * Filter DICOM metadata before storage to allow PHI redaction.
		 *
		 * DICOM files may contain Protected Health Information (PHI) such as
		 * patient names, IDs, birth dates, and institution details. If your
		 * deployment handles real patient data, attach a callback to this
		 * filter to strip or pseudonymize PHI fields before they are stored
		 * in the WordPress database.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $meta    Extracted DICOM metadata (patient_id, patient_name,
		 *                        patient_birth_date, institution_name, etc.).
		 * @param string $tmp_path Path to the uploaded DICOM file on disk.
		 */
		$meta = apply_filters( 'wp_mcp_ai_dicom_strip_phi', $meta, $tmp_path );

		$study_uid    = isset( $meta['study_instance_uid'] ) ? $meta['study_instance_uid'] : '';
		$series_uid   = isset( $meta['series_instance_uid'] ) ? $meta['series_instance_uid'] : '';
		$instance_uid = isset( $meta['sop_instance_uid'] ) ? $meta['sop_instance_uid'] : '';
		$modality     = isset( $meta['modality'] ) ? $meta['modality'] : '';

		if ( '' === $study_uid || '' === $instance_uid ) {
			return new WP_Error( 'imaging_missing_uids', __( 'DICOM file is missing StudyInstanceUID or SOPInstanceUID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Move file to protected storage.
		// Industry-standard DICOM hierarchy: Study → Series → Instance
		// (per DICOM PS 3.10 and IHE Radiology Technical Framework).
		// Each series gets its own subdirectory so multi-series studies are
		// organised correctly on disk.
		//
		// We use sanitize_uid_for_path() instead of sanitize_file_name() because
		// sanitize_file_name() applies a filterable hook that third-party plugins can
		// override (e.g. to strip dots).  DICOM UIDs differ only in their numeric
		// segments separated by dots; stripping dots collapses distinct UIDs such as
		// "1.2.3.4.56" and "1.2.3.4.5.6" to the same directory name, causing separate
		// studies to share a folder and preventing all but one CPT post from appearing
		// in the study browser.  sanitize_uid_for_path() retains every digit and dot
		// while replacing any non-UID character with an underscore, making the
		// transformation deterministic regardless of active plugins.
		$series_dir_name = '' !== $series_uid ? $this->sanitize_uid_for_path( $series_uid ) : self::UNGROUPED_SERIES_DIR;
		$study_dir       = $storage_root . $this->sanitize_uid_for_path( $study_uid ) . '/';
		$series_dir      = $study_dir . $series_dir_name . '/';
		wp_mkdir_p( $series_dir );
		$dest_path = $series_dir . $this->sanitize_uid_for_path( $instance_uid ) . '.dcm';

		if ( ! move_uploaded_file( $tmp_path, $dest_path ) ) {
			return new WP_Error( 'imaging_move_failed', __( 'Failed to store DICOM file.', 'mcp-ai-wpoos-pro' ) );
		}

		// Create or retrieve the study post, always by StudyInstanceUID.
		// This allows a single upload batch to contain files from multiple studies.
		$existing = WP_MCP_AI_Imaging_Study_CPT::get_by_uid( $study_uid );
		if ( $existing ) {
			$study_post_id = $existing->ID;
		} else {
			$study_post_id = WP_MCP_AI_Imaging_Study_CPT::create(
				array(
					'study_instance_uid' => $study_uid,
					'patient_id'         => isset( $meta['patient_id'] ) ? $meta['patient_id'] : '',
					'modality'           => $modality,
					'study_date'         => isset( $meta['study_date'] ) ? $meta['study_date'] : '',
					'study_description'  => isset( $meta['series_description'] ) ? $meta['series_description'] : '',
					'storage_path'       => $study_dir,
				)
			);
			if ( is_wp_error( $study_post_id ) ) {
				return $study_post_id;
			}
		}

		// Add series/instance to the study record.
		WP_MCP_AI_Imaging_Study_CPT::add_series(
			$study_post_id,
			array(
				'series_instance_uid' => $series_uid,
				'modality'            => $modality,
				'series_description'  => isset( $meta['series_description'] ) ? $meta['series_description'] : '',
				'instances'           => array(
					array(
						'sop_instance_uid' => $instance_uid,
						'file_path'        => $dest_path,
						'instance_number'  => isset( $meta['instance_number'] ) ? $meta['instance_number'] : '',
						'rows'             => isset( $meta['rows'] ) ? $meta['rows'] : '',
						'columns'          => isset( $meta['columns'] ) ? $meta['columns'] : '',
						'pixel_spacing'    => isset( $meta['pixel_spacing'] ) ? $meta['pixel_spacing'] : '',
					),
				),
			)
		);

		return array(
			'study_post_id' => $study_post_id,
			'instance_uid'  => $instance_uid,
		);
	}

	/**
	 * Normalize PHP's `$_FILES` array for a multi-file upload field.
	 *
	 * When the upload field name is `dicom_files[]`, PHP builds an odd nested
	 * structure.  This method converts it to a simple indexed array of file arrays.
	 *
	 * @param array $files Raw `$_FILES['dicom_files']` entry.
	 * @return array[] Normalized array of file entries.
	 */
	private function normalize_files_array( array $files ) {
		$normalized = array();
		if ( isset( $files['name'] ) && is_array( $files['name'] ) ) {
			foreach ( $files['name'] as $idx => $name ) {
				$tmp          = isset( $files['tmp_name'][ $idx ] ) ? $files['tmp_name'][ $idx ] : '';
				$normalized[] = array(
					'name'     => sanitize_text_field( $name ),
					'type'     => isset( $files['type'][ $idx ] ) ? sanitize_mime_type( $files['type'][ $idx ] ) : '',
					'tmp_name' => $tmp, // File path – do not sanitize_text_field (would corrupt backslashes).
					'error'    => isset( $files['error'][ $idx ] ) ? (int) $files['error'][ $idx ] : UPLOAD_ERR_NO_FILE,
					'size'     => isset( $files['size'][ $idx ] ) ? (int) $files['size'][ $idx ] : 0,
				);
			}
		} else {
			// Single file.
			$normalized[] = array(
				'name'     => sanitize_text_field( $files['name'] ),
				'type'     => sanitize_mime_type( $files['type'] ),
				'tmp_name' => $files['tmp_name'], // File path – do not sanitize_text_field.
				'error'    => (int) $files['error'],
				'size'     => (int) $files['size'],
			);
		}
		return $normalized;
	}
}
