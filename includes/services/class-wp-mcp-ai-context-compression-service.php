<?php
/**
 * Context Compression Service
 *
 * Implements RAG best practices for context compression and summarization.
 * Helps manage token budgets by compressing and summarizing context items.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Context compression service for agent memory management.
 *
 * Implements compression strategies based on RAG architecture best practices:
 * - Context summarization for long-term storage
 * - Automatic chunking with semantic boundaries
 * - Chunk overlap management (10-20%)
 * - TTL-aware compression policies
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Context_Compression_Service {
	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Context_Compression_Service|null
	 */
	private static $instance = null;

	/**
	 * Optimal chunk size range (tokens).
	 *
	 * @var array
	 */
	const CHUNK_SIZE_RANGE = array(
		'min' => 150,
		'max' => 1000,
	);

	/**
	 * Default chunk overlap percentage.
	 *
	 * @var float
	 */
	const DEFAULT_OVERLAP = 0.15; // 15%.

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Context_Compression_Service
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		// Constructor logic.
	}

	/**
	 * Compress context content for storage.
	 *
	 * Implements compression strategies:
	 * - Summarization for long content
	 * - Removal of redundant information
	 * - Preservation of key facts
	 *
	 * @param string $content   Content to compress.
	 * @param array  $options   Compression options.
	 * @return array Compressed content with metadata.
	 */
	public function compress_context( $content, $options = array() ) {
		$defaults = array(
			'target_ratio'   => 0.5,            // Target 50% compression.
			'preserve_facts' => true,
			'method'         => 'summarization', // phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Accepted values: 'summarization' or 'chunking'; documents valid options, not commented-out code.
		);

		$options = wp_parse_args( $options, $defaults );

		// Estimate current tokens.
		$original_tokens = $this->estimate_tokens( $content );
		$target_tokens   = (int) ( $original_tokens * $options['target_ratio'] );

		// Skip compression if already under target.
		if ( $original_tokens <= $target_tokens ) {
			return array(
				'success'         => true,
				'compressed'      => $content,
				'original_tokens' => $original_tokens,
				'final_tokens'    => $original_tokens,
				'ratio'           => 1.0,
				'method'          => 'none',
			);
		}

		$compressed = $content;
		$method     = 'basic';

		// Apply compression method.
		if ( 'summarization' === $options['method'] ) {
			$compressed = $this->summarize_content( $content, $target_tokens, $options['preserve_facts'] );
			$method     = 'summarization';
		} elseif ( 'chunking' === $options['method'] ) {
			// Chunking doesn't reduce size, but optimizes structure.
			$chunks     = $this->chunk_content( $content, $target_tokens );
			$compressed = implode( "\n\n", array_column( $chunks, 'content' ) );
			$method     = 'chunking';
		}

		$final_tokens = $this->estimate_tokens( $compressed );

		return array(
			'success'         => true,
			'compressed'      => $compressed,
			'original_tokens' => $original_tokens,
			'final_tokens'    => $final_tokens,
			'ratio'           => $final_tokens / $original_tokens,
			'method'          => $method,
			'saved_tokens'    => $original_tokens - $final_tokens,
		);
	}

	/**
	 * Chunk content into semantically meaningful pieces.
	 *
	 * Implements semantic chunking with overlap based on RAG best practices.
	 *
	 * @param string $content       Content to chunk.
	 * @param int    $chunk_size    Target chunk size in tokens.
	 * @param float  $overlap_ratio Overlap ratio (0-0.3).
	 * @return array Array of chunks with metadata.
	 */
	public function chunk_content( $content, $chunk_size = 500, $overlap_ratio = self::DEFAULT_OVERLAP ) {
		// Validate inputs.
		$chunk_size    = max( self::CHUNK_SIZE_RANGE['min'], min( self::CHUNK_SIZE_RANGE['max'], $chunk_size ) );
		$overlap_ratio = max( 0.0, min( 0.3, $overlap_ratio ) );

		// Split content into paragraphs (semantic boundaries).
		$paragraphs = preg_split( '/\n\s*\n/', $content, -1, PREG_SPLIT_NO_EMPTY );

		$chunks        = array();
		$current_chunk = '';
		$chunk_tokens  = 0;
		$chunk_index   = 0;

		foreach ( $paragraphs as $paragraph ) {
			$para_tokens = $this->estimate_tokens( $paragraph );

			// Check if adding this paragraph exceeds chunk size.
			if ( $chunk_tokens + $para_tokens > $chunk_size && ! empty( $current_chunk ) ) {
				// Save current chunk.
				$chunks[] = array(
					'index'   => $chunk_index,
					'content' => trim( $current_chunk ),
					'tokens'  => $chunk_tokens,
					'type'    => 'semantic',
				);

				// Calculate overlap size.
				$overlap_tokens = (int) ( $chunk_size * $overlap_ratio );

				// Start new chunk with overlap from previous chunk.
				$overlap_text  = $this->get_last_n_tokens( $current_chunk, $overlap_tokens );
				$current_chunk = $overlap_text . "\n\n" . $paragraph;
				$chunk_tokens  = $this->estimate_tokens( $current_chunk );
				++$chunk_index;
			} else {
				// Add paragraph to current chunk.
				$current_chunk .= "\n\n" . $paragraph;
				$chunk_tokens  += $para_tokens;
			}
		}

		// Add final chunk.
		if ( ! empty( $current_chunk ) ) {
			$chunks[] = array(
				'index'   => $chunk_index,
				'content' => trim( $current_chunk ),
				'tokens'  => $chunk_tokens,
				'type'    => 'semantic',
			);
		}

		return $chunks;
	}

	/**
	 * Summarize content to target token count.
	 *
	 * Uses AI summarization when available, falls back to extractive summarization.
	 *
	 * @param string $content        Content to summarize.
	 * @param int    $target_tokens  Target token count.
	 * @param bool   $preserve_facts Whether to preserve key facts.
	 * @return string Summarized content.
	 */
	private function summarize_content( $content, $target_tokens, $preserve_facts = true ) {
		// Try AI summarization first.
		$ai_summary = $this->ai_summarize( $content, $target_tokens, $preserve_facts );
		if ( ! empty( $ai_summary ) ) {
			return $ai_summary;
		}

		// Fallback to extractive summarization.
		return $this->extractive_summarize( $content, $target_tokens );
	}

	/**
	 * AI-powered summarization using available LLM.
	 *
	 * @param string $content        Content to summarize.
	 * @param int    $target_tokens  Target token count.
	 * @param bool   $preserve_facts Whether to preserve key facts.
	 * @return string Summarized content or empty on failure.
	 */
	private function ai_summarize( $content, $target_tokens, $preserve_facts ) {
		// Check if AI client is available.
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
		$api_key  = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';

		if ( empty( $api_key ) || ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			return '';
		}

		try {
			$client = new WP_MCP_AI_OpenAI_Client();

			$prompt = sprintf(
				'Summarize the following content to approximately %d tokens%s:\n\n%s',
				$target_tokens,
				$preserve_facts ? ', preserving all key facts and important details' : '',
				$content
			);

			$response = $client->create_chat_completion(
				array(
					'model'       => 'gpt-4o-mini',
					'messages'    => array(
						array(
							'role'    => 'system',
							'content' => 'You are a precise summarization assistant. Create concise summaries that preserve essential information.',
						),
						array(
							'role'    => 'user',
							'content' => $prompt,
						),
					),
					'max_tokens'  => $target_tokens,
					'temperature' => 0.3,
				)
			);

			if ( is_wp_error( $response ) ) {
				return '';
			}

			return $response['choices'][0]['message']['content'] ?? '';

		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Extractive summarization (fallback method).
	 *
	 * Selects most important sentences based on keyword frequency.
	 *
	 * @param string $content       Content to summarize.
	 * @param int    $target_tokens Target token count.
	 * @return string Summarized content.
	 */
	private function extractive_summarize( $content, $target_tokens ) {
		// Split into sentences.
		$sentences = preg_split( '/(?<=[.!?])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY );

		if ( count( $sentences ) <= 3 ) {
			return $content; // Too short to summarize.
		}

		// Score sentences by keyword frequency.
		$scores = array();
		$words  = str_word_count( strtolower( $content ), 1 );
		$freq   = array_count_values( $words );

		// Remove common words.
		$common = array( 'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been' );
		$freq   = array_diff_key( $freq, array_flip( $common ) );

		// Score each sentence.
		foreach ( $sentences as $index => $sentence ) {
			$sentence_words = str_word_count( strtolower( $sentence ), 1 );
			$score          = 0;
			foreach ( $sentence_words as $word ) {
				$score += isset( $freq[ $word ] ) ? $freq[ $word ] : 0;
			}
			$scores[ $index ] = $score;
		}

		// Sort by score descending.
		arsort( $scores );

		// Select top sentences until target tokens reached.
		$selected       = array();
		$selected_keys  = array();
		$current_tokens = 0;

		foreach ( $scores as $index => $score ) {
			$sentence_tokens = $this->estimate_tokens( $sentences[ $index ] );
			if ( $current_tokens + $sentence_tokens <= $target_tokens ) {
				$selected_keys[] = $index;
				$current_tokens += $sentence_tokens;
			}

			if ( $current_tokens >= $target_tokens * 0.9 ) {
				break; // Within 10% of target.
			}
		}

		// Sort selected keys to maintain order.
		sort( $selected_keys );

		// Build summary.
		foreach ( $selected_keys as $key ) {
			$selected[] = $sentences[ $key ];
		}

		return implode( ' ', $selected );
	}

	/**
	 * Get last N tokens from text.
	 *
	 * @param string $text  Text to extract from.
	 * @param int    $tokens Number of tokens to extract.
	 * @return string Extracted text.
	 */
	private function get_last_n_tokens( $text, $tokens ) {
		// Rough approximation: 4 chars per token.
		$chars = $tokens * 4;
		return substr( $text, -$chars );
	}

	/**
	 * Estimate token count for text.
	 *
	 * @param string $text Text to estimate.
	 * @return int Estimated token count.
	 */
	private function estimate_tokens( $text ) {
		if ( empty( $text ) ) {
			return 0;
		}
		// Conservative estimate: ~4 characters per token.
		return (int) ceil( strlen( $text ) / 4 );
	}

	/**
	 * Apply compression policy based on context age.
	 *
	 * Implements TTL-aware compression as per RAG best practices.
	 *
	 * @param array $context Context record.
	 * @return array Compressed context or original.
	 */
	public function apply_compression_policy( $context ) {
		if ( ! isset( $context['stored_at'] ) || ! isset( $context['data']['content'] ) ) {
			return $context;
		}

		$age_days = ( time() - strtotime( $context['stored_at'] ) ) / DAY_IN_SECONDS;

		// Compression policy based on age:
		// - 0-7 days: No compression (100%).
		// - 7-30 days: Light compression (75%).
		// - 30+ days: Heavy compression (50%).

		$target_ratio = 1.0;
		if ( $age_days > 30 ) {
			$target_ratio = 0.5;
		} elseif ( $age_days > 7 ) {
			$target_ratio = 0.75;
		}

		// Skip if no compression needed.
		if ( $target_ratio >= 1.0 ) {
			return $context;
		}

		// Compress content.
		$result = $this->compress_context(
			$context['data']['content'],
			array(
				'target_ratio'   => $target_ratio,
				'preserve_facts' => true,
				'method'         => 'summarization',
			)
		);

		if ( $result['success'] && $result['ratio'] < $target_ratio ) {
			$context['data']['content']     = $result['compressed'];
			$context['data']['compressed']  = true;
			$context['data']['compression'] = array(
				'method'          => $result['method'],
				'original_tokens' => $result['original_tokens'],
				'final_tokens'    => $result['final_tokens'],
				'ratio'           => $result['ratio'],
				'compressed_at'   => current_time( 'mysql' ),
			);
		}

		return $context;
	}
}
