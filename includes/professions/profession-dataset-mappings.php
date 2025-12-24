<?php
/**
 * Profession Dataset Mappings.
 *
 * Defines recommended HuggingFace datasets for each profession type.
 * Mappings are based on profession expertise areas and typical use cases.
 *
 * @package WP_MCP_AI
 * @since 1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get dataset recommendations for a profession slug.
 *
 * @param string $profession_slug Profession slug.
 * @return array Array of dataset information.
 */
function wp_mcp_ai_get_profession_dataset_recommendations( $profession_slug ) {
	$mappings = wp_mcp_ai_get_all_profession_dataset_mappings();
	
	if ( isset( $mappings[ $profession_slug ] ) ) {
		return $mappings[ $profession_slug ];
	}
	
	return array();
}

/**
 * Get all profession to dataset mappings.
 *
 * @return array Associative array of profession_slug => datasets.
 */
function wp_mcp_ai_get_all_profession_dataset_mappings() {
	return array(
		// DATA SCIENCE & AI PROFESSIONS.
		'data_scientist' => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'detection-datasets/coco',
				'name'     => 'COCO',
				'category' => 'vision',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'stanfordnlp/imdb',
				'name'     => 'IMDB Movie Reviews',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'computer_scientist' => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'research_scientist' => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'EdinburghNLP/xsum',
				'name'     => 'XSum',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'statistician' => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		// HEALTHCARE & MEDICAL PROFESSIONS.
		'healthcare_advisor' => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'medical_researcher' => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'EdinburghNLP/xsum',
				'name'     => 'XSum',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'epidemiologist' => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'public_health_advisor' => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'pharmacist' => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'pharmaceutical_researcher' => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'critical',
			),
		),
		
		// CREATIVE PROFESSIONS.
		'graphic_designer' => array(
			array(
				'dataset'  => 'detection-datasets/coco',
				'name'     => 'COCO',
				'category' => 'vision',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'nlphuji/flickr30k',
				'name'     => 'Flickr30k',
				'category' => 'multimodal',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'yerevann/coco-captions',
				'name'     => 'MS COCO Captions',
				'category' => 'multimodal',
				'priority' => 'high',
			),
		),
		
		'graphic_artist' => array(
			array(
				'dataset'  => 'detection-datasets/coco',
				'name'     => 'COCO',
				'category' => 'vision',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'nlphuji/flickr30k',
				'name'     => 'Flickr30k',
				'category' => 'multimodal',
				'priority' => 'high',
			),
		),
		
		'web_designer' => array(
			array(
				'dataset'  => 'detection-datasets/coco',
				'name'     => 'COCO',
				'category' => 'vision',
				'priority' => 'high',
			),
			array(
				'dataset'  => 'nlphuji/flickr30k',
				'name'     => 'Flickr30k',
				'category' => 'multimodal',
				'priority' => 'high',
			),
		),
		
		'ux_ui_designer' => array(
			array(
				'dataset'  => 'stanfordnlp/imdb',
				'name'     => 'IMDB Movie Reviews',
				'category' => 'nlp',
				'priority' => 'high',
			),
			array(
				'dataset'  => 'yelp_review_full',
				'name'     => 'Yelp Reviews',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'photographer' => array(
			array(
				'dataset'  => 'detection-datasets/coco',
				'name'     => 'COCO',
				'category' => 'vision',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'nlphuji/flickr30k',
				'name'     => 'Flickr30k',
				'category' => 'multimodal',
				'priority' => 'critical',
			),
		),
		
		'video_producer' => array(
			array(
				'dataset'  => 'yerevann/coco-captions',
				'name'     => 'MS COCO Captions',
				'category' => 'multimodal',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'nlphuji/flickr30k',
				'name'     => 'Flickr30k',
				'category' => 'multimodal',
				'priority' => 'high',
			),
		),
		
		'video_editor' => array(
			array(
				'dataset'  => 'yerevann/coco-captions',
				'name'     => 'MS COCO Captions',
				'category' => 'multimodal',
				'priority' => 'high',
			),
		),
		
		'film_director' => array(
			array(
				'dataset'  => 'stanfordnlp/imdb',
				'name'     => 'IMDB Movie Reviews',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'yerevann/coco-captions',
				'name'     => 'MS COCO Captions',
				'category' => 'multimodal',
				'priority' => 'high',
			),
		),
		
		'film_editor' => array(
			array(
				'dataset'  => 'stanfordnlp/imdb',
				'name'     => 'IMDB Movie Reviews',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'cinematographer' => array(
			array(
				'dataset'  => 'detection-datasets/coco',
				'name'     => 'COCO',
				'category' => 'vision',
				'priority' => 'critical',
			),
		),
		
		'sound_designer' => array(
			array(
				'dataset'  => 'librispeech_asr',
				'name'     => 'LibriSpeech',
				'category' => 'audio',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'mozilla-foundation/common_voice_13_0',
				'name'     => 'Common Voice',
				'category' => 'audio',
				'priority' => 'critical',
			),
		),
		
		// CONTENT & WRITING PROFESSIONS.
		'content_creator' => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'EdinburghNLP/xsum',
				'name'     => 'XSum',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'jigsaw_toxicity_pred',
				'name'     => 'Jigsaw Toxic Comments',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'screenwriter' => array(
			array(
				'dataset'  => 'stanfordnlp/imdb',
				'name'     => 'IMDB Movie Reviews',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'medical_writer' => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'EdinburghNLP/xsum',
				'name'     => 'XSum',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		// MARKETING & BUSINESS PROFESSIONS.
		'marketing_consultant' => array(
			array(
				'dataset'  => 'stanfordnlp/imdb',
				'name'     => 'IMDB Movie Reviews',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'yelp_review_full',
				'name'     => 'Yelp Reviews',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'ag_news',
				'name'     => 'AG News',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'business_consultant' => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
			array(
				'dataset'  => 'yelp_review_full',
				'name'     => 'Yelp Reviews',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		// LEGAL & ADVISORY PROFESSIONS.
		'lawyer' => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'legal_advisor' => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'critical',
			),
		),
		
		// FINANCIAL PROFESSIONS.
		'accountant' => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'tax_advisor' => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'financial_advisor' => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		// E-COMMERCE & RETAIL.
		'restaurant_consultant' => array(
			array(
				'dataset'  => 'yelp_review_full',
				'name'     => 'Yelp Reviews',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'ethz/food101',
				'name'     => 'Food-101',
				'category' => 'vision',
				'priority' => 'critical',
			),
		),
		
		// COMMUNITY MANAGEMENT.
		'hr_consultant' => array(
			array(
				'dataset'  => 'jigsaw_toxicity_pred',
				'name'     => 'Jigsaw Toxic Comments',
				'category' => 'nlp',
				'priority' => 'high',
			),
			array(
				'dataset'  => 'google/civil_comments',
				'name'     => 'Civil Comments',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		// EMERGENCY & CRISIS.
		'crisis_communications_manager' => array(
			array(
				'dataset'  => 'ag_news',
				'name'     => 'AG News',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		// ENVIRONMENTAL & SCIENCE.
		'marine_biologist' => array(
			array(
				'dataset'  => 'detection-datasets/coco',
				'name'     => 'COCO',
				'category' => 'vision',
				'priority' => 'high',
			),
		),
		
		'oceanographer' => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'environmental_scientist' => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		// ENGINEERING PROFESSIONS.
		'software_engineer' => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
		
		'it_consultant' => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
	);
}
