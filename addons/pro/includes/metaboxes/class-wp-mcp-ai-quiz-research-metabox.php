<?php
/**
 * Research Metabox for Quiz CPT.
 *
 * Provides AI-powered research for quiz topics before creating quizzes,
 * including suggested questions and answers.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Research metabox for quizzes.
 */
class WP_MCP_AI_Quiz_Research_Metabox extends WP_MCP_AI_Research_Metabox_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'mcp_ai_quiz',
			__( 'AI Quiz Research', 'mcp-ai-wpoos-pro' ),
			'research_quiz_topic'
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_intro_text() {
		return __( 'Research a topic to generate quiz questions and answers. Get AI-suggested content based on comprehensive research.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_search_label() {
		return __( 'Quiz topic:', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_search_placeholder() {
		return __( 'e.g., "World War II History" or "Basic Algebra"', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_field_map() {
		return array(
			'title'       => '#title',
			'description' => '#content',
			'subject'     => '#_quiz_subject',
			'difficulty'  => '#_quiz_difficulty',
			'time_limit'  => '#_quiz_time_limit',
			'pass_score'  => '#_quiz_pass_score',
		);
	}
}
