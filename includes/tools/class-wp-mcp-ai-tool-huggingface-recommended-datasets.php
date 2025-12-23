<?php
/**
 * Tool for discovering recommended HuggingFace datasets.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Huggingface_Recommended_Datasets' ) ) {
	/**
	 * Provides curated dataset recommendations based on use case.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Tool_Huggingface_Recommended_Datasets implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

		/**
		 * Check if the tool is available.
		 *
		 * @return bool
		 */
		public static function is_available() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return ! empty( $settings['enable_huggingface_datasets'] );
		}

		/**
		 * Message explaining why the tool is unavailable.
		 *
		 * @return string
		 */
		public static function get_unavailable_reason() {
			return __( 'The HuggingFace Recommended Datasets tool is disabled because HuggingFace Datasets integration is not enabled. Enable it in WP oOS → Providers settings.', 'wp-mcp-ai' );
		}

		/**
		 * Get tool slug.
		 *
		 * @return string
		 */
		public function get_slug() {
			return 'huggingface_recommended_datasets';
		}

	/**
	 * Get tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'HuggingFace Recommended Datasets', 'wp-mcp-ai' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Get a list of recommended HuggingFace datasets', 'wp-mcp-ai' );
	}

	/**
	 * Get tool parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'use_case' => array(
					'type'        => 'string',
					'description' => 'The use case (e.g., "comment moderation", "blog summarization", "product categorization", "multilingual translation")',
				),
				'category' => array(
					'type'        => 'string',
					'enum'        => array( 'nlp', 'vision', 'audio', 'multimodal', 'all' ),
					'description' => 'Filter by category',
					'default'     => 'all',
				),
				'limit'    => array(
					'type'        => 'integer',
					'description' => 'Number of recommendations to return',
					'default'     => 5,
					'minimum'     => 1,
					'maximum'     => 20,
				),
			),
			'required'   => array( 'use_case' ),
			'additionalProperties' => false,
		);
	}

		/**
		 * Get tool definition for MCP.
		 *
		 * @return array
		 */
		public function get_definition() {
			return array(
				'name'        => 'Get Recommended HuggingFace Datasets',
				'description' => 'Get curated dataset recommendations based on your use case (content creation, e-commerce, moderation, etc.)',
				'parameters'  => array(
					'use_case' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'The use case (e.g., "comment moderation", "blog summarization", "product categorization", "multilingual translation")',
					),
					'category'    => array(
						'type'        => 'string',
						'required'    => false,
						'enum'        => array( 'nlp', 'vision', 'audio', 'multimodal', 'all' ),
						'description' => 'Filter by category',
						'default'     => 'all',
					),
					'limit'       => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => 'Number of recommendations to return',
						'default'     => 5,
						'minimum'     => 1,
						'maximum'     => 20,
					),
				),
			);
		}

		/**
		 * Get required capability.
		 *
		 * @return string
		 */
		public function get_required_capability() {
			return apply_filters( 'wp_mcp_ai_tool_huggingface_datasets_capability', 'read' );
		}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array<string> Array of capability flag strings.
	 */
	public function get_capability_flags() {
		return array(
			'external-api',        // Makes external API calls to HuggingFace.
			'network-dependent',   // Requires internet connectivity.
			'read-only',           // Only reads data, doesn't modify WordPress state.
			'cacheable',           // Results can be cached.
			'paginated',           // Supports pagination.
			'large-response',      // May return large datasets.
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
			// Check if HuggingFace Datasets is enabled.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( empty( $settings['enable_huggingface_datasets'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_hf_datasets_disabled',
					__( 'HuggingFace Datasets integration is not enabled. Enable it in WP oOS → Providers settings.', 'wp-mcp-ai' )
				);
			}

			// Sanitize inputs.
			$use_case = sanitize_text_field( $arguments['use_case'] );
			$category = isset( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : 'all';
			$limit    = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 5;
			$limit    = max( 1, min( 20, $limit ) );

			// Get recommendations.
			$recommendations = $this->get_recommendations( $use_case, $category, $limit );

			return array(
				'use_case'        => $use_case,
				'category'        => $category,
				'recommendations' => $recommendations,
				'total'           => count( $recommendations ),
			);
		}

		/**
		 * Get dataset recommendations based on use case.
		 *
		 * @param string $use_case Use case description.
		 * @param string $category Category filter.
		 * @param int    $limit    Number of recommendations.
		 * @return array
		 */
		private function get_recommendations( $use_case, $category, $limit ) {
			$use_case_lower = strtolower( $use_case );
			$all_datasets   = $this->get_dataset_catalog();

			// Score datasets based on relevance to use case.
			$scored = array();
			foreach ( $all_datasets as $dataset ) {
				// Skip if category filter doesn't match.
				if ( 'all' !== $category && $dataset['category'] !== $category ) {
					continue;
				}

				$score           = $this->calculate_relevance_score( $use_case_lower, $dataset );
				$dataset['score'] = $score;

				if ( $score > 0 ) {
					$scored[] = $dataset;
				}
			}

			// Sort by score (descending).
			usort(
				$scored,
				function ( $a, $b ) {
					return $b['score'] - $a['score'];
				}
			);

			// Return top N recommendations.
			return array_slice( $scored, 0, $limit );
		}

		/**
		 * Calculate relevance score for a dataset.
		 *
		 * @param string $use_case Use case (lowercase).
		 * @param array  $dataset  Dataset info.
		 * @return int Score (0-100).
		 */
		private function calculate_relevance_score( $use_case, $dataset ) {
			$score = 0;

			// Check tags.
			foreach ( $dataset['tags'] as $tag ) {
				if ( false !== stripos( $use_case, $tag ) ) {
					$score += 20;
				}
			}

			// Check use cases.
			foreach ( $dataset['use_cases'] as $case ) {
				if ( false !== stripos( $use_case, $case ) ) {
					$score += 15;
				}
			}

			// Check description.
			if ( false !== stripos( $use_case, $dataset['description'] ) ) {
				$score += 10;
			}

			// Priority boost.
			$priority_boost = array(
				'critical' => 30,
				'high'     => 20,
				'medium'   => 10,
			);
			$score         += isset( $priority_boost[ $dataset['priority'] ] ) ? $priority_boost[ $dataset['priority'] ] : 0;

			return min( 100, $score );
		}

		/**
		 * Get the complete dataset catalog.
		 *
		 * @return array
		 */
		private function get_dataset_catalog() {
			return array(
				// NLP Datasets.
				array(
					'dataset'     => 'rajpurkar/squad',
					'name'        => 'SQuAD',
					'category'    => 'nlp',
					'priority'    => 'critical',
					'description' => 'Question answering dataset with 100K+ question-answer pairs',
					'size'        => '100K rows',
					'tags'        => array( 'qa', 'question', 'answer', 'chatbot', 'assistant' ),
					'use_cases'   => array( 'question answering', 'chatbot training', 'Q&A systems' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="rajpurkar/squad", split="train", limit=5)',
				),
				array(
					'dataset'     => 'stanfordnlp/imdb',
					'name'        => 'IMDB Movie Reviews',
					'category'    => 'nlp',
					'priority'    => 'critical',
					'description' => 'Sentiment analysis dataset with 50K movie reviews',
					'size'        => '50K rows',
					'tags'        => array( 'sentiment', 'review', 'comment', 'moderation', 'analysis' ),
					'use_cases'   => array( 'sentiment analysis', 'comment moderation', 'review classification' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="stanfordnlp/imdb", split="train", limit=5)',
				),
				array(
					'dataset'     => 'abisee/cnn_dailymail',
					'name'        => 'CNN/DailyMail',
					'category'    => 'nlp',
					'priority'    => 'critical',
					'description' => 'Text summarization dataset with 300K news articles',
					'size'        => '300K rows',
					'tags'        => array( 'summarization', 'summary', 'article', 'content', 'news' ),
					'use_cases'   => array( 'content summarization', 'article summaries', 'meta descriptions' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="abisee/cnn_dailymail", config="3.0.0", split="train", limit=5)',
				),
				array(
					'dataset'     => 'EdinburghNLP/xsum',
					'name'        => 'XSum',
					'category'    => 'nlp',
					'priority'    => 'critical',
					'description' => 'Extreme summarization with single-sentence summaries',
					'size'        => '227K rows',
					'tags'        => array( 'summarization', 'summary', 'concise', 'snippet', 'meta' ),
					'use_cases'   => array( 'social media snippets', 'meta descriptions', 'title generation' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="EdinburghNLP/xsum", split="train", limit=5)',
				),
				array(
					'dataset'     => 'nyu-mll/glue',
					'name'        => 'GLUE',
					'category'    => 'nlp',
					'priority'    => 'critical',
					'description' => 'General Language Understanding benchmark with 9 NLP tasks',
					'size'        => '120K rows',
					'tags'        => array( 'benchmark', 'classification', 'nlp', 'general' ),
					'use_cases'   => array( 'text classification', 'semantic similarity', 'natural language inference' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="nyu-mll/glue", config="sst2", split="train", limit=5)',
				),
				array(
					'dataset'     => 'ag_news',
					'name'        => 'AG News',
					'category'    => 'nlp',
					'priority'    => 'high',
					'description' => 'News article classification with 4 categories',
					'size'        => '127K rows',
					'tags'        => array( 'classification', 'news', 'category', 'content' ),
					'use_cases'   => array( 'content categorization', 'post classification', 'topic detection' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="ag_news", split="train", limit=5)',
				),
				array(
					'dataset'     => 'yelp_review_full',
					'name'        => 'Yelp Reviews',
					'category'    => 'nlp',
					'priority'    => 'high',
					'description' => 'Multi-class sentiment with 650K reviews (5-star scale)',
					'size'        => '650K rows',
					'tags'        => array( 'review', 'rating', 'sentiment', 'ecommerce', 'woocommerce' ),
					'use_cases'   => array( 'review analysis', 'rating prediction', 'e-commerce sentiment' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="yelp_review_full", split="train", limit=5)',
				),
				array(
					'dataset'     => 'conll2003',
					'name'        => 'CoNLL-2003',
					'category'    => 'nlp',
					'priority'    => 'high',
					'description' => 'Named entity recognition dataset',
					'size'        => '22K sentences',
					'tags'        => array( 'ner', 'entity', 'tagging', 'extraction', 'taxonomy' ),
					'use_cases'   => array( 'entity extraction', 'auto-tagging', 'taxonomy generation' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="conll2003", split="train", limit=5)',
				),

				// Vision Datasets.
				array(
					'dataset'     => 'detection-datasets/coco',
					'name'        => 'COCO',
					'category'    => 'vision',
					'priority'    => 'critical',
					'description' => 'Object detection with 330K images and 80 categories',
					'size'        => '330K images',
					'tags'        => array( 'image', 'object', 'detection', 'vision', 'media' ),
					'use_cases'   => array( 'image analysis', 'object detection', 'media tagging' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="detection-datasets/coco", config="2017", split="train", limit=5)',
				),
				array(
					'dataset'     => 'imagenet-1k',
					'name'        => 'ImageNet',
					'category'    => 'vision',
					'priority'    => 'critical',
					'description' => 'Image classification with 1.2M images and 1000 categories',
					'size'        => '1.2M images',
					'tags'        => array( 'image', 'classification', 'vision', 'media', 'tagging' ),
					'use_cases'   => array( 'image classification', 'auto-tagging', 'media organization' ),
					'example'     => 'Note: ImageNet requires registration. Use COCO or Open Images instead.',
				),
				array(
					'dataset'     => 'zalando-datasets/fashion_mnist',
					'name'        => 'Fashion MNIST',
					'category'    => 'vision',
					'priority'    => 'high',
					'description' => 'Fashion item classification for e-commerce',
					'size'        => '70K images',
					'tags'        => array( 'fashion', 'ecommerce', 'woocommerce', 'product', 'clothing' ),
					'use_cases'   => array( 'product categorization', 'fashion classification', 'e-commerce' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="zalando-datasets/fashion_mnist", split="train", limit=5)',
				),
				array(
					'dataset'     => 'ethz/food101',
					'name'        => 'Food-101',
					'category'    => 'vision',
					'priority'    => 'high',
					'description' => 'Food image classification with 101 categories',
					'size'        => '101K images',
					'tags'        => array( 'food', 'recipe', 'restaurant', 'culinary', 'blog' ),
					'use_cases'   => array( 'recipe categorization', 'food blog tagging', 'restaurant content' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="ethz/food101", split="train", limit=5)',
				),

				// Multimodal Datasets.
				array(
					'dataset'     => 'nlphuji/flickr30k',
					'name'        => 'Flickr30k',
					'category'    => 'multimodal',
					'priority'    => 'critical',
					'description' => 'Image captioning with 31K images and captions',
					'size'        => '31K images',
					'tags'        => array( 'caption', 'alt', 'accessibility', 'image', 'description' ),
					'use_cases'   => array( 'alt text generation', 'image captions', 'accessibility' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="nlphuji/flickr30k", split="test", limit=5)',
				),
				array(
					'dataset'     => 'yerevann/coco-captions',
					'name'        => 'MS COCO Captions',
					'category'    => 'multimodal',
					'priority'    => 'critical',
					'description' => 'Image-text understanding with 330K images',
					'size'        => '330K images',
					'tags'        => array( 'caption', 'image', 'text', 'multimodal', 'alt' ),
					'use_cases'   => array( 'image captioning', 'visual search', 'accessibility' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="yerevann/coco-captions", split="train", limit=5)',
				),
				array(
					'dataset'     => 'HuggingFaceM4/VQAv2',
					'name'        => 'VQA v2',
					'category'    => 'multimodal',
					'priority'    => 'high',
					'description' => 'Visual question answering with 1.1M questions',
					'size'        => '1.1M questions',
					'tags'        => array( 'vqa', 'visual', 'question', 'image', 'qa' ),
					'use_cases'   => array( 'visual Q&A', 'image queries', 'visual chatbots' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="HuggingFaceM4/VQAv2", split="train", limit=5)',
				),

				// Audio Datasets.
				array(
					'dataset'     => 'librispeech_asr',
					'name'        => 'LibriSpeech',
					'category'    => 'audio',
					'priority'    => 'critical',
					'description' => 'Speech recognition with 1000 hours of audio',
					'size'        => '1000 hours',
					'tags'        => array( 'speech', 'audio', 'transcription', 'accessibility', 'podcast' ),
					'use_cases'   => array( 'audio transcription', 'podcast indexing', 'accessibility' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="librispeech_asr", config="clean", split="train.100", limit=5)',
				),
				array(
					'dataset'     => 'mozilla-foundation/common_voice_13_0',
					'name'        => 'Common Voice',
					'category'    => 'audio',
					'priority'    => 'critical',
					'description' => 'Multilingual speech recognition in 100+ languages',
					'size'        => 'Thousands of hours',
					'tags'        => array( 'speech', 'multilingual', 'audio', 'transcription', 'international' ),
					'use_cases'   => array( 'multilingual transcription', 'international sites', 'speech recognition' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="mozilla-foundation/common_voice_13_0", config="en", split="train", limit=5)',
				),

				// Multilingual Datasets.
				array(
					'dataset'     => 'mc4',
					'name'        => 'mC4',
					'category'    => 'nlp',
					'priority'    => 'critical',
					'description' => 'Multilingual corpus in 101 languages',
					'size'        => '6.3TB',
					'tags'        => array( 'multilingual', 'international', 'translation', 'language', 'global' ),
					'use_cases'   => array( 'multilingual content', 'language detection', 'international sites' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="mc4", config="en", split="train", limit=5)',
				),
				array(
					'dataset'     => 'facebook/xnli',
					'name'        => 'XNLI',
					'category'    => 'nlp',
					'priority'    => 'high',
					'description' => 'Cross-lingual inference in 15 languages',
					'size'        => '500K examples',
					'tags'        => array( 'multilingual', 'inference', 'cross-lingual', 'international' ),
					'use_cases'   => array( 'cross-language understanding', 'multilingual AI', 'global content' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="facebook/xnli", config="en", split="train", limit=5)',
				),

				// Safety & Moderation.
				array(
					'dataset'     => 'google/civil_comments',
					'name'        => 'Civil Comments',
					'category'    => 'nlp',
					'priority'    => 'critical',
					'description' => 'Nuanced moderation with 2M comments and toxicity labels',
					'size'        => '2M comments',
					'tags'        => array( 'moderation', 'comment', 'civility', 'community', 'discussion', 'toxic', 'safety', 'filter' ),
					'use_cases'   => array( 'comment quality', 'discussion moderation', 'community management', 'comment moderation', 'content filtering', 'safety checks' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="google/civil_comments", split="train", limit=5)',
				),

				// Domain-Specific.
				array(
					'dataset'     => 'bigbio/med_qa',
					'name'        => 'MedQA',
					'category'    => 'nlp',
					'priority'    => 'high',
					'description' => 'Medical question answering dataset',
					'size'        => '60K+ Q&A pairs',
					'tags'        => array( 'medical', 'health', 'healthcare', 'qa', 'medicine' ),
					'use_cases'   => array( 'medical Q&A', 'health chatbots', 'healthcare content' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="bigbio/med_qa", config="med_qa_en_source", split="train", limit=5)',
				),
				array(
					'dataset'     => 'financial_phrasebank',
					'name'        => 'Financial PhraseBank',
					'category'    => 'nlp',
					'priority'    => 'high',
					'description' => 'Financial sentiment analysis dataset',
					'size'        => '4.8K sentences',
					'tags'        => array( 'finance', 'financial', 'sentiment', 'market', 'business' ),
					'use_cases'   => array( 'financial sentiment', 'market analysis', 'business content' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="financial_phrasebank", config="sentences_allagree", split="train", limit=5)',
				),
				array(
					'dataset'     => 'arxiv_dataset',
					'name'        => 'arXiv',
					'category'    => 'nlp',
					'priority'    => 'high',
					'description' => 'Scientific papers from arXiv',
					'size'        => '1.7M papers',
					'tags'        => array( 'scientific', 'research', 'academic', 'science', 'papers' ),
					'use_cases'   => array( 'research content', 'scientific writing', 'academic sites' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="arxiv_dataset", split="train", limit=5)',
				),
				array(
					'dataset'     => 'allenai/sciq',
					'name'        => 'SciQ',
					'category'    => 'nlp',
					'priority'    => 'high',
					'description' => 'Science question answering dataset',
					'size'        => '13K questions',
					'tags'        => array( 'science', 'education', 'qa', 'learning', 'stem' ),
					'use_cases'   => array( 'educational Q&A', 'science quizzes', 'STEM content' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="allenai/sciq", split="train", limit=5)',
				),
				array(
					'dataset'     => 'ehovy/race',
					'name'        => 'RACE',
					'category'    => 'nlp',
					'priority'    => 'high',
					'description' => 'Reading comprehension from examinations',
					'size'        => '100K questions',
					'tags'        => array( 'education', 'reading', 'comprehension', 'learning', 'quiz' ),
					'use_cases'   => array( 'educational content', 'reading comprehension', 'learning management' ),
					'example'     => 'Get examples: huggingface_dataset_preview_rows(dataset="ehovy/race", config="all", split="train", limit=5)',
				),
			);
		}
	}
}
