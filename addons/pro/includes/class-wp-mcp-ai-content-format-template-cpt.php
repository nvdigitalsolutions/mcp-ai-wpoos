<?php
/**
 * Content Format Template Custom Post Type for managing AI blog post templates.
 *
 * Provides user-editable templates that control the output format of AI-generated
 * blog posts, including content type, tone, word count, heading structure,
 * required sections, and featured image style.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Content Format Template custom post type.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Content_Format_Template_CPT {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_content_template';

	/**
	 * Initialize the class.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_template_meta' ), 10, 2 );
	}

	/**
	 * Register the custom post type.
	 *
	 * @since 1.0.0
	 */
	public static function register_post_type() {
		$labels = array(
			'name'               => _x( 'Content Templates', 'Post type general name', 'mcp-ai-wpoos-pro' ),
			'singular_name'      => _x( 'Content Template', 'Post type singular name', 'mcp-ai-wpoos-pro' ),
			'menu_name'          => _x( 'Content Templates', 'Admin Menu text', 'mcp-ai-wpoos-pro' ),
			'add_new'            => __( 'Add New', 'mcp-ai-wpoos-pro' ),
			'add_new_item'       => __( 'Add New Content Template', 'mcp-ai-wpoos-pro' ),
			'edit_item'          => __( 'Edit Content Template', 'mcp-ai-wpoos-pro' ),
			'view_item'          => __( 'View Content Template', 'mcp-ai-wpoos-pro' ),
			'all_items'          => __( 'All Templates', 'mcp-ai-wpoos-pro' ),
			'search_items'       => __( 'Search Content Templates', 'mcp-ai-wpoos-pro' ),
			'not_found'          => __( 'No content templates found.', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash' => __( 'No content templates found in Trash.', 'mcp-ai-wpoos-pro' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => true,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => 27,
			'menu_icon'          => 'dashicons-edit-page',
			'supports'           => array( 'title' ),
			'show_in_rest'       => true,
		);

		register_post_type( self::POST_TYPE, $args );

		// Register meta fields for REST API exposure.
		self::register_meta_fields();
	}

	/**
	 * Register meta fields with show_in_rest for block editor / API access.
	 *
	 * @since 1.0.0
	 */
	protected static function register_meta_fields() {
		$meta_fields = array(
			'_content_type'            => 'string',
			'_target_word_count_min'   => 'integer',
			'_target_word_count_max'   => 'integer',
			'_tone'                    => 'string',
			'_target_audience'         => 'string',
			'_heading_structure'       => 'string', // JSON-encoded.
			'_required_sections'       => 'string', // JSON-encoded.
			'_featured_image_style'    => 'string',
			'_featured_image_provider' => 'string',
			'_custom_instructions'     => 'string',
			'_template_variables'      => 'string', // JSON-encoded.
		);

		foreach ( $meta_fields as $meta_key => $type ) {
			register_post_meta(
				self::POST_TYPE,
				$meta_key,
				array(
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Register meta boxes.
	 *
	 * @since 1.0.0
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'content_template_config',
			__( 'Template Configuration', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_config_metabox' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the configuration metabox.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_config_metabox( $post ) {
		wp_nonce_field( 'content_template_meta', 'content_template_meta_nonce' );

		$raw_type                    = get_post_meta( $post->ID, '_content_type', true );
			$content_type            = ! empty( $raw_type ) ? $raw_type : 'how-to';
		$target_word_count_min       = get_post_meta( $post->ID, '_target_word_count_min', true ) ?: 1500;
		$target_word_count_max       = get_post_meta( $post->ID, '_target_word_count_max', true ) ?: 2500;
		$raw_tone                    = get_post_meta( $post->ID, '_tone', true );
			$tone                    = ! empty( $raw_tone ) ? $raw_tone : 'professional';
		$target_audience             = (string) get_post_meta( $post->ID, '_target_audience', true );
		$raw_headings                = get_post_meta( $post->ID, '_heading_structure', true );
			$heading_structure       = ! empty( $raw_headings ) ? $raw_headings : '[]';
		$raw_sections                = get_post_meta( $post->ID, '_required_sections', true );
			$required_sections       = ! empty( $raw_sections ) ? $raw_sections : '{}';
		$raw_style                   = get_post_meta( $post->ID, '_featured_image_style', true );
			$featured_image_style    = ! empty( $raw_style ) ? $raw_style : 'photographic';
		$raw_provider                = get_post_meta( $post->ID, '_featured_image_provider', true );
			$featured_image_provider = ! empty( $raw_provider ) ? $raw_provider : 'openai';
		$custom_instructions         = (string) get_post_meta( $post->ID, '_custom_instructions', true );

		$headings     = json_decode( $heading_structure, true );
			$headings = is_array( $headings ) ? $headings : array();
		$sections     = json_decode( $required_sections, true );
			$sections = is_array( $sections ) ? $sections : array();
		$heading_text = ! empty( $headings ) ? implode( "\n", $headings ) : '';
		?>
		<table class="form-table">
			<tr>
				<th><label for="content_type"><?php esc_html_e( 'Content Type', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="content_type" id="content_type" class="regular-text">
						<?php
						$types = array( 'how-to', 'listicle', 'case_study', 'comparison', 'opinion', 'news', 'review', 'pillar_page' );
						foreach ( $types as $type ) :
							?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $content_type, $type ); ?>>
								<?php echo esc_html( ucwords( str_replace( '_', ' ', $type ) ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The content format determines the structure and style of the generated post.', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th><label for="target_word_count_min"><?php esc_html_e( 'Target Word Count', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="number" name="target_word_count_min" id="target_word_count_min" value="<?php echo esc_attr( $target_word_count_min ); ?>" min="100" max="10000" step="100" class="small-text" />
					<span><?php esc_html_e( 'to', 'mcp-ai-wpoos-pro' ); ?></span>
					<input type="number" name="target_word_count_max" id="target_word_count_max" value="<?php echo esc_attr( $target_word_count_max ); ?>" min="100" max="10000" step="100" class="small-text" />
					<span><?php esc_html_e( 'words', 'mcp-ai-wpoos-pro' ); ?></span>
				</td>
			</tr>

			<tr>
				<th><label for="tone"><?php esc_html_e( 'Tone / Voice', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="tone" id="tone" class="regular-text">
						<?php
						$tones = array( 'professional', 'conversational', 'technical', 'persuasive', 'journalistic' );
						foreach ( $tones as $t ) :
							?>
							<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $tone, $t ); ?>>
								<?php echo esc_html( ucfirst( $t ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<tr>
				<th><label for="target_audience"><?php esc_html_e( 'Target Audience', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" name="target_audience" id="target_audience" value="<?php echo esc_attr( $target_audience ); ?>" class="large-text" />
					<p class="description"><?php esc_html_e( 'Describe the intended audience (e.g., "small business owners", "enterprise IT managers").', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th><label for="heading_structure"><?php esc_html_e( 'Heading Structure', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<textarea name="heading_structure" id="heading_structure" rows="5" class="large-text" placeholder="<?php esc_attr_e( 'Introduction', 'mcp-ai-wpoos-pro' ) . "\n" . esc_attr_e( 'Key Benefits', 'mcp-ai-wpoos-pro' ) . "\n" . esc_attr_e( 'Step-by-Step Guide', 'mcp-ai-wpoos-pro' ) . "\n" . esc_attr_e( 'Common Mistakes', 'mcp-ai-wpoos-pro' ) . "\n" . esc_attr_e( 'Conclusion', 'mcp-ai-wpoos-pro' ); ?>"><?php echo esc_textarea( $heading_text ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One H2 section title per line. These will be used as the main headings for the generated post.', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th><?php esc_html_e( 'Required Sections', 'mcp-ai-wpoos-pro' ); ?></th>
				<td>
					<?php
					$section_options = array(
						'seo_title'        => __( 'SEO-optimised title with primary keyword', 'mcp-ai-wpoos-pro' ),
						'meta_description' => __( 'Meta description (150-160 chars)', 'mcp-ai-wpoos-pro' ),
						'intro_hook'       => __( 'Introduction with hook (statistic, question, or story)', 'mcp-ai-wpoos-pro' ),
						'data_points'      => __( '3-5 data points or statistics with citations', 'mcp-ai-wpoos-pro' ),
						'internal_links'   => __( 'Internal links to 2-3 related posts', 'mcp-ai-wpoos-pro' ),
						'schema_markup'    => __( 'Schema.org Article markup', 'mcp-ai-wpoos-pro' ),
						'author_bio'       => __( 'Author bio snippet', 'mcp-ai-wpoos-pro' ),
						'cta'              => __( 'Call-to-action at the end', 'mcp-ai-wpoos-pro' ),
						'featured_image'   => __( 'AI-generated featured image', 'mcp-ai-wpoos-pro' ),
					);
					foreach ( $section_options as $key => $label ) :
						$checked = isset( $sections[ $key ] ) ? (bool) $sections[ $key ] : true;
						?>
						<label style="display: block; margin-bottom: 4px;">
							<input type="checkbox" name="required_sections[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $checked ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>

			<tr>
				<th><label for="featured_image_style"><?php esc_html_e( 'Featured Image Style', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="featured_image_style" id="featured_image_style" class="regular-text">
						<?php
						$styles = array( 'photographic', 'illustration', 'abstract', 'infographic', 'minimal' );
						foreach ( $styles as $s ) :
							?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $featured_image_style, $s ); ?>>
								<?php echo esc_html( ucfirst( $s ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<tr>
				<th><label for="featured_image_provider"><?php esc_html_e( 'Image Generation Provider', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="featured_image_provider" id="featured_image_provider" class="regular-text">
						<?php
						$providers = array(
							'openai'     => __( 'OpenAI DALL-E', 'mcp-ai-wpoos-pro' ),
							'gemini'     => __( 'Google Gemini', 'mcp-ai-wpoos-pro' ),
							'cloudflare' => __( 'Cloudflare AI', 'mcp-ai-wpoos-pro' ),
						);
						foreach ( $providers as $slug => $label ) :
							?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $featured_image_provider, $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The AI image generation tool the assistant will use. OpenAI DALL-E is recommended for highest quality.', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th><label for="custom_instructions"><?php esc_html_e( 'Custom Instructions', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<textarea name="custom_instructions" id="custom_instructions" rows="4" class="large-text"><?php echo esc_textarea( $custom_instructions ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Additional instructions for the AI (e.g., brand guidelines, forbidden topics, specific formatting requirements).', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save template metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_template_meta( $post_id, $post ) {
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['content_template_meta_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['content_template_meta_nonce'] ) ), 'content_template_meta' )
		) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Simple scalar fields.
		$scalar_fields = array(
			'content_type'            => 'sanitize_key',
			'target_word_count_min'   => 'absint',
			'target_word_count_max'   => 'absint',
			'tone'                    => 'sanitize_key',
			'target_audience'         => 'sanitize_text_field',
			'featured_image_style'    => 'sanitize_key',
			'featured_image_provider' => 'sanitize_key',
			'custom_instructions'     => 'sanitize_textarea_field',
		);

		foreach ( $scalar_fields as $field => $sanitize_callback ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta(
					$post_id,
					'_' . $field,
					call_user_func( $sanitize_callback, wp_unslash( $_POST[ $field ] ) )
				);
			}
		}

		// Heading structure: lines → JSON array.
		if ( isset( $_POST['heading_structure'] ) ) {
			$raw      = sanitize_textarea_field( wp_unslash( $_POST['heading_structure'] ) );
			$lines    = explode( "\n", $raw );
			$headings = array();
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$headings[] = $line;
				}
			}
			update_post_meta( $post_id, '_heading_structure', wp_json_encode( $headings ) );
		}

		// Required sections: checkbox array → JSON object.
		$sections = array();
		if ( isset( $_POST['required_sections'] ) && is_array( $_POST['required_sections'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$raw_sections = wp_unslash( $_POST['required_sections'] );
			foreach ( $raw_sections as $key => $val ) {
				$sections[ sanitize_key( $key ) ] = (bool) $val;
			}
		}
		update_post_meta( $post_id, '_required_sections', wp_json_encode( $sections ) );

		// Template variables: optional.
		if ( isset( $_POST['template_variables'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_vars = wp_unslash( $_POST['template_variables'] );
			$vars     = json_decode( $raw_vars, true );
			if ( is_array( $vars ) ) {
				update_post_meta( $post_id, '_template_variables', wp_json_encode( $vars ) );
			}
		}
	}

	/**
	 * Get all available content format templates.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_Post[] Array of template post objects.
	 */
	public static function get_templates() {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Get a single template by slug (post_name) or ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string|int $identifier Template slug or post ID.
	 * @return WP_Post|null Template post object or null.
	 */
	public static function get_template( $identifier ) {
		if ( is_numeric( $identifier ) ) {
			return get_post( absint( $identifier ) );
		}

		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'name'           => sanitize_title( $identifier ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);

		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Get the full template data as an associative array.
	 *
	 * @since 1.0.0
	 *
	 * @param string|int $identifier Template slug or post ID.
	 * @return array|null Template data or null if not found.
	 */
	public static function get_template_data( $identifier ) {
		$post = self::get_template( $identifier );
		if ( ! $post ) {
			return null;
		}

		return array(
			'id'                      => $post->ID,
			'slug'                    => $post->post_name,
			'title'                   => $post->post_title,
			'content_type'            => get_post_meta( $post->ID, '_content_type', true ) ?: 'how-to',
			'target_word_count_min'   => absint( get_post_meta( $post->ID, '_target_word_count_min', true ) ) ?: 1500,
			'target_word_count_max'   => absint( get_post_meta( $post->ID, '_target_word_count_max', true ) ) ?: 2500,
			'tone'                    => get_post_meta( $post->ID, '_tone', true ) ?: 'professional',
			'target_audience'         => get_post_meta( $post->ID, '_target_audience', true ) ?: '',
			'heading_structure'       => json_decode( get_post_meta( $post->ID, '_heading_structure', true ), true ) ?: array(),
			'required_sections'       => json_decode( get_post_meta( $post->ID, '_required_sections', true ), true ) ?: array(),
			'featured_image_style'    => get_post_meta( $post->ID, '_featured_image_style', true ) ?: 'photographic',
			'featured_image_provider' => get_post_meta( $post->ID, '_featured_image_provider', true ) ?: 'openai',
			'custom_instructions'     => get_post_meta( $post->ID, '_custom_instructions', true ) ?: '',
			'template_variables'      => json_decode( get_post_meta( $post->ID, '_template_variables', true ), true ) ?: array(),
		);
	}

	/**
	 * Seed default templates if none exist.
	 *
	 * @since 1.0.0
	 */
	public static function seed_defaults() {
		$existing = self::get_templates();
		if ( ! empty( $existing ) ) {
			return;
		}

		$defaults = array(
			array(
				'title'                   => __( 'Standard Blog Post', 'mcp-ai-wpoos-pro' ),
				'slug'                    => 'standard-blog-post',
				'content_type'            => 'how-to',
				'target_word_count_min'   => 1500,
				'target_word_count_max'   => 2500,
				'tone'                    => 'professional',
				'heading_structure'       => array(
					__( 'Introduction', 'mcp-ai-wpoos-pro' ),
					__( 'Why This Matters', 'mcp-ai-wpoos-pro' ),
					__( 'Key Strategies', 'mcp-ai-wpoos-pro' ),
					__( 'Common Mistakes to Avoid', 'mcp-ai-wpoos-pro' ),
					__( 'Conclusion', 'mcp-ai-wpoos-pro' ),
				),
				'required_sections'       => array(
					'seo_title'        => true,
					'meta_description' => true,
					'intro_hook'       => true,
					'data_points'      => true,
					'internal_links'   => true,
					'schema_markup'    => true,
					'author_bio'       => true,
					'cta'              => true,
					'featured_image'   => true,
				),
				'featured_image_style'    => 'photographic',
				'featured_image_provider' => 'openai',
			),
			array(
				'title'                   => __( 'How-To Guide', 'mcp-ai-wpoos-pro' ),
				'slug'                    => 'how-to-guide',
				'content_type'            => 'how-to',
				'target_word_count_min'   => 1200,
				'target_word_count_max'   => 2000,
				'tone'                    => 'technical',
				'heading_structure'       => array(
					__( 'What You Will Learn', 'mcp-ai-wpoos-pro' ),
					__( 'Prerequisites', 'mcp-ai-wpoos-pro' ),
					__( 'Step 1', 'mcp-ai-wpoos-pro' ),
					__( 'Step 2', 'mcp-ai-wpoos-pro' ),
					__( 'Step 3', 'mcp-ai-wpoos-pro' ),
					__( 'Troubleshooting', 'mcp-ai-wpoos-pro' ),
					__( 'Next Steps', 'mcp-ai-wpoos-pro' ),
				),
				'required_sections'       => array(
					'seo_title'        => true,
					'meta_description' => true,
					'intro_hook'       => true,
					'data_points'      => false,
					'internal_links'   => true,
					'schema_markup'    => true,
					'author_bio'       => false,
					'cta'              => true,
					'featured_image'   => true,
				),
				'featured_image_style'    => 'illustration',
				'featured_image_provider' => 'openai',
			),
			array(
				'title'                   => __( 'Listicle', 'mcp-ai-wpoos-pro' ),
				'slug'                    => 'listicle',
				'content_type'            => 'listicle',
				'target_word_count_min'   => 800,
				'target_word_count_max'   => 1500,
				'tone'                    => 'conversational',
				'heading_structure'       => array(
					__( 'Introduction', 'mcp-ai-wpoos-pro' ),
					__( 'The List', 'mcp-ai-wpoos-pro' ),
					__( 'Key Takeaways', 'mcp-ai-wpoos-pro' ),
				),
				'required_sections'       => array(
					'seo_title'        => true,
					'meta_description' => true,
					'intro_hook'       => true,
					'data_points'      => true,
					'internal_links'   => false,
					'schema_markup'    => true,
					'author_bio'       => false,
					'cta'              => true,
					'featured_image'   => true,
				),
				'featured_image_style'    => 'infographic',
				'featured_image_provider' => 'openai',
			),
			array(
				'title'                   => __( 'Case Study', 'mcp-ai-wpoos-pro' ),
				'slug'                    => 'case-study',
				'content_type'            => 'case_study',
				'target_word_count_min'   => 1500,
				'target_word_count_max'   => 2500,
				'tone'                    => 'persuasive',
				'heading_structure'       => array(
					__( 'Executive Summary', 'mcp-ai-wpoos-pro' ),
					__( 'The Challenge', 'mcp-ai-wpoos-pro' ),
					__( 'The Solution', 'mcp-ai-wpoos-pro' ),
					__( 'Implementation', 'mcp-ai-wpoos-pro' ),
					__( 'Results', 'mcp-ai-wpoos-pro' ),
					__( 'Key Lessons', 'mcp-ai-wpoos-pro' ),
				),
				'required_sections'       => array(
					'seo_title'        => true,
					'meta_description' => true,
					'intro_hook'       => true,
					'data_points'      => true,
					'internal_links'   => false,
					'schema_markup'    => true,
					'author_bio'       => true,
					'cta'              => true,
					'featured_image'   => true,
				),
				'featured_image_style'    => 'photographic',
				'featured_image_provider' => 'openai',
			),
			array(
				'title'                   => __( 'News Roundup', 'mcp-ai-wpoos-pro' ),
				'slug'                    => 'news-roundup',
				'content_type'            => 'news',
				'target_word_count_min'   => 500,
				'target_word_count_max'   => 1000,
				'tone'                    => 'journalistic',
				'heading_structure'       => array(
					__( 'Top Stories This Week', 'mcp-ai-wpoos-pro' ),
					__( 'Industry Impact', 'mcp-ai-wpoos-pro' ),
					__( 'What to Watch Next Week', 'mcp-ai-wpoos-pro' ),
				),
				'required_sections'       => array(
					'seo_title'        => true,
					'meta_description' => true,
					'intro_hook'       => true,
					'data_points'      => false,
					'internal_links'   => true,
					'schema_markup'    => true,
					'author_bio'       => false,
					'cta'              => false,
					'featured_image'   => true,
				),
				'featured_image_style'    => 'minimal',
				'featured_image_provider' => 'openai',
			),
		);

		foreach ( $defaults as $template ) {
			$post_id = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_title'  => $template['title'],
					'post_name'   => $template['slug'],
					'post_status' => 'publish',
				)
			);

			if ( $post_id && ! is_wp_error( $post_id ) ) {
				update_post_meta( $post_id, '_content_type', $template['content_type'] );
				update_post_meta( $post_id, '_target_word_count_min', $template['target_word_count_min'] );
				update_post_meta( $post_id, '_target_word_count_max', $template['target_word_count_max'] );
				update_post_meta( $post_id, '_tone', $template['tone'] );
				update_post_meta( $post_id, '_heading_structure', wp_json_encode( $template['heading_structure'] ) );
				update_post_meta( $post_id, '_required_sections', wp_json_encode( $template['required_sections'] ) );
				update_post_meta( $post_id, '_featured_image_style', $template['featured_image_style'] );
				update_post_meta( $post_id, '_featured_image_provider', $template['featured_image_provider'] );
			}
		}
	}
}
