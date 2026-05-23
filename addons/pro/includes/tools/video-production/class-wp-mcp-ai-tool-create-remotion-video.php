<?php
/**
 * Create Remotion Video Tool
 *
 * Render programmatic videos using the Remotion framework (React-based).
 * Uses the pre-built bin/remotion-render.bundle.js script so that end users
 * never need to install npm packages themselves.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-nodejs-subprocess.php';

/**
 * Tool: create_remotion_video
 *
 * Renders a Remotion composition to a video file using the pre-built
 * bin/remotion-render.bundle.js script (shipped with the plugin — no separate
 * npm install step is required).
 *
 * System requirements:
 *   - Node.js ≥ 18 in the server PATH
 *   - Chrome or Chromium (found automatically by Puppeteer)
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Create_Remotion_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_NodeJS_Subprocess;

	// ---------------------------------------------------------------------------
	// Availability helpers.
	// ---------------------------------------------------------------------------

	/**
	 * Whether this tool can be used on the current site.
	 *
	 * Requires Node.js and the pre-built remotion-render.bundle.js (which ships
	 * with the plugin — no separate npm install is needed).
	 *
	 * @return bool
	 */
	public static function is_available() {
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		if ( ! $process_service->is_command_available( 'node' ) ) {
			return false;
		}
		// The pre-built bundle is always present when the plugin is installed.
		return file_exists( WP_MCP_AI_PRO_PATH . 'bin/remotion-render.bundle.js' )
			|| static::check_remotion_node_modules();
	}

	/**
	 * Human-readable reason when the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		if ( ! $process_service->is_command_available( 'node' ) ) {
			return __( 'Create Remotion Video requires Node.js to be installed on the server. Install Node.js and ensure it is available in the system PATH.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'Remotion render bundle not found. Please rebuild the plugin assets with `npm run build:js:pro`.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Check whether the Remotion npm packages are available in node_modules or
	 * the vendored assets/vendor/ directory (fallback for development installs).
	 *
	 * @return bool
	 */
	private static function check_remotion_node_modules() {
		$pro_path = WP_MCP_AI_PRO_PATH;

		$paths = array(
			$pro_path . 'node_modules/remotion/package.json',
			$pro_path . 'assets/vendor/remotion/package.json',
		);

		/**
		 * Filter to override the Remotion availability check.
		 *
		 * @param bool|null $available Null to use built-in check, bool to force.
		 */
		$override = apply_filters( 'wp_mcp_ai_remotion_available', null );
		if ( null !== $override ) {
			return (bool) $override;
		}

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				return true;
			}
		}

		return false;
	}

	// ---------------------------------------------------------------------------
	// Tool interface.
	// ---------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_remotion_video';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Remotion Video', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Render programmatic videos from React/Remotion compositions. Provide composition source code or a named template, set dimensions, frame rate and duration, then download or upload the rendered MP4, WebM or GIF to the media library.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'composition_id'     => array(
					'type'        => 'string',
					'description' => __( 'Remotion composition ID to render (must match the id prop in <Composition>). Defaults to "MyVideo".', 'mcp-ai-wpoos-pro' ),
					'default'     => 'MyVideo',
				),
				'script'             => array(
					'type'        => 'string',
					'description' => __( 'Full source code of a valid Remotion index file (TSX/JSX/JS). Must export a RemotionRoot component that registers at least one <Composition>. When omitted, a built-in animated title card composition is used.', 'mcp-ai-wpoos-pro' ),
				),
				'props'              => array(
					'type'        => 'object',
					'description' => __( 'JSON props object passed to the composition as inputProps. Keys and values depend on the composition\'s own prop schema. When using the built-in title-card composition (no custom script provided), the following keys are supported: "title" (string, main heading text), "subtitle" (string, secondary line below the title), "background_color" (hex colour string, e.g. "#0a0a0a"), "text_color" (hex colour string, e.g. "#ffffff").', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'title'            => array(
							'type'        => 'string',
							'description' => __( 'Main heading displayed in the built-in title-card composition. Defaults to the site name.', 'mcp-ai-wpoos-pro' ),
						),
						'subtitle'         => array(
							'type'        => 'string',
							'description' => __( 'Secondary line shown beneath the title in the built-in composition. Defaults to the site tagline.', 'mcp-ai-wpoos-pro' ),
						),
						'background_color' => array(
							'type'        => 'string',
							'description' => __( 'Background hex colour for the built-in composition, e.g. "#0a0a0a". Defaults to near-black.', 'mcp-ai-wpoos-pro' ),
						),
						'text_color'       => array(
							'type'        => 'string',
							'description' => __( 'Text hex colour for the built-in composition, e.g. "#ffffff". Defaults to white.', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'width'              => array(
					'type'        => 'integer',
					'description' => __( 'Output video width in pixels. Overrides the composition default.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 16,
					'maximum'     => 7680,
				),
				'height'             => array(
					'type'        => 'integer',
					'description' => __( 'Output video height in pixels. Overrides the composition default.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 16,
					'maximum'     => 4320,
				),
				'fps'                => array(
					'type'        => 'integer',
					'description' => __( 'Frames per second. Common values: 24, 30 (default), 60.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 1, 15, 24, 25, 30, 48, 50, 60 ),
					'default'     => 30,
				),
				'duration_in_frames' => array(
					'type'        => 'integer',
					'description' => __( 'Total number of frames to render. At 30 fps, 150 frames = 5 seconds (the default).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'default'     => 150,
				),
				'output_format'      => array(
					'type'        => 'string',
					'description' => __( 'Output container / codec. mp4 uses H.264, webm uses VP8/VP9, gif produces an animated GIF.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'mp4', 'webm', 'gif' ),
					'default'     => 'mp4',
				),
				'upload_result'      => array(
					'type'        => 'boolean',
					'description' => __( 'When true (default), upload the rendered video to the WordPress media library and return the attachment ID and URL.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'write',                // Creates new media files.
			'requires-capability',  // Requires upload_files capability.
			'state-changing',       // Modifies the media library.
			'external-dependency',  // Requires Node.js + Remotion package.
			'performance-impact',   // Video rendering is CPU-intensive.
		);
	}

	// ---------------------------------------------------------------------------
	// Execution.
	// ---------------------------------------------------------------------------

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments (see get_parameters_schema()).
	 * @param array $context   Execution context (user_id, assistant_id, …).
	 * @return array Result array with 'success' key.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Guard: Node.js must be available.
		if ( ! $this->is_nodejs_available() ) {
			return array(
				'success' => false,
				'error'   => __( 'Node.js is not available on this server. Remotion requires Node.js to render videos.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Guard: Remotion render bundle or npm packages must be present.
		if ( ! $this->check_remotion_availability() ) {
			return array(
				'success' => false,
				'error'   => __( 'Remotion is not available. Ensure the plugin assets have been built (`npm run build:js:pro`) and that Node.js is installed on the server.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Parse arguments.
		$composition_id     = isset( $arguments['composition_id'] ) ? sanitize_text_field( $arguments['composition_id'] ) : 'MyVideo';
		$output_format      = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'mp4';
		$fps                = isset( $arguments['fps'] ) ? absint( $arguments['fps'] ) : 30;
		$duration_in_frames = isset( $arguments['duration_in_frames'] ) ? absint( $arguments['duration_in_frames'] ) : 150;
		$width              = isset( $arguments['width'] ) ? absint( $arguments['width'] ) : 0;
		$height             = isset( $arguments['height'] ) ? absint( $arguments['height'] ) : 0;
		$upload_result      = isset( $arguments['upload_result'] ) ? (bool) $arguments['upload_result'] : true;
		$props              = isset( $arguments['props'] ) && is_array( $arguments['props'] ) ? $arguments['props'] : array();
		$custom_script      = isset( $arguments['script'] ) ? $arguments['script'] : '';

		// Validate enum values.
		if ( ! in_array( $output_format, array( 'mp4', 'webm', 'gif' ), true ) ) {
			$output_format = 'mp4';
		}
		if ( ! in_array( $fps, array( 1, 15, 24, 25, 30, 48, 50, 60 ), true ) ) {
			$fps = 30;
		}

		// Build a temporary Remotion project directory.
		$tmp_dir = $this->create_temp_project( $composition_id, $fps, $duration_in_frames, $width, $height, $custom_script, $props );
		if ( is_wp_error( $tmp_dir ) ) {
			return array(
				'success' => false,
				'error'   => $tmp_dir->get_error_message(),
			);
		}

		// Determine output file path.
		$ext         = ( 'gif' === $output_format ) ? 'gif' : $output_format;
		$output_file = $tmp_dir . '/out/video.' . $ext;

		// Run Remotion render.
		$render_result = $this->run_remotion_render( $tmp_dir, $composition_id, $output_file, $output_format );

		if ( is_wp_error( $render_result ) ) {
			$this->recursive_rmdir( $tmp_dir );
			return array(
				'success' => false,
				'error'   => $render_result->get_error_message(),
			);
		}

		if ( empty( $render_result['success'] ) ) {
			$this->recursive_rmdir( $tmp_dir );
			return array(
				'success' => false,
				'error'   => isset( $render_result['error'] ) ? $render_result['error'] : __( 'Remotion render failed with an unknown error.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Optionally upload to media library.
		$attachment_id = null;
		$url           = null;

		if ( $upload_result && file_exists( $output_file ) ) {
			$attachment_id = $this->upload_video_to_media_library( $output_file, $output_format );
			if ( $attachment_id ) {
				$url = wp_get_attachment_url( $attachment_id );
			}
		}

		// Clean up the entire temp project directory (including the rendered output).
		$this->recursive_rmdir( $tmp_dir );

		$file_size = isset( $render_result['file_size'] ) ? $render_result['file_size'] : null;

		return array(
			'success'       => true,
			'message'       => sprintf(
				/* translators: 1: composition ID, 2: output format */
				__( 'Remotion composition "%1$s" rendered successfully as %2$s.', 'mcp-ai-wpoos-pro' ),
				$composition_id,
				strtoupper( $output_format )
			),
			'attachment_id' => $attachment_id,
			'url'           => $url,
			'file_size'     => $file_size,
			'composition'   => $composition_id,
			'format'        => $output_format,
			'fps'           => $fps,
			'frames'        => $duration_in_frames,
		);
	}

	// ---------------------------------------------------------------------------
	// Private helpers.
	// ---------------------------------------------------------------------------

	/**
	 * Check whether Remotion CLI is installed and accessible.
	 *
	 * Looks for the Remotion package in the standard install locations inside
	 * the addons/pro directory.
	 *
	 * @return bool
	 */
	private function check_remotion_availability() {
		return file_exists( WP_MCP_AI_PRO_PATH . 'bin/remotion-render.bundle.js' )
			|| static::check_remotion_node_modules();
	}

	/**
	 * Write a minimal Remotion composition index.js to a temp directory.
	 *
	 * @param string $composition_id     Composition ID.
	 * @param int    $fps                Frames per second.
	 * @param int    $duration_in_frames Total frames.
	 * @param int    $width              Video width (0 = composition default).
	 * @param int    $height             Video height (0 = composition default).
	 * @param string $custom_script      Optional custom composition source code.
	 * @param array  $props              Props for the default composition.
	 * @return string|WP_Error Temp directory path or WP_Error.
	 */
	private function create_temp_project( $composition_id, $fps, $duration_in_frames, $width, $height, $custom_script, $props ) {
		$tmp_base = get_temp_dir() . 'remotion-' . uniqid( '', true );
		if ( ! wp_mkdir_p( $tmp_base ) ) {
			return new WP_Error(
				'wp_mcp_ai_remotion_tmp_dir',
				sprintf(
					/* translators: %s: temp directory path */
					__( 'Failed to create temporary Remotion project directory at %s.', 'mcp-ai-wpoos-pro' ),
					$tmp_base
				)
			);
		}

		wp_mkdir_p( $tmp_base . '/out' );

		// Build the index source (either custom or default animated title card).
		if ( ! empty( $custom_script ) ) {
			$index_source = $custom_script;
		} else {
			$safe_title      = isset( $props['title'] ) ? esc_html( $props['title'] ) : get_bloginfo( 'name' );
			$safe_subtitle   = isset( $props['subtitle'] ) ? esc_html( $props['subtitle'] ) : get_bloginfo( 'description' );
			$safe_bg_color   = isset( $props['background_color'] ) ? sanitize_hex_color( $props['background_color'] ) : '#0a0a0a';
			$safe_text_color = isset( $props['text_color'] ) ? sanitize_hex_color( $props['text_color'] ) : '#ffffff';

			if ( ! $safe_bg_color ) {
				$safe_bg_color = '#0a0a0a';
			}
			if ( ! $safe_text_color ) {
				$safe_text_color = '#ffffff';
			}

			$index_source = $this->build_default_composition(
				$composition_id,
				$fps,
				$duration_in_frames,
				$width ? $width : 1920,
				$height ? $height : 1080,
				$safe_title,
				$safe_subtitle,
				$safe_bg_color,
				$safe_text_color
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $tmp_base . '/index.js', $index_source ) ) {
			$this->recursive_rmdir( $tmp_base );
			return new WP_Error(
				'wp_mcp_ai_remotion_write_index',
				__( 'Failed to write Remotion index.js to temporary directory.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $tmp_base;
	}

	/**
	 * Build the source code for the built-in animated title-card composition.
	 *
	 * Uses registerRoot() so the composition is compatible with both the
	 * pre-built bin/remotion-render.bundle.js and the Remotion CLI.
	 *
	 * @param string $composition_id     Composition ID.
	 * @param int    $fps                Frames per second.
	 * @param int    $duration_in_frames Total frames.
	 * @param int    $width              Video width in pixels.
	 * @param int    $height             Video height in pixels.
	 * @param string $title              Title text shown in the video.
	 * @param string $subtitle           Subtitle text.
	 * @param string $bg_color           Background hex colour.
	 * @param string $text_color         Text hex colour.
	 * @return string JavaScript source code.
	 */
	private function build_default_composition( $composition_id, $fps, $duration_in_frames, $width, $height, $title, $subtitle, $bg_color, $text_color ) {
		$js_title          = addslashes( $title );
		$js_subtitle       = addslashes( $subtitle );
		$js_bg_color       = addslashes( $bg_color );
		$js_text_color     = addslashes( $text_color );
		$js_composition_id = addslashes( $composition_id );

		// phpcs:ignore Squiz.PHP.Heredoc
		return <<<JS
'use strict';
const { registerRoot, Composition, AbsoluteFill, useCurrentFrame, interpolate, useVideoConfig } = require('remotion');
const React = require('react');

const TitleCard = ({ title, subtitle, bgColor, textColor }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  const titleOpacity = interpolate(frame, [0, fps * 0.5], [0, 1], { extrapolateRight: 'clamp' });
  const titleY       = interpolate(frame, [0, fps * 0.5], [40, 0], { extrapolateRight: 'clamp' });
  const subtitleOpacity = interpolate(frame, [fps * 0.4, fps * 0.9], [0, 1], { extrapolateRight: 'clamp' });

  return React.createElement(
    AbsoluteFill,
    { style: { backgroundColor: bgColor, justifyContent: 'center', alignItems: 'center', flexDirection: 'column' } },
    React.createElement(
      'h1',
      { style: { color: textColor, fontSize: 72, margin: 0, opacity: titleOpacity, transform: 'translateY(' + titleY + 'px)', fontFamily: 'sans-serif', textAlign: 'center', padding: '0 60px' } },
      title
    ),
    subtitle ? React.createElement(
      'p',
      { style: { color: textColor, fontSize: 36, marginTop: 24, opacity: subtitleOpacity, fontFamily: 'sans-serif', textAlign: 'center', padding: '0 60px' } },
      subtitle
    ) : null
  );
};

registerRoot(() =>
  React.createElement(
    Composition,
    {
      id: '$js_composition_id',
      component: TitleCard,
      durationInFrames: $duration_in_frames,
      fps: $fps,
      width: $width,
      height: $height,
      defaultProps: {
        title:     '$js_title',
        subtitle:  '$js_subtitle',
        bgColor:   '$js_bg_color',
        textColor: '$js_text_color',
      },
    }
  )
);
JS;
	}

	/**
	 * Render the Remotion composition to a video file.
	 *
	 * Prefers the pre-built bin/remotion-render.bundle.js (ships with the plugin
	 * — no separate npm install required).  Falls back to the locally installed
	 * Remotion CLI binary when the bundle is absent.
	 *
	 * @param string $project_dir    Path to the temporary Remotion project.
	 * @param string $composition_id Composition ID to render.
	 * @param string $output_file    Absolute output file path.
	 * @param string $output_format  Mp4 | webm | gif.
	 * @return array|WP_Error Result array or WP_Error.
	 */
	private function run_remotion_render( $project_dir, $composition_id, $output_file, $output_format ) {
		$codec_map = array(
			'mp4'  => 'h264',
			'webm' => 'vp8',
			'gif'  => 'gif',
		);
		$codec     = isset( $codec_map[ $output_format ] ) ? $codec_map[ $output_format ] : 'h264';

		/**
		 * Filter to allow a custom Remotion render implementation.
		 *
		 * Return a non-false value to bypass the built-in Node.js subprocess call.
		 *
		 * @param array|WP_Error|false $result         Custom result or false to use built-in.
		 * @param string               $project_dir    Temp project directory.
		 * @param string               $composition_id Composition ID.
		 * @param string               $output_file    Output file path.
		 * @param string               $output_format  Format string (mp4/webm/gif).
		 */
		$custom_result = apply_filters( 'wp_mcp_ai_remotion_render', false, $project_dir, $composition_id, $output_file, $output_format );
		if ( false !== $custom_result ) {
			return $custom_result;
		}

		$node_path = $this->get_nodejs_executable();
		if ( is_wp_error( $node_path ) ) {
			return $node_path;
		}

		$index_file = $project_dir . '/index.js';
		$pro_path   = WP_MCP_AI_PRO_PATH;
		$bundle     = $pro_path . 'bin/remotion-render.bundle.js';

		// ------------------------------------------------------------------
		// Path A: pre-built bundle (ships with the plugin, no install needed).
		// ------------------------------------------------------------------
		if ( file_exists( $bundle ) ) {
			$node_modules = is_dir( $pro_path . 'node_modules' )
				? $pro_path . 'node_modules'
				: $pro_path . 'assets/vendor';

			$json_arg = wp_json_encode(
				array(
					'indexFile'       => $index_file,
					'nodeModulesPath' => $node_modules,
					'compositionId'   => $composition_id,
					'outputFile'      => $output_file,
					'codec'           => $codec,
				)
			);

			$command         = array( $node_path, $bundle, $json_arg );
			$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
			$result          = $process_service->run_silent(
				$command,
				array( 'timeout' => 300 )
			);

			return $this->parse_render_result( $result, $output_file );
		}

		// ------------------------------------------------------------------
		// Path B: fall back to locally installed Remotion CLI.
		// ------------------------------------------------------------------
		$local_bin = $pro_path . 'node_modules/.bin/remotion';
		if ( file_exists( $local_bin ) ) {
			$command = array( $node_path, $local_bin, 'render', $index_file, $composition_id, $output_file, '--codec=' . $codec, '--log=error' );
		} elseif ( \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance()->is_command_available( 'npx' ) ) {
			$command = array( 'npx', '--yes', 'remotion', 'render', $index_file, $composition_id, $output_file, '--codec=' . $codec, '--log=error' );
		} else {
			return new WP_Error(
				'wp_mcp_ai_remotion_not_found',
				__( 'Remotion render bundle not found and no local CLI is installed. Please rebuild the plugin assets with `npm run build:js:pro`.', 'mcp-ai-wpoos-pro' )
			);
		}

		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		$result          = $process_service->run_silent(
			$command,
			array(
				'timeout' => 300,
				'cwd'     => $project_dir,
			)
		);

		return $this->parse_render_result( $result, $output_file );
	}

	/**
	 * Parse a raw Process Service result into a WP_Error or success array.
	 *
	 * @param array  $result      Result from WP_MCP_AI_Process_Service::run_silent().
	 * @param string $output_file Expected output file path.
	 * @return array|WP_Error
	 */
	private function parse_render_result( $result, $output_file ) {
		if ( isset( $result['timeout'] ) && $result['timeout'] ) {
			return new WP_Error(
				'wp_mcp_ai_remotion_timeout',
				__( 'Remotion render timed out (5 minute limit). Try reducing duration_in_frames or lowering the resolution.', 'mcp-ai-wpoos-pro' )
			);
		}

		$exit_code = isset( $result['exit_code'] ) ? (int) $result['exit_code'] : -1;
		if ( 0 !== $exit_code ) {
			$stderr = isset( $result['stderr'] ) ? trim( $result['stderr'] ) : '';
			// The pre-built bundle writes a JSON error to stdout.
			$stdout = isset( $result['stdout'] ) ? trim( $result['stdout'] ) : '';
			if ( $stdout ) {
				$decoded = json_decode( $stdout, true );
				if ( is_array( $decoded ) && ! empty( $decoded['error'] ) ) {
					$stderr = $decoded['error'];
				}
			}
			return new WP_Error(
				'wp_mcp_ai_remotion_render_failed',
				sprintf(
					/* translators: 1: exit code, 2: error output */
					__( 'Remotion render failed (exit code %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
					$exit_code,
					$stderr ? $stderr : __( 'No error output captured.', 'mcp-ai-wpoos-pro' )
				)
			);
		}

		if ( ! file_exists( $output_file ) ) {
			return new WP_Error(
				'wp_mcp_ai_remotion_no_output',
				__( 'Remotion render completed but the output file was not found. Check server permissions.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'success'   => true,
			'file_size' => filesize( $output_file ),
		);
	}

	/**
	 * Upload a rendered video file to the WordPress media library.
	 *
	 * @param string $file_path     Absolute path to the video file.
	 * @param string $output_format Mp4 | webm | gif.
	 * @return int|false New attachment ID or false on failure.
	 */
	private function upload_video_to_media_library( $file_path, $output_format ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		$mime_map  = array(
			'mp4'  => 'video/mp4',
			'webm' => 'video/webm',
			'gif'  => 'image/gif',
		);
		$mime_type = isset( $mime_map[ $output_format ] ) ? $mime_map[ $output_format ] : 'video/mp4';

		// Copy file to uploads directory.
		$upload_dir  = wp_upload_dir();
		$file_name   = 'remotion-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.' . $output_format;
		$target_path = $upload_dir['path'] . '/' . $file_name;

		if ( ! copy( $file_path, $target_path ) ) {
			return false;
		}

		$attachment_data = array(
			'post_mime_type' => $mime_type,
			'post_title'     => preg_replace( '/\.[^.]+$/', '', $file_name ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment_data, $target_path );

		if ( ! is_wp_error( $attachment_id ) && $attachment_id ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = wp_generate_attachment_metadata( $attachment_id, $target_path );
			wp_update_attachment_metadata( $attachment_id, $metadata );
			return $attachment_id;
		}

		return false;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Path to directory.
	 */
	private function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_link( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- WP_Filesystem unavailable here.
				if ( ! unlink( $path ) ) {
					// Log but do not abort — best-effort cleanup of temporary files.
					error_log( 'WP_MCP_AI: recursive_rmdir could not remove symlink: ' . $path ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			} elseif ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem unavailable here.
		if ( ! rmdir( $dir ) ) {
			error_log( 'WP_MCP_AI: recursive_rmdir could not remove directory: ' . $dir ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
