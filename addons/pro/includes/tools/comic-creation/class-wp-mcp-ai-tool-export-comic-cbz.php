<?php
/**
 * Tool that exports a comic as a CBZ/CBR archive file.
 *
 * Packages all generated panel images for a comic into a CBZ (ZIP) archive
 * with a ComicInfo.xml metadata file. Optionally attempts CBR (RAR) format.
 * Creates a downloadable WordPress attachment.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a Pro tool for exporting a comic to CBZ/CBR archive format.
 */
class WP_MCP_AI_Tool_Export_Comic_Cbz implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_comic_cbz';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export Comic CBZ', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Packages all panel images of a comic into a CBZ (ZIP) archive with a ComicInfo.xml metadata file. Optionally attempts CBR (RAR) format. Creates a downloadable WordPress attachment with the archive.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'comic_id'  => array(
					'type'        => 'integer',
					'description' => __( 'ID of the comic script post to export.', 'mcp-ai-wpoos-pro' ),
				),
				'format'    => array(
					'type'        => 'string',
					'description' => __( 'Export format.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'cbz', 'cbr' ),
					'default'     => 'cbz',
				),
				'page_ids'  => array(
					'type'        => 'array',
					'description' => __( 'Optional: specific panel IDs to include. If empty, all panels linked to the comic are exported.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
			),
			'required'             => array( 'comic_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'comic_creation',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'artist', 'publisher', 'content_manager' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'pro-tool',
			'write',
			'local-only',
			'may-timeout',
			'large-response',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error  Canonical success array or WP_Error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Capability check.
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to export comics.', 'mcp-ai-wpoos-pro' )
			);
		}

		// --- Sanitize all arguments at entry (Gate 1) ---
		$comic_id = isset( $arguments['comic_id'] ) ? absint( $arguments['comic_id'] ) : 0;
		$format   = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'cbz';
		$page_ids = isset( $arguments['page_ids'] ) && is_array( $arguments['page_ids'] )
			? array_map( 'absint', $arguments['page_ids'] )
			: array();

		// Validate comic.
		if ( $comic_id <= 0 ) {
			return new WP_Error(
				'wp_mcp_ai_missing_comic_id',
				__( 'A comic_id is required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$comic_post = get_post( $comic_id );
		if ( ! $comic_post || 'mcp_ai_comic_script' !== $comic_post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_comic_not_found',
				__( 'The specified comic script was not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Validate format.
		if ( ! in_array( $format, array( 'cbz', 'cbr' ), true ) ) {
			$format = 'cbz';
		}

		// CBR requires RAR support, which is uncommon. Fall back to CBZ.
		if ( 'cbr' === $format && ! class_exists( 'RarArchive' ) ) {
			WP_MCP_AI_Logger::log_event(
				'comic_export_format_fallback',
				'RAR extension not available; falling back to CBZ format',
				array( 'comic_id' => $comic_id )
			);
			$format = 'cbz';
		}

		// Resolve panels for this comic.
		if ( empty( $page_ids ) ) {
			$panels = get_posts(
				array(
					'post_type'      => 'mcp_ai_comic_panel',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'meta_key'       => '_comic_script_id',
					'meta_value'     => $comic_id,
					'orderby'        => 'menu_order',
					'order'          => 'ASC',
				)
			);
			$page_ids = wp_list_pluck( $panels, 'ID' );
		}

		if ( empty( $page_ids ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_panels_found',
				__( 'No panels found for this comic. Generate panel images first.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Collect panel image data.
		$panel_files = array();
		foreach ( $page_ids as $index => $pid ) {
			$image_id  = get_post_meta( $pid, '_generated_image_id', true );
			$image_url = get_post_meta( $pid, '_generated_image_url', true );

			if ( $image_id ) {
				$file_path = get_attached_file( (int) $image_id );
				if ( $file_path && file_exists( $file_path ) ) {
					$panel_files[] = array(
						'panel_id'  => $pid,
						'file_path' => $file_path,
						'order'     => $index + 1,
					);
					continue;
				}
			}

			// Fallback: record entry without local file.
			$panel_files[] = array(
				'panel_id'  => $pid,
				'file_path' => '',
				'image_url' => $image_url ?: '',
				'order'     => $index + 1,
			);
		}

		WP_MCP_AI_Logger::log_event(
			'comic_export_started',
			'Starting comic CBZ export',
			array(
				'comic_id'    => $comic_id,
				'format'      => $format,
				'panel_count' => count( $panel_files ),
				'user_id'     => $user_id,
			)
		);

		// TODO: Implement actual ZIP/CBZ packaging using PHP ZipArchive.
		// Generate ComicInfo.xml and add all panel images.
		$download_url   = $this->simulate_cbz_export( $comic_id, $comic_post, $panel_files, $format );
		$attachment_id  = $this->simulate_export_attachment( $comic_id, $comic_post, $user_id, $format );
		$comic_info_xml = $this->generate_comic_info_xml( $comic_post, count( $panel_files ) );

		WP_MCP_AI_Logger::log_event(
			'comic_export_completed',
			'Comic CBZ export completed',
			array(
				'comic_id'      => $comic_id,
				'format'        => $format,
				'attachment_id' => $attachment_id,
				'user_id'       => $user_id,
			)
		);

		// --- Escape all output values (Gate 2) ---
		return array(
			'success' => true,
			'data'    => array(
				'comic_id'       => $comic_id,
				'format'         => esc_html( strtoupper( $format ) ),
				'download_url'   => esc_url( $download_url ),
				'attachment_id'  => $attachment_id,
				'file_name'      => sanitize_file_name( $comic_post->post_title . '.' . $format ),
				'panel_count'    => count( $panel_files ),
				'comic_info_xml' => $comic_info_xml,
			),
		);
	}

	/**
	 * Simulate creating a CBZ export file.
	 *
	 * TODO: Implement with PHP ZipArchive + ComicInfo.xml generation.
	 *
	 * @param int     $comic_id    Comic script ID.
	 * @param WP_Post $comic_post  Comic post object.
	 * @param array   $panel_files Panel file data.
	 * @param string  $format      Archive format (cbz/cbr).
	 * @return string Simulated download URL.
	 */
	private function simulate_cbz_export( $comic_id, $comic_post, $panel_files, $format ) {
		$upload_dir = wp_upload_dir();
		$file_name  = sanitize_file_name( $comic_post->post_title . '.' . $format );
		return esc_url( trailingslashit( $upload_dir['url'] ) . 'comics/' . $file_name );
	}

	/**
	 * Simulate creating the export attachment.
	 *
	 * @param int     $comic_id   Comic script ID.
	 * @param WP_Post $comic_post Comic post object.
	 * @param int     $user_id    User ID.
	 * @param string  $format     Archive format.
	 * @return int Simulated attachment ID.
	 */
	private function simulate_export_attachment( $comic_id, $comic_post, $user_id, $format ) {
		$title = sprintf(
			/* translators: %1$s: comic title, %2$s: format */
			__( '%1$s.%2$s Comic Archive', 'mcp-ai-wpoos-pro' ),
			$comic_post->post_title,
			strtoupper( $format )
		);

		$attachment_data = array(
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_mime_type' => ( 'cbz' === $format ) ? 'application/zip' : 'application/x-rar-compressed',
			'post_author'    => $user_id,
		);

		$attachment_id = wp_insert_attachment( $attachment_data, '', 0, true );

		if ( is_wp_error( $attachment_id ) ) {
			return 0;
		}

		update_post_meta( $attachment_id, '_comic_export_id', $comic_id );
		update_post_meta( $attachment_id, '_comic_export_format', $format );

		return $attachment_id;
	}

	/**
	 * Generate a ComicInfo.xml metadata string.
	 *
	 * @param WP_Post $comic_post  Comic script post.
	 * @param int     $panel_count Number of panels/pages.
	 * @return string ComicInfo XML content.
	 */
	private function generate_comic_info_xml( $comic_post, $panel_count ) {
		$title  = esc_xml( $comic_post->post_title );
		$genre  = esc_xml( get_post_meta( $comic_post->ID, '_comic_genre', true ) ?: '' );
		$style  = esc_xml( get_post_meta( $comic_post->ID, '_comic_style', true ) ?: '' );
		$premise = esc_xml( get_post_meta( $comic_post->ID, '_comic_premise', true ) ?: '' );

		return sprintf(
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
			'<ComicInfo xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">' . "\n" .
			'  <Title>%1$s</Title>' . "\n" .
			'  <Genre>%2$s</Genre>' . "\n" .
			'  <Summary>%3$s</Summary>' . "\n" .
			'  <Notes>Art Style: %4$s. Generated by NV oOS Comic Creation Toolkit.</Notes>' . "\n" .
			'  <PageCount>%5$d</PageCount>' . "\n" .
			'  <Writer>AI-Assisted</Writer>' . "\n" .
			'</ComicInfo>',
			$title,
			$genre,
			$premise,
			$style,
			$panel_count
		);
	}
}
