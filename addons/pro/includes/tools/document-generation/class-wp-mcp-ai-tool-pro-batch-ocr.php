<?php
/**
 * Pro Batch OCR Tool — Bulk document processing via Action Scheduler.
 *
 * Schedules OCR jobs for multiple documents using self-hosted Unlimited-OCR
 * or DeepSeek-OCR. Supports both synchronous (up to 10 docs) and asynchronous
 * (unlimited via Action Scheduler) modes.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.5.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license  Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-self-hosted-ocr-client.php';

/**
 * Pro Batch OCR Tool.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Tool_Pro_Batch_OCR implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Action Scheduler hook for batch OCR jobs.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const AS_HOOK = 'wp_mcp_ai_batch_ocr_process';

	/**
	 * Action Scheduler group.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const AS_GROUP = 'wp_mcp_ai_ocr';

	/**
	 * Maximum documents for synchronous processing.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const MAX_SYNC_DOCS = 10;

	/**
	 * Maximum documents per batch job chunk.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const CHUNK_SIZE = 10;

	/**
	 * Maximum total documents per batch request.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const MAX_TOTAL_DOCS = 100;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'pro_batch_ocr';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Pro Batch OCR', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __(
			'Process multiple documents with self-hosted OCR in batch. '
			. 'Supports synchronous mode (up to 10 docs) and asynchronous mode '
			. '(up to 100 docs via background jobs). Requires a self-hosted '
			. 'Unlimited-OCR or DeepSeek-OCR vLLM instance.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'source'        => array(
					'type'        => 'object',
					'description' => __( 'Source documents to process.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'attachment_ids' => array(
							'type'        => 'array',
							'items'       => array( 'type' => array( 'integer', 'string' ) ),
							'maxItems'    => self::MAX_TOTAL_DOCS,
							'description' => __( 'Array of WordPress attachment IDs.', 'mcp-ai-wpoos-pro' ),
						),
						'urls'           => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'maxItems'    => self::MAX_TOTAL_DOCS,
							'description' => __( 'Array of image/PDF URLs.', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'model_type'    => array(
					'type'        => 'string',
					'enum'        => array( 'unlimited_ocr', 'deepseek_ocr' ),
					'default'     => 'unlimited_ocr',
					'description' => __( 'OCR model to use.', 'mcp-ai-wpoos-pro' ),
				),
				'async'         => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Process asynchronously via background jobs. When true, returns immediately with a job ID. When false, processes up to 10 documents synchronously.', 'mcp-ai-wpoos-pro' ),
				),
				'output_format' => array(
					'type'        => 'string',
					'enum'        => array( 'text', 'json' ),
					'default'     => 'text',
					'description' => __( 'Output format for results (sync mode only).', 'mcp-ai-wpoos-pro' ),
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
			'local-only',
			'external-api',
			'async',
			'cacheable',
			'model-dependent',
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
				__( 'You must be logged in to use batch OCR.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use batch OCR.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		$source     = isset( $arguments['source'] ) && is_array( $arguments['source'] ) ? $arguments['source'] : array();
		$model_type = isset( $arguments['model_type'] ) ? sanitize_key( $arguments['model_type'] ) : 'unlimited_ocr';
		$async      = ! isset( $arguments['async'] ) || ! empty( $arguments['async'] );
		$output_fmt = isset( $arguments['output_format'] ) ? sanitize_key( $arguments['output_format'] ) : 'text';

		// Resolve URLs.
		$urls = $this->resolve_source_urls( $source );
		if ( is_wp_error( $urls ) ) {
			return $urls;
		}

		if ( empty( $urls ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_source',
				__( 'No valid source documents found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$doc_count = count( $urls );

		// Validate client availability.
		if ( ! class_exists( 'WP_MCP_AI_Self_Hosted_OCR_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_client_unavailable',
				__( 'Self-hosted OCR client is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$client = new WP_MCP_AI_Self_Hosted_OCR_Client();
		if ( ! $client->is_valid_model_type( $model_type ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_model',
				__( 'Invalid OCR model type.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Synchronous mode: process up to MAX_SYNC_DOCS inline.
		if ( ! $async ) {
			$limit = min( $doc_count, self::MAX_SYNC_DOCS );
			return $this->process_sync( array_slice( $urls, 0, $limit ), $model_type, $client, $output_fmt );
		}

		// Asynchronous mode: enqueue Action Scheduler chunks.
		return $this->enqueue_async( $urls, $model_type );
	}

	/**
	 * Process documents synchronously.
	 *
	 * @since 1.5.0
	 *
	 * @param string[]                         $urls       Image URLs.
	 * @param string                           $model_type Model type.
	 * @param WP_MCP_AI_Self_Hosted_OCR_Client $client    Client instance.
	 * @param string                           $output_fmt Output format.
	 * @return array Results array with per-document data.
	 */
	private function process_sync( array $urls, $model_type, $client, $output_fmt ) {
		$results     = array();
		$successful  = 0;
		$failed      = 0;
		$total_words = 0;
		$start_time  = microtime( true );

		foreach ( $urls as $url ) {
			// Match the client's single-image OCR default (120s) so a hung
			// download never outlives the model request it feeds.
			$image_data = $client->fetch_and_encode_image( $url, 120 );
			if ( is_wp_error( $image_data ) ) {
				++$failed;
				$results[] = array(
					'url'   => $url,
					'error' => $image_data->get_error_message(),
				);
				continue;
			}

			$defaults = $client->get_model_defaults( $model_type );
			$prompt   = $defaults ? $defaults['prompt_template'] : '';

			$result = $client->ocr_image( $image_data, $prompt, $model_type );

			if ( is_wp_error( $result ) ) {
				++$failed;
				$results[] = array(
					'url'   => $url,
					'error' => $result->get_error_message(),
				);
				continue;
			}

			++$successful;
			$word_count   = str_word_count( $result['text'] );
			$total_words += $word_count;

			$doc_result = array(
				'url'        => $url,
				'word_count' => $word_count,
			);

			if ( 'json' === $output_fmt ) {
				$doc_result['text']     = $result['text'];
				$doc_result['metadata'] = $result['metadata'];
			} else {
				$doc_result['text'] = $result['text'];
			}

			$results[] = $doc_result;
		}

		$duration = round( microtime( true ) - $start_time, 2 );

		return array(
			'documents_count' => count( $urls ),
			'successful'      => $successful,
			'failed'          => $failed,
			'total_words'     => $total_words,
			'total_duration'  => $duration,
			'model_type'      => $model_type,
			'mode'            => 'sync',
			'documents'       => $results,
		);
	}

	/**
	 * Enqueue batch OCR jobs via Action Scheduler.
	 *
	 * @since 1.5.0
	 *
	 * @param string[] $urls       Image URLs.
	 * @param string   $model_type Model type.
	 * @return array|WP_Error Job info or error.
	 */
	private function enqueue_async( array $urls, $model_type ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new WP_Error(
				'wp_mcp_ai_as_unavailable',
				__( 'Action Scheduler is required for async batch OCR but is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		$chunks     = array_chunk( $urls, self::CHUNK_SIZE );
		$job_ids    = array();
		$total_docs = count( $urls );

		foreach ( $chunks as $index => $chunk ) {
			$action_id = as_enqueue_async_action(
				self::AS_HOOK,
				array(
					'urls'         => $chunk,
					'model_type'   => $model_type,
					'chunk_index'  => $index + 1,
					'total_chunks' => count( $chunks ),
				),
				self::AS_GROUP
			);

			if ( $action_id ) {
				$job_ids[] = $action_id;
			}
		}

		if ( empty( $job_ids ) ) {
			return new WP_Error(
				'wp_mcp_ai_enqueue_failed',
				__( 'Failed to enqueue batch OCR jobs.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Fires when batch OCR jobs are enqueued.
		 *
		 * @since 1.5.0
		 *
		 * @param int[]  $job_ids    Action Scheduler action IDs.
		 * @param int    $total_docs Total document count.
		 * @param string $model_type OCR model type.
		 */
		do_action( 'wp_mcp_ai_batch_ocr_enqueued', $job_ids, $total_docs, $model_type );

		return array(
			'job_ids'     => $job_ids,
			'total_docs'  => $total_docs,
			'chunk_count' => count( $chunks ),
			'model_type'  => $model_type,
			'mode'        => 'async',
			'status'      => 'queued',
			'message'     => sprintf(
				/* translators: 1: document count, 2: chunk count */
				__( '%1$d documents queued for processing in %2$d background job(s).', 'mcp-ai-wpoos-pro' ),
				$total_docs,
				count( $chunks )
			),
		);
	}

	/**
	 * Resolve source arguments into an array of image URLs.
	 *
	 * @since 1.5.0
	 *
	 * @param array $source Source arguments.
	 * @return string[]|WP_Error Array of URLs or error.
	 */
	private function resolve_source_urls( array $source ) {
		$urls        = array();
		$seen_urls   = array();
		$seen_attach = array();

		// Process attachment IDs.
		if ( ! empty( $source['attachment_ids'] ) && is_array( $source['attachment_ids'] ) ) {
			foreach ( $source['attachment_ids'] as $id ) {
				$aid = absint( $id );
				if ( isset( $seen_attach[ $aid ] ) ) {
					continue;
				}
				$seen_attach[ $aid ] = true;
				$url                 = wp_get_attachment_url( $aid );
				if ( $url && ! isset( $seen_urls[ $url ] ) ) {
					$seen_urls[ $url ] = true;
					$urls[]            = $url;
				}
			}
		}

		// Process direct URLs.
		if ( ! empty( $source['urls'] ) && is_array( $source['urls'] ) ) {
			foreach ( $source['urls'] as $u ) {
				$url = esc_url_raw( $u );
				if ( ! isset( $seen_urls[ $url ] ) ) {
					$seen_urls[ $url ] = true;
					$urls[]            = $url;
				}
			}
		}

		return $urls;
	}
}
