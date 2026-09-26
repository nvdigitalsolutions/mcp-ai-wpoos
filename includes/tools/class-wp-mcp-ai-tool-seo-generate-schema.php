<?php
/**
 * Tool: seo_generate_schema — Generates JSON-LD structured data for a post.
 *
 * Port of mcp-wordpress wp_seo_generate_schema tool.
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
 * SEO Generate Schema — builds schema.org JSON-LD for a post.
 *
 * Supports 14 schema types (Article, Product, FAQPage, HowTo, Organization,
 * Website, BreadcrumbList, Event, Recipe, Person, LocalBusiness, Review,
 * VideoObject, Course) from post and site data plus optional custom_data.
 *
 * This tool only GENERATES the markup — it never persists anything.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_SEO_Generate_Schema implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Supported schema types.
	 *
	 * @since 1.1.87
	 *
	 * @var array
	 */
	private $allowed_types = array( 'Article', 'Product', 'FAQPage', 'HowTo', 'Organization', 'Website', 'BreadcrumbList', 'Event', 'Recipe', 'Person', 'LocalBusiness', 'Review', 'VideoObject', 'Course' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'seo_generate_schema';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'SEO Schema Generator', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates JSON-LD structured data (schema.org) for a post from post and site data. Supports 14 schema types including Article, Product, FAQPage, and HowTo. This tool only generates the markup — it does not save anything.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Generating structured-data markup for a page, e.g. an Article for a blog post, FAQPage from Q&A pairs, or a Product schema with an offer.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Saving schema to the site; this tool only returns the markup. Use seo_meta_optimizer to persist Rank Math schema, and seo_validate_schema to check markup you already have.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'seo_validate_schema', 'seo_meta_optimizer', 'get_rankmath_seo' ),
			'notes'           => __( 'Pass extra type-specific data via custom_data (e.g. questions for FAQPage, steps for HowTo, price/currency for Product). Output is returned as both a PHP array and a JSON string.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'     => array(
					'type'        => 'integer',
					'description' => __( 'ID of the post the schema describes.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'schema_type' => array(
					'type'        => 'string',
					'description' => __( 'The schema.org type to generate.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'Article', 'Product', 'FAQPage', 'HowTo', 'Organization', 'Website', 'BreadcrumbList', 'Event', 'Recipe', 'Person', 'LocalBusiness', 'Review', 'VideoObject', 'Course' ),
				),
				'custom_data' => array(
					'type'                 => 'object',
					'description'          => __( 'Type-specific extra data: FAQPage needs questions, HowTo needs steps, Event needs startDate/endDate, Recipe needs recipeIngredient/recipeInstructions, VideoObject needs contentUrl.', 'mcp-ai-wpoos' ),
					'additionalProperties' => true,
				),
			),
			'required'             => array( 'post_id', 'schema_type' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => null,
			'consumes' => array( 'post_id' ),
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

		$post_id     = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$schema_type = isset( $arguments['schema_type'] ) ? sanitize_text_field( $arguments['schema_type'] ) : '';

		if ( ! in_array( $schema_type, $this->allowed_types, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_schema_type',
				sprintf(
					/* translators: %s: the requested schema type */
					__( 'Unsupported schema type "%s".', 'mcp-ai-wpoos' ),
					esc_html( $schema_type )
				)
			);
		}

		$custom_data = isset( $arguments['custom_data'] ) && is_array( $arguments['custom_data'] ) ? $this->sanitize_custom_data( $arguments['custom_data'] ) : array();

		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'wp_mcp_ai_post_not_found', __( 'The requested post could not be found.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'edit_post', $post_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate schema for this post.', 'mcp-ai-wpoos' ) );
		}

		$permalink   = (string) get_permalink( $post_id );
		$description = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( $post->post_content ), 20, '' );

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => $schema_type,
			'@id'      => $permalink . '#' . strtolower( $schema_type ),
		);

		switch ( $schema_type ) {
			case 'Article':
				$schema['headline']         = esc_html( $post->post_title );
				$schema['description']      = esc_html( $description );
				$schema['datePublished']    = get_post_time( DATE_W3C, true, $post );
				$schema['dateModified']     = get_post_modified_time( DATE_W3C, true, $post );
				$schema['author']           = array(
					'@type' => 'Person',
					'name'  => esc_html( get_the_author_meta( 'display_name', (int) $post->post_author ) ),
				);
				$schema['publisher']        = array(
					'@type' => 'Organization',
					'name'  => esc_html( get_bloginfo( 'name' ) ),
				);
				$schema['mainEntityOfPage'] = esc_url( $permalink );
				if ( has_post_thumbnail( $post_id ) ) {
					$schema['image'] = esc_url( get_the_post_thumbnail_url( $post_id, 'full' ) );
				}
				break;

			case 'Product':
				$schema['name']        = esc_html( $post->post_title );
				$schema['description'] = esc_html( $description );
				if ( has_post_thumbnail( $post_id ) ) {
					$schema['image'] = esc_url( get_the_post_thumbnail_url( $post_id, 'full' ) );
				}
				$schema['brand'] = array(
					'@type' => 'Brand',
					'name'  => esc_html( ! empty( $custom_data['brand'] ) ? $custom_data['brand'] : get_bloginfo( 'name' ) ),
				);
				if ( ! empty( $custom_data['price'] ) ) {
					$schema['offers'] = array(
						'@type'         => 'Offer',
						'price'         => sanitize_text_field( $custom_data['price'] ),
						'priceCurrency' => ! empty( $custom_data['currency'] ) ? strtoupper( sanitize_text_field( $custom_data['currency'] ) ) : 'USD',
						'availability'  => 'https://schema.org/InStock',
					);
				}
				break;

			case 'FAQPage':
				if ( empty( $custom_data['questions'] ) || ! is_array( $custom_data['questions'] ) ) {
					return new WP_Error( 'wp_mcp_ai_schema_questions_required', __( 'FAQPage schema requires custom_data.questions as an array of {question, answer} pairs.', 'mcp-ai-wpoos' ) );
				}
				$main_entity = array();
				foreach ( $custom_data['questions'] as $question ) {
					if ( ! is_array( $question ) || empty( $question['question'] ) ) {
						continue;
					}
					$main_entity[] = array(
						'@type'          => 'Question',
						'name'           => esc_html( wp_kses_post( $question['question'] ) ),
						'acceptedAnswer' => array(
							'@type' => 'Answer',
							'text'  => wp_kses_post( isset( $question['answer'] ) ? $question['answer'] : '' ),
						),
					);
				}
				$schema['mainEntity'] = $main_entity;
				break;

			case 'HowTo':
				if ( empty( $custom_data['steps'] ) || ! is_array( $custom_data['steps'] ) ) {
					return new WP_Error( 'wp_mcp_ai_schema_steps_required', __( 'HowTo schema requires custom_data.steps as an array of strings or {name, text} objects.', 'mcp-ai-wpoos' ) );
				}
				$schema['name']        = esc_html( $post->post_title );
				$schema['description'] = esc_html( $description );
				$steps                 = array();
				$step_number           = 1;
				foreach ( $custom_data['steps'] as $step ) {
					if ( is_array( $step ) ) {
						$name = isset( $step['name'] ) && '' !== $step['name'] ? wp_kses_post( $step['name'] ) : sprintf(
							/* translators: %d: step number */
							__( 'Step %d', 'mcp-ai-wpoos' ),
							$step_number
						);
						$text = isset( $step['text'] ) ? wp_kses_post( $step['text'] ) : $name;
					} elseif ( is_string( $step ) && '' !== trim( $step ) ) {
						$name = sprintf(
							/* translators: %d: step number */
							__( 'Step %d', 'mcp-ai-wpoos' ),
							$step_number
						);
						$text = wp_kses_post( $step );
					} else {
						continue;
					}
					$steps[] = array(
						'@type' => 'HowToStep',
						'name'  => esc_html( $name ),
						'text'  => $text,
					);
					++$step_number;
				}
				$schema['step'] = $steps;
				break;

			case 'Organization':
				$schema['name'] = esc_html( get_bloginfo( 'name' ) );
				$schema['url']  = esc_url( home_url( '/' ) );
				$logo_id        = get_theme_mod( 'custom_logo' );
				if ( $logo_id ) {
					$logo_url = wp_get_attachment_image_url( absint( $logo_id ), 'full' );
					if ( $logo_url ) {
						$schema['logo'] = esc_url( $logo_url );
					}
				}
				break;

			case 'Website':
				$schema['name']            = esc_html( get_bloginfo( 'name' ) );
				$schema['url']             = esc_url( home_url( '/' ) );
				$schema['potentialAction'] = array(
					'@type'       => 'SearchAction',
					'target'      => home_url( '/?s={search_term_string}' ),
					'query-input' => 'required name=search_term_string',
				);
				break;

			case 'BreadcrumbList':
				$crumbs   = array();
				$position = 1;
				if ( ! empty( $custom_data['breadcrumbs'] ) && is_array( $custom_data['breadcrumbs'] ) ) {
					foreach ( $custom_data['breadcrumbs'] as $crumb ) {
						if ( ! is_array( $crumb ) || empty( $crumb['name'] ) ) {
							continue;
						}
						$crumbs[] = array(
							'@type'    => 'ListItem',
							'position' => $position,
							'name'     => esc_html( wp_kses_post( $crumb['name'] ) ),
							'item'     => ! empty( $crumb['url'] ) ? esc_url_raw( $crumb['url'] ) : esc_url( home_url( '/' ) ),
						);
						++$position;
					}
				}
				if ( empty( $crumbs ) ) {
					$crumbs[] = array(
						'@type'    => 'ListItem',
						'position' => 1,
						'name'     => __( 'Home', 'mcp-ai-wpoos' ),
						'item'     => esc_url( home_url( '/' ) ),
					);
					$crumbs[] = array(
						'@type'    => 'ListItem',
						'position' => 2,
						'name'     => esc_html( $post->post_title ),
						'item'     => esc_url( $permalink ),
					);
				}
				$schema['itemListElement'] = $crumbs;
				break;

			case 'Event':
				if ( empty( $custom_data['startDate'] ) || empty( $custom_data['endDate'] ) ) {
					return new WP_Error( 'wp_mcp_ai_schema_dates_required', __( 'Event schema requires custom_data.startDate and custom_data.endDate in ISO 8601 format.', 'mcp-ai-wpoos' ) );
				}
				$schema['name']      = esc_html( $post->post_title );
				$schema['startDate'] = sanitize_text_field( $custom_data['startDate'] );
				$schema['endDate']   = sanitize_text_field( $custom_data['endDate'] );
				if ( ! empty( $custom_data['location'] ) ) {
					$schema['location'] = esc_html( wp_kses_post( $custom_data['location'] ) );
				}
				$schema['description'] = esc_html( $description );
				break;

			case 'Recipe':
				if ( empty( $custom_data['recipeIngredient'] ) || ! is_array( $custom_data['recipeIngredient'] ) || empty( $custom_data['recipeInstructions'] ) || ! is_array( $custom_data['recipeInstructions'] ) ) {
					return new WP_Error( 'wp_mcp_ai_schema_recipe_required', __( 'Recipe schema requires custom_data.recipeIngredient and custom_data.recipeInstructions arrays.', 'mcp-ai-wpoos' ) );
				}
				$schema['name']               = esc_html( $post->post_title );
				$schema['description']        = esc_html( $description );
				$schema['recipeIngredient']   = array_map( 'sanitize_text_field', $custom_data['recipeIngredient'] );
				$schema['recipeInstructions'] = array_map( 'wp_kses_post', $custom_data['recipeInstructions'] );
				if ( has_post_thumbnail( $post_id ) ) {
					$schema['image'] = esc_url( get_the_post_thumbnail_url( $post_id, 'full' ) );
				}
				break;

			case 'Person':
				$schema['name'] = esc_html( ! empty( $custom_data['name'] ) ? $custom_data['name'] : get_the_author_meta( 'display_name', (int) $post->post_author ) );
				$person_url     = ! empty( $custom_data['url'] ) ? $custom_data['url'] : get_author_posts_url( (int) $post->post_author );
				if ( $person_url ) {
					$schema['url'] = esc_url_raw( $person_url );
				}
				break;

			case 'LocalBusiness':
				$schema['name'] = esc_html( ! empty( $custom_data['name'] ) ? $custom_data['name'] : get_bloginfo( 'name' ) );
				$schema['url']  = esc_url( home_url( '/' ) );
				if ( ! empty( $custom_data['address'] ) ) {
					$schema['address'] = esc_html( wp_kses_post( $custom_data['address'] ) );
				}
				if ( ! empty( $custom_data['telephone'] ) ) {
					$schema['telephone'] = sanitize_text_field( $custom_data['telephone'] );
				}
				break;

			case 'Review':
				if ( ! empty( $custom_data['item'] ) ) {
					$schema['itemReviewed'] = array(
						'@type' => 'Thing',
						'name'  => esc_html( wp_kses_post( $custom_data['item'] ) ),
					);
				}
				if ( ! empty( $custom_data['rating'] ) ) {
					$schema['reviewRating'] = array(
						'@type'       => 'Rating',
						'ratingValue' => sanitize_text_field( $custom_data['rating'] ),
					);
				}
				$schema['author'] = array(
					'@type' => 'Person',
					'name'  => esc_html( get_the_author_meta( 'display_name', (int) $post->post_author ) ),
				);
				break;

			case 'VideoObject':
				if ( empty( $custom_data['contentUrl'] ) ) {
					return new WP_Error( 'wp_mcp_ai_schema_content_url_required', __( 'VideoObject schema requires custom_data.contentUrl pointing to the video file.', 'mcp-ai-wpoos' ) );
				}
				$schema['name']        = esc_html( $post->post_title );
				$schema['description'] = esc_html( $description );
				$schema['uploadDate']  = sanitize_text_field( ! empty( $custom_data['uploadDate'] ) ? $custom_data['uploadDate'] : get_post_time( DATE_W3C, true, $post ) );
				$schema['contentUrl']  = esc_url_raw( $custom_data['contentUrl'] );
				if ( ! empty( $custom_data['thumbnailUrl'] ) ) {
					$schema['thumbnailUrl'] = esc_url_raw( $custom_data['thumbnailUrl'] );
				} elseif ( has_post_thumbnail( $post_id ) ) {
					$schema['thumbnailUrl'] = esc_url( get_the_post_thumbnail_url( $post_id, 'full' ) );
				}
				break;

			case 'Course':
				$schema['name']        = esc_html( $post->post_title );
				$schema['description'] = esc_html( $description );
				if ( ! empty( $custom_data['provider'] ) ) {
					$schema['provider'] = array(
						'@type' => 'Organization',
						'name'  => esc_html( wp_kses_post( $custom_data['provider'] ) ),
					);
				}
				break;
		}

		$message = sprintf(
			/* translators: 1: schema type, 2: post title */
			__( 'Generated %1$s JSON-LD schema for "%2$s". This tool only generates the schema; it does not save it.', 'mcp-ai-wpoos' ),
			$schema_type,
			esc_html( get_the_title( $post ) )
		);

		return array(
			'message'     => $message,
			'schema'      => $schema,
			'schema_json' => wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		);
	}

	/**
	 * Recursively sanitizes custom_data input.
	 *
	 * @since 1.1.87
	 *
	 * @param array $data Raw custom data.
	 * @return array Sanitized custom data.
	 */
	private function sanitize_custom_data( $data ) {
		$clean = array();
		foreach ( $data as $key => $value ) {
			$key = sanitize_key( $key );
			if ( is_array( $value ) ) {
				$clean[ $key ] = $this->sanitize_custom_data( $value );
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = wp_kses_post( trim( wp_unslash( $value ) ) );
			} else {
				$clean[ $key ] = $value;
			}
		}
		return $clean;
	}
}
