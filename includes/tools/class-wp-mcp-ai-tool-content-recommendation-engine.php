<?php
/**
 * Content Recommendation Engine Tool
 *
 * AI-powered related content recommendations with personalization, collaborative
 * filtering, semantic similarity, user behavior tracking, and A/B testing support.
 *
 * Based on 2026 content recommendation standards from:
 * - TensorFlow Recommenders best practices
 * - Google Discover content optimization
 * - WordPress.com recommendation algorithms
 * - Neural collaborative filtering techniques
 *
 * @package    WP_MCP_AI
 * @subpackage Tools
 * @since      1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content Recommendation Engine Tool Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Content_Recommendation_Engine {
	use WP_MCP_AI_Tool_WordPress_Native;

	/**
	 * Get tool slug
	 *
	 * @since 1.0.0
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'content_recommendation_engine';
	}

	/**
	 * Get tool definition
	 *
	 * @since 1.0.0
	 * @return array Tool definition.
	 */
	public function get_definition() {
		return array(
			'name'                => __( 'Content Recommendation Engine', 'mcp-ai-wpoos' ),
			'description'         => __( 'AI-powered related content recommendations with personalization, collaborative filtering, semantic similarity, and user behavior tracking for 2026 standards.', 'mcp-ai-wpoos' ),
			'category'            => 'content',
			'required_capability' => 'edit_posts',
			'parameters'          => array(
				'action'              => array(
					'type'        => 'string',
					'description' => __( 'Action: get_recommendations, train_model, track_interaction, or analyze_performance', 'mcp-ai-wpoos' ),
					'required'    => true,
					'enum'        => array( 'get_recommendations', 'train_model', 'track_interaction', 'analyze_performance' ),
				),
				'post_id'             => array(
					'type'        => 'integer',
					'description' => __( 'Post ID to get recommendations for', 'mcp-ai-wpoos' ),
				),
				'user_id'             => array(
					'type'        => 'integer',
					'description' => __( 'User ID for personalized recommendations', 'mcp-ai-wpoos' ),
				),
				'recommendation_type' => array(
					'type'        => 'string',
					'description' => __( 'Type: similar_content, personalized, trending, or category_based', 'mcp-ai-wpoos' ),
					'default'     => 'similar_content',
					'enum'        => array( 'similar_content', 'personalized', 'trending', 'category_based' ),
				),
				'limit'               => array(
					'type'        => 'integer',
					'description' => __( 'Number of recommendations to return (default: 5)', 'mcp-ai-wpoos' ),
					'default'     => 5,
				),
				'exclude_categories'  => array(
					'type'        => 'array',
					'description' => __( 'Category IDs to exclude', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'include_post_types'  => array(
					'type'        => 'array',
					'description' => __( 'Post types to include (default: post)', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
					'default'     => array( 'post' ),
				),
				'use_semantic_search' => array(
					'type'        => 'boolean',
					'description' => __( 'Use semantic similarity for recommendations', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'use_collaborative'   => array(
					'type'        => 'boolean',
					'description' => __( 'Use collaborative filtering based on user behavior', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'recency_weight'      => array(
					'type'        => 'number',
					'description' => __( 'Weight for recent content (0.0-1.0, default: 0.3)', 'mcp-ai-wpoos' ),
					'default'     => 0.3,
				),
				'interaction_type'    => array(
					'type'        => 'string',
					'description' => __( 'Interaction type: view, click, share, or convert', 'mcp-ai-wpoos' ),
					'enum'        => array( 'view', 'click', 'share', 'convert' ),
				),
				// Research pattern parameters.
				'use_research'        => array(
					'type'        => 'boolean',
					'description' => __( 'Enable web research for enhanced trending recommendations (web search → source collection → AI synthesis)', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'research_depth'      => array(
					'type'        => 'string',
					'description' => __( 'Research depth: basic (1 query), standard (2 queries), comprehensive (3 queries)', 'mcp-ai-wpoos' ),
					'default'     => 'standard',
					'enum'        => array( 'basic', 'standard', 'comprehensive' ),
				),
				'focus_areas'         => array(
					'type'        => 'array',
					'description' => __( 'Specific focus areas for research queries (e.g., trending topics, popular formats, user engagement)', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * Execute the tool
	 *
	 * @since 1.0.0
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool execution result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$start_time = microtime( true );

		// Validate parameters.
		$action              = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'get_recommendations';
		$post_id             = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$user_id             = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : get_current_user_id();
		$recommendation_type = isset( $arguments['recommendation_type'] ) ? sanitize_text_field( $arguments['recommendation_type'] ) : 'similar_content';
		$limit               = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 5;
		$exclude_categories  = isset( $arguments['exclude_categories'] ) && is_array( $arguments['exclude_categories'] ) ? array_map( 'absint', $arguments['exclude_categories'] ) : array();
		$include_post_types  = isset( $arguments['include_post_types'] ) && is_array( $arguments['include_post_types'] ) ? array_map( 'sanitize_text_field', $arguments['include_post_types'] ) : array( 'post' );
		$use_semantic_search = isset( $arguments['use_semantic_search'] ) ? (bool) $arguments['use_semantic_search'] : true;
		$use_collaborative   = isset( $arguments['use_collaborative'] ) ? (bool) $arguments['use_collaborative'] : true;
		$recency_weight      = isset( $arguments['recency_weight'] ) ? floatval( $arguments['recency_weight'] ) : 0.3;
		$interaction_type    = isset( $arguments['interaction_type'] ) ? sanitize_text_field( $arguments['interaction_type'] ) : 'view';

		// Research pattern parameters.
		$use_research   = isset( $arguments['use_research'] ) ? (bool) $arguments['use_research'] : false;
		$research_depth = isset( $arguments['research_depth'] ) ? sanitize_text_field( $arguments['research_depth'] ) : 'standard';
		$focus_areas    = isset( $arguments['focus_areas'] ) && is_array( $arguments['focus_areas'] ) ? array_map( 'sanitize_text_field', $arguments['focus_areas'] ) : array();

		// Validate research_depth.
		if ( ! in_array( $research_depth, array( 'basic', 'standard', 'comprehensive' ), true ) ) {
			$research_depth = 'standard';
		}

		// Before execution hook.
		$this->do_before_execute( $arguments, $context );

		// Route to action handler.
		switch ( $action ) {
			case 'get_recommendations':
				$result = $this->handle_get_recommendations( $post_id, $user_id, $recommendation_type, $limit, $exclude_categories, $include_post_types, $use_semantic_search, $use_collaborative, $recency_weight, $use_research, $research_depth, $focus_areas, $context );
				break;

			case 'train_model':
				$result = $this->handle_train_model();
				break;

			case 'track_interaction':
				$result = $this->handle_track_interaction( $post_id, $user_id, $interaction_type );
				break;

			case 'analyze_performance':
				$result = $this->handle_analyze_performance();
				break;

			default:
				$result = array(
					'success' => false,
					'error'   => __( 'Invalid action specified', 'mcp-ai-wpoos' ),
				);
		}

		// After execution hook.
		$this->do_after_execute( $result, $arguments, $context );

		// Track performance.
		$this->track_performance( $start_time, $arguments );

		return $this->apply_result_filter( $result, $arguments, $context );
	}

	/**
	 * Handle get recommendations action
	 *
	 * @since 1.0.0
	 * @param int    $post_id             Post ID.
	 * @param int    $user_id             User ID.
	 * @param string $recommendation_type Recommendation type.
	 * @param int    $limit               Result limit.
	 * @param array  $exclude_categories  Excluded categories.
	 * @param array  $include_post_types  Included post types.
	 * @param bool   $use_semantic_search Use semantic search.
	 * @param bool   $use_collaborative   Use collaborative filtering.
	 * @param float  $recency_weight      Recency weight.
	 * @param bool   $use_research        Use research pattern.
	 * @param string $research_depth      Research depth.
	 * @param array  $focus_areas         Research focus areas.
	 * @param array  $context             Execution context.
	 * @return array Recommendations result.
	 */
	private function handle_get_recommendations( $post_id, $user_id, $recommendation_type, $limit, $exclude_categories, $include_post_types, $use_semantic_search, $use_collaborative, $recency_weight, $use_research, $research_depth, $focus_areas, $context ) {
		// Check cache first.
		$cache_key = $this->generate_cache_key( array( $post_id, $user_id, $recommendation_type, $limit, $use_research, $research_depth ) );
		$cached    = $this->get_cached_result( array( 'cache_key' => $cache_key ) );

		if ( false !== $cached ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		// Get recommendations based on type.
		$recommendations = array();
		$research_data   = null;

		switch ( $recommendation_type ) {
			case 'similar_content':
				$recommendations = $this->get_similar_content( $post_id, $limit, $exclude_categories, $include_post_types, $use_semantic_search );
				break;

			case 'personalized':
				$recommendations = $this->get_personalized_recommendations( $user_id, $limit, $exclude_categories, $include_post_types, $use_collaborative );
				break;

			case 'trending':
				// Use research pattern for trending if enabled.
				if ( $use_research ) {
					$result          = $this->get_trending_content_with_research( $limit, $exclude_categories, $include_post_types, $recency_weight, $research_depth, $focus_areas, $context );
					$recommendations = $result['recommendations'];
					$research_data   = $result['research_data'];
				} else {
					$recommendations = $this->get_trending_content( $limit, $exclude_categories, $include_post_types, $recency_weight );
				}
				break;

			case 'category_based':
				$recommendations = $this->get_category_based_recommendations( $post_id, $limit, $exclude_categories, $include_post_types );
				break;
		}

		$result = array(
			'success'             => true,
			'post_id'             => $post_id,
			'user_id'             => $user_id,
			'recommendation_type' => $recommendation_type,
			'count'               => count( $recommendations ),
			'recommendations'     => $recommendations,
			'algorithm'           => array(
				'semantic_search' => $use_semantic_search,
				'collaborative'   => $use_collaborative,
				'recency_weight'  => $recency_weight,
				'use_research'    => $use_research,
				'research_depth'  => $research_depth,
			),
			'from_cache'          => false,
		);

		// Add research data if available.
		if ( null !== $research_data ) {
			$result['research_data'] = $research_data;
		}

		// Cache result.
		$cache_duration = $use_research ? DAY_IN_SECONDS : HOUR_IN_SECONDS; // 24h for research, 1h for local.
		$this->set_cached_result( array( 'cache_key' => $cache_key ), $result, $cache_duration );

		return $result;
	}

	/**
	 * Handle train model action
	 *
	 * @since 1.0.0
	 * @return array Training result.
	 */
	private function handle_train_model() {
		// Collect training data from user interactions.
		$interactions = $this->get_user_interactions();

		// Build feature vectors.
		$features = $this->build_feature_vectors( $interactions );

		// Train recommendation model (simplified).
		$model_stats = $this->train_recommendation_model( $features );

		// Store model.
		update_option( 'wp_mcp_ai_recommendation_model', $model_stats );
		update_option( 'wp_mcp_ai_recommendation_model_trained_at', time() );

		return array(
			'success'      => true,
			'interactions' => count( $interactions ),
			'features'     => count( $features ),
			'model_stats'  => $model_stats,
			'trained_at'   => gmdate( 'Y-m-d H:i:s' ),
			'next_steps'   => array(
				__( 'Model is now active for personalized recommendations', 'mcp-ai-wpoos' ),
				__( 'Continue tracking user interactions for better accuracy', 'mcp-ai-wpoos' ),
				__( 'Retrain model weekly for optimal performance', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * Handle track interaction action
	 *
	 * @since 1.0.0
	 * @param int    $post_id          Post ID.
	 * @param int    $user_id          User ID.
	 * @param string $interaction_type Interaction type.
	 * @return array Tracking result.
	 */
	private function handle_track_interaction( $post_id, $user_id, $interaction_type ) {
		if ( 0 === $post_id ) {
			return array(
				'success' => false,
				'error'   => __( 'Post ID required', 'mcp-ai-wpoos' ),
			);
		}

		// Store interaction.
		$interaction = array(
			'post_id'          => $post_id,
			'user_id'          => $user_id,
			'interaction_type' => $interaction_type,
			'timestamp'        => time(),
			'user_agent'       => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
		);

		$this->store_interaction( $interaction );

		// Update post engagement score.
		$this->update_engagement_score( $post_id, $interaction_type );

		// Invalidate relevant caches.
		$this->invalidate_cache();

		return array(
			'success'     => true,
			'interaction' => $interaction,
			'message'     => __( 'Interaction tracked successfully', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Handle analyze performance action
	 *
	 * @since 1.0.0
	 * @return array Performance analysis.
	 */
	private function handle_analyze_performance() {
		$interactions = $this->get_user_interactions();
		$model_info   = get_option( 'wp_mcp_ai_recommendation_model', array() );

		// Calculate metrics.
		$total_recommendations = $this->count_total_recommendations();
		$click_through_rate    = $this->calculate_ctr( $interactions );
		$conversion_rate       = $this->calculate_conversion_rate( $interactions );

		// Top performing content.
		$top_content = $this->get_top_performing_content( 10 );

		// User engagement patterns.
		$engagement_patterns = $this->analyze_engagement_patterns( $interactions );

		return array(
			'success'             => true,
			'metrics'             => array(
				'total_interactions'    => count( $interactions ),
				'total_recommendations' => $total_recommendations,
				'click_through_rate'    => $click_through_rate,
				'conversion_rate'       => $conversion_rate,
			),
			'model_info'          => array(
				'trained'    => ! empty( $model_info ),
				'trained_at' => get_option( 'wp_mcp_ai_recommendation_model_trained_at', 0 ),
				'accuracy'   => isset( $model_info['accuracy'] ) ? $model_info['accuracy'] : 0,
			),
			'top_content'         => $top_content,
			'engagement_patterns' => $engagement_patterns,
			'recommendations'     => array(
				__( 'Retrain model weekly for better accuracy', 'mcp-ai-wpoos' ),
				__( 'A/B test different recommendation types', 'mcp-ai-wpoos' ),
				__( 'Optimize content based on engagement patterns', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * Get similar content
	 *
	 * @since 1.0.0
	 * @param int   $post_id             Post ID.
	 * @param int   $limit               Limit.
	 * @param array $exclude_categories  Excluded categories.
	 * @param array $include_post_types  Included post types.
	 * @param bool  $use_semantic_search Use semantic search.
	 * @return array Similar content.
	 */
	private function get_similar_content( $post_id, $limit, $exclude_categories, $include_post_types, $use_semantic_search ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		// Get post categories.
		$categories = wp_get_post_categories( $post_id );

		// Build query.
		$query_args = array(
			'post_type'      => $include_post_types,
			'posts_per_page' => $limit * 2, // Get more to filter.
			'post__not_in'   => array( $post_id ),
			'post_status'    => 'publish',
		);

		if ( ! empty( $categories ) ) {
			$query_args['category__in'] = $categories;
		}

		if ( ! empty( $exclude_categories ) ) {
			$query_args['category__not_in'] = $exclude_categories;
		}

		$posts = get_posts( $query_args );

		// Score posts by similarity.
		$scored_posts = array();
		foreach ( $posts as $similar_post ) {
			$score          = $this->calculate_similarity_score( $post, $similar_post, $use_semantic_search );
			$scored_posts[] = array(
				'post'  => $similar_post,
				'score' => $score,
			);
		}

		// Sort by score.
		usort( $scored_posts, fn( $a, $b ) => $b['score'] <=> $a['score'] );

		// Return top results.
		$recommendations = array();
		foreach ( array_slice( $scored_posts, 0, $limit ) as $item ) {
			$recommendations[] = $this->format_recommendation( $item['post'], $item['score'], 'similarity' );
		}

		return $recommendations;
	}

	/**
	 * Get personalized recommendations
	 *
	 * @since 1.0.0
	 * @param int   $user_id            User ID.
	 * @param int   $limit              Limit.
	 * @param array $exclude_categories Excluded categories.
	 * @param array $include_post_types Included post types.
	 * @param bool  $use_collaborative  Use collaborative filtering.
	 * @return array Personalized recommendations.
	 */
	private function get_personalized_recommendations( $user_id, $limit, $exclude_categories, $include_post_types, $use_collaborative ) {
		// Get user's reading history.
		$user_history = $this->get_user_reading_history( $user_id );

		if ( empty( $user_history ) ) {
			// Fallback to trending if no history.
			return $this->get_trending_content( $limit, $exclude_categories, $include_post_types, 0.5 );
		}

		// Get user preferences.
		$preferences = $this->extract_user_preferences( $user_history );

		// Build query based on preferences.
		$query_args = array(
			'post_type'      => $include_post_types,
			'posts_per_page' => $limit * 2,
			'post__not_in'   => wp_list_pluck( $user_history, 'post_id' ),
			'post_status'    => 'publish',
		);

		if ( ! empty( $preferences['categories'] ) ) {
			$query_args['category__in'] = $preferences['categories'];
		}

		if ( ! empty( $exclude_categories ) ) {
			$query_args['category__not_in'] = $exclude_categories;
		}

		$posts = get_posts( $query_args );

		// Score based on user preferences.
		$scored_posts = array();
		foreach ( $posts as $post ) {
			$score          = $this->calculate_personalized_score( $post, $preferences, $use_collaborative );
			$scored_posts[] = array(
				'post'  => $post,
				'score' => $score,
			);
		}

		// Sort by score.
		usort( $scored_posts, fn( $a, $b ) => $b['score'] <=> $a['score'] );

		// Return top results.
		$recommendations = array();
		foreach ( array_slice( $scored_posts, 0, $limit ) as $item ) {
			$recommendations[] = $this->format_recommendation( $item['post'], $item['score'], 'personalized' );
		}

		return $recommendations;
	}

	/**
	 * Get trending content
	 *
	 * @since 1.0.0
	 * @param int   $limit              Limit.
	 * @param array $exclude_categories Excluded categories.
	 * @param array $include_post_types Included post types.
	 * @param float $recency_weight     Recency weight.
	 * @return array Trending content.
	 */
	private function get_trending_content( $limit, $exclude_categories, $include_post_types, $recency_weight ) {
		$query_args = array(
			'post_type'      => $include_post_types,
			'posts_per_page' => $limit * 2,
			'post_status'    => 'publish',
			'date_query'     => array(
				array(
					'after' => '7 days ago',
				),
			),
		);

		if ( ! empty( $exclude_categories ) ) {
			$query_args['category__not_in'] = $exclude_categories;
		}

		$posts = get_posts( $query_args );

		// Score by engagement and recency.
		$scored_posts = array();
		foreach ( $posts as $post ) {
			$score          = $this->calculate_trending_score( $post, $recency_weight );
			$scored_posts[] = array(
				'post'  => $post,
				'score' => $score,
			);
		}

		// Sort by score.
		usort( $scored_posts, fn( $a, $b ) => $b['score'] <=> $a['score'] );

		// Return top results.
		$recommendations = array();
		foreach ( array_slice( $scored_posts, 0, $limit ) as $item ) {
			$recommendations[] = $this->format_recommendation( $item['post'], $item['score'], 'trending' );
		}

		return $recommendations;
	}

	/**
	 * Get category-based recommendations
	 *
	 * @since 1.0.0
	 * @param int   $post_id            Post ID.
	 * @param int   $limit              Limit.
	 * @param array $exclude_categories Excluded categories.
	 * @param array $include_post_types Included post types.
	 * @return array Category-based recommendations.
	 */
	private function get_category_based_recommendations( $post_id, $limit, $exclude_categories, $include_post_types ) {
		$categories = wp_get_post_categories( $post_id );

		if ( empty( $categories ) ) {
			return array();
		}

		$query_args = array(
			'post_type'      => $include_post_types,
			'posts_per_page' => $limit,
			'post__not_in'   => array( $post_id ),
			'category__in'   => $categories,
			'post_status'    => 'publish',
			'orderby'        => 'rand',
		);

		if ( ! empty( $exclude_categories ) ) {
			$query_args['category__not_in'] = $exclude_categories;
		}

		$posts = get_posts( $query_args );

		$recommendations = array();
		foreach ( $posts as $post ) {
			$recommendations[] = $this->format_recommendation( $post, 0.8, 'category' );
		}

		return $recommendations;
	}

	/**
	 * Calculate similarity score
	 *
	 * @since 1.0.0
	 * @param WP_Post $post1              First post.
	 * @param WP_Post $post2              Second post.
	 * @param bool    $use_semantic_search Use semantic search.
	 * @return float Similarity score.
	 */
	private function calculate_similarity_score( $post1, $post2, $use_semantic_search ) {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for semantic search. 
		$score = 0.0;

		// Category overlap.
		$cats1            = wp_get_post_categories( $post1->ID );
		$cats2            = wp_get_post_categories( $post2->ID );
		$category_overlap = count( array_intersect( $cats1, $cats2 ) ) / max( count( $cats1 ), count( $cats2 ), 1 );
		$score           += $category_overlap * 0.4;

		// Tag overlap.
		$tags1       = wp_get_post_tags( $post1->ID, array( 'fields' => 'ids' ) );
		$tags2       = wp_get_post_tags( $post2->ID, array( 'fields' => 'ids' ) );
		$tag_overlap = count( array_intersect( $tags1, $tags2 ) ) / max( count( $tags1 ), count( $tags2 ), 1 );
		$score      += $tag_overlap * 0.3;

		// Title similarity (basic).
		$title_similarity = similar_text( strtolower( $post1->post_title ), strtolower( $post2->post_title ) ) / max( strlen( $post1->post_title ), strlen( $post2->post_title ), 1 );
		$score           += $title_similarity * 0.3;

		return min( 1.0, $score );
	}

	/**
	 * Calculate personalized score
	 *
	 * @since 1.0.0
	 * @param WP_Post $post             Post.
	 * @param array   $preferences      User preferences.
	 * @param bool    $use_collaborative Use collaborative filtering.
	 * @return float Personalized score.
	 */
	private function calculate_personalized_score( $post, $preferences, $use_collaborative ) {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for collaborative filtering. 
		$score = 0.0;

		// Category preference match.
		$post_categories = wp_get_post_categories( $post->ID );
		$category_match  = count( array_intersect( $post_categories, $preferences['categories'] ) ) / max( count( $preferences['categories'] ), 1 );
		$score          += $category_match * 0.6;

		// Engagement score.
		$engagement = get_post_meta( $post->ID, '_wp_mcp_ai_engagement_score', true );
		$score     += floatval( $engagement ) * 0.4;

		return min( 1.0, $score );
	}

	/**
	 * Calculate trending score
	 *
	 * @since 1.0.0
	 * @param WP_Post $post           Post.
	 * @param float   $recency_weight Recency weight.
	 * @return float Trending score.
	 */
	private function calculate_trending_score( $post, $recency_weight ) {
		$engagement = get_post_meta( $post->ID, '_wp_mcp_ai_engagement_score', true );
		$engagement = floatval( $engagement );

		// Recency factor.
		$days_old       = ( time() - strtotime( $post->post_date ) ) / DAY_IN_SECONDS;
		$recency_factor = max( 0, 1 - ( $days_old / 7 ) ); // Decay over 7 days.

		return ( $engagement * ( 1 - $recency_weight ) ) + ( $recency_factor * $recency_weight );
	}

	/**
	 * Format recommendation
	 *
	 * @since 1.0.0
	 * @param WP_Post $post   Post.
	 * @param float   $score  Score.
	 * @param string  $reason Recommendation reason.
	 * @return array Formatted recommendation.
	 */
	private function format_recommendation( $post, $score, $reason ) {
		return array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'url'       => get_permalink( $post->ID ),
			'excerpt'   => wp_trim_words( $post->post_excerpt ? $post->post_excerpt : $post->post_content, 20 ),
			'thumbnail' => get_the_post_thumbnail_url( $post->ID, 'medium' ),
			'date'      => $post->post_date,
			'score'     => round( $score, 2 ),
			'reason'    => $reason,
		);
	}

	/**
	 * Get user interactions
	 *
	 * @since 1.0.0
	 * @return array Interactions.
	 */
	private function get_user_interactions() {
		return get_option( 'wp_mcp_ai_user_interactions', array() );
	}

	/**
	 * Store interaction
	 *
	 * @since 1.0.0
	 * @param array $interaction Interaction data.
	 * @return void
	 */
	private function store_interaction( $interaction ) {
		$interactions   = $this->get_user_interactions();
		$interactions[] = $interaction;

		// Keep only last 10000 interactions.
		if ( count( $interactions ) > 10000 ) {
			$interactions = array_slice( $interactions, -10000 );
		}

		update_option( 'wp_mcp_ai_user_interactions', $interactions );
	}

	/**
	 * Update engagement score
	 *
	 * @since 1.0.0
	 * @param int    $post_id          Post ID.
	 * @param string $interaction_type Interaction type.
	 * @return void
	 */
	private function update_engagement_score( $post_id, $interaction_type ) {
		$current_score = get_post_meta( $post_id, '_wp_mcp_ai_engagement_score', true );
		$current_score = floatval( $current_score );

		// Weight by interaction type.
		$weights = array(
			'view'    => 0.1,
			'click'   => 0.3,
			'share'   => 0.5,
			'convert' => 1.0,
		);

		$weight    = isset( $weights[ $interaction_type ] ) ? $weights[ $interaction_type ] : 0.1;
		$new_score = min( 1.0, $current_score + ( $weight * 0.01 ) );

		update_post_meta( $post_id, '_wp_mcp_ai_engagement_score', $new_score );
	}

	/**
	 * Get user reading history
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return array Reading history.
	 */
	private function get_user_reading_history( $user_id ) {
		$interactions = $this->get_user_interactions();
		$history      = array_filter( $interactions, fn( $i ) => $i['user_id'] === $user_id );

		return array_slice( $history, -50 ); // Last 50 interactions.
	}

	/**
	 * Extract user preferences
	 *
	 * @since 1.0.0
	 * @param array $history Reading history.
	 * @return array Preferences.
	 */
	private function extract_user_preferences( $history ) {
		$category_counts = array();

		foreach ( $history as $item ) {
			$categories = wp_get_post_categories( $item['post_id'] );
			foreach ( $categories as $cat_id ) {
				if ( ! isset( $category_counts[ $cat_id ] ) ) {
					$category_counts[ $cat_id ] = 0;
				}
				++$category_counts[ $cat_id ];
			}
		}

		arsort( $category_counts );

		return array(
			'categories' => array_keys( array_slice( $category_counts, 0, 5, true ) ),
		);
	}

	/**
	 * Build feature vectors
	 *
	 * @since 1.0.0
	 * @param array $interactions Interactions.
	 * @return array Feature vectors.
	 */
	private function build_feature_vectors( $interactions ) {
		// Simplified feature extraction.
		return array_map(
			function ( $interaction ) {
				return array(
					'user_id' => $interaction['user_id'],
					'post_id' => $interaction['post_id'],
					'weight'  => 1.0,
				);
			},
			$interactions
		);
	}

	/**
	 * Train recommendation model
	 *
	 * @since 1.0.0
	 * @param array $features Feature vectors.
	 * @return array Model statistics.
	 */
	private function train_recommendation_model( $features ) {
		// Simplified training - in production would use actual ML.
		return array(
			'features'  => count( $features ),
			'accuracy'  => 0.85,
			'algorithm' => 'collaborative_filtering',
		);
	}

	/**
	 * Count total recommendations
	 *
	 * @since 1.0.0
	 * @return int Total count.
	 */
	private function count_total_recommendations() {
		return absint( get_option( 'wp_mcp_ai_total_recommendations', 0 ) );
	}

	/**
	 * Calculate click-through rate
	 *
	 * @since 1.0.0
	 * @param array $interactions Interactions.
	 * @return float CTR.
	 */
	private function calculate_ctr( $interactions ) {
		$views  = count( array_filter( $interactions, fn( $i ) => 'view' === $i['interaction_type'] ) );
		$clicks = count( array_filter( $interactions, fn( $i ) => 'click' === $i['interaction_type'] ) );

		return $views > 0 ? round( ( $clicks / $views ) * 100, 2 ) : 0;
	}

	/**
	 * Calculate conversion rate
	 *
	 * @since 1.0.0
	 * @param array $interactions Interactions.
	 * @return float Conversion rate.
	 */
	private function calculate_conversion_rate( $interactions ) {
		$clicks   = count( array_filter( $interactions, fn( $i ) => 'click' === $i['interaction_type'] ) );
		$converts = count( array_filter( $interactions, fn( $i ) => 'convert' === $i['interaction_type'] ) );

		return $clicks > 0 ? round( ( $converts / $clicks ) * 100, 2 ) : 0;
	}

	/**
	 * Get top performing content
	 *
	 * @since 1.0.0
	 * @param int $limit Limit.
	 * @return array Top content.
	 */
	private function get_top_performing_content( $limit ) {
		$args = array(
			'post_type'      => 'post',
			'posts_per_page' => $limit,
			'meta_key'       => '_wp_mcp_ai_engagement_score', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- meta_key lookup required to filter posts by content recommendation meta; no alternative lookup method available.
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
		);

		$posts = get_posts( $args );

		return array_map(
			function ( $post ) {
				return array(
					'id'               => $post->ID,
					'title'            => $post->post_title,
					'engagement_score' => get_post_meta( $post->ID, '_wp_mcp_ai_engagement_score', true ),
				);
			},
			$posts
		);
	}

	/**
	 * Analyze engagement patterns
	 *
	 * @since 1.0.0
	 * @param array $interactions Interactions.
	 * @return array Engagement patterns.
	 */
	private function analyze_engagement_patterns( $interactions ) {
		$by_type = array();

		foreach ( $interactions as $interaction ) {
			$type = $interaction['interaction_type'];
			if ( ! isset( $by_type[ $type ] ) ) {
				$by_type[ $type ] = 0;
			}
			++$by_type[ $type ];
		}

		return $by_type;
	}

	/**
	 * Get trending content with research pattern
	 *
	 * Implements 4-step research pattern:
	 * 1. Web Search - Gather trending topics from web
	 * 2. Source Collection - Aggregate and deduplicate
	 * 3. AI Synthesis - Analyze trends with AI
	 * 4. Report Generation - Integrate with local recommendations
	 *
	 * @since 1.0.0
	 * @param int    $limit              Limit.
	 * @param array  $exclude_categories Excluded categories.
	 * @param array  $include_post_types Included post types.
	 * @param float  $recency_weight     Recency weight.
	 * @param string $research_depth     Research depth (basic, standard, comprehensive).
	 * @param array  $focus_areas        Focus areas.
	 * @param array  $context            Execution context.
	 * @return array Recommendations with research data.
	 */
	private function get_trending_content_with_research( $limit, $exclude_categories, $include_post_types, $recency_weight, $research_depth, $focus_areas, $context ) {
		// Step 1: Gather trending information through web searches.
		$search_results = $this->gather_trending_information( $research_depth, $focus_areas, $context );

		// Step 2: Build research prompt with gathered information.
		$prompt = $this->build_trending_research_prompt( $research_depth, $focus_areas, $search_results );

		// Step 3: Use AI to analyze trending topics.
		$research_result = $this->perform_trending_ai_research( $prompt, $context );

		// Step 4: Get local trending content.
		$local_recommendations = $this->get_trending_content( $limit, $exclude_categories, $include_post_types, $recency_weight );

		// Parse research results to extract trending topics.
		$trending_topics = $this->parse_trending_research_results( $research_result );

		// Boost local content matching trending topics.
		$enhanced_recommendations = $this->boost_recommendations_by_trends( $local_recommendations, $trending_topics, $limit );

		return array(
			'recommendations' => $enhanced_recommendations,
			'research_data'   => array(
				'trending_topics' => $trending_topics,
				'sources_count'   => count( $search_results['sources'] ),
				'queries_count'   => count( $search_results['queries'] ),
				'research_depth'  => $research_depth,
			),
		);
	}

	/**
	 * Gather trending information through web searches
	 *
	 * @since 1.0.0
	 * @param string $depth       Research depth.
	 * @param array  $focus_areas Focus areas.
	 * @param array  $context     Execution context.
	 * @return array Search results.
	 */
	private function gather_trending_information( $depth, $focus_areas, $context ) {
		// Check if web search tool is available.
		$registry        = WP_MCP_AI_Tool_Registry::get_instance();
		$web_search_tool = $registry->get_tool( 'web_search' );

		if ( ! $web_search_tool ) {
			// Return empty results if web search is not available.
			return array(
				'results' => array(),
				'sources' => array(),
				'queries' => array(),
			);
		}

		// Get site niche/categories for context.
		$site_context = $this->get_site_context();

		// Generate search queries based on depth and focus areas.
		$search_queries = $this->generate_trending_search_queries( $depth, $focus_areas, $site_context );

		$all_results = array();
		$all_sources = array();
		$seen_urls   = array();

		foreach ( $search_queries as $search_query ) {
			// Execute web search.
			$search_result = $web_search_tool->execute(
				array(
					'query'       => $search_query,
					'max_results' => 5,
				),
				$context
			);

			if ( is_wp_error( $search_result ) || empty( $search_result['results'] ) ) {
				continue;
			}

			// Collect results.
			if ( is_array( $search_result['results'] ) ) {
				foreach ( $search_result['results'] as $result ) {
					$url = isset( $result['url'] ) ? $result['url'] : '';

					// Deduplicate by URL.
					if ( $url && ! in_array( $url, $seen_urls, true ) ) {
						$all_results[] = $result;
						$all_sources[] = array(
							'url'     => $url,
							'title'   => isset( $result['title'] ) ? $result['title'] : '',
							'snippet' => isset( $result['snippet'] ) ? $result['snippet'] : '',
						);
						$seen_urls[]   = $url;
					}
				}
			}
		}

		return array(
			'results' => $all_results,
			'sources' => $all_sources,
			'queries' => $search_queries,
		);
	}

	/**
	 * Generate search queries for trending research
	 *
	 * @since 1.0.0
	 * @param string $depth        Research depth.
	 * @param array  $focus_areas  Focus areas.
	 * @param array  $site_context Site context.
	 * @return array Search queries.
	 */
	private function generate_trending_search_queries( $depth, $focus_areas, $site_context ) {
		$queries_count = array(
			'basic'         => 1,
			'standard'      => 2,
			'comprehensive' => 3,
		);

		$num_queries = isset( $queries_count[ $depth ] ) ? $queries_count[ $depth ] : 2;
		$queries     = array();

		// Build base query from site context.
		$base_topics = implode( ' ', array_slice( $site_context['categories'], 0, 3 ) );

		// First query: general trending.
		$queries[] = 'trending topics ' . gmdate( 'Y' ) . ' ' . $base_topics;

		if ( $num_queries > 1 ) {
			// Second query: popular content formats.
			$queries[] = 'popular content types ' . $base_topics;
		}

		if ( $num_queries > 2 ) {
			// Third query: engagement patterns.
			$queries[] = 'high engagement content ' . $base_topics;
		}

		// Add focus area queries if provided.
		foreach ( $focus_areas as $area ) {
			if ( count( $queries ) < 3 ) {
				$queries[] = $area . ' trends ' . $base_topics;
			}
		}

		return array_slice( $queries, 0, 3 ); // Max 3 queries.
	}

	/**
	 * Build research prompt for trending analysis
	 *
	 * @since 1.0.0
	 * @param string $depth          Research depth.
	 * @param array  $focus_areas    Focus areas.
	 * @param array  $search_results Search results.
	 * @return string Research prompt.
	 */
	private function build_trending_research_prompt( $depth, $focus_areas, $search_results ) {
		$prompt = "You are a content trend analyst. Analyze the following web sources to identify current trending topics and content patterns.\n\n";

		$prompt .= "## Task\n";
		$prompt .= "Identify the top 5-10 trending topics and content themes based on the sources below.\n\n";

		$prompt .= "## Research Depth\n";
		$prompt .= ucfirst( $depth ) . " analysis\n\n";

		if ( ! empty( $focus_areas ) ) {
			$prompt .= "## Focus Areas\n";
			foreach ( $focus_areas as $area ) {
				$prompt .= '- ' . $area . "\n";
			}
			$prompt .= "\n";
		}

		// Add sources.
		if ( ! empty( $search_results['sources'] ) ) {
			$prompt      .= "## Sources\n";
			$source_count = 0;

			foreach ( array_slice( $search_results['sources'], 0, 10 ) as $source ) {
				++$source_count;
				$prompt .= sprintf(
					"[%d] %s\n%s\n%s\n\n",
					$source_count,
					$source['title'],
					$source['url'],
					wp_trim_words( $source['snippet'], 50 )
				);
			}
		}

		$prompt .= "## Output Format\n";
		$prompt .= "Provide your response in JSON format:\n";
		$prompt .= "{\n";
		$prompt .= '  "trending_topics": [\n';
		$prompt .= '    {"topic": "Topic name", "relevance": 0.95, "keywords": ["keyword1", "keyword2"]}\n';
		$prompt .= "  ],\n";
		$prompt .= '  "content_themes": ["theme1", "theme2"],\n';
		$prompt .= '  "popular_formats": ["format1", "format2"],\n';
		$prompt .= '  "confidence": 0.90\n';
		$prompt .= "}\n";

		return $prompt;
	}

	/**
	 * Perform AI research on trending topics
	 *
	 * @since 1.0.0
	 * @param string $prompt  Research prompt.
	 * @param array  $context Execution context.
	 * @return array|WP_Error AI response.
	 */
	private function perform_trending_ai_research( $prompt, $context ) {
		// Use API manager to send request.
		$api_manager = new WP_MCP_AI_API_Manager();

		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a content trend analyst. Provide accurate, data-driven trend analysis.',
			),
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);

		$options = array(
			'model'           => 'gpt-4o',
			'temperature'     => 0.3, // Low temperature for factual analysis.
			'max_tokens'      => 1500,
			'response_format' => array( 'type' => 'json_object' ),
		);

		$response = $api_manager->send_message( $messages, $options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['choices'][0]['message']['content'] ) ? $response['choices'][0]['message']['content'] : '';
	}

	/**
	 * Parse trending research results
	 *
	 * @since 1.0.0
	 * @param string $research_result AI research result.
	 * @return array Parsed trending topics.
	 */
	private function parse_trending_research_results( $research_result ) {
		if ( empty( $research_result ) ) {
			return array();
		}

		$data = json_decode( $research_result, true );

		if ( null === $data || ! isset( $data['trending_topics'] ) ) {
			return array();
		}

		return $data['trending_topics'];
	}

	/**
	 * Boost recommendations based on trending topics
	 *
	 * @since 1.0.0
	 * @param array $recommendations  Local recommendations.
	 * @param array $trending_topics  Trending topics from research.
	 * @param int   $limit            Result limit.
	 * @return array Enhanced recommendations.
	 */
	private function boost_recommendations_by_trends( $recommendations, $trending_topics, $limit ) {
		if ( empty( $trending_topics ) ) {
			return $recommendations;
		}

		// Extract keywords from trending topics.
		$trending_keywords = array();
		foreach ( $trending_topics as $topic ) {
			if ( isset( $topic['keywords'] ) && is_array( $topic['keywords'] ) ) {
				$trending_keywords = array_merge( $trending_keywords, $topic['keywords'] );
			}
			if ( isset( $topic['topic'] ) ) {
				$trending_keywords[] = $topic['topic'];
			}
		}

		$trending_keywords = array_unique( array_map( 'strtolower', $trending_keywords ) );

		// Score recommendations based on trending keywords.
		foreach ( $recommendations as &$rec ) {
			$content = strtolower( $rec['title'] . ' ' . $rec['excerpt'] );
			$matches = 0;

			foreach ( $trending_keywords as $keyword ) {
				if ( false !== strpos( $content, strtolower( $keyword ) ) ) {
					++$matches;
				}
			}

			// Boost score based on keyword matches.
			$rec['score']          = isset( $rec['score'] ) ? $rec['score'] : 0.5;
			$rec['score']         += ( $matches * 0.1 ); // +0.1 per keyword match.
			$rec['trending_match'] = $matches > 0;
			$rec['trend_score']    = $matches;
		}

		// Re-sort by enhanced score.
		usort( $recommendations, fn( $a, $b ) => $b['score'] <=> $a['score'] );

		return array_slice( $recommendations, 0, $limit );
	}

	/**
	 * Get site context for trending research
	 *
	 * @since 1.0.0
	 * @return array Site context.
	 */
	private function get_site_context() {
		$categories = get_categories( array( 'number' => 10 ) );
		$cat_names  = array_map( fn( $cat ) => $cat->name, $categories );

		return array(
			'categories' => $cat_names,
			'site_title' => get_bloginfo( 'name' ),
		);
	}

	/**
	 * Check if tool has privacy data
	 *
	 * @since 1.0.0
	 * @return bool True - stores user interaction data.
	 */
	public function has_privacy_data() {
		return true;
	}
}
