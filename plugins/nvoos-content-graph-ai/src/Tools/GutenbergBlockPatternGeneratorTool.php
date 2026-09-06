<?php
/**
 * Gutenberg Block Pattern Generator tool (D8 Cluster 2c-5 port of the
 * base plugin's WP_MCP_AI_Tool_Gutenberg_Block_Pattern_Generator —
 * byte-identical slug, schema, error codes, envelope, pattern templates,
 * and option storage key; per-mode hook/cache seams via
 * WordPressNativeTrait).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

/**
 * AI-powered block pattern creation with theme.json integration, FSE
 * support, pattern categories, and block variation generation.
 */
class GutenbergBlockPatternGeneratorTool extends AbstractAiTool {

	use WordPressNativeTrait;

	public function getSlug(): string {
		return 'gutenberg_block_pattern_generator';
	}

	public function getName(): string {
		return __( 'Gutenberg Block Pattern Generator', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'AI-powered block pattern creation with theme.json integration, FSE support, pattern categories, and responsive design following 2026 WordPress standards.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'         => array(
					'type'        => 'string',
					'description' => __( 'Action: generate_pattern, list_patterns, sync_theme_json, or validate_pattern', 'nvoos-content-graph-ai' ),
					'enum'        => array( 'generate_pattern', 'list_patterns', 'sync_theme_json', 'validate_pattern' ),
				),
				'pattern_type'   => array(
					'type'        => 'string',
					'description' => __( 'Pattern type: hero, call-to-action, testimonial, pricing, gallery, team, or custom', 'nvoos-content-graph-ai' ),
					'default'     => 'hero',
					'enum'        => array( 'hero', 'call-to-action', 'testimonial', 'pricing', 'gallery', 'team', 'custom' ),
				),
				'title'          => array(
					'type'        => 'string',
					'description' => __( 'Pattern title', 'nvoos-content-graph-ai' ),
				),
				'description'    => array(
					'type'        => 'string',
					'description' => __( 'Pattern description', 'nvoos-content-graph-ai' ),
				),
				'categories'     => array(
					'type'        => 'array',
					'description' => __( 'Pattern categories (featured, buttons, columns, text, etc.)', 'nvoos-content-graph-ai' ),
					'items'       => array( 'type' => 'string' ),
					'default'     => array( 'featured' ),
				),
				'keywords'       => array(
					'type'        => 'array',
					'description' => __( 'Search keywords for pattern discovery', 'nvoos-content-graph-ai' ),
					'items'       => array( 'type' => 'string' ),
				),
				'content'        => array(
					'type'        => 'string',
					'description' => __( 'Custom block content (HTML or block markup)', 'nvoos-content-graph-ai' ),
				),
				'viewport_width' => array(
					'type'        => 'integer',
					'description' => __( 'Pattern preview viewport width (default: 1280)', 'nvoos-content-graph-ai' ),
					'default'     => 1280,
				),
				'block_types'    => array(
					'type'        => 'array',
					'description' => __( 'Restrict pattern to specific block types', 'nvoos-content-graph-ai' ),
					'items'       => array( 'type' => 'string' ),
				),
				'inserter'       => array(
					'type'        => 'boolean',
					'description' => __( 'Show pattern in inserter (default: true)', 'nvoos-content-graph-ai' ),
					'default'     => true,
				),
				'sync_theme'     => array(
					'type'        => 'boolean',
					'description' => __( 'Sync with active theme.json settings', 'nvoos-content-graph-ai' ),
					'default'     => true,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array( 'write', 'state-changing', 'local-only' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$start_time = microtime( true );

		// Validate parameters.
		$action         = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'generate_pattern';
		$pattern_type   = isset( $arguments['pattern_type'] ) ? sanitize_text_field( $arguments['pattern_type'] ) : 'hero';
		$title          = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$description    = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$categories     = isset( $arguments['categories'] ) && is_array( $arguments['categories'] ) ? array_map( 'sanitize_text_field', $arguments['categories'] ) : array( 'featured' );
		$keywords       = isset( $arguments['keywords'] ) && is_array( $arguments['keywords'] ) ? array_map( 'sanitize_text_field', $arguments['keywords'] ) : array();
		$content        = isset( $arguments['content'] ) ? wp_kses_post( $arguments['content'] ) : '';
		$viewport_width = isset( $arguments['viewport_width'] ) ? absint( $arguments['viewport_width'] ) : 1280;
		$block_types    = isset( $arguments['block_types'] ) && is_array( $arguments['block_types'] ) ? array_map( 'sanitize_text_field', $arguments['block_types'] ) : array();
		$inserter       = isset( $arguments['inserter'] ) ? (bool) $arguments['inserter'] : true;
		$sync_theme     = isset( $arguments['sync_theme'] ) ? (bool) $arguments['sync_theme'] : true;

		// Before execution hook.
		$intercepted = $this->do_before_execute( $arguments, $context );
		if ( null !== $intercepted ) {
			return $intercepted;
		}

		// Route to action handler.
		switch ( $action ) {
			case 'generate_pattern':
				$result = $this->handle_generate_pattern( $pattern_type, $title, $description, $categories, $keywords, $content, $viewport_width, $block_types, $inserter, $sync_theme );
				break;

			case 'list_patterns':
				$result = $this->handle_list_patterns();
				break;

			case 'sync_theme_json':
				$result = $this->handle_sync_theme_json();
				break;

			case 'validate_pattern':
				$result = $this->handle_validate_pattern( $content );
				break;

			default:
				$result = new \WP_Error(
					'wp_mcp_ai_error',
					__( 'Invalid action specified', 'nvoos-content-graph-ai' )
				);
		}

		// After execution hook.
		$this->do_after_execute( $result, $arguments, $context );

		// Track performance.
		$this->track_performance( $start_time, $arguments );

		return $this->apply_result_filter( $result, $arguments, $context );
	}

	/**
	 * Handle generate pattern action.
	 *
	 * @param string $pattern_type   Pattern type.
	 * @param string $title          Title.
	 * @param string $description    Description.
	 * @param array  $categories     Categories.
	 * @param array  $keywords       Keywords.
	 * @param string $content        Content.
	 * @param int    $viewport_width Viewport width.
	 * @param array  $block_types    Block types.
	 * @param bool   $inserter       Show in inserter.
	 * @param bool   $sync_theme     Sync with theme.
	 * @return array Generation result.
	 */
	private function handle_generate_pattern( $pattern_type, $title, $description, $categories, $keywords, $content, $viewport_width, $block_types, $inserter, $sync_theme ) {
		// Generate content if not provided.
		if ( empty( $content ) ) {
			$content = $this->generate_pattern_content( $pattern_type );
		}

		// Sync with theme.json if enabled.
		if ( $sync_theme ) {
			$content = $this->apply_theme_json_styles( $content );
		}

		// Generate pattern slug.
		$slug = $this->generate_pattern_slug( $title, $pattern_type );

		// Register the pattern.
		$pattern_properties = array(
			'title'         => ! empty( $title ) ? $title : $this->get_default_title( $pattern_type ),
			'description'   => ! empty( $description ) ? $description : $this->get_default_description( $pattern_type ),
			'content'       => $content,
			'categories'    => $categories,
			'keywords'      => $keywords,
			'viewportWidth' => $viewport_width,
			'inserter'      => $inserter,
		);

		if ( ! empty( $block_types ) ) {
			$pattern_properties['blockTypes'] = $block_types;
		}

		// Register pattern.
		register_block_pattern( $slug, $pattern_properties );

		// Store pattern for persistence.
		$this->store_pattern( $slug, $pattern_properties );

		return array(
			'success'    => true,
			'slug'       => $slug,
			'title'      => $pattern_properties['title'],
			'pattern'    => $pattern_properties,
			'preview'    => $this->generate_pattern_preview( $content ),
			'usage'      => array(
				'inserter'  => __( 'Pattern is available in the block inserter', 'nvoos-content-graph-ai' ),
				'shortcode' => sprintf( '[pattern slug="%s"]', $slug ),
				'php'       => sprintf( "<?php echo do_blocks('<!-- wp:pattern {\"slug\":\"%s\"} /-->'); ?>", $slug ),
			),
			'theme_json' => $sync_theme ? __( 'Synced with theme.json settings', 'nvoos-content-graph-ai' ) : __( 'Independent styling', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Handle list patterns action.
	 *
	 * @return array List result.
	 */
	private function handle_list_patterns() {
		$patterns     = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();
		$pattern_list = array();

		foreach ( $patterns as $pattern ) {
			$pattern_list[] = array(
				'name'        => $pattern['name'],
				'title'       => $pattern['title'],
				'description' => isset( $pattern['description'] ) ? $pattern['description'] : '',
				'categories'  => isset( $pattern['categories'] ) ? $pattern['categories'] : array(),
				'keywords'    => isset( $pattern['keywords'] ) ? $pattern['keywords'] : array(),
				'inserter'    => isset( $pattern['inserter'] ) ? $pattern['inserter'] : true,
			);
		}

		return array(
			'success'    => true,
			'count'      => count( $pattern_list ),
			'patterns'   => $pattern_list,
			'categories' => $this->get_pattern_categories(),
		);
	}

	/**
	 * Handle sync theme.json action.
	 *
	 * @return array Sync result.
	 */
	private function handle_sync_theme_json() {
		$theme_json = $this->get_theme_json_data();

		if ( empty( $theme_json ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'No theme.json found in active theme', 'nvoos-content-graph-ai' )
			);
		}

		return array(
			'success'     => true,
			'theme_json'  => $theme_json,
			'settings'    => isset( $theme_json['settings'] ) ? $theme_json['settings'] : array(),
			'styles'      => isset( $theme_json['styles'] ) ? $theme_json['styles'] : array(),
			'patterns'    => isset( $theme_json['patterns'] ) ? $theme_json['patterns'] : array(),
			'integration' => array(
				'colors'     => $this->extract_theme_colors( $theme_json ),
				'typography' => $this->extract_theme_typography( $theme_json ),
				'spacing'    => $this->extract_theme_spacing( $theme_json ),
			),
		);
	}

	/**
	 * Handle validate pattern action.
	 *
	 * @param string $content Pattern content.
	 * @return array Validation result.
	 */
	private function handle_validate_pattern( $content ) {
		if ( empty( $content ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'No content provided for validation', 'nvoos-content-graph-ai' )
			);
		}

		$validation_results = array();
		$is_valid           = true;

		// Check if content contains valid blocks.
		if ( ! has_blocks( $content ) ) {
			$validation_results[] = array(
				'type'    => 'error',
				'message' => __( 'Content does not contain valid block markup', 'nvoos-content-graph-ai' ),
			);
			$is_valid             = false;
		}

		// Parse blocks.
		$blocks = parse_blocks( $content );

		// Validate block structure.
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) && ! empty( trim( $block['innerHTML'] ) ) ) {
				$validation_results[] = array(
					'type'    => 'warning',
					'message' => __( 'Found content outside of blocks', 'nvoos-content-graph-ai' ),
				);
			}

			// Check for deprecated blocks.
			if ( ! empty( $block['blockName'] ) && $this->is_block_deprecated( $block['blockName'] ) ) {
				$validation_results[] = array(
					'type'    => 'warning',
					'message' => sprintf(
						/* translators: %s: block name */
						__( 'Block %s is deprecated', 'nvoos-content-graph-ai' ),
						$block['blockName']
					),
				);
			}
		}

		// Check responsive design.
		$has_responsive = $this->check_responsive_attributes( $blocks );
		if ( ! $has_responsive ) {
			$validation_results[] = array(
				'type'    => 'info',
				'message' => __( 'Consider adding responsive attributes for mobile optimization', 'nvoos-content-graph-ai' ),
			);
		}

		// Check accessibility.
		$accessibility_issues = $this->check_accessibility( $blocks );
		$validation_results   = array_merge( $validation_results, $accessibility_issues );

		return array(
			'success'     => $is_valid,
			'valid'       => $is_valid,
			'blocks'      => count( $blocks ),
			'validations' => $validation_results,
			'summary'     => array(
				'errors'   => count( array_filter( $validation_results, fn( $r ) => 'error' === $r['type'] ) ),
				'warnings' => count( array_filter( $validation_results, fn( $r ) => 'warning' === $r['type'] ) ),
				'info'     => count( array_filter( $validation_results, fn( $r ) => 'info' === $r['type'] ) ),
			),
		);
	}

	/**
	 * Generate pattern content based on type.
	 *
	 * @param string $pattern_type Pattern type.
	 * @return string Block content.
	 */
	private function generate_pattern_content( $pattern_type ) {
		$templates = array(
			'hero'           => $this->get_hero_template(),
			'call-to-action' => $this->get_cta_template(),
			'testimonial'    => $this->get_testimonial_template(),
			'pricing'        => $this->get_pricing_template(),
			'gallery'        => $this->get_gallery_template(),
			'team'           => $this->get_team_template(),
			'custom'         => '<!-- wp:paragraph --><p>Custom pattern content</p><!-- /wp:paragraph -->',
		);

		return isset( $templates[ $pattern_type ] ) ? $templates[ $pattern_type ] : $templates['custom'];
	}

	/**
	 * Get hero template.
	 *
	 * @return string Hero block markup.
	 */
	private function get_hero_template() {
		return '<!-- wp:cover {"url":"","dimRatio":50,"overlayColor":"black","minHeight":600,"contentPosition":"center center","isDark":true,"align":"full"} -->
<div class="wp-block-cover alignfull is-light" style="min-height:600px"><span aria-hidden="true" class="wp-block-cover__background has-black-background-color has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:heading {"textAlign":"center","level":1} -->
<h1 class="wp-block-heading has-text-align-center">Welcome to Our Site</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">Discover amazing content and transform your experience</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-fill"} -->
<div class="wp-block-button is-style-fill"><a class="wp-block-button__link wp-element-button">Get Started</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div></div>
<!-- /wp:cover -->';
	}

	/**
	 * Get call-to-action template.
	 *
	 * @return string CTA block markup.
	 */
	private function get_cta_template() {
		return '<!-- wp:group {"align":"full","backgroundColor":"primary","textColor":"white","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-white-color has-primary-background-color has-text-color has-background"><!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">Ready to Get Started?</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">Join thousands of satisfied customers today</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"white","textColor":"primary"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-primary-color has-white-background-color has-text-color has-background wp-element-button">Sign Up Now</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->';
	}

	/**
	 * Get testimonial template.
	 *
	 * @return string Testimonial block markup.
	 */
	private function get_testimonial_template() {
		return '<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:quote -->
<blockquote class="wp-block-quote"><p>This product changed my life! Highly recommended.</p><cite>Jane Doe</cite></blockquote>
<!-- /wp:quote --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:quote -->
<blockquote class="wp-block-quote"><p>Outstanding service and support. Five stars!</p><cite>John Smith</cite></blockquote>
<!-- /wp:quote --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->';
	}

	/**
	 * Get pricing template.
	 *
	 * @return string Pricing block markup.
	 */
	private function get_pricing_template() {
		return '<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide"><!-- wp:column {"className":"pricing-card"} -->
<div class="wp-block-column pricing-card"><!-- wp:heading {"textAlign":"center","level":3} -->
<h3 class="wp-block-heading has-text-align-center">Basic</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","fontSize":"large"} -->
<p class="has-text-align-center has-large-font-size"><strong>$9.99/mo</strong></p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><li>Feature 1</li><li>Feature 2</li><li>Feature 3</li></ul>
<!-- /wp:list -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Choose Plan</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->';
	}

	/**
	 * Get gallery template.
	 *
	 * @return string Gallery block markup.
	 */
	private function get_gallery_template() {
		return '<!-- wp:gallery {"columns":3,"linkTo":"none","align":"wide"} -->
<figure class="wp-block-gallery alignwide has-nested-images columns-3 is-cropped"><!-- wp:image -->
<figure class="wp-block-image"><img alt=""/></figure>
<!-- /wp:image -->

<!-- wp:image -->
<figure class="wp-block-image"><img alt=""/></figure>
<!-- /wp:image -->

<!-- wp:image -->
<figure class="wp-block-image"><img alt=""/></figure>
<!-- /wp:image --></figure>
<!-- /wp:gallery -->';
	}

	/**
	 * Get team template.
	 *
	 * @return string Team block markup.
	 */
	private function get_team_template() {
		return '<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:image {"align":"center","className":"is-style-rounded"} -->
<figure class="wp-block-image aligncenter is-style-rounded"><img alt="Team Member"/></figure>
<!-- /wp:image -->

<!-- wp:heading {"textAlign":"center","level":4} -->
<h4 class="wp-block-heading has-text-align-center">Team Member Name</h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">Position</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->';
	}

	/**
	 * Apply theme.json styles to content.
	 *
	 * @param string $content Block content.
	 * @return string Styled content.
	 */
	private function apply_theme_json_styles( $content ) {
		$theme_json = $this->get_theme_json_data();

		if ( empty( $theme_json ) ) {
			return $content;
		}

		// Apply theme colors and typography settings.
		return $content;
	}

	/**
	 * Generate pattern slug.
	 *
	 * @param string $title        Pattern title.
	 * @param string $pattern_type Pattern type.
	 * @return string Pattern slug.
	 */
	private function generate_pattern_slug( $title, $pattern_type ) {
		$slug = ! empty( $title ) ? sanitize_title( $title ) : $pattern_type;
		return 'wp-mcp-ai/' . $slug . '-' . time();
	}

	/**
	 * Get default title for pattern type.
	 *
	 * @param string $pattern_type Pattern type.
	 * @return string Default title.
	 */
	private function get_default_title( $pattern_type ) {
		$titles = array(
			'hero'           => __( 'Hero Section', 'nvoos-content-graph-ai' ),
			'call-to-action' => __( 'Call to Action', 'nvoos-content-graph-ai' ),
			'testimonial'    => __( 'Testimonials', 'nvoos-content-graph-ai' ),
			'pricing'        => __( 'Pricing Table', 'nvoos-content-graph-ai' ),
			'gallery'        => __( 'Image Gallery', 'nvoos-content-graph-ai' ),
			'team'           => __( 'Team Members', 'nvoos-content-graph-ai' ),
			'custom'         => __( 'Custom Pattern', 'nvoos-content-graph-ai' ),
		);

		return isset( $titles[ $pattern_type ] ) ? $titles[ $pattern_type ] : $titles['custom'];
	}

	/**
	 * Get default description for pattern type.
	 *
	 * @param string $pattern_type Pattern type.
	 * @return string Default description.
	 */
	private function get_default_description( $pattern_type ) {
		$descriptions = array(
			'hero'           => __( 'A full-width hero section with heading, description, and call-to-action button', 'nvoos-content-graph-ai' ),
			'call-to-action' => __( 'Engaging call-to-action section to drive conversions', 'nvoos-content-graph-ai' ),
			'testimonial'    => __( 'Customer testimonials in a multi-column layout', 'nvoos-content-graph-ai' ),
			'pricing'        => __( 'Pricing tables with features and call-to-action buttons', 'nvoos-content-graph-ai' ),
			'gallery'        => __( 'Responsive image gallery with grid layout', 'nvoos-content-graph-ai' ),
			'team'           => __( 'Team member profiles with images and descriptions', 'nvoos-content-graph-ai' ),
			'custom'         => __( 'Custom block pattern', 'nvoos-content-graph-ai' ),
		);

		return isset( $descriptions[ $pattern_type ] ) ? $descriptions[ $pattern_type ] : $descriptions['custom'];
	}

	/**
	 * Store pattern for persistence (base-identical option key).
	 *
	 * @param string $slug       Pattern slug.
	 * @param array  $properties Pattern properties.
	 * @return void
	 */
	private function store_pattern( $slug, $properties ) {
		$patterns          = get_option( 'wp_mcp_ai_custom_patterns', array() );
		$patterns[ $slug ] = $properties;
		update_option( 'wp_mcp_ai_custom_patterns', $patterns );
	}

	/**
	 * Generate pattern preview.
	 *
	 * @param string $content Block content.
	 * @return string Preview HTML.
	 */
	private function generate_pattern_preview( $content ) {
		return do_blocks( $content );
	}

	/**
	 * Get theme.json data.
	 *
	 * @return array Theme JSON data.
	 */
	private function get_theme_json_data() {
		$theme_json_path = get_stylesheet_directory() . '/theme.json';

		if ( ! file_exists( $theme_json_path ) ) {
			return array();
		}

		$theme_json_string = file_get_contents( $theme_json_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
		return json_decode( $theme_json_string, true );
	}

	/**
	 * Extract theme colors.
	 *
	 * @param array $theme_json Theme JSON data.
	 * @return array Color palette.
	 */
	private function extract_theme_colors( $theme_json ) {
		return isset( $theme_json['settings']['color']['palette'] ) ? $theme_json['settings']['color']['palette'] : array();
	}

	/**
	 * Extract theme typography.
	 *
	 * @param array $theme_json Theme JSON data.
	 * @return array Typography settings.
	 */
	private function extract_theme_typography( $theme_json ) {
		return isset( $theme_json['settings']['typography'] ) ? $theme_json['settings']['typography'] : array();
	}

	/**
	 * Extract theme spacing.
	 *
	 * @param array $theme_json Theme JSON data.
	 * @return array Spacing settings.
	 */
	private function extract_theme_spacing( $theme_json ) {
		return isset( $theme_json['settings']['spacing'] ) ? $theme_json['settings']['spacing'] : array();
	}

	/**
	 * Get pattern categories.
	 *
	 * @return array Pattern categories.
	 */
	private function get_pattern_categories() {
		$categories = \WP_Block_Pattern_Categories_Registry::get_instance()->get_all_registered();
		return array_map(
			fn( $cat ) => array(
				'name'  => $cat['name'],
				'label' => $cat['label'],
			),
			$categories
		);
	}

	/**
	 * Check if block is deprecated.
	 *
	 * @param string $block_name Block name.
	 * @return bool True if deprecated.
	 */
	private function is_block_deprecated( $block_name ) {
		// List of deprecated blocks.
		$deprecated = array(
			'core/legacy-widget',
		);

		return in_array( $block_name, $deprecated, true );
	}

	/**
	 * Check responsive attributes.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return bool True if has responsive attributes.
	 */
	private function check_responsive_attributes( $blocks ) {
		foreach ( $blocks as $block ) {
			if ( isset( $block['attrs']['align'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check accessibility.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array Accessibility issues.
	 */
	private function check_accessibility( $blocks ) {
		$issues = array();

		foreach ( $blocks as $block ) {
			// Check images for alt text.
			if ( 'core/image' === $block['blockName'] ) {
				if ( empty( $block['attrs']['alt'] ) ) {
					$issues[] = array(
						'type'    => 'warning',
						'message' => __( 'Image missing alt text', 'nvoos-content-graph-ai' ),
					);
				}
			}

			// Check heading hierarchy.
			if ( 'core/heading' === $block['blockName'] ) {
				if ( ! isset( $block['attrs']['level'] ) ) {
					$issues[] = array(
						'type'    => 'info',
						'message' => __( 'Heading level not explicitly set', 'nvoos-content-graph-ai' ),
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * Whether the tool holds privacy-relevant user data (base-identical).
	 *
	 * @return bool False - no privacy data.
	 */
	public function has_privacy_data() {
		return false;
	}

	/**
	 * Log an activity event (per-mode seam).
	 *
	 * @param string $type    Event type.
	 * @param string $message Event message.
	 * @param array  $data    Event context.
	 * @return void
	 */
	private function log_event( $type, $message, array $data = array() ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $type, $message, $data );
		}
	}

	/**
	 * Log an error event (per-mode seam).
	 *
	 * @param string $message Error message.
	 * @param array  $data    Error context.
	 * @return void
	 */
	private function log_error( $message, array $data = array() ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_error( $message, $data );
		}
	}
}
