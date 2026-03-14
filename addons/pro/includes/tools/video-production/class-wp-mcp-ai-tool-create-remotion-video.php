<?php
/**
 * Create Remotion Video Tool
 *
 * Render programmatic videos using the Remotion framework (React-based).
 * Accepts a Remotion composition script, renders it via `npx remotion render`,
 * and optionally uploads the resulting MP4 / WebM / GIF to the media library.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-nodejs-subprocess.php';

/**
 * Tool: create_remotion_video
 *
 * Renders a Remotion composition to a video file.  The tool writes a minimal
 * Remotion project to a temporary directory, runs `npx remotion render`, and
 * then (optionally) uploads the rendered file to the WordPress media library.
 *
 * Remotion must be available in one of:
 *   - addons/pro/node_modules/remotion  (development install)
 *   - addons/pro/assets/vendor/remotion  (production vendor bundle)
 *
 * For a production environment run:
 *   npm install remotion @remotion/cli --prefix <addons/pro dir>
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Create_Remotion_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_NodeJS_Subprocess;

	// ---------------------------------------------------------------------------
	// Availability helpers
	// ---------------------------------------------------------------------------

	/**
	 * Whether this tool can be used on the current site.
	 *
	 * Requires Node.js to be available on the server (Remotion runs via Node.js).
	 *
	 * @return bool
	 */
	public static function is_available() {
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		return $process_service->is_command_available( 'node' );
	}

	/**
	 * Human-readable reason when the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Create Remotion Video requires Node.js to be installed on the server. Install Node.js and ensure it is available in the system PATH.', 'mcp-ai-wpoos-pro' );
	}

	// ---------------------------------------------------------------------------
	// Tool interface
	// ---------------------------------------------------------------------------

	/** {@inheritdoc} */
	public function get_slug() {
		return 'create_remotion_video';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Create Remotion Video', 'mcp-ai-wpoos-pro' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Render programmatic videos from React/Remotion compositions. Provide composition source code or a named template, set dimensions, frame rate and duration, then download or upload the rendered MP4, WebM or GIF to the media library.', 'mcp-ai-wpoos-pro' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'composition_id'    => array(
					'type'        => 'string',
					'description' => __( 'Remotion composition ID to render (must match the id prop in <Composition>). Defaults to "MyVideo".', 'mcp-ai-wpoos-pro' ),
					'default'     => 'MyVideo',
				),
				'script'            => array(
					'type'        => 'string',
					'description' => __( 'Full source code of a valid Remotion index file (TSX/JSX/JS). Must export a RemotionRoot component that registers at least one <Composition>. When omitted, a built-in animated title card composition is used.', 'mcp-ai-wpoos-pro' ),
				),
				'props'             => array(
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
				'width'             => array(
					'type'        => 'integer',
					'description' => __( 'Output video width in pixels. Overrides the composition default.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 16,
					'maximum'     => 7680,
				),
				'height'            => array(
					'type'        => 'integer',
					'description' => __( 'Output video height in pixels. Overrides the composition default.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 16,
					'maximum'     => 4320,
				),
				'fps'               => array(
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
				'output_format'     => array(
					'type'        => 'string',
					'description' => __( 'Output container / codec. mp4 uses H.264, webm uses VP8/VP9, gif produces an animated GIF.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'mp4', 'webm', 'gif' ),
					'default'     => 'mp4',
				),
				'upload_result'     => array(
					'type'        => 'boolean',
					'description' => __( 'When true (default), upload the rendered video to the WordPress media library and return the attachment ID and URL.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array(),
		);
	}

	/** {@inheritdoc} */
	public function get_required_capability() {
		return 'upload_files';
	}

	/** {@inheritdoc} */
	public function requires_base_pro() {
		return true;
	}

	/** {@inheritdoc} */
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
	// Execution
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

		// Guard: Remotion must be installed.
		if ( ! $this->check_remotion_availability() ) {
			return array(
				'success' => false,
				'error'   => __( 'Remotion is not installed. Please run "npm install remotion @remotion/cli" in the addons/pro directory, or "npm install --prefix <plugin-dir>/addons/pro remotion @remotion/cli".', 'mcp-ai-wpoos-pro' ),
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
	// Private helpers
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
		$pro_path = WP_MCP_AI_PRO_PATH;

		// Production vendor bundle.
		$vendor_path = $pro_path . 'assets/vendor/remotion/package.json';
		// Development node_modules.
		$node_modules_path = $pro_path . 'node_modules/remotion/package.json';

		/**
		 * Filter to allow overriding the Remotion availability check.
		 *
		 * @param bool|null $available Null to use built-in check, bool to force.
		 */
		$override = apply_filters( 'wp_mcp_ai_remotion_available', null );
		if ( null !== $override ) {
			return (bool) $override;
		}

		return file_exists( $vendor_path ) || file_exists( $node_modules_path );
	}

	/**
	 * Return the path to the Remotion CLI binary (remotion or @remotion/cli).
	 *
	 * @return string|WP_Error CLI path or WP_Error if not found.
	 */
	private function get_remotion_cli_path() {
		$pro_path = WP_MCP_AI_PRO_PATH;

		// Prefer locally installed binary (avoids version conflicts).
		$local_bin = $pro_path . 'node_modules/.bin/remotion';
		if ( file_exists( $local_bin ) ) {
			return $local_bin;
		}

		// Vendor bundle binary.
		$vendor_bin = $pro_path . 'assets/vendor/remotion/bin/remotion.js';
		if ( file_exists( $vendor_bin ) ) {
			return $vendor_bin;
		}

		// Fall back to npx (global).
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		if ( $process_service->is_command_available( 'npx' ) ) {
			return 'npx';
		}

		return new WP_Error(
			'wp_mcp_ai_remotion_cli_not_found',
			__( 'Remotion CLI not found. Please install Remotion: npm install @remotion/cli --prefix <addons/pro dir>', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Write a minimal Remotion project to a temp directory and return its path.
	 *
	 * @param string $composition_id     Composition ID.
	 * @param int    $fps                Frames per second.
	 * @param int    $duration_in_frames Total frames.
	 * @param int    $width              Video width (0 = use composition default).
	 * @param int    $height             Video height (0 = use composition default).
	 * @param string $custom_script      Optional custom composition source code.
	 * @param array  $props              Props for the composition.
	 * @return string|WP_Error Temp directory path or WP_Error.
	 */
	private function create_temp_project( $composition_id, $fps, $duration_in_frames, $width, $height, $custom_script, $props ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Create a uniquely named temp directory.
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

		// Create output directory.
		wp_mkdir_p( $tmp_base . '/out' );

		// Determine which node_modules to reference.
		$pro_path          = WP_MCP_AI_PRO_PATH;
		$node_modules_path = $pro_path . 'node_modules';
		if ( ! is_dir( $node_modules_path ) ) {
			$node_modules_path = $pro_path . 'assets/vendor';
		}

		// Build the index source (either custom or default animated title card).
		if ( ! empty( $custom_script ) ) {
			$index_source = $custom_script;
		} else {
			$safe_title       = isset( $props['title'] ) ? esc_html( $props['title'] ) : get_bloginfo( 'name' );
			$safe_subtitle    = isset( $props['subtitle'] ) ? esc_html( $props['subtitle'] ) : get_bloginfo( 'description' );
			$safe_bg_color    = isset( $props['background_color'] ) ? sanitize_hex_color( $props['background_color'] ) : '#0a0a0a';
			$safe_text_color  = isset( $props['text_color'] ) ? sanitize_hex_color( $props['text_color'] ) : '#ffffff';

			// Use simple defaults when sanitize_hex_color returns empty.
			if ( ! $safe_bg_color ) {
				$safe_bg_color = '#0a0a0a';
			}
			if ( ! $safe_text_color ) {
				$safe_text_color = '#ffffff';
			}

			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
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
			// phpcs:enable
		}

		// Write index.js.
		$index_path = $tmp_base . '/index.js';
		if ( false === file_put_contents( $index_path, $index_source ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct filesystem; WP_Filesystem unavailable in this context.
			$this->recursive_rmdir( $tmp_base );
			return new WP_Error(
				'wp_mcp_ai_remotion_write_index',
				__( 'Failed to write Remotion index.js to temporary directory.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Write a minimal package.json so Remotion can resolve dependencies.
		$package_json = wp_json_encode(
			array(
				'name'         => 'remotion-render-' . sanitize_title( $composition_id ),
				'version'      => '1.0.0',
				'description'  => 'Temporary Remotion project for WP MCP AI rendering.',
				'dependencies' => array(
					'remotion' => '*',
					'react'    => '*',
					'react-dom' => '*',
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		if ( false === file_put_contents( $tmp_base . '/package.json', $package_json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct filesystem; WP_Filesystem unavailable in this context.
			$this->recursive_rmdir( $tmp_base );
			return new WP_Error(
				'wp_mcp_ai_remotion_write_pkg',
				__( 'Failed to write package.json to temporary Remotion project directory.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Symlink node_modules so Remotion can find its runtime without a slow npm install.
		if ( is_dir( $node_modules_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- No WP_Filesystem equivalent.
			@symlink( $node_modules_path, $tmp_base . '/node_modules' );
		}

		return $tmp_base;
	}

	/**
	 * Build the source code for the built-in animated title-card composition.
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
		// Escape values for safe embedding in a JS template literal.
		$js_title           = addslashes( $title );
		$js_subtitle        = addslashes( $subtitle );
		$js_bg_color        = addslashes( $bg_color );
		$js_text_color      = addslashes( $text_color );
		$js_composition_id  = addslashes( $composition_id );

		return <<<JS
'use strict';
const { Composition, AbsoluteFill, useCurrentFrame, interpolate, spring, useVideoConfig } = require('remotion');
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

exports.RemotionRoot = () =>
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
  );
JS;
	}

	/**
	 * Run `remotion render` via Node.js subprocess.
	 *
	 * @param string $project_dir    Path to the temporary Remotion project.
	 * @param string $composition_id Composition ID to render.
	 * @param string $output_file    Absolute output file path.
	 * @param string $output_format  mp4 | webm | gif.
	 * @return array|WP_Error Result array or WP_Error.
	 */
	private function run_remotion_render( $project_dir, $composition_id, $output_file, $output_format ) {
		$cli = $this->get_remotion_cli_path();
		if ( is_wp_error( $cli ) ) {
			return $cli;
		}

		$codec_map = array(
			'mp4'  => 'h264',
			'webm' => 'vp8',
			'gif'  => 'gif',
		);
		$codec = isset( $codec_map[ $output_format ] ) ? $codec_map[ $output_format ] : 'h264';

		/**
		 * Filter to allow a custom Remotion render implementation.
		 *
		 * Return a non-false value to bypass the built-in Node.js subprocess call.
		 *
		 * @param array|WP_Error|false $result     Custom result or false to use built-in.
		 * @param string               $project_dir Temp project directory.
		 * @param string               $composition_id Composition ID.
		 * @param string               $output_file    Output file path.
		 * @param string               $output_format  Format string (mp4/webm/gif).
		 */
		$custom_result = apply_filters( 'wp_mcp_ai_remotion_render', false, $project_dir, $composition_id, $output_file, $output_format );
		if ( false !== $custom_result ) {
			return $custom_result;
		}

		// Build the render command arguments.
		// When cli === 'npx' we use Node.js to call npx; otherwise we call the binary directly via node.
		$node_path = $this->get_nodejs_executable();
		if ( is_wp_error( $node_path ) ) {
			return $node_path;
		}

		$index_file = $project_dir . '/index.js';

		if ( 'npx' === $cli ) {
			// Run npx directly; Process Service can invoke it without a node wrapper.
			$command = array( 'npx', '--yes', 'remotion', 'render', $index_file, $composition_id, $output_file, '--codec=' . $codec, '--log=error' );
		} else {
			// Use the local binary: node <cli-path> render ...
			$command = array( $node_path, $cli, 'render', $index_file, $composition_id, $output_file, '--codec=' . $codec, '--log=error' );
		}

		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		$result          = $process_service->run_silent(
			$command,
			array(
				'timeout' => 300, // 5 minutes — rendering can take a while.
				'cwd'     => $project_dir,
			)
		);

		if ( isset( $result['timeout'] ) && $result['timeout'] ) {
			return new WP_Error(
				'wp_mcp_ai_remotion_timeout',
				__( 'Remotion render timed out (5 minute limit). Try reducing duration_in_frames or lowering the resolution.', 'mcp-ai-wpoos-pro' )
			);
		}

		$exit_code = isset( $result['exit_code'] ) ? (int) $result['exit_code'] : -1;
		if ( 0 !== $exit_code ) {
			$stderr = isset( $result['stderr'] ) ? trim( $result['stderr'] ) : '';
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
	 * @param string $output_format mp4 | webm | gif.
	 * @return int|false New attachment ID or false on failure.
	 */
	private function upload_video_to_media_library( $file_path, $output_format ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		$mime_map = array(
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
				@unlink( $path );
			} elseif ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem unavailable here.
		@rmdir( $dir );
	}
}
