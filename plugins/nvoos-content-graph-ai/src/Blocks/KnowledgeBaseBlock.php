<?php
/**
 * Knowledge base upload block for the Content Graph AI addon.
 *
 * Aligned port of the base plugin's `mcp-ai-wpoos/knowledge-base` block:
 * a server-rendered dropzone that uploads files to the media library via
 * the core `wp/v2/media` REST route (cookie auth + nonce) and stores the
 * resulting attachment IDs in a hidden input. Capability gate, type/size/
 * count limits, and data-attribute contract kept; CSS classes are
 * ecosystem-prefixed (`nvoos-cg-*`) so both blocks coexist in monolith
 * installs.
 *
 * @package NvoosContentGraphAi\Blocks
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `nvoos-content-graph-ai/knowledge-base` block.
 *
 * @since 1.1.0
 */
class KnowledgeBaseBlock {

	/**
	 * Block name.
	 */
	const BLOCK_NAME = 'nvoos-content-graph-ai/knowledge-base';

	/**
	 * Block metadata (title/icon/category/attributes).
	 *
	 * @return array
	 */
	public static function metadata(): array {
		return array(
			'apiVersion'  => 3,
			'title'       => __( 'Knowledge Base Upload', 'nvoos-content-graph-ai' ),
			'category'    => 'nvoos-content-graph-ai',
			'icon'        => 'media-document',
			'description' => __( 'Upload files to include in an AI assistant\'s knowledge base.', 'nvoos-content-graph-ai' ),
			'keywords'    => array( 'ai', 'knowledge', 'upload', 'files', 'documents' ),
			'attributes'  => array(
				'title'           => array(
					'type'    => 'string',
					'default' => '',
				),
				'description'     => array(
					'type'    => 'string',
					'default' => '',
				),
				'allowedTypes'    => array(
					'type'    => 'string',
					'default' => '.pdf,.txt,.md,.doc,.docx,.csv,.json',
				),
				'maxFiles'        => array(
					'type'    => 'number',
					'default' => 10,
				),
				'maxFileSizeMB'   => array(
					'type'    => 'number',
					'default' => 10,
				),
				'showPreview'     => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'uploadedFileIds' => array(
					'type'    => 'array',
					'default' => array(),
				),
			),
			'supports'    => array(
				'anchor'  => true,
				'html'    => false,
				'spacing' => array(
					'margin'  => true,
					'padding' => true,
				),
			),
		);
	}

	/**
	 * Server-side render callback.
	 *
	 * The third argument is nullable so admin pages (e.g. the Build
	 * Assistant Prompt tab) can embed the same markup outside a block
	 * context without constructing a WP_Block instance.
	 *
	 * @param array         $attributes Block attributes.
	 * @param string        $content    Inner block content (unused).
	 * @param \WP_Block|null $block     Block instance (null in admin embeds).
	 * @return string Rendered block HTML.
	 */
	public static function render( array $attributes, string $content, ?\WP_Block $block = null ): string {
		unset( $content );

		if ( ! current_user_can( 'upload_files' ) ) {
			return '<p class="nvoos-cg-kb__notice">' . esc_html__( 'You do not have permission to upload files.', 'nvoos-content-graph-ai' ) . '</p>';
		}

		$block_title   = isset( $attributes['title'] ) && '' !== $attributes['title']
			? sanitize_text_field( (string) $attributes['title'] )
			: __( 'Knowledge Base', 'nvoos-content-graph-ai' );
		$description   = isset( $attributes['description'] ) && '' !== $attributes['description']
			? sanitize_text_field( (string) $attributes['description'] )
			: __( 'Upload files to include in the assistant\'s knowledge base.', 'nvoos-content-graph-ai' );
		$allowed_types = isset( $attributes['allowedTypes'] ) && '' !== $attributes['allowedTypes']
			? sanitize_text_field( (string) $attributes['allowedTypes'] )
			: '.pdf,.txt,.md,.doc,.docx,.csv,.json';
		$max_files     = isset( $attributes['maxFiles'] ) ? max( 1, absint( $attributes['maxFiles'] ) ) : 10;
		$max_file_size = isset( $attributes['maxFileSizeMB'] ) ? max( 1, absint( $attributes['maxFileSizeMB'] ) ) : 10;
		$uploaded_ids  = isset( $attributes['uploadedFileIds'] ) && is_array( $attributes['uploadedFileIds'] )
			? array_filter( array_map( 'absint', $attributes['uploadedFileIds'] ) )
			: array();

		$unique_id       = wp_unique_id( 'nvoos-cg-kb-' );
		$max_upload_size = min( wp_max_upload_size(), $max_file_size * 1024 * 1024 );

		$type_names = array_map(
			static function ( string $ext ): string {
				return strtoupper( ltrim( trim( $ext ), '.' ) );
			},
			explode( ',', $allowed_types )
		);

		$wrapper_attributes = self::wrapper_attributes( $block, $unique_id, $allowed_types, $max_files, $max_upload_size );

		Blocks::enqueue_assistant_assets();

		$html = '<div ' . $wrapper_attributes . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitised by get_block_wrapper_attributes() or the esc_attr() fallback.

		if ( '' !== $block_title ) {
			$html .= '<h3 class="nvoos-cg-kb__title">' . esc_html( $block_title ) . '</h3>';
		}

		if ( '' !== $description ) {
			$html .= '<p class="nvoos-cg-kb__description">' . esc_html( $description ) . '</p>';
		}

		$html .= '<div class="nvoos-cg-kb__upload-area">';
		$html .= '<div class="nvoos-cg-kb__dropzone" tabindex="0" role="button" aria-label="' . esc_attr__( 'Upload files', 'nvoos-content-graph-ai' ) . '">';
		$html .= '<p class="nvoos-cg-kb__dropzone-text">' . esc_html__( 'Drop files here or click to upload', 'nvoos-content-graph-ai' ) . '</p>';
		$html .= '<p class="nvoos-cg-kb__dropzone-hint">';
		$html .= sprintf(
			/* translators: 1: accepted file types, 2: max file size. */
			esc_html__( 'Accepted: %1$s • Max %2$s per file', 'nvoos-content-graph-ai' ),
			esc_html( implode( ', ', $type_names ) ),
			esc_html( size_format( $max_upload_size ) )
		);
		$html .= '</p>';
		$html .= '<input type="file" class="nvoos-cg-kb__file-input" id="' . esc_attr( $unique_id ) . '-input" accept="' . esc_attr( $allowed_types ) . '" multiple hidden>';
		$html .= '</div></div>';

		$html .= '<div class="nvoos-cg-kb__files">';
		$html .= '<div class="nvoos-cg-kb__files-header">';
		$html .= '<span class="nvoos-cg-kb__files-count"><strong class="nvoos-cg-kb__count">' . esc_html( (string) count( $uploaded_ids ) ) . '</strong> / ' . esc_html( (string) $max_files ) . ' ' . esc_html__( 'files', 'nvoos-content-graph-ai' ) . '</span>';
		$html .= '<button type="button" class="nvoos-cg-kb__clear-all button button-link" style="display: none;">' . esc_html__( 'Remove All', 'nvoos-content-graph-ai' ) . '</button>';
		$html .= '</div>';
		$html .= '<ul class="nvoos-cg-kb__file-list" role="list"></ul>';
		$html .= '</div>';

		$html .= '<input type="hidden" class="nvoos-cg-kb__file-ids" name="knowledge_base_files" value="' . esc_attr( implode( ',', $uploaded_ids ) ) . '">';

		$html .= '<div class="nvoos-cg-kb__progress" style="display: none;"><div class="nvoos-cg-kb__progress-bar"><div class="nvoos-cg-kb__progress-fill"></div></div><span class="nvoos-cg-kb__progress-text">' . esc_html__( 'Uploading...', 'nvoos-content-graph-ai' ) . '</span></div>';

		$html .= '</div>';

		return $html;
	}

	/**
	 * Wrapper attributes, block-context aware.
	 *
	 * @param \WP_Block|null $block            Block instance or null.
	 * @param string         $unique_id        Unique instance ID.
	 * @param string         $allowed_types    Accepted extensions string.
	 * @param int            $max_files        Max file count.
	 * @param int            $max_upload_size  Max bytes per file.
	 * @return string Sanitised attribute string.
	 */
	protected static function wrapper_attributes( ?\WP_Block $block, string $unique_id, string $allowed_types, int $max_files, int $max_upload_size ): string {
		$classes = array( 'wp-block-nvoos-content-graph-ai-knowledge-base', 'nvoos-cg-kb' );

		$extra = array(
			'class'              => implode( ' ', $classes ),
			'data-block-id'      => $unique_id,
			'data-allowed-types' => $allowed_types,
			'data-max-files'     => (string) $max_files,
			'data-max-size'      => (string) $max_upload_size,
			'data-nonce'         => wp_create_nonce( 'wp_rest' ),
			'data-upload-url'    => rest_url( 'wp/v2/media' ),
		);

		if ( function_exists( 'get_block_wrapper_attributes' ) && $block instanceof \WP_Block ) {
			return get_block_wrapper_attributes( $extra );
		}

		return sprintf(
			'class="%s" data-block-id="%s" data-allowed-types="%s" data-max-files="%s" data-max-size="%s" data-nonce="%s" data-upload-url="%s"',
			esc_attr( $extra['class'] ),
			esc_attr( $extra['data-block-id'] ),
			esc_attr( $extra['data-allowed-types'] ),
			esc_attr( $extra['data-max-files'] ),
			esc_attr( $extra['data-max-size'] ),
			esc_attr( $extra['data-nonce'] ),
			esc_attr( $extra['data-upload-url'] )
		);
	}
}
