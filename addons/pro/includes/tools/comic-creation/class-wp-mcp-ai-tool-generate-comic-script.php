<?php
/**
 * Tool that generates a full comic script using AI.
 *
 * Takes a premise, genre, panel count, and style to produce a structured
 * JSON comic script with scene breakdowns, character dialogue, and panel
 * descriptions. Creates a `mcp_ai_comic_script` custom post to store the
 * generated script for downstream tools (panel breakdown, character sheets).
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a Pro tool for generating a comic script with AI.
 */
class WP_MCP_AI_Tool_Generate_Comic_Script implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_comic_script';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Comic Script', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates a full comic script using AI based on a premise, genre, panel count, and art style. Returns structured JSON with scene breakdowns, character dialogue, and panel-by-panel descriptions. Creates a comic script post in WordPress for downstream tools.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'premise'    => array(
					'type'        => 'string',
					'description' => __( 'The story premise or core idea for the comic (e.g., "A detective in a cyberpunk city discovers a conspiracy").', 'mcp-ai-wpoos-pro' ),
				),
				'genre'      => array(
					'type'        => 'string',
					'description' => __( 'The genre of the comic.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'superhero', 'sci-fi', 'fantasy', 'horror', 'mystery', 'romance', 'slice-of-life', 'action', 'comedy', 'noir', 'western', 'historical' ),
					'default'     => 'sci-fi',
				),
				'panel_count' => array(
					'type'        => 'integer',
					'description' => __( 'Number of panels to generate (4-60).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 4,
					'maximum'     => 60,
					'default'     => 12,
				),
				'style'      => array(
					'type'        => 'string',
					'description' => __( 'Art style description for the comic.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'modern digital comic',
				),
				'title'      => array(
					'type'        => 'string',
					'description' => __( 'Optional title for the comic script.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'premise' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'comic_creation',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'artist', 'content_manager', 'writer' ),
			'risk_level'            => 'standard',
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
			'pro-tool',
			'write',
			'consumes-tokens',
			'external-api',
			'may-timeout',
			'requires-credentials',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error  Canonical success array or WP_Error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Capability check.
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate comic scripts.', 'mcp-ai-wpoos-pro' )
			);
		}

		// --- Sanitize all arguments at entry (Gate 1) ---
		$premise    = isset( $arguments['premise'] ) ? sanitize_textarea_field( $arguments['premise'] ) : '';
		$premise    = trim( $premise );
		$genre      = isset( $arguments['genre'] ) ? sanitize_text_field( $arguments['genre'] ) : 'sci-fi';
		$panel_count = isset( $arguments['panel_count'] ) ? absint( $arguments['panel_count'] ) : 12;
		$style      = isset( $arguments['style'] ) ? sanitize_text_field( $arguments['style'] ) : 'modern digital comic';
		$title      = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';

		// Validate required fields.
		if ( '' === $premise ) {
			return new WP_Error(
				'wp_mcp_ai_missing_premise',
				__( 'A premise is required to generate a comic script.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Clamp panel count.
		if ( $panel_count < 4 ) {
			$panel_count = 4;
		}
		if ( $panel_count > 60 ) {
			$panel_count = 60;
		}

		// Validate genre.
		$allowed_genres = array( 'superhero', 'sci-fi', 'fantasy', 'horror', 'mystery', 'romance', 'slice-of-life', 'action', 'comedy', 'noir', 'western', 'historical' );
		if ( ! in_array( $genre, $allowed_genres, true ) ) {
			$genre = 'sci-fi';
		}

		// Auto-generate title if empty.
		if ( '' === $title ) {
			$title = sprintf(
				/* translators: %1$s: premise excerpt, %2$s: genre */
				__( 'Untitled %2$s Comic: %1$s', 'mcp-ai-wpoos-pro' ),
				wp_trim_words( $premise, 5, '...' ),
				ucfirst( $genre )
			);
		}

		WP_MCP_AI_Logger::log_event(
			'comic_script_generation_started',
			'Starting comic script generation',
			array(
				'premise'     => $premise,
				'genre'       => $genre,
				'panel_count' => $panel_count,
				'style'       => $style,
				'user_id'     => $user_id,
			)
		);

		// TODO: Integrate with actual AI LLM API (OpenAI/Gemini) to generate script.
		// For now, produce a simulated structured script.
		$script_content = $this->generate_simulated_script( $premise, $genre, $panel_count, $style );

		// Create the comic script post.
		$post_data = array(
			'post_type'    => 'mcp_ai_comic_script',
			'post_title'   => wp_strip_all_tags( $title ),
			'post_content' => wp_json_encode( $script_content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
			'post_status'  => 'publish',
			'post_author'  => $user_id,
		);

		$script_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $script_id ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to create comic script post: ' . $script_id->get_error_message(),
				array( 'user_id' => $user_id )
			);
			return new WP_Error(
				'wp_mcp_ai_script_creation_failed',
				__( 'Failed to create the comic script post.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		// Store meta.
		update_post_meta( $script_id, '_comic_genre', $genre );
		update_post_meta( $script_id, '_comic_style', $style );
		update_post_meta( $script_id, '_comic_panel_count', $panel_count );
		update_post_meta( $script_id, '_comic_premise', $premise );

		// --- Escape all output values (Gate 2) ---
		$result = array(
			'success' => true,
			'data'    => array(
				'script_id'      => $script_id,
				'title'          => esc_html( $title ),
				'premise'        => esc_html( $premise ),
				'genre'          => esc_html( $genre ),
				'panel_count'    => $panel_count,
				'style'          => esc_html( $style ),
				'script_content' => $script_content,
				'edit_url'       => esc_url( get_edit_post_link( $script_id, 'raw' ) ),
			),
		);

		WP_MCP_AI_Logger::log_event(
			'comic_script_generated',
			'Comic script generated successfully',
			array(
				'script_id'   => $script_id,
				'panel_count' => $panel_count,
				'user_id'     => $user_id,
			)
		);

		return $result;
	}

	/**
	 * Generate a simulated comic script (placeholder for AI integration).
	 *
	 * TODO: Replace with actual AI LLM API call.
	 *
	 * @param string $premise     Story premise.
	 * @param string $genre       Comic genre.
	 * @param int    $panel_count Desired number of panels.
	 * @param string $style       Art style description.
	 * @return array Structured script content.
	 */
	private function generate_simulated_script( $premise, $genre, $panel_count, $style ) {
		$scenes = array();
		$panels_per_scene = max( 1, (int) ceil( $panel_count / 3 ) );

		for ( $s = 1; $s <= 3; $s++ ) {
			$start_panel = ( ( $s - 1 ) * $panels_per_scene ) + 1;
			$end_panel   = min( $start_panel + $panels_per_scene - 1, $panel_count );

			$scene_panels = array();
			for ( $p = $start_panel; $p <= $end_panel; $p++ ) {
				$scene_panels[] = array(
					'panel_number' => $p,
					'description'  => sprintf(
						/* translators: %1$d: panel number, %2$s: premise excerpt */
						__( 'Panel %1$d: A scene depicting an element of "%2$s" in %3$s style.', 'mcp-ai-wpoos-pro' ),
						$p,
						$premise,
						$genre
					),
					'dialogue'     => array(
						array(
							'character' => __( 'Character A', 'mcp-ai-wpoos-pro' ),
							'text'      => __( '[Dialogue placeholder — to be generated by AI]', 'mcp-ai-wpoos-pro' ),
						),
					),
					'camera_angle' => $this->get_random_camera_angle( $p ),
					'mood'         => ( 1 === $s ) ? 'opening' : ( ( 3 === $s ) ? 'climactic' : 'developing' ),
				);
			}

			$scenes[] = array(
				'scene_number' => $s,
				'scene_name'   => sprintf(
					/* translators: %d: scene number */
					__( 'Scene %d', 'mcp-ai-wpoos-pro' ),
					$s
				),
				'description'  => sprintf(
					/* translators: %1$d: scene number, %2$s: genre */
					__( '%2$s comic scene %1$d based on the premise.', 'mcp-ai-wpoos-pro' ),
					$s,
					ucfirst( $genre )
				),
				'panels'       => $scene_panels,
			);

			if ( $end_panel >= $panel_count ) {
				break;
			}
		}

		return array(
			'title'       => '',
			'premise'     => $premise,
			'genre'       => $genre,
			'art_style'   => $style,
			'total_panels' => $panel_count,
			'scenes'      => $scenes,
			'characters'  => array(
				array(
					'name'        => __( 'Protagonist', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'The main character driving the story forward.', 'mcp-ai-wpoos-pro' ),
					'role'        => 'protagonist',
				),
				array(
					'name'        => __( 'Antagonist', 'mcp-ai-wpoos-pro' ),
					'description' => __( 'The opposing force creating conflict.', 'mcp-ai-wpoos-pro' ),
					'role'        => 'antagonist',
				),
			),
		);
	}

	/**
	 * Pick a camera angle for a panel based on position.
	 *
	 * @param int $panel_number Panel index.
	 * @return string Camera angle description.
	 */
	private function get_random_camera_angle( $panel_number ) {
		$angles = array(
			__( 'wide establishing shot', 'mcp-ai-wpoos-pro' ),
			__( 'medium shot', 'mcp-ai-wpoos-pro' ),
			__( 'close-up', 'mcp-ai-wpoos-pro' ),
			__( 'low angle', 'mcp-ai-wpoos-pro' ),
			__( 'high angle', 'mcp-ai-wpoos-pro' ),
			__( 'over-the-shoulder', 'mcp-ai-wpoos-pro' ),
			__( 'dutch angle', 'mcp-ai-wpoos-pro' ),
			__( 'extreme close-up', 'mcp-ai-wpoos-pro' ),
		);
		return $angles[ $panel_number % count( $angles ) ];
	}
}
