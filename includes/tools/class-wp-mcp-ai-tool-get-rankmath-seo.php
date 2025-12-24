<?php
/**
 * Tool that returns Rank Math SEO metadata for a post.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves Rank Math SEO insights for a single post.
 */
class WP_MCP_AI_Tool_Get_RankMath_SEO implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Check whether the tool can be registered.
	 *
	 * @return bool
	 */
	public static function is_available() {
				return defined( 'RANK_MATH_VERSION' ) && class_exists( '\RankMath\Helper' );
	}

	/**
	 * Provide a message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Rank Math SEO plugin must be installed and activated to use the Rank Math SEO tool.', 'wp-mcp-ai' );
	}

	/**
	 * Check whether Rank Math Pro is active.
	 *
	 * @return bool
	 */
	protected function is_pro_active() {
		return defined( 'RANK_MATH_PRO_VERSION' ) || defined( 'RANK_MATH_PRO_FILE' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_rankmath_seo';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Rank Math SEO Overview', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns Rank Math SEO details for a specific post, including focus keywords, SEO score, schema configuration, and Pro features (Content AI, Analytics, Link Counter, Image SEO) if Rank Math Pro is installed.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the post to inspect. Required if a URL is not provided.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'url'     => array(
					'type'        => 'string',
					'description' => __( 'Permalink for the post to inspect. Used when a post ID is not supplied.', 'wp-mcp-ai' ),
					'format'      => 'uri',
				),
			),
			'additionalProperties' => false,
			'anyOf'                => array(
				array(
					'required' => array( 'post_id' ),
				),
				array(
					'required' => array( 'url' ),
				),
			),
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
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_rankmath_missing_plugin', __( 'Rank Math SEO is not available on this site.', 'wp-mcp-ai' ) );
		}

		$post_id = 0;

		if ( isset( $arguments['post_id'] ) ) {
			$post_id = absint( $arguments['post_id'] );
		}

		if ( ! $post_id && ! empty( $arguments['url'] ) ) {
			$post_id = url_to_postid( esc_url_raw( $arguments['url'] ) );
		}

		if ( ! $post_id ) {
			return new WP_Error( 'wp_mcp_ai_rankmath_missing_post', __( 'Unable to determine the post to analyse.', 'wp-mcp-ai' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'revision' === $post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_rankmath_invalid_post', __( 'The requested post could not be found.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'edit_post', $post_id ) ) {
			return new WP_Error( 'wp_mcp_ai_rankmath_forbidden', __( 'You do not have permission to inspect Rank Math SEO data for this post.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_rankmath_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$seo_score_raw = $this->get_meta_value( 'seo_score', $post_id );
		$seo_score     = is_numeric( $seo_score_raw ) ? (int) $seo_score_raw : null;

		$score_rating = null;
		if ( null !== $seo_score ) {
			if ( $seo_score >= 81 ) {
				$score_rating = array(
					'slug'        => 'great',
					'label'       => __( 'Great', 'wp-mcp-ai' ),
					'explanation' => __( 'The post passes most Rank Math SEO tests (score between 81 and 100).', 'wp-mcp-ai' ),
				);
			} elseif ( $seo_score >= 51 ) {
				$score_rating = array(
					'slug'        => 'good',
					'label'       => __( 'Good', 'wp-mcp-ai' ),
					'explanation' => __( 'There is room for improvement (score between 51 and 80).', 'wp-mcp-ai' ),
				);
			} else {
				$score_rating = array(
					'slug'        => 'needs-improvement',
					'label'       => __( 'Needs Improvement', 'wp-mcp-ai' ),
					'explanation' => __( 'The post fails many Rank Math SEO tests (score 50 or lower).', 'wp-mcp-ai' ),
				);
			}
		}

		$focus_keyword_raw = $this->get_meta_value( 'focus_keyword', $post_id );
		$focus_keywords    = array();
		if ( is_string( $focus_keyword_raw ) && '' !== $focus_keyword_raw ) {
			$focus_keywords = array_filter( array_map( 'trim', explode( ',', $focus_keyword_raw ) ) );
			$focus_keywords = array_values( array_unique( $focus_keywords ) );
		}

		$robots_meta = $this->get_meta_value( 'robots', $post_id );
		if ( is_string( $robots_meta ) ) {
			$robots_meta = array_filter( array_map( 'trim', explode( ',', $robots_meta ) ) );
		}

		$advanced_robots_meta = $this->get_meta_value( 'advanced_robots', $post_id );

		$seo_title       = $this->get_meta_value( 'title', $post_id );
		$seo_description = $this->get_meta_value( 'description', $post_id );
		$canonical_url   = $this->get_meta_value( 'canonical', $post_id );
		if ( empty( $canonical_url ) ) {
			$canonical_url = $this->get_meta_value( 'rank_math_canonical_url', $post_id, '', false );
		}

		if ( empty( $seo_title ) ) {
			$seo_title = get_the_title( $post );
		}

		if ( empty( $seo_description ) ) {
			$seo_description = wp_trim_words( wp_strip_all_tags( $post->post_content ), 32 );
		}

		if ( empty( $canonical_url ) ) {
			$canonical_url = get_permalink( $post );
		}

		$schema_data = $this->get_schema_data( $post_id );

		$result = array(
			'summary'   => sprintf(
				/* translators: 1: post title, 2: SEO score */
				__( 'Rank Math SEO for "%1$s": Score %2$d/100', 'wp-mcp-ai' ),
				get_the_title( $post ),
				$seo_score ? $seo_score : 0
			),
			'post'      => array(
				'ID'        => $post->ID,
				'title'     => get_the_title( $post ),
				'permalink' => get_permalink( $post ),
				'post_type' => $post->post_type,
				'status'    => get_post_status( $post ),
				'published' => get_post_time( DATE_W3C, true, $post ),
				'modified'  => get_post_modified_time( DATE_W3C, true, $post ),
				'author_id' => (int) $post->post_author,
			),
			'rank_math' => array(
				'version'          => defined( 'RANK_MATH_VERSION' ) ? sanitize_text_field( RANK_MATH_VERSION ) : null,
				'is_pro'           => $this->is_pro_active(),
				'pro_version'      => defined( 'RANK_MATH_PRO_VERSION' ) ? sanitize_text_field( RANK_MATH_PRO_VERSION ) : null,
				'seo_score'        => $seo_score,
				'seo_score_rating' => $score_rating,
				'focus_keywords'   => $focus_keywords,
				'seo_title'        => $this->sanitize_meta_output( $seo_title ),
				'seo_description'  => $this->sanitize_meta_output( $seo_description ),
				'canonical_url'    => esc_url_raw( $canonical_url ),
				'robots'           => $this->sanitize_meta_output( $robots_meta ),
				'advanced_robots'  => $this->sanitize_meta_output( $advanced_robots_meta ),
				'schema'           => $schema_data,
			),
		);

		// Add Rank Math Pro features if available.
		if ( $this->is_pro_active() ) {
			$result['rank_math']['pro_features'] = $this->get_pro_features( $post_id );
		}

		return $result;
	}

	/**
	 * Retrieve a Rank Math post meta value.
	 *
	 * @param string $key          Meta key (either with or without the `rank_math_` prefix).
	 * @param int    $post_id      Post ID.
	 * @param mixed  $default      Default value when meta is not set.
	 * @param bool   $auto_prefix  Whether to automatically prepend the Rank Math prefix when missing.
	 *
	 * @return mixed
	 */
	protected function get_meta_value( $key, $post_id, $default = '', $auto_prefix = true ) {
		$value = null;

		$helper_callable = array( '\\RankMath\\Helper', 'get_post_meta' );

		if ( is_callable( $helper_callable ) ) {
			if ( $auto_prefix && 0 !== strpos( $key, 'rank_math_' ) ) {
				$value = call_user_func( $helper_callable, $key, $post_id, $default );
			} else {
				$trimmed_key = 0 === strpos( $key, 'rank_math_' ) ? substr( $key, strlen( 'rank_math_' ) ) : $key;
				$value       = call_user_func( $helper_callable, $trimmed_key, $post_id, $default );
			}
		}

		if ( null === $value || '' === $value ) {
			$meta_key = $key;

			if ( $auto_prefix && 0 !== strpos( $key, 'rank_math_' ) ) {
				$meta_key = 'rank_math_' . $key;
			}

			$value = get_post_meta( $post_id, $meta_key, true );
		}

		if ( null === $value || '' === $value ) {
			return $default;
		}

		if ( is_string( $value ) ) {
			$value = wp_unslash( $value );
		}

		return $value;
	}

	/**
	 * Compile schema metadata stored by Rank Math for the given post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array
	 */
	protected function get_schema_data( $post_id ) {
		$schema = array();

		$all_meta = get_post_meta( $post_id );
		foreach ( $all_meta as $meta_key => $values ) {
			if ( 0 !== strpos( $meta_key, 'rank_math_schema_' ) || empty( $values ) ) {
				continue;
			}

			$schema_type = substr( $meta_key, strlen( 'rank_math_schema_' ) );
			if ( ! $schema_type ) {
				continue;
			}

			$value                  = maybe_unserialize( $values[0] );
			$schema[ $schema_type ] = $this->sanitize_meta_output( $value );
		}

		return $schema;
	}

	/**
	 * Sanitize meta data before returning it to the model.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return mixed
	 */
	protected function sanitize_meta_output( $value ) {
		if ( is_string( $value ) ) {
			return wp_kses_post( trim( wp_unslash( $value ) ) );
		}

		if ( is_array( $value ) ) {
			return map_deep(
				$value,
				function ( $item ) {
					if ( is_string( $item ) ) {
						return wp_kses_post( trim( wp_unslash( $item ) ) );
					}

					return $item;
				}
			);
		}

		return $value;
	}

	/**
	 * Retrieve Rank Math Pro features data for the post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array Pro features data.
	 */
	protected function get_pro_features( $post_id ) {
		$pro_data = array();

		// Content AI suggestions.
		$content_ai_data = $this->get_meta_value( 'contentai_score', $post_id );
		if ( ! empty( $content_ai_data ) ) {
			$pro_data['content_ai'] = array(
				'score'       => $content_ai_data,
				'suggestions' => $this->get_meta_value( 'contentai_suggestions', $post_id ),
			);
		}

		// Link counter (internal/external links).
		$internal_links = $this->get_meta_value( 'internal_link_count', $post_id );
		$external_links = $this->get_meta_value( 'external_link_count', $post_id );
		if ( ! empty( $internal_links ) || ! empty( $external_links ) ) {
			$pro_data['link_counter'] = array(
				'internal_links' => absint( $internal_links ),
				'external_links' => absint( $external_links ),
			);
		}

		// Image SEO data.
		$image_seo = $this->get_meta_value( 'image_seo_score', $post_id );
		if ( ! empty( $image_seo ) ) {
			$pro_data['image_seo'] = array(
				'score'           => $image_seo,
				'missing_alt'     => $this->get_meta_value( 'images_missing_alt', $post_id ),
				'missing_title'   => $this->get_meta_value( 'images_missing_title', $post_id ),
				'optimized_count' => $this->get_meta_value( 'images_optimized', $post_id ),
			);
		}

		// Video schema (Pro enhanced).
		$video_schema = $this->get_meta_value( 'snippet_video_url', $post_id );
		if ( ! empty( $video_schema ) ) {
			$pro_data['video_schema'] = array(
				'url'         => esc_url_raw( $video_schema ),
				'thumbnail'   => esc_url_raw( $this->get_meta_value( 'snippet_video_thumbnail', $post_id ) ),
				'duration'    => $this->get_meta_value( 'snippet_video_duration', $post_id ),
				'upload_date' => $this->get_meta_value( 'snippet_video_upload_date', $post_id ),
			);
		}

		// Local SEO data (if applicable).
		$local_seo = $this->get_meta_value( 'local_seo_type', $post_id );
		if ( ! empty( $local_seo ) ) {
			$pro_data['local_seo'] = array(
				'business_type' => $local_seo,
				'address'       => $this->get_meta_value( 'local_address', $post_id ),
				'phone'         => $this->get_meta_value( 'local_phone', $post_id ),
				'latitude'      => $this->get_meta_value( 'local_latitude', $post_id ),
				'longitude'     => $this->get_meta_value( 'local_longitude', $post_id ),
			);
		}

		// Analytics data (if Pro Analytics is enabled).
		if ( class_exists( '\RankMathPro\Analytics\Posts' ) ) {
			$analytics = $this->get_analytics_data( $post_id );
			if ( ! empty( $analytics ) ) {
				$pro_data['analytics'] = $analytics;
			}
		}

		// Advanced schema templates.
		$schema_templates = $this->get_meta_value( 'schema_templates', $post_id );
		if ( ! empty( $schema_templates ) ) {
			$pro_data['schema_templates'] = $this->sanitize_meta_output( $schema_templates );
		}

		return $pro_data;
	}

	/**
	 * Retrieve analytics data from Rank Math Pro Analytics module.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array|null Analytics data or null if unavailable.
	 */
	protected function get_analytics_data( $post_id ) {
		// Check if Analytics module is active and available.
		if ( ! class_exists( '\RankMathPro\Analytics\Posts' ) ) {
			return null;
		}

		$analytics_data = array();

		// Try to get analytics data from post meta or database.
		$impressions = $this->get_meta_value( 'analytics_impressions', $post_id );
		$clicks      = $this->get_meta_value( 'analytics_clicks', $post_id );
		$position    = $this->get_meta_value( 'analytics_position', $post_id );

		if ( ! empty( $impressions ) || ! empty( $clicks ) || ! empty( $position ) ) {
			$analytics_data = array(
				'impressions' => absint( $impressions ),
				'clicks'      => absint( $clicks ),
				'ctr'         => $impressions > 0 ? round( ( $clicks / $impressions ) * 100, 2 ) : 0,
				'position'    => ! empty( $position ) ? round( floatval( $position ), 1 ) : null,
			);
		}

		// Get keyword rankings if available.
		global $wpdb;
		$analytics_table = $wpdb->prefix . 'rank_math_analytics_objects';
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $analytics_table ) ) ) === $analytics_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$keywords = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT keyword, position, clicks, impressions 
					FROM `{$wpdb->prefix}rank_math_analytics_objects` 
					WHERE object_id = %d 
					ORDER BY impressions DESC 
					LIMIT 10",
					$post_id
				)
			);

			if ( ! empty( $keywords ) ) {
				$analytics_data['top_keywords'] = array_map(
					function ( $row ) {
						return array(
							'keyword'     => sanitize_text_field( $row->keyword ),
							'position'    => round( floatval( $row->position ), 1 ),
							'clicks'      => absint( $row->clicks ),
							'impressions' => absint( $row->impressions ),
						);
					},
					$keywords
				);
			}
		}

		return ! empty( $analytics_data ) ? $analytics_data : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		$flags = array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);

		// Add Pro flag if Rank Math Pro is active.
		if ( $this->is_pro_active() ) {
			$flags[] = 'pro';
		}

		return $flags;
	}
}
