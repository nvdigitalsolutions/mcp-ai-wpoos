<?php
/**
 * WP_MCP_AI_Tool_Pro_Unlimited_OCR (ecosystem port - Wave F2, document-generation tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/document-generation/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger/trait requires gain exists-check seams resolving from the addon's D8-compat `src/` copies; the self-hosted-OCR-client require gains a class_exists seam resolving from the D8-compat `src/` copy; the structured-extraction-service require gains a class_exists seam resolving from `src/services/`.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}


if ( ! trait_exists( 'WP_MCP_AI_Tool_Chat_Response' ) ) {
	$nvoos_content_graph_pro_tool_chat_response = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/trait-wp-mcp-ai-tool-chat-response.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_chat_response ) ) {
		require_once $nvoos_content_graph_pro_tool_chat_response;
	}
}
if ( ! trait_exists( 'WP_MCP_AI_Tool_Document_Response' ) ) {
	$nvoos_content_graph_pro_tool_document_response = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/trait-wp-mcp-ai-tool-document-response.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_document_response ) ) {
		require_once $nvoos_content_graph_pro_tool_document_response;
	}
}
if ( ! trait_exists( 'WP_MCP_AI_Attachment_File_Resolver' ) ) {
	$nvoos_content_graph_pro_attachment_file_resolver = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
	if ( file_exists( $nvoos_content_graph_pro_attachment_file_resolver ) ) {
		require_once $nvoos_content_graph_pro_attachment_file_resolver;
	}
}
if ( ! defined( 'WP_MCP_AI_PATH' ) && ! class_exists( 'WP_MCP_AI_Self_Hosted_OCR_Client', false ) ) {
	$nvoos_content_graph_pro_self_hosted_ocr_client = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-self-hosted-ocr-client.php';
	if ( file_exists( $nvoos_content_graph_pro_self_hosted_ocr_client ) ) {
		require_once $nvoos_content_graph_pro_self_hosted_ocr_client;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Structured_Extraction_Service' ) ) {
	$nvoos_content_graph_pro_structured_extraction_service = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/services/class-wp-mcp-ai-structured-extraction-service.php';
	if ( file_exists( $nvoos_content_graph_pro_structured_extraction_service ) ) {
		require_once $nvoos_content_graph_pro_structured_extraction_service;
	}
}

/**
 * Pro Unlimited-OCR Tool.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Tool_Pro_Unlimited_OCR implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Document_Response;
	use WP_MCP_AI_Attachment_File_Resolver;

	/**
	 * Maximum batch images.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const MAX_BATCH_IMAGES = 20;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'pro_unlimited_ocr';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Pro Unlimited-OCR', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __(
			'Advanced self-hosted OCR using Baidu Unlimited-OCR or DeepSeek-OCR. '
			. 'Features: one-shot multi-page PDF parsing (up to dozens of pages in a single pass), '
			. 'structured output with layout preservation, table extraction, and Paper Store integration. '
			. 'Requires a self-hosted vLLM instance with GPU. Zero per-page API costs.',
			'nvoos-content-graph-pro'
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'source'  => array(
					'type'        => 'object',
					'description' => __( 'Source document(s) to extract text from.', 'nvoos-content-graph-pro' ),
					'properties'  => array(
						'attachment_id'  => array(
							'type'        => array( 'integer', 'string' ),
							'description' => __( 'Single WordPress attachment ID.', 'nvoos-content-graph-pro' ),
						),
						'attachment_ids' => array(
							'type'        => 'array',
							'items'       => array( 'type' => array( 'integer', 'string' ) ),
							'maxItems'    => self::MAX_BATCH_IMAGES,
							'description' => __( 'Array of WordPress attachment IDs for batch processing.', 'nvoos-content-graph-pro' ),
						),
						'url'            => array(
							'type'        => 'string',
							'description' => __( 'Single image or PDF URL.', 'nvoos-content-graph-pro' ),
						),
						'urls'           => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'maxItems'    => self::MAX_BATCH_IMAGES,
							'description' => __( 'Array of image/PDF URLs for batch processing.', 'nvoos-content-graph-pro' ),
						),
					),
				),
				'options' => array(
					'type'        => 'object',
					'description' => __( 'OCR processing options.', 'nvoos-content-graph-pro' ),
					'properties'  => array(
						'model_type'          => array(
							'type'        => 'string',
							'enum'        => array( 'unlimited_ocr', 'deepseek_ocr' ),
							'default'     => 'unlimited_ocr',
							'description' => __( 'OCR model to use. Unlimited-OCR recommended for long documents; DeepSeek-OCR supports richer prompt modes.', 'nvoos-content-graph-pro' ),
						),
						'image_mode'          => array(
							'type'        => 'string',
							'enum'        => array( 'gundam', 'base' ),
							'default'     => 'gundam',
							'description' => __( 'Image resolution mode. gundam = cropped high-res tiles + base overview (best for documents). base = single resolution (required for multi-page).', 'nvoos-content-graph-pro' ),
						),
						'output_format'       => array(
							'type'        => 'string',
							'enum'        => array( 'text', 'structured', 'raw' ),
							'default'     => 'text',
							'description' => __( 'Output format. text = cleaned plain text. structured = blocks with category + bbox. raw = unprocessed model output with <|det|> markers.', 'nvoos-content-graph-pro' ),
						),
						'preserve_layout'     => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'Preserve document layout and formatting.', 'nvoos-content-graph-pro' ),
						),
						'extract_tables'      => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Detect and extract tables as structured data. Only applies when output_format is "structured".', 'nvoos-content-graph-pro' ),
						),
						'save_to_paper_store' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Save extracted text to the Paper Store for later retrieval and processing.', 'nvoos-content-graph-pro' ),
						),
					),
				),
			),
			'required'   => array( 'source' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_authentication() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-vision-model',
			'read-only',
			'external-api',
			'local-only',
			'model-dependent',
			'async',
			'cacheable',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be logged in to use OCR.', 'nvoos-content-graph-pro' ),
				array( 'status' => 403 )
			);
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use OCR.', 'nvoos-content-graph-pro' ),
				array( 'status' => 403 )
			);
		}

		// Resolve source documents.
		$image_urls = $this->resolve_source_urls( $arguments );
		if ( is_wp_error( $image_urls ) ) {
			return $image_urls;
		}

		if ( empty( $image_urls ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_source',
				__( 'No valid source documents found. Provide attachment_id, attachment_ids, url, or urls.', 'nvoos-content-graph-pro' ),
				array( 'status' => 400 )
			);
		}

		// Parse options.
		$options     = isset( $arguments['options'] ) && is_array( $arguments['options'] ) ? $arguments['options'] : array();
		$model_type  = isset( $options['model_type'] ) ? sanitize_key( $options['model_type'] ) : 'unlimited_ocr';
		$image_mode  = isset( $options['image_mode'] ) ? sanitize_key( $options['image_mode'] ) : 'gundam';
		$output_fmt  = isset( $options['output_format'] ) ? sanitize_key( $options['output_format'] ) : 'text';
		$extract_tbl = ! empty( $options['extract_tables'] );
		$save_paper  = ! empty( $options['save_to_paper_store'] );
		$is_multi    = count( $image_urls ) > 1;

		// Multi-page forces base mode.
		if ( $is_multi ) {
			$image_mode = 'base';
		}

		// Validate client availability.
		if ( ! class_exists( 'WP_MCP_AI_Self_Hosted_OCR_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_client_unavailable',
				__( 'Self-hosted OCR client is not available.', 'nvoos-content-graph-pro' ),
				array( 'status' => 500 )
			);
		}

		$client = new WP_MCP_AI_Self_Hosted_OCR_Client();

		if ( ! $client->is_valid_model_type( $model_type ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_model',
				sprintf(
					/* translators: %s: model type */
					__( 'Invalid OCR model type: %s.', 'nvoos-content-graph-pro' ),
					esc_html( $model_type )
				),
				array( 'status' => 400 )
			);
		}

		// Fetch and encode images. Use the same timeout as the OCR call below so a
		// hung download never outlives the model request it feeds.
		$timeout        = $is_multi ? 300 : 120;
		$encoded_images = array();
		foreach ( $image_urls as $url ) {
			$encoded = $client->fetch_and_encode_image( $url, $timeout );
			if ( is_wp_error( $encoded ) ) {
				return $encoded;
			}
			$encoded_images[] = $encoded;
		}

		// Perform OCR.
		$client_options = array(
			'image_mode' => $image_mode,
			'timeout'    => $timeout,
		);

		$defaults = $client->get_model_defaults( $model_type );
		$prompt   = $defaults ? $defaults['prompt_template'] : '';

		$result = $client->ocr_images( $encoded_images, $prompt, $model_type, $client_options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Build structured output based on format.
		$response = array(
			'text'       => $result['text'],
			'model_type' => $model_type,
			'metadata'   => $result['metadata'],
		);

		if ( 'raw' === $output_fmt ) {
			$response['raw'] = $result['raw'];
		}

		if ( 'structured' === $output_fmt && 'unlimited_ocr' === $model_type ) {
			$extraction_service = new WP_MCP_AI_Structured_Extraction_Service();
			$response['blocks'] = $extraction_service->parse_blocks( $result['raw'] );
			if ( $extract_tbl ) {
				$response['tables'] = $extraction_service->extract_tables( $response['blocks'] );
			}
		}

		// Optionally save to Paper Store.
		if ( $save_paper && class_exists( 'WP_MCP_AI_Paper_Store_Manager' ) ) {
			$paper_result = $this->save_to_paper_store( $result['text'], $model_type );
			if ( ! is_wp_error( $paper_result ) ) {
				$response['paper_store'] = $paper_result;
			}
		}

		return $response;
	}

	/**
	 * Resolve source arguments into an array of image URLs.
	 *
	 * @since 1.5.0
	 *
	 * @param array $arguments Tool arguments.
	 * @return string[]|WP_Error Array of URLs or error.
	 */
	private function resolve_source_urls( array $arguments ) {
		$source = isset( $arguments['source'] ) && is_array( $arguments['source'] )
			? $arguments['source']
			: array();

		$urls        = array();
		$seen_urls   = array();
		$seen_attach = array();

		// Process attachment IDs.
		$attachment_ids = array();
		if ( ! empty( $source['attachment_id'] ) ) {
			$attachment_ids[] = absint( $source['attachment_id'] );
		}
		if ( ! empty( $source['attachment_ids'] ) && is_array( $source['attachment_ids'] ) ) {
			foreach ( $source['attachment_ids'] as $id ) {
				$attachment_ids[] = absint( $id );
			}
		}

		foreach ( $attachment_ids as $id ) {
			if ( isset( $seen_attach[ $id ] ) ) {
				continue;
			}
			$seen_attach[ $id ] = true;
			$url                = wp_get_attachment_url( $id );
			if ( $url && ! isset( $seen_urls[ $url ] ) ) {
				$seen_urls[ $url ] = true;
				$urls[]            = $url;
			}
		}

		// Process direct URLs.
		$direct_urls = array();
		if ( ! empty( $source['url'] ) ) {
			$direct_urls[] = esc_url_raw( $source['url'] );
		}
		if ( ! empty( $source['urls'] ) && is_array( $source['urls'] ) ) {
			foreach ( $source['urls'] as $u ) {
				$direct_urls[] = esc_url_raw( $u );
			}
		}

		foreach ( $direct_urls as $url ) {
			if ( ! isset( $seen_urls[ $url ] ) ) {
				$seen_urls[ $url ] = true;
				$urls[]            = $url;
			}
		}

		return $urls;
	}

	/**
	 * Parse raw OCR output into structured blocks via the extraction service.
	 *
	 * @since 1.5.0
	 *
	 * @param string $raw Raw OCR output with <|det|> markers.
	 * @return array[] Array of blocks with category, text, bbox keys.
	 */
	private function parse_structured_blocks( $raw ) {
		$service = new WP_MCP_AI_Structured_Extraction_Service();
		return $service->parse_blocks( $raw );
	}

	/**
	 * Extract tables from structured blocks via the extraction service.
	 *
	 * @since 1.5.0
	 *
	 * @param array[] $blocks Structured blocks from parse_structured_blocks().
	 * @return array[] Extracted tables with headers and rows.
	 */
	private function extract_tables_from_blocks( $blocks ) {
		$service = new WP_MCP_AI_Structured_Extraction_Service();
		return $service->extract_tables( $blocks );
	}

	/**
	 * Save extracted text to the Paper Store.
	 *
	 * @since 1.5.0
	 *
	 * @param string $text       Extracted OCR text.
	 * @param string $model_type Model type used.
	 * @return array|WP_Error Paper Store record info or error.
	 */
	private function save_to_paper_store( $text, $model_type ) {
		if ( ! class_exists( 'WP_MCP_AI_Paper_Store_Manager' ) ) {
			return new WP_Error(
				'paper_store_unavailable',
				__( 'Paper Store is not available.', 'nvoos-content-graph-pro' )
			);
		}

		try {
			$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
			$repo    = $manager->get_repository( 'ocr_extracts' );

			$record_id = 'ocr_' . $model_type . '_' . gmdate( 'YmdHis' ) . '_' . substr( md5( $text ), 0, 8 );

			$repo->save(
				array(
					'id'     => $record_id,
					'type'   => 'ocr_extract',
					'title'  => sprintf(
						/* translators: 1: model type, 2: date */
						__( 'OCR Extract — %1$s — %2$s', 'nvoos-content-graph-pro' ),
						$model_type,
						gmdate( 'Y-m-d H:i:s' )
					),
					'status' => 'published',
					'tags'   => array( 'ocr', $model_type ),
					'body'   => array(
						'text'       => $text,
						'model_type' => $model_type,
						'word_count' => str_word_count( $text ),
					),
				)
			);

			return array(
				'paper_store_id' => $record_id,
				'collection'     => 'ocr_extracts',
			);
		} catch ( \Exception $e ) {
			return new WP_Error(
				'paper_store_error',
				$e->getMessage()
			);
		}
	}
}
