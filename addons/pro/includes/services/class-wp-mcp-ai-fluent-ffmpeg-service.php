<?php
/**
 * Fluent FFmpeg Service
 *
 * Provides Node.js fluent-ffmpeg integration for advanced video processing.
 * This service acts as a bridge between WordPress/PHP and the fluent-ffmpeg NPM package.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fluent FFmpeg Service class
 *
 * Provides advanced video processing capabilities using the fluent-ffmpeg NPM package:
 * - Video metadata extraction
 * - Frame extraction with precise timing
 * - Video transcoding and format conversion
 * - Thumbnail generation
 * - Video clipping and trimming
 * - Audio extraction
 *
 * This service uses a Node.js microservice pattern where PHP communicates with
 * a Node.js script that uses fluent-ffmpeg for processing.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Fluent_FFmpeg_Service {
	use WP_MCP_AI_Media_Worker_Client;

	/**
	 * Check if fluent-ffmpeg package is available
	 *
	 * @return bool True if available, false otherwise.
	 */
	public function is_available() {
		// Check if fluent-ffmpeg package exists in vendor directory (production) or node_modules (development).
		$vendor_path       = WP_MCP_AI_PRO_PATH . 'assets/vendor/fluent-ffmpeg/index.js';
		$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/fluent-ffmpeg/index.js';

		if ( ! file_exists( $vendor_path ) && ! file_exists( $node_modules_path ) ) {
			return false;
		}

		// Use Process Service to check for Node.js and FFmpeg availability.
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();

		if ( ! $process_service->is_command_available( 'node' ) ) {
			return false;
		}

		if ( ! $process_service->is_command_available( 'ffmpeg' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Extract video metadata using fluent-ffmpeg
	 *
	 * @param string $video_path Path to video file.
	 * @return array|WP_Error Video metadata or error.
	 */
	public function get_metadata( $video_path ) {
		if ( ! file_exists( $video_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_video_not_found',
				__( 'Video file not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Try the Media Worker sidecar first (opt-in routing — fails fast
		// when no sidecar URL is configured or the health check fails).
		if ( $this->is_sidecar_video_processing_available() ) {
			$sidecar = $this->sidecar_upload( '/api/video/info', $video_path, array(), 120 );
			if ( ! is_wp_error( $sidecar ) && isset( $sidecar['success'] ) ) {
				unset( $sidecar['success'] );
				$sidecar['source'] = 'media-worker';
				return $sidecar;
			}
		}

		$params = array(
			'action'     => 'get_metadata',
			'video_path' => $video_path,
		);

		/**
		 * Filter to allow custom fluent-ffmpeg metadata extraction.
		 *
		 * Runs after the sidecar attempt: legacy local-Node handlers only
		 * execute when a local Node.js is installed.
		 *
		 * @param array|false $result Metadata result or false.
		 * @param array       $params Processing parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_fluent_ffmpeg_get_metadata', false, $params );
		if ( false !== $result ) {
			return $result;
		}

		// Fallback to basic FFprobe if filter not implemented.
		return $this->fallback_get_metadata( $video_path );
	}

	/**
	 * Extract frames from video using fluent-ffmpeg
	 *
	 * @param string $video_path Path to video file.
	 * @param array  $options    Frame extraction options.
	 * @return array|WP_Error Array of frame paths or error.
	 */
	public function extract_frames( $video_path, $options = array() ) {
		if ( ! file_exists( $video_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_video_not_found',
				__( 'Video file not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$defaults = array(
			'timestamps' => array(), // Specific timestamps in seconds.
			'count'      => 10,      // Number of frames to extract.
			'size'       => '640x?', // Output size.
			'folder'     => null,    // Output folder (null = temp).
			'filename'   => 'frame-%i.jpg', // Output filename pattern.
		);

		$options = wp_parse_args( $options, $defaults );

		// Try the Media Worker sidecar first (opt-in routing — fails fast
		// when no sidecar URL is configured or the health check fails).
		if ( $this->is_sidecar_video_processing_available() ) {
			$fields = array(
				'operation' => 'extract_frames',
				'format'    => 'jpg',
			);
			if ( ! empty( $options['timestamps'] ) && is_array( $options['timestamps'] ) ) {
				$stamps = array();
				foreach ( $options['timestamps'] as $ts ) {
					if ( is_numeric( $ts ) ) {
						$stamps[] = (float) $ts;
					}
				}
				if ( ! empty( $stamps ) ) {
					$fields['timestamps'] = implode( ',', $stamps );
				}
			} elseif ( ! empty( $options['count'] ) ) {
				$fields['count'] = absint( $options['count'] );
			}
			if ( ! empty( $options['size'] ) && preg_match( '/^(\d+)x/', $options['size'], $m ) ) {
				$fields['width'] = (int) $m[1];
			}

			$sidecar = $this->sidecar_upload( '/api/video/process', $video_path, $fields, 330 );
			if ( ! is_wp_error( $sidecar ) && ! empty( $sidecar['output_files'] ) && is_array( $sidecar['output_files'] ) ) {
				$folder  = ! empty( $options['folder'] ) ? untrailingslashit( $options['folder'] ) : untrailingslashit( get_temp_dir() ) . '/frames-' . uniqid();
				$pattern = ! empty( $options['filename'] ) ? $options['filename'] : 'frame-%i.jpg';
				if ( false === strpos( $pattern, '%i' ) ) {
					$pattern = 'frame-%i.jpg';
				}

				$frames   = array();
				$download = null;
				foreach ( $sidecar['output_files'] as $i => $name ) {
					$filename    = str_replace( '%i', str_pad( (string) ( $i + 1 ), 3, '0', STR_PAD_LEFT ), $pattern );
					$destination = trailingslashit( $folder ) . $filename;
					$download    = $this->sidecar_download( $name, $destination );
					if ( is_wp_error( $download ) ) {
						break;
					}
					$frames[] = $download;
				}

				if ( ! is_wp_error( $download ) ) {
					return $frames;
				}

				// Remove partial frames before falling through to local paths.
				foreach ( $frames as $frame ) {
					if ( file_exists( $frame ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
						unlink( $frame );
					}
				}
			}
		}

		$params = array(
			'action'     => 'extract_frames',
			'video_path' => $video_path,
			'options'    => $options,
		);

		/**
		 * Filter to allow custom fluent-ffmpeg frame extraction.
		 *
		 * Runs after the sidecar attempt: legacy local-Node handlers only
		 * execute when a local Node.js is installed.
		 *
		 * @param array|false $result Frame extraction result or false.
		 * @param array       $params Processing parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_fluent_ffmpeg_extract_frames', false, $params );
		if ( false !== $result ) {
			return $result;
		}

		return new WP_Error(
			'wp_mcp_ai_fluent_ffmpeg_not_configured',
			__( 'Fluent-ffmpeg frame extraction requires Node.js integration. Configure the Media Worker sidecar or implement the wp_mcp_ai_fluent_ffmpeg_extract_frames filter.', 'mcp-ai-wpoos-pro' ),
			array(
				'status'  => 501,
				'package' => 'fluent-ffmpeg',
			)
		);
	}

	/**
	 * Generate video thumbnail using fluent-ffmpeg
	 *
	 * @param string $video_path Path to video file.
	 * @param array  $options    Thumbnail generation options.
	 * @return string|WP_Error Thumbnail path or error.
	 */
	public function generate_thumbnail( $video_path, $options = array() ) {
		if ( ! file_exists( $video_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_video_not_found',
				__( 'Video file not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$defaults = array(
			'timestamp' => '10%',    // Timestamp or percentage.
			'size'      => '320x240', // Thumbnail size.
			'folder'    => null,      // Output folder (null = temp).
			'filename'  => 'thumbnail.jpg', // Output filename.
		);

		$options = wp_parse_args( $options, $defaults );

		$params = array(
			'action'     => 'generate_thumbnail',
			'video_path' => $video_path,
			'options'    => $options,
		);

		// Try the Media Worker sidecar first (opt-in routing — fails fast
		// when no sidecar URL is configured or the health check fails).
		if ( $this->is_sidecar_video_processing_available() ) {
			$fields = array(
				'operation' => 'thumbnail',
				'format'    => 'jpg',
			);
			if ( isset( $options['timestamp'] ) && is_numeric( $options['timestamp'] ) ) {
				$fields['start'] = (float) $options['timestamp'];
			}
			if ( ! empty( $options['size'] ) && preg_match( '/^(\d+)x/', $options['size'], $m ) ) {
				$fields['width'] = (int) $m[1];
			}

			$sidecar = $this->sidecar_upload( '/api/video/process', $video_path, $fields, 330 );
			if ( ! is_wp_error( $sidecar ) && ! empty( $sidecar['output_file'] ) ) {
				$folder   = ! empty( $options['folder'] ) ? $options['folder'] : get_temp_dir();
				$filename = ! empty( $options['filename'] ) ? $options['filename'] : 'thumbnail.jpg';
				$download = $this->sidecar_download( $sidecar['output_file'], trailingslashit( $folder ) . $filename );
				if ( ! is_wp_error( $download ) ) {
					return $download;
				}
			}
		}

		/**
		 * Filter to allow custom fluent-ffmpeg thumbnail generation.
		 *
		 * Runs after the sidecar attempt: legacy local-Node handlers only
		 * execute when a local Node.js is installed.
		 *
		 * @param string|false $result Thumbnail path or false.
		 * @param array        $params Processing parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_fluent_ffmpeg_generate_thumbnail', false, $params );
		if ( false !== $result ) {
			return $result;
		}

		return new WP_Error(
			'wp_mcp_ai_fluent_ffmpeg_not_configured',
			__( 'Fluent-ffmpeg thumbnail generation requires Node.js integration. Configure the Media Worker sidecar or implement the wp_mcp_ai_fluent_ffmpeg_generate_thumbnail filter.', 'mcp-ai-wpoos-pro' ),
			array(
				'status'  => 501,
				'package' => 'fluent-ffmpeg',
			)
		);
	}

	/**
	 * Transcode video to different format using fluent-ffmpeg
	 *
	 * @param string $video_path   Path to video file.
	 * @param string $output_path  Output file path.
	 * @param array  $options      Transcoding options.
	 * @return string|WP_Error Output path or error.
	 */
	public function transcode_video( $video_path, $output_path, $options = array() ) {
		if ( ! file_exists( $video_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_video_not_found',
				__( 'Video file not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$defaults = array(
			'format'      => 'mp4',        // Output format.
			'codec'       => 'libx264',    // Video codec.
			'audio_codec' => 'aac',        // Audio codec.
			'bitrate'     => '1000k',      // Video bitrate.
			'size'        => null,         // Output size (e.g., '1280x720').
			'fps'         => null,         // Frame rate.
		);

		$options = wp_parse_args( $options, $defaults );

		$params = array(
			'action'      => 'transcode_video',
			'video_path'  => $video_path,
			'output_path' => $output_path,
			'options'     => $options,
		);

		// Try the Media Worker sidecar first (opt-in routing — fails fast
		// when no sidecar URL is configured or the health check fails).
		if ( $this->is_sidecar_video_processing_available() ) {
			$ext    = strtolower( pathinfo( $output_path, PATHINFO_EXTENSION ) );
			$fields = array(
				'format' => $ext ? $ext : 'mp4',
			);
			if ( ! empty( $options['size'] ) && preg_match( '/^(\d+)x(\d+|\?)/', $options['size'], $m ) ) {
				$fields['operation'] = 'resize';
				$fields['width']     = (int) $m[1];
				if ( ! empty( $m[2] ) && '?' !== $m[2] ) {
					$fields['height'] = (int) $m[2];
				}
			} else {
				$fields['operation'] = 'convert';
			}

			$sidecar = $this->sidecar_upload( '/api/video/process', $video_path, $fields, 330 );
			if ( ! is_wp_error( $sidecar ) && ! empty( $sidecar['output_file'] ) ) {
				$download = $this->sidecar_download( $sidecar['output_file'], $output_path );
				if ( ! is_wp_error( $download ) ) {
					return $download;
				}
			}
		}

		/**
		 * Filter to allow custom fluent-ffmpeg video transcoding.
		 *
		 * Runs after the sidecar attempt: legacy local-Node handlers only
		 * execute when a local Node.js is installed.
		 *
		 * @param string|false $result Output path or false.
		 * @param array        $params Processing parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_fluent_ffmpeg_transcode_video', false, $params );
		if ( false !== $result ) {
			return $result;
		}

		return new WP_Error(
			'wp_mcp_ai_fluent_ffmpeg_not_configured',
			__( 'Fluent-ffmpeg video transcoding requires Node.js integration. Configure the Media Worker sidecar or implement the wp_mcp_ai_fluent_ffmpeg_transcode_video filter.', 'mcp-ai-wpoos-pro' ),
			array(
				'status'  => 501,
				'package' => 'fluent-ffmpeg',
			)
		);
	}

	/**
	 * Extract audio from video using fluent-ffmpeg
	 *
	 * @param string $video_path  Path to video file.
	 * @param string $output_path Output audio file path.
	 * @param array  $options     Audio extraction options.
	 * @return string|WP_Error Output path or error.
	 */
	public function extract_audio( $video_path, $output_path, $options = array() ) {
		if ( ! file_exists( $video_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_video_not_found',
				__( 'Video file not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$defaults = array(
			'format'   => 'mp3',    // Output audio format.
			'codec'    => 'libmp3lame', // Audio codec.
			'bitrate'  => '128k',   // Audio bitrate.
			'channels' => 2,        // Number of audio channels.
		);

		$options = wp_parse_args( $options, $defaults );

		$params = array(
			'action'      => 'extract_audio',
			'video_path'  => $video_path,
			'output_path' => $output_path,
			'options'     => $options,
		);

		// Try the Media Worker sidecar first (opt-in routing — fails fast
		// when no sidecar URL is configured or the health check fails).
		if ( $this->is_sidecar_video_processing_available() ) {
			$ext    = strtolower( pathinfo( $output_path, PATHINFO_EXTENSION ) );
			$fields = array(
				'operation' => 'extract_audio',
				'format'    => $ext ? $ext : 'mp3',
			);

			$sidecar = $this->sidecar_upload( '/api/video/process', $video_path, $fields, 330 );
			if ( ! is_wp_error( $sidecar ) && ! empty( $sidecar['output_file'] ) ) {
				$download = $this->sidecar_download( $sidecar['output_file'], $output_path );
				if ( ! is_wp_error( $download ) ) {
					return $download;
				}
			}
		}

		/**
		 * Filter to allow custom fluent-ffmpeg audio extraction.
		 *
		 * Runs after the sidecar attempt: legacy local-Node handlers only
		 * execute when a local Node.js is installed.
		 *
		 * @param string|false $result Output path or false.
		 * @param array        $params Processing parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_fluent_ffmpeg_extract_audio', false, $params );
		if ( false !== $result ) {
			return $result;
		}

		return new WP_Error(
			'wp_mcp_ai_fluent_ffmpeg_not_configured',
			__( 'Fluent-ffmpeg audio extraction requires Node.js integration. Configure the Media Worker sidecar or implement the wp_mcp_ai_fluent_ffmpeg_extract_audio filter.', 'mcp-ai-wpoos-pro' ),
			array(
				'status'  => 501,
				'package' => 'fluent-ffmpeg',
			)
		);
	}

	/**
	 * Check whether video processing can be offloaded to the Media Worker.
	 *
	 * Requires a reachable sidecar and the cURL extension (multipart uploads).
	 * Delegates to the shared trait check (is_sidecar_upload_supported).
	 *
	 * @return bool True when video operations can use the sidecar.
	 */
	public function is_sidecar_video_processing_available() {
		return $this->is_sidecar_upload_supported();
	}

	/**
	 * Upload a video file to the sidecar as multipart/form-data.
	 *
	 * Uses cURL directly (CURLFile) because the WordPress HTTP API
	 * (Requests) form-encodes array bodies and cannot stream large file
	 * parts; cURL streams the file from disk without loading it into
	 * memory. The curl extension is a hard requirement for these uploads
	 * (is_sidecar_video_processing_available() checks for it).
	 *
	 * @param string $endpoint   API path (e.g. '/api/video/process').
	 * @param string $video_path Local video file path.
	 * @param array  $fields     Extra form fields sent alongside the file.
	 * @param int    $timeout    Request timeout in seconds.
	 * @return array|WP_Error Decoded JSON body or error.
	 */
	private function sidecar_upload( $endpoint, $video_path, $fields = array(), $timeout = 330 ) {
		if ( ! function_exists( 'curl_file_create' ) ) {
			return new WP_Error(
				'wp_mcp_ai_curl_required',
				__( 'Multipart video uploads require the cURL extension.', 'mcp-ai-wpoos-pro' )
			);
		}
		if ( ! file_exists( $video_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_video_not_found',
				__( 'Video file not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}
		$url = $this->get_sidecar_url();
		if ( empty( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_sidecar_not_configured',
				__( 'Media Worker sidecar URL is not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$filetype = wp_check_filetype( $video_path );
		$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream';

		$postfields = $fields;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_file_create -- cURL streaming multipart upload; the WordPress HTTP API cannot stream file parts.
		$postfields['file'] = curl_file_create( $video_path, $mime, basename( $video_path ) );

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_errno,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_close -- Streaming multipart upload via cURL; see method docblock.
		$ch = curl_init( rtrim( $url, '/' ) . '/' . ltrim( $endpoint, '/' ) );
		if ( false === $ch ) {
			return new WP_Error( 'wp_mcp_ai_curl_init_failed', __( 'Failed to initialise cURL.', 'mcp-ai-wpoos-pro' ) );
		}

		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $postfields );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, (int) $timeout );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			array(
				'X-Site-Token: ' . $this->get_sidecar_token(),
				'X-Site-Url: ' . home_url(),
			)
		);

		/**
		 * Filter: modify the sidecar upload request before sending.
		 *
		 * @param resource $ch       cURL handle.
		 * @param string   $endpoint The API endpoint path.
		 * @param array    $fields   Extra form fields.
		 */
		$ch = apply_filters( 'wp_mcp_ai_sidecar_upload_handle', $ch, $endpoint, $fields );

		$raw = curl_exec( $ch );
		if ( false === $raw ) {
			$errno = curl_errno( $ch );
			$error = curl_error( $ch );
			curl_close( $ch );
			$this->sidecar_available = false;
			return new WP_Error( 'wp_mcp_ai_sidecar_error', sprintf( 'cURL %d: %s', $errno, $error ) );
		}

		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );
		// phpcs:enable

		$decoded = json_decode( $raw, true );

		if ( 200 !== $status && 202 !== $status ) {
			$error_msg = isset( $decoded['error'] )
				? $decoded['error']
				: sprintf( 'HTTP %d: %s', $status, substr( $raw, 0, 200 ) );

			return new WP_Error(
				'wp_mcp_ai_sidecar_error',
				$error_msg,
				array(
					'status'   => $status,
					'response' => $decoded,
				)
			);
		}

		if ( null === $decoded ) {
			return new WP_Error(
				'wp_mcp_ai_sidecar_invalid_json',
				__( 'Media Worker returned invalid JSON.', 'mcp-ai-wpoos-pro' )
			);
		}

		$this->sidecar_available = true;
		return $decoded;
	}

	/**
	 * Download a processed output file from the sidecar to a local path.
	 *
	 * Uses cURL with CURLOPT_FILE so large outputs stream to disk instead of
	 * loading into memory (same rationale as sidecar_upload()).
	 *
	 * @param string $name        Relative output name from the worker response.
	 * @param string $destination Absolute local destination path.
	 * @return string|WP_Error Destination path on success.
	 */
	private function sidecar_download( $name, $destination ) {
		$url = $this->get_sidecar_url();
		if ( empty( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_sidecar_not_configured',
				__( 'Media Worker sidecar URL is not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$dir = dirname( $destination );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Encode each path segment so '/' separators survive proxies that
		// reject %2F in URLs.
		$segments    = array_map( 'rawurlencode', explode( '/', $name ) );
		$request_url = rtrim( $url, '/' ) . '/api/video/download/' . implode( '/', $segments );

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_errno,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_close -- Streaming cURL download; see method docblock.
		$ch = curl_init( $request_url );
		if ( false === $ch ) {
			return new WP_Error( 'wp_mcp_ai_curl_init_failed', __( 'Failed to initialise cURL.', 'mcp-ai-wpoos-pro' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Destination is a site temp/uploads path created via wp_mkdir_p() above.
		$fp = fopen( $destination, 'wb' );
		if ( false === $fp ) {
			curl_close( $ch );
			return new WP_Error( 'wp_mcp_ai_download_open_failed', __( 'Could not open destination file for writing.', 'mcp-ai-wpoos-pro' ) );
		}

		curl_setopt( $ch, CURLOPT_FILE, $fp );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 120 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			array(
				'X-Site-Token: ' . $this->get_sidecar_token(),
				'X-Site-Url: ' . home_url(),
			)
		);

		$result = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		if ( false === $result ) {
			$errno = curl_errno( $ch );
			$error = curl_error( $ch );
			curl_close( $ch );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			if ( file_exists( $destination ) && 0 === filesize( $destination ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $destination );
			}
			$this->sidecar_available = false;
			return new WP_Error( 'wp_mcp_ai_sidecar_error', sprintf( 'cURL %d: %s', $errno, $error ) );
		}
		curl_close( $ch );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );
		// phpcs:enable

		if ( 200 !== $status ) {
			if ( file_exists( $destination ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $destination );
			}
			return new WP_Error(
				'wp_mcp_ai_sidecar_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'HTTP %d while downloading worker output.', 'mcp-ai-wpoos-pro' ),
					$status
				),
				array( 'status' => $status )
			);
		}

		if ( ! file_exists( $destination ) || 0 === filesize( $destination ) ) {
			return new WP_Error(
				'wp_mcp_ai_sidecar_download_empty',
				__( 'Worker output file was empty.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $destination;
	}

	/**
	 * Fallback metadata extraction using FFprobe directly
	 *
	 * @param string $video_path Path to video file.
	 * @return array|WP_Error Video metadata or error.
	 */
	private function fallback_get_metadata( $video_path ) {
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();

		// Use FFprobe to get JSON metadata.
		$result = $process_service->run(
			array(
				'ffprobe',
				'-v',
				'quiet',
				'-print_format',
				'json',
				'-show_format',
				'-show_streams',
				$video_path,
			),
			array( 'timeout' => 30 )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$metadata = json_decode( $result['output'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'wp_mcp_ai_json_decode_failed',
				__( 'Failed to parse video metadata.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		return $metadata;
	}
}
