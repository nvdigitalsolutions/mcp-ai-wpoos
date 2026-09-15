<?php
/**
 * REST API controller for assistant export/import.
 *
 * Exposes the canonical `nvoos-assistant` portability bundle over REST:
 *
 *   - POST /mcp-ai/v1/assistants/export — build a bundle for one, many, or
 *     all assistants (optionally embedding A2A agent cards).
 *   - POST /mcp-ai/v1/assistants/import — parse + apply a bundle, legacy CLI
 *     file, or blueprint JSON with skip/overwrite/duplicate modes and a
 *     dry-run preview.
 *
 * Both routes are admin-only (`manage_options`) because export exposes
 * system prompts and tool wiring while import creates posts.
 *
 * @package WP_MCP_AI
 * @since   1.1.80
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent fatal error if WP_REST_Controller is not available yet.
// This can happen during plugin activation before WordPress REST API is fully loaded.
if ( ! class_exists( 'WP_REST_Controller' ) ) {
	return;
}

/**
 * Assistant portability REST controller.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_REST_Assistant_Portability_Controller extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @since 1.1.80
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Capability required for every route.
	 *
	 * @since 1.1.80
	 * @var string
	 */
	const REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @since 1.1.80
	 */
	public function __construct() {
		// Routes are registered by calling register_routes() from the main REST controller.
	}

	/**
	 * Register the export/import routes.
	 *
	 * @since 1.1.80
	 */
	public function register_routes() {
		// Build an export bundle.
		register_rest_route(
			self::REST_NAMESPACE,
			'/assistants/export',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_export' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'ids'         => array(
						'description'       => __( 'Assistant post IDs to export. Omit to export all.', 'mcp-ai-wpoos' ),
						'type'              => 'array',
						'items'             => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'required'          => false,
						'sanitize_callback' => function ( $ids ) {
							return array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
						},
					),
					'include_a2a' => array(
						'description' => __( 'Embed an A2A agent card per assistant in the bundle.', 'mcp-ai-wpoos' ),
						'type'        => 'boolean',
						'default'     => true,
						'required'    => false,
					),
					'format'      => array(
						'description' => __( 'Output format. "a2a" exports a single assistant only.', 'mcp-ai-wpoos' ),
						'type'        => 'string',
						'enum'        => array( 'json', 'a2a' ),
						'default'     => 'json',
						'required'    => false,
					),
				),
			)
		);

		// Apply an import payload.
		register_rest_route(
			self::REST_NAMESPACE,
			'/assistants/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_import' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'json'            => array(
						'description' => __( 'The import payload as a JSON string. Either this or attachment_id is required.', 'mcp-ai-wpoos' ),
						'type'        => 'string',
						'required'    => false,
						'maxLength'   => 2097152, // 2 MB hard cap.
					),
					'attachment_id'   => array(
						'description' => __( 'WordPress attachment ID of a JSON export file. Either this or json is required.', 'mcp-ai-wpoos' ),
						'type'        => 'integer',
						'minimum'     => 1,
						'required'    => false,
					),
					'mode'            => array(
						'description' => __( 'Behaviour when an imported assistant already exists.', 'mcp-ai-wpoos' ),
						'type'        => 'string',
						'enum'        => array( 'skip', 'overwrite', 'duplicate' ),
						'default'     => 'skip',
						'required'    => false,
					),
					'dry_run'         => array(
						'description' => __( 'Validate and report what would happen without writing anything.', 'mcp-ai-wpoos' ),
						'type'        => 'boolean',
						'default'     => false,
						'required'    => false,
					),
					'status_override' => array(
						'description' => __( 'Force imported assistants to this post status.', 'mcp-ai-wpoos' ),
						'type'        => 'string',
						'enum'        => array( 'draft', 'publish', 'private' ),
						'required'    => false,
					),
				),
			)
		);
	}

	/**
	 * Permission check for every route.
	 *
	 * @since 1.1.80
	 *
	 * @return bool|WP_Error True when the current user may export/import assistants.
	 */
	public function check_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_portability_forbidden',
				__( 'You are not allowed to export or import assistants.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle the export request.
	 *
	 * @since 1.1.80
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error Export payload or error.
	 */
	public function handle_export( $request ) {
		$ids         = $request->get_param( 'ids' );
		$include_a2a = (bool) $request->get_param( 'include_a2a' );
		$format      = $request->get_param( 'format' );

		if ( 'a2a' === $format ) {
			if ( empty( $ids ) || 1 !== count( $ids ) ) {
				return new WP_Error(
					'wp_mcp_ai_portability_a2a_single',
					__( 'The a2a format exports exactly one assistant.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$card = WP_MCP_AI_Assistant_Portability::export_a2a_card( $ids[0] );

			if ( is_wp_error( $card ) ) {
				return $card;
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $card,
				),
				200
			);
		}

		$bundle = WP_MCP_AI_Assistant_Portability::export_assistants(
			empty( $ids ) ? 'all' : $ids,
			array( 'include_a2a' => $include_a2a )
		);

		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $bundle,
			),
			200
		);
	}

	/**
	 * Handle the import request.
	 *
	 * @since 1.1.80
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error Import report or error.
	 */
	public function handle_import( $request ) {
		$json          = $request->get_param( 'json' );
		$attachment_id = absint( $request->get_param( 'attachment_id' ) );

		if ( '' === (string) $json && 0 === $attachment_id ) {
			return new WP_Error(
				'wp_mcp_ai_portability_missing_payload',
				__( 'Provide either a JSON payload or an attachment_id.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === (string) $json && $attachment_id > 0 ) {
			$file = get_attached_file( $attachment_id );

			if ( ! $file || ! is_readable( $file ) ) {
				return new WP_Error(
					'wp_mcp_ai_portability_attachment_unreadable',
					__( 'The attachment file is missing or unreadable.', 'mcp-ai-wpoos' ),
					array( 'status' => 404 )
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local media attachment, not a remote URL.
			$json = file_get_contents( $file );

			if ( false === $json ) {
				return new WP_Error(
					'wp_mcp_ai_portability_attachment_read_failed',
					__( 'Could not read the attachment file.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}
		}

		$bundle = WP_MCP_AI_Assistant_Portability::parse_import( (string) $json );

		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		$report = WP_MCP_AI_Assistant_Portability::import_bundle(
			$bundle,
			array(
				'mode'            => $request->get_param( 'mode' ),
				'dry_run'         => (bool) $request->get_param( 'dry_run' ),
				'status_override' => $request->get_param( 'status_override' ),
			)
		);

		if ( is_wp_error( $report ) ) {
			return $report;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $report,
			),
			200
		);
	}
}
