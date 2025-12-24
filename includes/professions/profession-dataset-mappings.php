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
		'data_scientist'                => array(
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

		'computer_scientist'            => array(
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

		'research_scientist'            => array(
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

		'statistician'                  => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		// HEALTHCARE & MEDICAL PROFESSIONS.
		'healthcare_advisor'            => array(
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

		'medical_researcher'            => array(
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

		'epidemiologist'                => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'public_health_advisor'         => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'pharmacist'                    => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'pharmaceutical_researcher'     => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'critical',
			),
		),

		// CREATIVE PROFESSIONS.
		'graphic_designer'              => array(
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

		'graphic_artist'                => array(
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

		'web_designer'                  => array(
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

		'ux_ui_designer'                => array(
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

		'photographer'                  => array(
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

		'video_producer'                => array(
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

		'video_editor'                  => array(
			array(
				'dataset'  => 'yerevann/coco-captions',
				'name'     => 'MS COCO Captions',
				'category' => 'multimodal',
				'priority' => 'high',
			),
		),

		'film_director'                 => array(
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

		'film_editor'                   => array(
			array(
				'dataset'  => 'stanfordnlp/imdb',
				'name'     => 'IMDB Movie Reviews',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'cinematographer'               => array(
			array(
				'dataset'  => 'detection-datasets/coco',
				'name'     => 'COCO',
				'category' => 'vision',
				'priority' => 'critical',
			),
		),

		'sound_designer'                => array(
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
		'content_creator'               => array(
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

		'screenwriter'                  => array(
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

		'medical_writer'                => array(
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
		'marketing_consultant'          => array(
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

		'business_consultant'           => array(
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
		'lawyer'                        => array(
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

		'legal_advisor'                 => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'critical',
			),
		),

		// FINANCIAL PROFESSIONS.
		'accountant'                    => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'tax_advisor'                   => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'financial_advisor'             => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		// E-COMMERCE & RETAIL.
		'restaurant_consultant'         => array(
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
		'hr_consultant'                 => array(
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
		'marine_biologist'              => array(
			array(
				'dataset'  => 'detection-datasets/coco',
				'name'     => 'COCO',
				'category' => 'vision',
				'priority' => 'high',
			),
		),

		'oceanographer'                 => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'environmental_scientist'       => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		// ENGINEERING PROFESSIONS.
		'software_engineer'             => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'it_consultant'                 => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		// FOOD & CULINARY PROFESSIONS.
		'chef'                          => array(
			array(
				'dataset'  => 'ethz/food101',
				'name'     => 'Food-101',
				'category' => 'vision',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'yelp_review_full',
				'name'     => 'Yelp Reviews',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'restaurant_manager'            => array(
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
				'priority' => 'high',
			),
			array(
				'dataset'  => 'jigsaw_toxicity_pred',
				'name'     => 'Jigsaw Toxic Comments',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'bartender'                     => array(
			array(
				'dataset'  => 'yelp_review_full',
				'name'     => 'Yelp Reviews',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		// EDUCATION PROFESSIONS.
		'elementary_school_teacher'     => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'allenai/sciq',
				'name'     => 'SciQ',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'high_school_teacher'           => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'allenai/sciq',
				'name'     => 'SciQ',
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

		'college_professor'             => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'allenai/sciq',
				'name'     => 'SciQ',
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

		'special_education_teacher'     => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'critical',
			),
		),

		'corporate_trainer'             => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'instructional_designer'        => array(
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

		'esl_teacher'                   => array(
			array(
				'dataset'  => 'mc4',
				'name'     => 'mC4',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		// IGCSE TUTORS.
		'igcse_biology_tutor'           => array(
			array(
				'dataset'  => 'allenai/sciq',
				'name'     => 'SciQ',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'igcse_chemistry_tutor'         => array(
			array(
				'dataset'  => 'allenai/sciq',
				'name'     => 'SciQ',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'igcse_physics_tutor'           => array(
			array(
				'dataset'  => 'allenai/sciq',
				'name'     => 'SciQ',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'igcse_mathematics_tutor'       => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'allenai/sciq',
				'name'     => 'SciQ',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'igcse_sciences_tutor'          => array(
			array(
				'dataset'  => 'allenai/sciq',
				'name'     => 'SciQ',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'igcse_english_tutor'           => array(
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

		'igcse_computer_science_tutor'  => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'critical',
			),
		),

		// JOURNALISM & WRITING.
		'journalist'                    => array(
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
				'dataset'  => 'ag_news',
				'name'     => 'AG News',
				'category' => 'nlp',
				'priority' => 'critical',
			),
		),

		'writer'                        => array(
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
			array(
				'dataset'  => 'stanfordnlp/imdb',
				'name'     => 'IMDB Movie Reviews',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'social_media_manager'          => array(
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
				'dataset'  => 'jigsaw_toxicity_pred',
				'name'     => 'Jigsaw Toxic Comments',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'google/civil_comments',
				'name'     => 'Civil Comments',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'pr_specialist'                 => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'ag_news',
				'name'     => 'AG News',
				'category' => 'nlp',
				'priority' => 'high',
			),
			array(
				'dataset'  => 'stanfordnlp/imdb',
				'name'     => 'IMDB Movie Reviews',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		// ADDITIONAL CREATIVE PROFESSIONS.
		'actor'                         => array(
			array(
				'dataset'  => 'stanfordnlp/imdb',
				'name'     => 'IMDB Movie Reviews',
				'category' => 'nlp',
				'priority' => 'critical',
			),
		),

		'animator'                      => array(
			array(
				'dataset'  => 'detection-datasets/coco',
				'name'     => 'COCO',
				'category' => 'vision',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'yerevann/coco-captions',
				'name'     => 'MS COCO Captions',
				'category' => 'multimodal',
				'priority' => 'high',
			),
		),

		'game_designer'                 => array(
			array(
				'dataset'  => 'detection-datasets/coco',
				'name'     => 'COCO',
				'category' => 'vision',
				'priority' => 'high',
			),
			array(
				'dataset'  => 'stanfordnlp/imdb',
				'name'     => 'IMDB Movie Reviews',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'musician'                      => array(
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
				'priority' => 'high',
			),
		),

		'interior_designer'             => array(
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

		'landscape_architect'           => array(
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

		// ADDITIONAL HEALTHCARE PROFESSIONS.
		'physician'                     => array(
			array(
				'dataset'  => 'bigbio/med_qa',
				'name'     => 'MedQA',
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

		'nurse_practitioner'            => array(
			array(
				'dataset'  => 'bigbio/med_qa',
				'name'     => 'MedQA',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'registered_nurse'              => array(
			array(
				'dataset'  => 'bigbio/med_qa',
				'name'     => 'MedQA',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'dentist'                       => array(
			array(
				'dataset'  => 'bigbio/med_qa',
				'name'     => 'MedQA',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'psychologist'                  => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
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

		// BUSINESS & FINANCE.
		'entrepreneur'                  => array(
			array(
				'dataset'  => 'yelp_review_full',
				'name'     => 'Yelp Reviews',
				'category' => 'nlp',
				'priority' => 'high',
			),
			array(
				'dataset'  => 'financial_phrasebank',
				'name'     => 'Financial PhraseBank',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'sales_manager'                 => array(
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
				'priority' => 'critical',
			),
		),

		'project_manager'               => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'economist'                     => array(
			array(
				'dataset'  => 'financial_phrasebank',
				'name'     => 'Financial PhraseBank',
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

		'retail_manager'                => array(
			array(
				'dataset'  => 'yelp_review_full',
				'name'     => 'Yelp Reviews',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'zalando-datasets/fashion_mnist',
				'name'     => 'Fashion MNIST',
				'category' => 'vision',
				'priority' => 'high',
			),
		),

		// TECHNICAL PROFESSIONS.
		'biologist'                     => array(
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'high',
			),
			array(
				'dataset'  => 'allenai/sciq',
				'name'     => 'SciQ',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'chemist'                       => array(
			array(
				'dataset'  => 'allenai/sciq',
				'name'     => 'SciQ',
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

		'physicist'                     => array(
			array(
				'dataset'  => 'allenai/sciq',
				'name'     => 'SciQ',
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

		'mathematician'                 => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
			array(
				'dataset'  => 'allenai/sciq',
				'name'     => 'SciQ',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'software_developer'            => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'web_developer'                 => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		'cybersecurity_specialist'      => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'high',
			),
			array(
				'dataset'  => 'jigsaw_toxicity_pred',
				'name'     => 'Jigsaw Toxic Comments',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),

		// MULTILINGUAL PROFESSIONS.
		'interpreter_translator'        => array(
			array(
				'dataset'  => 'mc4',
				'name'     => 'mC4',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'mozilla-foundation/common_voice_13_0',
				'name'     => 'Common Voice',
				'category' => 'audio',
				'priority' => 'critical',
			),
		),

		// LEGAL PROFESSIONS.
		'paralegal'                     => array(
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

		'judge'                         => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'critical',
			),
		),

		// COMMUNITY & SOCIAL SERVICES.
		'social_worker'                 => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
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

		'librarian'                     => array(
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

		'customer_service_rep'          => array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'yelp_review_full',
				'name'     => 'Yelp Reviews',
				'category' => 'nlp',
				'priority' => 'high',
			),
			array(
				'dataset'  => 'jigsaw_toxicity_pred',
				'name'     => 'Jigsaw Toxic Comments',
				'category' => 'nlp',
				'priority' => 'high',
			),
		),
	);
}
