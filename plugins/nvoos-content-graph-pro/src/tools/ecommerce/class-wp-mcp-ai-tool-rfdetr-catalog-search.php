<?php
/**
 * Tool for catalog-specific object detection via a fine-tuned RF-DETR model (ecosystem port — Wave F2, e-commerce).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-tool-rfdetr-catalog-search.php`
 * for the standalone `nvoos-content-graph-pro` addon. Kept byte-identical.
 * The base Pro addon owns the class in monolith installs — the addon's
 * autoloader skips its copy when `WP_MCP_AI_PRO_PATH` is defined (see the
 * plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; base-owned file requires are monolith-gated
 * behind `defined( 'WP_MCP_AI_PATH' )` (classmap-served in the matrices)
 * with wave-proof re-check guards; the URL-guard call is
 * class-exists-guarded (dormant standalone — the base guard lands with its
 * owning wave); the dHash helper resolves from the addon's D8-compat
 * `src/helpers/` copy; the Roboflow-service require resolves from the
 * addon's `src/services/` copy.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
}
if ( defined( 'WP_MCP_AI_PATH' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-url-guard.php';
}
if ( ! trait_exists( 'WP_MCP_AI_Attachment_File_Resolver' ) ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
}
if ( ! trait_exists( 'WP_MCP_AI_Tool_Chat_Response' ) ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/trait-wp-mcp-ai-tool-chat-response.php';
}
if ( ! class_exists( 'WP_MCP_AI_Image_DHash' ) ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/helpers/class-wp-mcp-ai-image-dhash.php';
}

if ( ! class_exists( 'WP_MCP_AI_Roboflow_Inference_Service' ) ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/services/class-wp-mcp-ai-roboflow-inference-service.php';
}

/**
 * Catalog-specific detection tool backed by a fine-tuned RF-DETR model.
 *
 * Returns ranked detections with the checkpoint's class names — the
 * structured signal e-commerce assistants need for "is product X in this
 * photo, and where?" questions.
 *
 * @since 1.1.90
 */
class WP_MCP_AI_Pro_Tool_Rfdetr_Catalog_Search implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Attachment_File_Resolver;
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Default required capability (filterable).
	 *
	 * @var string
	 */
	const DEFAULT_REQUIRED_CAPABILITY = 'edit_posts';

	/**
	 * Transient cache TTL for identical image+model pairs (seconds).
	 *
	 * @var int
	 */
	const CACHE_TTL_SECONDS = 300;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'rfdetr_catalog_search';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'RF-DETR Catalog Search', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Detects catalog-specific objects (brand packaging, SKU-specific products, custom classes) in an image using a fine-tuned RF-DETR model. Returns ranked detections with the checkpoint class names and bounding boxes. Configure the model under Pro → Vision Analysis (RF-DETR section); identical image+model requests are cached for 5 minutes.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Asking "is product X in this photo?" against a trained catalog model — brand/package presence, planogram compliance, warehouse shelf checks.', 'nvoos-content-graph-pro' ),
			'when_not_to_use' => __( 'General COCO-class detection — use rfdetr_detect or analyze_image_objects instead. No fine-tuned model configured — the tool refuses with a setup error.', 'nvoos-content-graph-pro' ),
			'related_tools'   => array( 'rfdetr_detect', 'analyze_image_objects', 'vision_product_search', 'woo_products' ),
			'notes'           => __( 'Needs va_roboflow_catalog_model set (alias or workspace/project/version). Self-hosted endpoints keep image bytes on your network.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id' => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID of the image to search.', 'nvoos-content-graph-pro' ),
				),
				'image_url'     => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'URL of the image to search.', 'nvoos-content-graph-pro' ),
				),
				'image_content' => array(
					'type'        => 'string',
					'description' => __( 'Base64-encoded image content.', 'nvoos-content-graph-pro' ),
				),
				'model_id'      => array(
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => __( 'Optional model override (alias or workspace/project/version). Defaults to the configured catalog model.', 'nvoos-content-graph-pro' ),
				),
				'confidence'    => array(
					'type'        => 'number',
					'minimum'     => 0.0,
					'maximum'     => 1.0,
					'description' => __( 'Minimum confidence threshold (0.0–1.0). Default: 0.5.', 'nvoos-content-graph-pro' ),
					'default'     => 0.5,
				),
				'max_results'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => __( 'Maximum detections to return. Default: 25.', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array(),
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
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'read-only',
			'requires-credentials',
			'external-api',
			'network-dependent',
			'rate-limited',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_rfdetr_catalog_forbidden',
				__( 'You must be logged in to use catalog search.', 'nvoos-content-graph-pro' ),
				array( 'status' => 403 )
			);
		}

		$required_capability = apply_filters(
			'wp_mcp_ai_rfdetr_catalog_search_required_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $user_id && $required_capability && ! user_can( $user_id, $required_capability ) ) {
			return new WP_Error(
				'wp_mcp_ai_rfdetr_catalog_forbidden',
				__( 'You do not have permission to use catalog search.', 'nvoos-content-graph-pro' ),
				array( 'status' => 403 )
			);
		}

		if ( empty( $arguments['attachment_id'] ) && empty( $arguments['image_url'] ) && empty( $arguments['image_content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_rfdetr_catalog_missing_input',
				__( 'One of attachment_id, image_url, or image_content must be provided.', 'nvoos-content-graph-pro' ),
				array( 'status' => 400 )
			);
		}

		$confidence  = isset( $arguments['confidence'] ) ? (float) max( 0.0, min( 1.0, $arguments['confidence'] ) ) : 0.5;
		$max_results = isset( $arguments['max_results'] ) ? min( 100, max( 1, absint( $arguments['max_results'] ) ) ) : 25;

		$service = new WP_MCP_AI_Roboflow_Inference_Service();

		$model_id = isset( $arguments['model_id'] ) && '' !== trim( (string) $arguments['model_id'] )
			? sanitize_text_field( $arguments['model_id'] )
			: $service->get_catalog_model();

		if ( '' === $model_id ) {
			return new WP_Error(
				'wp_mcp_ai_rfdetr_catalog_model_unset',
				__( 'No catalog model is configured. Set the RF-DETR catalog model under Pro → Vision Analysis settings (or pass model_id).', 'nvoos-content-graph-pro' ),
				array( 'status' => 400 )
			);
		}

		// --- Resolve local image bytes ---
		$file = $this->resolve_local_file( $arguments );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		list( $file_path, $is_temp ) = $file;

		if ( ! file_exists( $file_path ) ) {
			if ( $is_temp ) {
				wp_delete_file( $file_path );
			}
			return new WP_Error(
				'wp_mcp_ai_rfdetr_catalog_source_unreadable',
				__( 'The source image could not be read.', 'nvoos-content-graph-pro' ),
				array( 'status' => 400 )
			);
		}

		// --- Per-model + dHash cache (5 min TTL) ---
		$cache_key = '';
		$cached    = false;

		if ( function_exists( 'imagecreatetruecolor' ) ) {
			$hash = WP_MCP_AI_Image_DHash::compute( $file_path );
			if ( ! is_wp_error( $hash ) ) {
				$cache_key = 'wp_mcp_ai_rfdetr_catalog_' . md5( $model_id . '|' . $hash );
				$cached    = get_transient( $cache_key );
			}
		}

		if ( is_array( $cached ) ) {
			if ( $is_temp ) {
				wp_delete_file( $file_path );
			}
			$cached['cached'] = true;
			return $cached;
		}

		$image_data = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
		if ( $is_temp ) {
			wp_delete_file( $file_path );
		}

		if ( false === $image_data ) {
			return new WP_Error( 'wp_mcp_ai_rfdetr_catalog_source_unreadable', __( 'The source image could not be read.', 'nvoos-content-graph-pro' ), array( 'status' => 500 ) );
		}

		$image_base64 = base64_encode( $image_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64_encode used to encode binary image data for API transmission, not for obfuscation.

		if ( strlen( $image_base64 ) > WP_MCP_AI_Roboflow_Inference_Service::MAX_PAYLOAD_BYTES ) {
			return new WP_Error(
				'wp_mcp_ai_rfdetr_catalog_payload_too_large',
				__( 'The image exceeds the maximum inference payload size. Resize it before retrying.', 'nvoos-content-graph-pro' ),
				array( 'status' => 413 )
			);
		}

		$result = $service->infer( $image_base64, $model_id, $confidence );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$detections = array_slice( $result['detections'], 0, $max_results );

		$envelope = array(
			'success'     => true,
			'model'       => $result['model'],
			'provider'    => 'roboflow',
			'detections'  => $detections,
			'total_count' => count( $detections ),
			'cached'      => false,
		);

		if ( null !== $result['inference_ms'] ) {
			$envelope['inference_ms'] = $result['inference_ms'];
		}

		$envelope['message'] = sprintf(
			/* translators: 1: number of detections, 2: model identifier */
			__( 'Found %1$d catalog detection(s) with %2$s.', 'nvoos-content-graph-pro' ),
			count( $detections ),
			$result['model']
		);

		if ( '' !== $cache_key ) {
			set_transient( $cache_key, $envelope, self::CACHE_TTL_SECONDS );
		}

		return $envelope;
	}

	/**
	 * Resolve the input image to a local file for inference.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Two-element array ( path, is_temp ), or WP_Error.
	 */
	private function resolve_local_file( array $arguments ) {
		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			$file_path     = get_attached_file( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_rfdetr_catalog_missing_file',
					sprintf(
						/* translators: %d: attachment ID */
						__( 'No local file found for attachment ID %d.', 'nvoos-content-graph-pro' ),
						$attachment_id
					),
					array( 'status' => 404 )
				);
			}

			return array( $file_path, false );
		}

		if ( ! empty( $arguments['image_content'] ) ) {
			$content = sanitize_text_field( $arguments['image_content'] );

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding caller-provided image content for inference; no secret handling involved.
			$decoded = base64_decode( $content, true );

			if ( false === $decoded || '' === $decoded ) {
				return new WP_Error(
					'wp_mcp_ai_rfdetr_catalog_invalid_content',
					__( 'image_content is not valid base64-encoded image data.', 'nvoos-content-graph-pro' ),
					array( 'status' => 400 )
				);
			}

			$tmp_file = wp_tempnam( 'wpoos-rfdetr-catalog' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing temporary file for the inference request.
			if ( false === file_put_contents( $tmp_file, $decoded ) ) {
				return new WP_Error(
					'wp_mcp_ai_rfdetr_catalog_temp_write_failed',
					__( 'Could not write the image content to a temporary file.', 'nvoos-content-graph-pro' ),
					array( 'status' => 500 )
				);
			}

			return array( $tmp_file, true );
		}

		if ( ! empty( $arguments['image_url'] ) ) {
			$url = esc_url_raw( $arguments['image_url'] );

			$attachment_id = attachment_url_to_postid( $url );
			if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
				$file_path = get_attached_file( $attachment_id );

				if ( $file_path && file_exists( $file_path ) ) {
					return array( $file_path, false );
				}
			}

			// Standalone seam: the base SSRF guard is dormant until its owning
			// wave ports; monolith installs keep the full guard (documented).
			if ( class_exists( 'WP_MCP_AI_URL_Guard' ) ) {
				$nvoos_content_graph_pro_guard = WP_MCP_AI_URL_Guard::validate( $url );
				if ( is_wp_error( $nvoos_content_graph_pro_guard ) ) {
					return new WP_Error(
						'wp_mcp_ai_rfdetr_catalog_blocked_url',
						$nvoos_content_graph_pro_guard->get_error_message(),
						array( 'status' => 403 )
					);
				}
			}

			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$tmp_file = download_url( $url, 30 );

			if ( is_wp_error( $tmp_file ) ) {
				return new WP_Error(
					'wp_mcp_ai_rfdetr_catalog_download_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Could not download the image: %s', 'nvoos-content-graph-pro' ),
						$tmp_file->get_error_message()
					),
					array( 'status' => 502 )
				);
			}

			return array( $tmp_file, true );
		}

		return new WP_Error(
			'wp_mcp_ai_rfdetr_catalog_missing_input',
			__( 'One of attachment_id, image_url, or image_content must be provided.', 'nvoos-content-graph-pro' ),
			array( 'status' => 400 )
		);
	}
}
