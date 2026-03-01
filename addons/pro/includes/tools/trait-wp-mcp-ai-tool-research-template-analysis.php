<?php
/**
 * Trait for research tool template analysis, auto-detection, and document export.
 *
 * Provides shared functionality for research_page and research_post tools:
 * - Template type auto-detection from JSON structure (Elementor, Block Editor, generic)
 * - Structural summary extraction (sections, widget types, content hierarchy)
 * - Document export via DomPDF (PDF) and PhpWord (DOCX)
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Tool_Research_Template_Analysis
 *
 * Shared template analysis and document export methods for research tools.
 *
 * Usage:
 * ```php
 * class WP_MCP_AI_Tool_Research_Page implements WP_MCP_AI_Tool_Interface {
 *     use WP_MCP_AI_Tool_Research_Template_Analysis;
 *
 *     public function execute( array $arguments = array(), array $context = array() ) {
 *         $analysis = $this->analyze_template_data( $decoded_json );
 *         // $analysis['detected_type'] => 'elementor' | 'block-editor' | 'generic'
 *         // $analysis['summary'] => human-readable structural summary
 *     }
 * }
 * ```
 */
trait WP_MCP_AI_Tool_Research_Template_Analysis {

	/**
	 * Analyze template_data JSON to detect template type and extract structural summary.
	 *
	 * Supports auto-detection of:
	 * - Elementor templates (elType, widgetType, elements keys)
	 * - Block Editor / Gutenberg patterns (blockName, attrs, innerBlocks keys)
	 * - Generic JSON structures
	 *
	 * @param array $decoded Decoded JSON template data.
	 * @return array Analysis results with 'detected_type' and 'summary' keys.
	 */
	protected function analyze_template_data( $decoded ) {
		$analysis = array(
			'detected_type' => 'generic',
			'summary'       => '',
			'sections'      => array(),
			'widget_types'  => array(),
		);

		if ( empty( $decoded ) || ! is_array( $decoded ) ) {
			return $analysis;
		}

		// Detect Elementor template structure.
		if ( $this->is_elementor_template( $decoded ) ) {
			$analysis['detected_type'] = 'elementor';
			$analysis['widget_types']  = $this->extract_elementor_widgets( $decoded );
			$analysis['sections']      = $this->extract_elementor_sections( $decoded );
			$analysis['summary']       = $this->build_elementor_summary( $analysis );
			return $analysis;
		}

		// Detect Block Editor (Gutenberg) pattern structure.
		if ( $this->is_block_editor_template( $decoded ) ) {
			$analysis['detected_type'] = 'block-editor';
			$analysis['widget_types']  = $this->extract_block_types( $decoded );
			$analysis['sections']      = $this->extract_block_sections( $decoded );
			$analysis['summary']       = $this->build_block_editor_summary( $analysis );
			return $analysis;
		}

		// Generic JSON — extract top-level keys as sections.
		$analysis['sections'] = array_keys( $decoded );
		$section_list         = implode( ', ', array_slice( $analysis['sections'], 0, 10 ) );
		$analysis['summary']  = sprintf( "Generic JSON structure with %d top-level keys: %s", count( $analysis['sections'] ), $section_list );

		return $analysis;
	}

	/**
	 * Check if decoded JSON is an Elementor template.
	 *
	 * @param array $data Decoded JSON data.
	 * @return bool True if Elementor template structure detected.
	 */
	protected function is_elementor_template( $data ) {
		// Elementor templates are arrays of elements with elType.
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			$first = $data[0];
			if ( isset( $first['elType'] ) || isset( $first['widgetType'] ) || isset( $first['elements'] ) ) {
				return true;
			}
		}

		// Single element structure.
		if ( isset( $data['elType'] ) || isset( $data['widgetType'] ) ) {
			return true;
		}

		// Elementor export format with content key.
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			$content = $data['content'];
			if ( isset( $content[0]['elType'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if decoded JSON is a Block Editor (Gutenberg) template.
	 *
	 * @param array $data Decoded JSON data.
	 * @return bool True if Block Editor pattern structure detected.
	 */
	protected function is_block_editor_template( $data ) {
		// Array of blocks.
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			$first = $data[0];
			if ( isset( $first['blockName'] ) || isset( $first['attrs'] ) || isset( $first['innerBlocks'] ) ) {
				return true;
			}
		}

		// Single block structure.
		if ( isset( $data['blockName'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Extract widget types from Elementor template data recursively.
	 *
	 * @param array $elements Elementor elements array.
	 * @return array Unique widget type names.
	 */
	protected function extract_elementor_widgets( $elements ) {
		$widgets = array();

		// Handle export format with content key.
		if ( isset( $elements['content'] ) && is_array( $elements['content'] ) ) {
			$elements = $elements['content'];
		}

		if ( ! isset( $elements[0] ) ) {
			$elements = array( $elements );
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['widgetType'] ) ) {
				$widgets[] = $element['widgetType'];
			} elseif ( ! empty( $element['elType'] ) ) {
				$widgets[] = $element['elType'];
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$widgets = array_merge( $widgets, $this->extract_elementor_widgets( $element['elements'] ) );
			}
		}

		return array_unique( $widgets );
	}

	/**
	 * Extract section structure from Elementor template data.
	 *
	 * @param array $elements Elementor elements array.
	 * @return array Section descriptions.
	 */
	protected function extract_elementor_sections( $elements ) {
		$sections = array();

		// Handle export format with content key.
		if ( isset( $elements['content'] ) && is_array( $elements['content'] ) ) {
			$elements = $elements['content'];
		}

		if ( ! isset( $elements[0] ) ) {
			$elements = array( $elements );
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$el_type = isset( $element['elType'] ) ? $element['elType'] : '';
			if ( in_array( $el_type, array( 'section', 'container' ), true ) ) {
				$child_count = ! empty( $element['elements'] ) ? count( $element['elements'] ) : 0;
				$sections[]  = sprintf( '%s (%d children)', ucfirst( $el_type ), $child_count );
			}
		}

		return $sections;
	}

	/**
	 * Build a human-readable summary of an Elementor template analysis.
	 *
	 * @param array $analysis Analysis results.
	 * @return string Summary text.
	 */
	protected function build_elementor_summary( $analysis ) {
		$parts = array();
		if ( ! empty( $analysis['sections'] ) ) {
			$parts[] = sprintf( '%d section(s): %s', count( $analysis['sections'] ), implode( ', ', array_slice( $analysis['sections'], 0, 5 ) ) );
		}
		if ( ! empty( $analysis['widget_types'] ) ) {
			$parts[] = sprintf( 'Widget types: %s', implode( ', ', array_slice( $analysis['widget_types'], 0, 10 ) ) );
		}

		return 'Elementor template — ' . implode( '. ', $parts );
	}

	/**
	 * Extract block types from Block Editor pattern data recursively.
	 *
	 * @param array $blocks Block Editor blocks array.
	 * @return array Unique block type names.
	 */
	protected function extract_block_types( $blocks ) {
		$types = array();

		if ( ! isset( $blocks[0] ) ) {
			$blocks = array( $blocks );
		}

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( ! empty( $block['blockName'] ) ) {
				$types[] = $block['blockName'];
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$types = array_merge( $types, $this->extract_block_types( $block['innerBlocks'] ) );
			}
		}

		return array_unique( $types );
	}

	/**
	 * Extract section-level blocks from Block Editor pattern data.
	 *
	 * @param array $blocks Block Editor blocks array.
	 * @return array Section descriptions.
	 */
	protected function extract_block_sections( $blocks ) {
		$sections = array();

		if ( ! isset( $blocks[0] ) ) {
			$blocks = array( $blocks );
		}

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$inner_count = ! empty( $block['innerBlocks'] ) ? count( $block['innerBlocks'] ) : 0;
			$sections[]  = sprintf( '%s (%d inner)', $block['blockName'], $inner_count );
		}

		return $sections;
	}

	/**
	 * Build a human-readable summary of a Block Editor template analysis.
	 *
	 * @param array $analysis Analysis results.
	 * @return string Summary text.
	 */
	protected function build_block_editor_summary( $analysis ) {
		$parts = array();
		if ( ! empty( $analysis['sections'] ) ) {
			$parts[] = sprintf( '%d top-level block(s): %s', count( $analysis['sections'] ), implode( ', ', array_slice( $analysis['sections'], 0, 5 ) ) );
		}
		if ( ! empty( $analysis['widget_types'] ) ) {
			$parts[] = sprintf( 'Block types: %s', implode( ', ', array_slice( $analysis['widget_types'], 0, 10 ) ) );
		}

		return 'Block Editor pattern — ' . implode( '. ', $parts );
	}

	/**
	 * Export research results as a document (PDF or Word).
	 *
	 * Uses DomPDF for PDF generation and PhpWord for Word document generation,
	 * both available from the pro vendor packages.
	 *
	 * @param array  $data          Parsed research data (page or post).
	 * @param string $output_format Output format ('pdf' or 'docx').
	 * @return array|WP_Error Document export result with attachment info or error.
	 */
	protected function export_research_document( $data, $output_format ) {
		$title   = ! empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : __( 'Research', 'mcp-ai-wpoos-pro' );
		$content = ! empty( $data['content'] ) ? $data['content'] : '';

		if ( empty( $content ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_content',
				__( 'No content available for document export.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( 'pdf' === $output_format ) {
			return $this->export_as_pdf( $title, $content, $data );
		}

		if ( 'docx' === $output_format ) {
			return $this->export_as_docx( $title, $content, $data );
		}

		return new WP_Error(
			'wp_mcp_ai_unsupported_format',
			sprintf(
				/* translators: %s: output format */
				__( 'Unsupported output format: %s', 'mcp-ai-wpoos-pro' ),
				$output_format
			)
		);
	}

	/**
	 * Export research content as PDF using DomPDF.
	 *
	 * @param string $title   Document title.
	 * @param string $content HTML content.
	 * @param array  $data    Full research data for metadata.
	 * @return array|WP_Error Export result or error.
	 */
	protected function export_as_pdf( $title, $content, $data ) {
		if ( ! class_exists( 'Dompdf\\Dompdf' ) ) {
			// Attempt to load pro vendor autoloader.
			if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				$autoloader = WP_MCP_AI_PRO_PATH . 'vendor/autoload.php';
				if ( file_exists( $autoloader ) ) {
					require_once $autoloader;
				}
			}
		}

		if ( ! class_exists( 'Dompdf\\Dompdf' ) ) {
			return new WP_Error(
				'wp_mcp_ai_dompdf_unavailable',
				__( 'DomPDF library is not available. PDF export requires the Pro addon with DomPDF installed.', 'mcp-ai-wpoos-pro' )
			);
		}

		try {
			$html  = '<!DOCTYPE html><html><head><meta charset="utf-8">';
			$html .= '<style>body{font-family:DejaVu Sans,sans-serif;font-size:12px;line-height:1.6;margin:40px;}';
			$html .= 'h1{font-size:24px;margin-bottom:20px;color:#333;}h2{font-size:18px;margin-top:20px;color:#444;}';
			$html .= 'h3{font-size:15px;margin-top:15px;color:#555;}p{margin-bottom:10px;}';
			$html .= 'ul,ol{margin-bottom:10px;padding-left:20px;}</style>';
			$html .= '</head><body>';
			$html .= '<h1>' . esc_html( $title ) . '</h1>';
			$html .= wp_kses_post( $content );

			// Add metadata footer.
			if ( ! empty( $data['sources'] ) ) {
				$html .= '<hr><h3>' . esc_html__( 'Sources', 'mcp-ai-wpoos-pro' ) . '</h3><ul>';
				foreach ( array_slice( $data['sources'], 0, 10 ) as $source ) {
					$html .= '<li>' . esc_html( $source ) . '</li>';
				}
				$html .= '</ul>';
			}

			$html .= '</body></html>';

			$dompdf = new \Dompdf\Dompdf();
			$dompdf->loadHtml( $html );
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->render();

			$pdf_content = $dompdf->output();
			$filename    = sanitize_file_name( $title ) . '.pdf';

			// Save to WordPress uploads.
			$upload = wp_upload_bits( $filename, null, $pdf_content );
			if ( ! empty( $upload['error'] ) ) {
				return new WP_Error( 'wp_mcp_ai_upload_error', $upload['error'] );
			}

			// Create attachment.
			$attachment = array(
				'post_title'     => $title,
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
			);

			$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			require_once ABSPATH . 'wp-admin/includes/image.php';
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

			return array(
				'format'        => 'pdf',
				'attachment_id' => $attachment_id,
				'url'           => $upload['url'],
				'file_name'     => $filename,
			);
		} catch ( \Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_pdf_error',
				sprintf(
					/* translators: %s: error message */
					__( 'PDF generation failed: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Export research content as Word document using PhpWord.
	 *
	 * @param string $title   Document title.
	 * @param string $content HTML content.
	 * @param array  $data    Full research data for metadata.
	 * @return array|WP_Error Export result or error.
	 */
	protected function export_as_docx( $title, $content, $data ) {
		if ( ! class_exists( 'PhpOffice\\PhpWord\\PhpWord' ) ) {
			// Attempt to load pro vendor autoloader.
			if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				$autoloader = WP_MCP_AI_PRO_PATH . 'vendor/autoload.php';
				if ( file_exists( $autoloader ) ) {
					require_once $autoloader;
				}
			}
		}

		if ( ! class_exists( 'PhpOffice\\PhpWord\\PhpWord' ) ) {
			return new WP_Error(
				'wp_mcp_ai_phpword_unavailable',
				__( 'PhpWord library is not available. Word export requires the Pro addon with PhpWord installed.', 'mcp-ai-wpoos-pro' )
			);
		}

		try {
			$phpword = new \PhpOffice\PhpWord\PhpWord();

			// Document properties.
			$properties = $phpword->getDocInfo();
			$properties->setTitle( $title );
			$properties->setCreator( 'NV oOS Research Tool' );

			$section = $phpword->addSection();

			// Title.
			$section->addTitle( $title, 1 );

			// Convert HTML content to Word elements.
			\PhpOffice\PhpWord\Shared\Html::addHtml( $section, $content, false, false );

			// Add sources section if available.
			if ( ! empty( $data['sources'] ) ) {
				$section->addTextBreak();
				$section->addTitle( __( 'Sources', 'mcp-ai-wpoos-pro' ), 2 );
				foreach ( array_slice( $data['sources'], 0, 10 ) as $source ) {
					$section->addListItem( $source, 0 );
				}
			}

			// Save to temp file then upload via WP Filesystem.
			$temp_file = wp_tempnam( 'research_' );
			$writer    = \PhpOffice\PhpWord\IOFactory::createWriter( $phpword, 'Word2007' );
			$writer->save( $temp_file );

			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			$file_content = $wp_filesystem->get_contents( $temp_file );
			wp_delete_file( $temp_file );

			if ( false === $file_content ) {
				return new WP_Error( 'wp_mcp_ai_file_error', __( 'Failed to read generated Word document.', 'mcp-ai-wpoos-pro' ) );
			}

			$filename = sanitize_file_name( $title ) . '.docx';
			$upload   = wp_upload_bits( $filename, null, $file_content );
			if ( ! empty( $upload['error'] ) ) {
				return new WP_Error( 'wp_mcp_ai_upload_error', $upload['error'] );
			}

			$attachment = array(
				'post_title'     => $title,
				'post_mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'post_status'    => 'inherit',
			);

			$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			require_once ABSPATH . 'wp-admin/includes/image.php';
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

			return array(
				'format'        => 'docx',
				'attachment_id' => $attachment_id,
				'url'           => $upload['url'],
				'file_name'     => $filename,
			);
		} catch ( \Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_docx_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Word document generation failed: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				)
			);
		}
	}
}
