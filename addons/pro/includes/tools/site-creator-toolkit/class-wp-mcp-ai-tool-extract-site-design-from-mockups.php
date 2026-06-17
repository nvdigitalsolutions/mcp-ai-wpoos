<?php
/**
 * Tool: extract_site_design_from_mockups.
 *
 * Pro-tier Site Creator tool that ingests a bag of inputs (mockup images,
 * HTML/CSS reference files, optional live URLs, free-text brief) and produces
 * a single install-ready PHP "site design snippet" — design tokens + small JS
 * interaction layer + JFB form skin — shaped exactly like the Aerlinn-style
 * examples bundled with the planning ticket.
 *
 * The tool is intentionally orchestration-heavy: the heavy lifting lives in
 * {@see WP_MCP_AI_Design_Extractor_Service} (input → Design System JSON) and
 * {@see WP_MCP_AI_Design_Snippet_Renderer} (Design System JSON → PHP file).
 * The tool itself only validates inputs, enforces capability + settings gates,
 * coordinates the two services, and optionally hands the result off to the
 * existing `create_wpcode_snippet` tool / `wp_site_template` CPT /
 * `WP_MCP_AI_Theme_JSON_Generator` helper.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Site_Creator_Toolkit
 * @since      1.2.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Always available — the helpers are pure-PHP utilities.
require_once dirname( __DIR__, 2 ) . '/site-creator-toolkit/class-wp-mcp-ai-design-extractor-service.php';
require_once dirname( __DIR__, 2 ) . '/site-creator-toolkit/class-wp-mcp-ai-design-snippet-renderer.php';

/**
 * extract_site_design_from_mockups tool.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Extract_Site_Design_From_Mockups implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'extract_site_design_from_mockups';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Extract Site Design From Mockups', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __(
			'Analyze mockup images, HTML/CSS reference files and/or live URLs to extract a design system (palette, typography, spacing, radii, shadows, motion, JFB form skin) and emit a single install-ready PHP "site design snippet" that runs on top of WordPress, Elementor, and JetFormBuilder. Optionally persists as a WPCode snippet, a Site Template CPT row, and/or a theme.json partial.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'inputs'       => array(
					'type'        => 'object',
					'description' => __( 'Input bag — at least one of images, html_files, urls or brief should be supplied.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'images'     => array(
							'type'        => 'array',
							'description' => __( 'Mockup images. Vision is only called for role=mockup|reference.', 'mcp-ai-wpoos-pro' ),
							'maxItems'    => 8,
							'items'       => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'media_id' => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
									'url'      => array( 'type' => 'string' ),
									'base64'   => array( 'type' => 'string' ),
									'role'     => array(
										'type' => 'string',
										'enum' => array( 'mockup', 'logo', 'reference' ),
									),
								),
							),
						),
						'html_files' => array(
							'type'        => 'array',
							'description' => __( 'HTML or CSS reference files (sanitized + parsed; never executed).', 'mcp-ai-wpoos-pro' ),
							'items'       => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'media_id' => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
									'url'      => array( 'type' => 'string' ),
									'content'  => array( 'type' => 'string' ),
								),
							),
						),
						'urls'       => array(
							'type'        => 'array',
							'description' => __( 'Live URLs analyzed via the existing analyze_competitor_sites tool.', 'mcp-ai-wpoos-pro' ),
							'items'       => array( 'type' => 'string' ),
						),
						'brief'      => array(
							'type'        => 'string',
							'description' => __( 'Free-text brief describing brand voice, target stack, etc.', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'targets'      => array(
					'type'        => 'array',
					'description' => __( 'Target stacks; defaults to all three.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'wordpress', 'elementor', 'jet-form-builder' ),
					),
				),
				'skin_variant' => array(
					'type'        => 'string',
					'description' => __( 'JFB skin variant. "auto" picks based on extracted radius/saturation.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'luxury', 'panel', 'minimal', 'auto' ),
					'default'     => 'auto',
				),
				'features'     => array(
					'type'        => 'array',
					'description' => __( 'Opt-in interaction list.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'custom_cursor', 'scroll_reveal', 'header_scroll_state', 'mobile_drawer', 'rotating_steps', 'hover_link_underline' ),
					),
				),
				'output'       => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'format'                   => array(
							'type'    => 'string',
							'enum'    => array( 'php_snippet', 'package' ),
							'default' => 'php_snippet',
						),
						'persist_as_wpcode'        => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'persist_as_site_template' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'write_theme_json_partial' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				'dry_run'      => array(
					'type'        => 'boolean',
					'description' => __( 'When true, persistence flags are honored but no rows are written.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'cacheable', 'external-api', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// 1. Capability gate.
		$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$can     = $user_id ? user_can( $user_id, 'manage_options' ) : current_user_can( 'manage_options' );
		if ( ! $can ) {
			return new WP_Error( 'forbidden', __( 'Permission denied. extract_site_design_from_mockups requires manage_options.', 'mcp-ai-wpoos-pro' ) );
		}

		// 2. Settings gate.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error( 'site_creator_toolkit_disabled', __( 'Enable the Site Creator Toolkit before running this tool.', 'mcp-ai-wpoos-pro' ) );
		}
		// The extractor sub-feature gate (default off — opt-in because it consumes vision tokens).
		if ( empty( $settings['enable_design_extractor'] ) ) {
			return new WP_Error( 'design_extractor_disabled', __( 'Enable the Design Extractor in Site Creator Toolkit settings before running this tool.', 'mcp-ai-wpoos-pro' ) );
		}

		// 3. Sanitize inputs.
		$inputs = isset( $arguments['inputs'] ) && is_array( $arguments['inputs'] ) ? $arguments['inputs'] : array();
		$inputs = $this->sanitize_inputs( $inputs );

		$targets = isset( $arguments['targets'] ) && is_array( $arguments['targets'] )
			? array_values( array_intersect( WP_MCP_AI_Design_Snippet_Renderer::TARGETS, array_map( 'sanitize_text_field', $arguments['targets'] ) ) )
			: WP_MCP_AI_Design_Snippet_Renderer::TARGETS;

		$features = isset( $arguments['features'] ) && is_array( $arguments['features'] )
			? array_values( array_intersect( WP_MCP_AI_Design_Snippet_Renderer::FEATURES, array_map( 'sanitize_text_field', $arguments['features'] ) ) )
			: array( 'scroll_reveal', 'header_scroll_state', 'hover_link_underline' );

		$skin_variant = isset( $arguments['skin_variant'] ) ? sanitize_key( $arguments['skin_variant'] ) : 'auto';
		if ( ! in_array( $skin_variant, array_merge( WP_MCP_AI_Design_Snippet_Renderer::SKIN_VARIANTS, array( 'auto' ) ), true ) ) {
			$skin_variant = 'auto';
		}

		$output  = isset( $arguments['output'] ) && is_array( $arguments['output'] ) ? $arguments['output'] : array();
		$format  = isset( $output['format'] ) && 'package' === $output['format'] ? 'package' : 'php_snippet';
		$dry_run = ! empty( $arguments['dry_run'] );

		// 4. Extract Design System JSON.
		$extractor = new WP_MCP_AI_Design_Extractor_Service();
		$extracted = $extractor->extract( $inputs );

		$design_system   = $extracted['design_system'];
		$contrast_report = $extracted['contrast_report'];
		$is_draft        = ! empty( $extracted['is_draft'] );
		$warnings        = $extracted['warnings'];
		$provenance      = $extracted['_provenance'];

		// 5. Render snippet.
		$picked_variant = WP_MCP_AI_Design_Snippet_Renderer::pick_skin_variant( $design_system, $skin_variant );

		$fingerprint = substr(
			md5(
				wp_json_encode(
					array(
						'ds'       => $design_system,
						'features' => $features,
						'variant'  => $picked_variant,
					)
				)
			),
			0,
			12
		);
		$snippet     = WP_MCP_AI_Design_Snippet_Renderer::render(
			$design_system,
			array(
				'features'        => $features,
				'targets'         => $targets,
				'skin_variant'    => $skin_variant,
				'is_draft'        => $is_draft,
				'fingerprint'     => $fingerprint,
				'provenance'      => $provenance,
				'contrast_report' => $contrast_report,
			)
		);

		$result = array(
			'success'            => true,
			'design_system'      => $design_system,
			'contrast_report'    => $contrast_report,
			'is_draft'           => $is_draft,
			'warnings'           => $warnings,
			'snippet'            => $snippet,
			'fingerprint'        => $fingerprint,
			'skin_variant'       => $picked_variant,
			'features'           => $features,
			'targets'            => $targets,
			'persisted'          => array(),
			'apply_to_elementor' => $this->build_elementor_classes_hint( $features ),
		);

		if ( 'package' === $format ) {
			$result['package'] = array(
				'tokens_css'         => WP_MCP_AI_Design_Snippet_Renderer::render_tokens_css( $design_system ),
				'interactions_js'    => WP_MCP_AI_Design_Snippet_Renderer::render_interactions_js( $features ),
				'interactions_css'   => WP_MCP_AI_Design_Snippet_Renderer::render_interactions_css( $features ),
				'jfb_css'            => in_array( 'jet-form-builder', $targets, true )
					? WP_MCP_AI_Design_Snippet_Renderer::render_jfb_skin_css( $picked_variant )
					: '',
				'theme_json_partial' => $this->build_theme_json_partial( $design_system ),
			);
		}

		// 6. Persistence (skipped on dry_run).
		if ( ! empty( $output['write_theme_json_partial'] ) ) {
			$result['theme_json_partial'] = $this->build_theme_json_partial( $design_system );
		}

		if ( $dry_run ) {
			$result['persisted']['dry_run'] = true;
		} else {
			if ( ! empty( $output['persist_as_wpcode'] ) ) {
				$persist = $this->persist_as_wpcode( $snippet, $fingerprint );
				if ( is_wp_error( $persist ) ) {
					$result['warnings'][] = $persist->get_error_message();
				} else {
					$result['persisted']['wpcode_snippet_id'] = $persist;
				}
			}

			if ( ! empty( $output['persist_as_site_template'] ) ) {
				$persist = $this->persist_as_site_template( $design_system, $snippet, $fingerprint, $picked_variant );
				if ( is_wp_error( $persist ) ) {
					$result['warnings'][] = $persist->get_error_message();
				} else {
					$result['persisted']['site_template_post_id'] = $persist;
				}
			}
		}

		// 7. Activity log (no brief text, only counts).
		if ( function_exists( 'wp_mcp_ai_log_activity' ) ) {
			wp_mcp_ai_log_activity(
				'extract_site_design_from_mockups',
				array(
					'images'      => count( isset( $inputs['images'] ) ? $inputs['images'] : array() ),
					'html_files'  => count( isset( $inputs['html_files'] ) ? $inputs['html_files'] : array() ),
					'urls'        => count( isset( $inputs['urls'] ) ? $inputs['urls'] : array() ),
					'features'    => $features,
					'is_draft'    => $is_draft,
					'fingerprint' => $fingerprint,
				)
			);
		}

		return $result;
	}

	/**
	 * Sanitize the `inputs` sub-bag.
	 *
	 * @param array $inputs Raw inputs.
	 * @return array Sanitized inputs.
	 */
	private function sanitize_inputs( array $inputs ) {
		$out = array();

		if ( ! empty( $inputs['images'] ) && is_array( $inputs['images'] ) ) {
			$out['images'] = array();
			foreach ( $inputs['images'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$clean = array();
				if ( isset( $row['media_id'] ) ) {
					$clean['media_id'] = absint( $row['media_id'] );
				}
				if ( isset( $row['url'] ) && function_exists( 'wp_http_validate_url' ) ) {
					$url = wp_http_validate_url( (string) $row['url'] );
					if ( $url ) {
						$clean['url'] = esc_url_raw( $url );
					}
				}
				if ( isset( $row['base64'] ) && is_string( $row['base64'] ) ) {
					// Cap at 4MB of base64 (~3MB binary).
					$clean['base64'] = substr( preg_replace( '/[^a-zA-Z0-9+\/=]/', '', $row['base64'] ), 0, 4 * 1024 * 1024 );
				}
				$role = isset( $row['role'] ) ? sanitize_key( $row['role'] ) : 'mockup';
				if ( in_array( $role, array( 'mockup', 'logo', 'reference' ), true ) ) {
					$clean['role'] = $role;
				}
				if ( ! empty( $clean ) ) {
					$out['images'][] = $clean;
				}
			}
		}

		if ( ! empty( $inputs['html_files'] ) && is_array( $inputs['html_files'] ) ) {
			$out['html_files'] = array();
			foreach ( $inputs['html_files'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$clean = array();
				if ( isset( $row['media_id'] ) ) {
					$clean['media_id'] = absint( $row['media_id'] );
				}
				if ( isset( $row['url'] ) && function_exists( 'wp_http_validate_url' ) ) {
					$url = wp_http_validate_url( (string) $row['url'] );
					if ( $url ) {
						$clean['url'] = esc_url_raw( $url );
					}
				}
				if ( isset( $row['content'] ) && is_string( $row['content'] ) ) {
					// Hard cap 1MB; stripped of NUL bytes.
					$clean['content'] = substr( str_replace( "\0", '', $row['content'] ), 0, 1024 * 1024 );
				}
				if ( ! empty( $clean ) ) {
					$out['html_files'][] = $clean;
				}
			}
		}

		if ( ! empty( $inputs['urls'] ) && is_array( $inputs['urls'] ) ) {
			$out['urls'] = array();
			foreach ( $inputs['urls'] as $url ) {
				if ( ! is_string( $url ) ) {
					continue;
				}
				$url = function_exists( 'wp_http_validate_url' ) ? wp_http_validate_url( $url ) : $url;
				if ( $url ) {
					$out['urls'][] = esc_url_raw( $url );
				}
			}
		}

		if ( isset( $inputs['brief'] ) && is_string( $inputs['brief'] ) ) {
			$out['brief'] = sanitize_textarea_field( substr( $inputs['brief'], 0, 4000 ) );
		}

		return $out;
	}

	/**
	 * Build a theme.json partial via the existing Theme JSON Generator helper.
	 *
	 * @param array $design_system Design System JSON.
	 * @return array Theme.json partial (or empty if helper unavailable).
	 */
	private function build_theme_json_partial( array $design_system ) {
		if ( ! class_exists( 'WP_MCP_AI_Theme_JSON_Generator' ) ) {
			return array();
		}

		$palette = array();
		if ( ! empty( $design_system['palette'] ) && is_array( $design_system['palette'] ) ) {
			foreach ( $design_system['palette'] as $role => $color ) {
				$palette[] = array(
					'slug'  => sanitize_key( 'nv-' . $role ),
					'name'  => ucfirst( str_replace( '-', ' ', $role ) ),
					'color' => WP_MCP_AI_Design_Snippet_Renderer::sanitize_color( $color ),
				);
			}
		}

		$args = array(
			'theme_name'    => 'NV Site Design',
			'color_palette' => $palette,
		);

		// Theme_JSON_Generator::generate is a static helper.
		$theme_json = WP_MCP_AI_Theme_JSON_Generator::generate( $args );

		// Surface tokens under settings.custom for parity with the snippet's :root vars.
		if ( is_array( $theme_json ) ) {
			if ( ! isset( $theme_json['settings'] ) || ! is_array( $theme_json['settings'] ) ) {
				$theme_json['settings'] = array();
			}
			$theme_json['settings']['custom'] = array(
				'nv' => $design_system,
			);
		}

		return is_array( $theme_json ) ? $theme_json : array();
	}

	/**
	 * Persist the snippet as a WPCode snippet via the existing tool.
	 *
	 * @param string $snippet     Generated PHP snippet.
	 * @param string $fingerprint Short fingerprint used for the title.
	 * @return int|WP_Error Snippet post ID on success.
	 */
	private function persist_as_wpcode( $snippet, $fingerprint ) {
		if ( ! function_exists( 'wp_mcp_ai_get_tool_registry' ) ) {
			return new WP_Error( 'wpcode_unavailable', __( 'Tool registry unavailable.', 'mcp-ai-wpoos-pro' ) );
		}
		$registry = wp_mcp_ai_get_tool_registry();
		if ( ! $registry ) {
			return new WP_Error( 'wpcode_unavailable', __( 'Tool registry unavailable.', 'mcp-ai-wpoos-pro' ) );
		}
		$tool = $registry->get_tool( 'create_wpcode_snippet' );
		if ( ! $tool ) {
			return new WP_Error( 'wpcode_unavailable', __( 'WPCode is not active; cannot persist snippet.', 'mcp-ai-wpoos-pro' ) );
		}

		// The snippet starts with `<?php`; WPCode's php executor expects the body without the opener.
		$body = $snippet;
		if ( 0 === strpos( $body, '<?php' ) ) {
			$body = substr( $body, 5 );
		}

		$response = $tool->execute(
			array(
				'title'       => 'NV Site Design Snippet (' . $fingerprint . ')',
				'code'        => $body,
				'code_type'   => 'php',
				'auto_insert' => true,
				'location'    => 'everywhere',
				'activate'    => false,
				'tags'        => array( 'nv-site-design', 'extracted' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( is_array( $response ) && isset( $response['id'] ) ) {
			return (int) $response['id'];
		}
		return new WP_Error( 'wpcode_unknown_response', __( 'WPCode tool returned an unexpected response.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Persist the snippet + JSON as a `wp_site_template` CPT entry.
	 *
	 * @param array  $design_system Design System JSON.
	 * @param string $snippet       Generated PHP snippet.
	 * @param string $fingerprint   Fingerprint.
	 * @param string $variant       Selected variant.
	 * @return int|WP_Error
	 */
	private function persist_as_site_template( array $design_system, $snippet, $fingerprint, $variant ) {
		if ( ! post_type_exists( 'wp_site_template' ) ) {
			return new WP_Error( 'site_template_cpt_missing', __( 'wp_site_template CPT is not registered.', 'mcp-ai-wpoos-pro' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'wp_site_template',
				'post_status'  => 'draft',
				'post_title'   => 'NV Site Design — ' . $fingerprint,
				'post_content' => $snippet,
				'meta_input'   => array(
					'_nv_design_system'  => wp_json_encode( $design_system ),
					'_nv_design_variant' => $variant,
					'_nv_design_finger'  => $fingerprint,
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		return (int) $post_id;
	}

	/**
	 * Build a short Markdown hint listing the utility classes Elementor authors
	 * paste into the per-widget "CSS Classes" field to opt into each feature.
	 *
	 * @param string[] $features Active feature list.
	 * @return string Markdown.
	 */
	private function build_elementor_classes_hint( array $features ) {
		$rows   = array( '## How to apply in Elementor' );
		$rows[] = '';
		$rows[] = 'Paste these utility classes into the Elementor widget "CSS Classes" field:';
		$rows[] = '';
		$rows[] = '- `nv-reveal` (with optional `nv-reveal-delay-1`, `-2`, `-3`)';
		if ( in_array( 'header_scroll_state', $features, true ) ) {
			$rows[] = '- `nv-scroll-nav` on the header section';
		}
		if ( in_array( 'hover_link_underline', $features, true ) ) {
			$rows[] = '- `nv-hover-link` on link widgets';
			$rows[] = '- `nv-outline-accent` on accented buttons';
		}
		if ( in_array( 'rotating_steps', $features, true ) ) {
			$rows[] = '- `nv-hiw-step` on each step in a "How it works" section';
		}
		if ( in_array( 'mobile_drawer', $features, true ) ) {
			$rows[] = '- `id="nv-hamburger"` on the mobile menu trigger and `id="nv-drawer"` on the drawer container';
		}
		return implode( "\n", $rows );
	}
}
