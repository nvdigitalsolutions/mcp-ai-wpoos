<?php
/**
 * Tool: seo_site_audit — Audits published posts and pages for SEO issues.
 *
 * Port of mcp-wordpress wp_seo_site_audit tool.
 *
 * @link    https://github.com/docdyhr/mcp-wordpress
 * @credit  mcp-wordpress by Aionda GmbH (MIT)
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO Site Audit — scans published posts and pages for common SEO problems.
 *
 * Checks titles, meta descriptions, headings, links, content depth, and
 * image alt coverage, then returns a site-level summary plus a capped list
 * of per-page issues.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_SEO_Site_Audit implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'seo_site_audit';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'SEO Site Auditor', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Audits recently modified published posts and pages for SEO issues: title length, meta descriptions, H1 headings, internal/external links, thin content, and images missing alt text. Returns a site-level summary and a list of per-page issues.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Getting a health check across the site before a content push: finding pages with missing meta descriptions, duplicate titles, thin content, or heading problems.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Deep single-page analysis; use seo_analyze_content. Server speed or uptime checks are not covered here.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_rankmath_seo', 'performance_optimizer_assistant', 'check_site_security', 'content_freshness_checker' ),
			'notes'           => __( 'Audits the most recently modified published posts and pages (up to max_pages). External links are only counted when include_external_links is true.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'audit_type'             => array(
					'type'        => 'string',
					'description' => __( 'Which audit sections to run.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'technical', 'content', 'performance', 'full' ),
					'default'     => 'full',
				),
				'max_pages'              => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of pages to audit.', 'mcp-ai-wpoos' ),
					'minimum'     => 5,
					'maximum'     => 100,
					'default'     => 25,
				),
				'include_external_links' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to count external (off-site) links per page.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.87
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'content_publishing',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'seo_specialist', 'content_strategist' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // Only reads data, does not modify state.
			'local-only',          // No external API calls.
			'requires-capability', // Requires user capabilities.
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
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to use this tool.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to run a site audit.', 'mcp-ai-wpoos' ) );
		}

		$audit_type = isset( $arguments['audit_type'] ) ? sanitize_key( $arguments['audit_type'] ) : 'full';
		if ( ! in_array( $audit_type, array( 'technical', 'content', 'performance', 'full' ), true ) ) {
			$audit_type = 'full';
		}

		$max_pages = isset( $arguments['max_pages'] ) ? absint( $arguments['max_pages'] ) : 25;
		if ( $max_pages < 5 ) {
			$max_pages = 5;
		} elseif ( $max_pages > 100 ) {
			$max_pages = 100;
		}

		$include_external_links = ! empty( $arguments['include_external_links'] );

		$run_technical   = in_array( $audit_type, array( 'technical', 'full' ), true );
		$run_content     = in_array( $audit_type, array( 'content', 'full' ), true );
		$run_performance = in_array( $audit_type, array( 'performance', 'full' ), true );

		$query = new WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => $max_pages,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$pages                    = array();
		$issues                   = array();
		$titles_seen              = array();
		$total_words              = 0;
		$missing_meta             = 0;
		$duplicate_titles         = 0;
		$no_h1                    = 0;
		$multiple_h1              = 0;
		$total_images             = 0;
		$total_images_without_alt = 0;
		$total_internal_links     = 0;

		$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		foreach ( $query->posts as $post ) {
			$content = $post->post_content;
			$page    = array(
				'post_id' => (int) $post->ID,
				'title'   => esc_html( get_the_title( $post ) ),
				'url'     => esc_url( get_permalink( $post ) ),
			);

			$title_text = wp_strip_all_tags( get_the_title( $post ) );

			if ( $run_technical ) {
				$title_len            = mb_strlen( $title_text );
				$page['title_length'] = $title_len;
				$page['title_ok']     = ( $title_len >= 30 && $title_len <= 60 );

				if ( ! $page['title_ok'] ) {
					$this->add_issue(
						$issues,
						$post,
						__( 'Title length outside the recommended range', 'mcp-ai-wpoos' ),
						sprintf(
							/* translators: %d: title length in characters */
							__( 'Title is %d characters long (recommended: 30-60).', 'mcp-ai-wpoos' ),
							$title_len
						)
					);
				}

				$meta_desc = get_post_meta( $post->ID, 'rank_math_description', true );
				if ( '' === (string) $meta_desc ) {
					$meta_desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
				}
				if ( '' === (string) $meta_desc ) {
					$meta_desc = get_post_meta( $post->ID, '_aioseo_description', true );
				}
				if ( '' === (string) $meta_desc ) {
					$meta_desc = $post->post_excerpt;
				}

				$meta_desc                       = trim( wp_strip_all_tags( (string) $meta_desc ) );
				$meta_len                        = mb_strlen( $meta_desc );
				$page['meta_description_length'] = $meta_len;
				$page['meta_description_ok']     = ( $meta_len >= 50 && $meta_len <= 160 );

				if ( '' === $meta_desc ) {
					++$missing_meta;
					$this->add_issue(
						$issues,
						$post,
						__( 'Missing meta description', 'mcp-ai-wpoos' ),
						__( 'No meta description found in an SEO plugin and no excerpt is set.', 'mcp-ai-wpoos' )
					);
				} elseif ( ! $page['meta_description_ok'] ) {
					$this->add_issue(
						$issues,
						$post,
						__( 'Meta description length outside the recommended range', 'mcp-ai-wpoos' ),
						sprintf(
							/* translators: %d: meta description length in characters */
							__( 'Meta description is %d characters long (recommended: 50-160).', 'mcp-ai-wpoos' ),
							$meta_len
						)
					);
				}

				$h1_count            = preg_match_all( '/<h1[^>]*>/i', $content, $h1_matches );
				$page['h1_count']    = $h1_count;
				$page['multiple_h1'] = $h1_count > 1;

				if ( 0 === $h1_count ) {
					++$no_h1;
					$this->add_issue( $issues, $post, __( 'No H1 heading', 'mcp-ai-wpoos' ), __( 'The page has no H1 heading.', 'mcp-ai-wpoos' ) );
				} elseif ( $h1_count > 1 ) {
					++$multiple_h1;
					$this->add_issue(
						$issues,
						$post,
						__( 'Multiple H1 headings', 'mcp-ai-wpoos' ),
						sprintf(
							/* translators: %d: number of H1 headings */
							__( 'The page has %d H1 headings; one is recommended.', 'mcp-ai-wpoos' ),
							$h1_count
						)
					);
				}

				preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content, $link_matches );
				$internal = 0;
				$external = 0;
				foreach ( $link_matches[1] as $href ) {
					$host = wp_parse_url( $href, PHP_URL_HOST );
					if ( empty( $host ) || $host === $site_host ) {
						++$internal;
					} elseif ( $include_external_links ) {
						++$external;
					}
				}
				$page['internal_links'] = $internal;
				$total_internal_links  += $internal;
				if ( $include_external_links ) {
					$page['external_links'] = $external;
				}

				$title_key = md5( strtolower( trim( $title_text ) ) );
				if ( isset( $titles_seen[ $title_key ] ) ) {
					++$duplicate_titles;
					$this->add_issue( $issues, $post, __( 'Duplicate title', 'mcp-ai-wpoos' ), __( 'Another audited page uses the same title.', 'mcp-ai-wpoos' ) );
				} else {
					$titles_seen[ $title_key ] = true;
				}
			}

			if ( $run_content ) {
				$words              = str_word_count( wp_strip_all_tags( $content ) );
				$page['word_count'] = $words;
				$total_words       += $words;

				if ( $words < 300 ) {
					$this->add_issue(
						$issues,
						$post,
						__( 'Thin content', 'mcp-ai-wpoos' ),
						sprintf(
							/* translators: %d: word count */
							__( 'Only %d words; 300+ words are recommended.', 'mcp-ai-wpoos' ),
							$words
						)
					);
				}

				$images_without_alt         = preg_match_all( '/<img\b(?![^>]*\balt\s*=)[^>]*>/i', $content, $alt_matches );
				$page['images_without_alt'] = $images_without_alt;
				$total_images_without_alt  += $images_without_alt;

				if ( $images_without_alt > 0 ) {
					$this->add_issue(
						$issues,
						$post,
						__( 'Images missing alt text', 'mcp-ai-wpoos' ),
						sprintf(
							/* translators: %d: number of images */
							__( '%d image(s) have no alt attribute.', 'mcp-ai-wpoos' ),
							$images_without_alt
						)
					);
				}
			}

			if ( $run_performance ) {
				$images         = preg_match_all( '/<img\b[^>]*>/i', $content, $perf_img_matches );
				$page['images'] = $images;
				$total_images  += $images;
			}

			$pages[] = $page;
		}

		$pages_audited = count( $pages );

		$summary = array(
			'pages_audited'            => $pages_audited,
			'total_issues'             => count( $issues ),
			'duplicate_titles'         => $duplicate_titles,
			'no_h1'                    => $no_h1,
			'multiple_h1'              => $multiple_h1,
			'missing_meta_description' => $missing_meta,
		);

		if ( $run_content || $run_performance ) {
			$summary['avg_word_count'] = $pages_audited > 0 ? round( $total_words / $pages_audited ) : 0;
		}

		if ( $run_performance ) {
			$summary['total_images']             = $total_images;
			$summary['total_internal_links']     = $total_internal_links;
			$summary['total_images_without_alt'] = $total_images_without_alt;
		}

		$message = sprintf(
			/* translators: 1: number of pages audited, 2: audit type, 3: number of issues found */
			__( 'SEO site audit (%2$s) reviewed %1$d page(s) and found %3$d issue(s).', 'mcp-ai-wpoos' ),
			$pages_audited,
			$audit_type,
			count( $issues )
		);

		return array(
			'message'       => $message,
			'audit_type'    => $audit_type,
			'pages_audited' => $pages_audited,
			'summary'       => $summary,
			'issues'        => $issues,
			'pages'         => $pages,
		);
	}

	/**
	 * Appends an issue entry to the issue list (capped at 50 entries).
	 *
	 * @since 1.1.87
	 *
	 * @param array   $issues Issue list (modified in place).
	 * @param WP_Post $post   The affected post.
	 * @param string  $issue  Issue label.
	 * @param string  $detail Issue detail.
	 */
	private function add_issue( &$issues, $post, $issue, $detail ) {
		if ( count( $issues ) >= 50 ) {
			return;
		}

		$issues[] = array(
			'post_id' => (int) $post->ID,
			'title'   => esc_html( get_the_title( $post ) ),
			'issue'   => $issue,
			'detail'  => $detail,
		);
	}
}
