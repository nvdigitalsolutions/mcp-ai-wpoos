<?php
/**
 * Characterization tests for the Wave F2 video-services slice — the Pro-owned
 * frame-extractor + fluent-ffmpeg services and the base-owned D8-compat
 * copies (LLM sanitizer interface, attachment resolver trait, Node.js
 * subprocess trait, media URL utils, Process service).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base plugin/base Pro addon own
 *   the symbols (classmap-autoloaded); the byte-identical contracts are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/` are
 *   asserted in full, including the per-mode seams.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Video services slice tests.
 */
class Test_Video_Services_Slice extends WP_UnitTestCase {

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Video_Frame_Extractor_Service' => 'services/class-wp-mcp-ai-video-frame-extractor-service.php',
			'WP_MCP_AI_Fluent_FFmpeg_Service'         => 'services/class-wp-mcp-ai-fluent-ffmpeg-service.php',
			'WP_MCP_AI_Tool_LLM_Sanitizer_Interface'  => 'interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php',
			'WP_MCP_AI_Attachment_File_Resolver'      => 'traits/trait-wp-mcp-ai-attachment-file-resolver.php',
			'WP_MCP_AI_NodeJS_Subprocess'             => 'traits/trait-wp-mcp-ai-nodejs-subprocess.php',
			'WP_MCP_AI_Media_Worker_Client'           => 'traits/trait-wp-mcp-ai-media-worker-client.php',
			'WP_MCP_AI_Media_URL_Utils'               => 'class-wp-mcp-ai-media-url-utils.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'includes/' . $file, $path, $class );
			} else {
				// Standalone: the addon copy serves — except when the monorepo
				// root classmap answers first (real standalone installs have no
				// root vendor, so the addon copy serves there).
				$this->assertTrue(
					false !== strpos( $path, 'nvoos-content-graph-pro/src/' . $file )
						|| false !== strpos( $path, 'includes/' . $file ),
					$class . ' served from an unexpected file: ' . $path
				);
			}
		}
	}

	/**
	 * The namespaced Process service resolves from the right file per matrix
	 * (it cannot be autoloaded standalone — explicit require, documented).
	 */
	public function test_process_service_source(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$reflection = new ReflectionClass( 'WP_MCP_AI\Services\WP_MCP_AI_Process_Service' );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			$this->assertStringContainsString( 'includes/services/class-wp-mcp-ai-process-service.php', $path );
			return;
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/services/class-wp-mcp-ai-process-service.php';
		$reflection = new ReflectionClass( 'WP_MCP_AI\Services\WP_MCP_AI_Process_Service' );
		$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
		$this->assertStringContainsString( 'nvoos-content-graph-pro/src/services/class-wp-mcp-ai-process-service.php', $path );
	}

	/**
	 * The frame-extractor service contracts must be byte-identical.
	 */
	public function test_frame_extractor_contracts(): void {
		$extractor = new WP_MCP_AI_Video_Frame_Extractor_Service();
		$this->assertSame( 10, $this->read_prop( $extractor, 'default_frame_count' ) );
		$this->assertSame( 20, $this->read_prop( $extractor, 'max_frame_count' ) );

		// Availability probes degrade gracefully without ffmpeg/media.
		$this->assertIsBool( $extractor->is_ffmpeg_available() );
		$duration = $extractor->get_video_duration( '/nonexistent/video.mp4' );
		$this->assertTrue( is_wp_error( $duration ) || is_numeric( $duration ) );
	}

	/**
	 * The fluent-ffmpeg service contracts must be byte-identical.
	 */
	public function test_fluent_ffmpeg_contracts(): void {
		$ffmpeg = new WP_MCP_AI_Fluent_FFmpeg_Service();
		// The availability probe is bundle + node + ffmpeg dependent — assert
		// the boolean contract, not the host-dependent value.
		$this->assertIsBool( $ffmpeg->is_available() );
	}

	/**
	 * The media URL utils must degrade gracefully without an upload URL.
	 */
	public function test_media_url_utils_contracts(): void {
		$this->assertSame( '', WP_MCP_AI_Media_URL_Utils::get_local_upload_url( array(), 0 ) );
		$this->assertSame(
			'https://example.com/video.mp4',
			WP_MCP_AI_Media_URL_Utils::get_local_upload_url( array( 'url' => 'https://example.com/video.mp4' ), 0 )
		);
	}

	/**
	 * Standalone only: the per-mode seams in the addon's attachment-resolver
	 * copy must be defined-guarded (the base-owned message-attachments/
	 * openai-client files have no standalone copies yet). The addon copy is
	 * read directly — the monorepo root classmap may serve the base trait in
	 * this matrix.
	 */
	public function test_attachment_resolver_seams_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base trait carries no standalone seams.' );
		}

		$file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
		$src  = (string) file_get_contents( $file );

		$this->assertStringContainsString( "if ( defined( 'WP_MCP_AI_PATH' ) ) {", $src );
		$this->assertStringNotContainsString( "\n\t\t\trequire_once WP_MCP_AI_PATH", $src );
	}

	/**
	 * Read a protected property for byte-identical pinning.
	 *
	 * @param object $instance Object instance.
	 * @param string $prop     Property name.
	 * @return mixed Property value.
	 */
	private function read_prop( object $instance, string $prop ) {
		$reflection = new ReflectionObject( $instance );
		while ( ! $reflection->hasProperty( $prop ) && $reflection->getParentClass() ) {
			$reflection = $reflection->getParentClass();
		}
		$property = $reflection->getProperty( $prop );
		$property->setAccessible( true );
		return $property->getValue( $instance );
	}
}
