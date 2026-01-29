<?php
/**
 * HTML Formatter for Document Generation
 *
 * Utility class for creating well-formatted HTML suitable for conversion to DOCX and PDF.
 * Follows industry best practices for HTML-to-document conversion.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTML formatter for document generation.
 *
 * Creates semantic, well-structured HTML that follows industry standards for
 * conversion to Word and PDF documents. Key features:
 * - Semantic HTML with proper heading hierarchy
 * - Inline CSS for predictable formatting
 * - Print-optimized styles
 * - Standards-compliant structure
 *
 * @since 1.1.0
 */
class WP_MCP_AI_HTML_Formatter {

	/**
	 * Default font family for documents.
	 *
	 * @var string
	 */
	protected $default_font_family = 'Arial, Helvetica, sans-serif';

	/**
	 * Default font size for body text.
	 *
	 * @var int
	 */
	protected $default_font_size = 12;

	/**
	 * Default line height.
	 *
	 * @var float
	 */
	protected $default_line_height = 1.6;

	/**
	 * Page width for print layout (in pixels).
	 *
	 * @var int
	 */
	protected $page_width = 816; // US Letter width at 96 DPI (8.5 inches).

	/**
	 * Create a complete HTML document from content.
	 *
	 * @param string $content  Document content (HTML body).
	 * @param array  $options  Optional formatting options.
	 * @return string Complete HTML document.
	 */
	public function create_document( $content, $options = array() ) {
		$defaults = array(
			'title'        => 'Document',
			'author'       => '',
			'font_family'  => $this->default_font_family,
			'font_size'    => $this->default_font_size,
			'line_height'  => $this->default_line_height,
			'margin'       => '1in',
			'page_width'   => $this->page_width . 'px',
			'text_align'   => 'left',
			'orientation'  => 'portrait',
		);

		$options = wp_parse_args( $options, $defaults );

		$styles = $this->generate_base_styles( $options );
		$meta   = $this->generate_meta_tags( $options );

		$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	{$meta}
	<title>{$this->escape_html( $options['title'] )}</title>
	<style>
{$styles}
	</style>
</head>
<body>
	{$content}
</body>
</html>
HTML;

		return $html;
	}

	/**
	 * Generate base CSS styles for the document.
	 *
	 * @param array $options Formatting options.
	 * @return string CSS styles.
	 */
	protected function generate_base_styles( $options ) {
		$font_family = esc_attr( $options['font_family'] );
		$font_size   = absint( $options['font_size'] );
		$line_height = floatval( $options['line_height'] );
		$margin      = esc_attr( $options['margin'] );
		$page_width  = esc_attr( $options['page_width'] );
		$text_align  = esc_attr( $options['text_align'] );

		return <<<CSS
		* {
			margin: 0;
			padding: 0;
			box-sizing: border-box;
		}
		
		html, body {
			font-family: {$font_family};
			font-size: {$font_size}pt;
			line-height: {$line_height};
			color: #333;
			background: #fff;
		}
		
		body {
			max-width: {$page_width};
			margin: 0 auto;
			padding: {$margin};
		}
		
		/* Headings */
		h1, h2, h3, h4, h5, h6 {
			font-weight: bold;
			margin-top: 1.5em;
			margin-bottom: 0.75em;
			line-height: 1.3;
		}
		
		h1 {
			font-size: 2em;
			margin-top: 0;
			text-align: center;
		}
		
		h2 {
			font-size: 1.5em;
		}
		
		h3 {
			font-size: 1.25em;
		}
		
		h4 {
			font-size: 1.1em;
		}
		
		h5 {
			font-size: 1em;
		}
		
		h6 {
			font-size: 0.9em;
		}
		
		/* Paragraphs */
		p {
			margin-bottom: 1em;
			text-align: {$text_align};
		}
		
		/* Lists */
		ul, ol {
			margin-bottom: 1em;
			padding-left: 2em;
		}
		
		li {
			margin-bottom: 0.5em;
		}
		
		/* Tables */
		table {
			width: 100%;
			border-collapse: collapse;
			margin-bottom: 1em;
		}
		
		th, td {
			border: 1px solid #ddd;
			padding: 8px 12px;
			text-align: left;
		}
		
		th {
			background-color: #f2f2f2;
			font-weight: bold;
		}
		
		/* Text formatting */
		strong, b {
			font-weight: bold;
		}
		
		em, i {
			font-style: italic;
		}
		
		u {
			text-decoration: underline;
		}
		
		a {
			color: #0066cc;
			text-decoration: underline;
		}
		
		/* Code blocks */
		code {
			font-family: 'Courier New', monospace;
			background-color: #f5f5f5;
			padding: 2px 4px;
			border-radius: 3px;
		}
		
		pre {
			font-family: 'Courier New', monospace;
			background-color: #f5f5f5;
			padding: 1em;
			margin-bottom: 1em;
			border-radius: 3px;
			overflow-x: auto;
		}
		
		/* Blockquotes */
		blockquote {
			margin: 1em 0;
			padding-left: 1em;
			border-left: 4px solid #ddd;
			color: #666;
		}
		
		/* Print-specific styles */
		@media print {
			body {
				margin: 0;
			}
			
			h1, h2, h3, h4, h5, h6 {
				page-break-after: avoid;
			}
			
			p, li {
				page-break-inside: avoid;
			}
			
			table {
				page-break-inside: avoid;
			}
			
			a {
				color: #0066cc;
			}
		}
		
		/* Page break utilities */
		.page-break {
			page-break-after: always;
		}
		
		.avoid-break {
			page-break-inside: avoid;
		}
CSS;
	}

	/**
	 * Generate meta tags for the document.
	 *
	 * @param array $options Formatting options.
	 * @return string HTML meta tags.
	 */
	protected function generate_meta_tags( $options ) {
		$meta_tags = array();

		if ( ! empty( $options['author'] ) ) {
			$meta_tags[] = sprintf(
				'<meta name="author" content="%s">',
				$this->escape_html( $options['author'] )
			);
		}

		if ( ! empty( $options['description'] ) ) {
			$meta_tags[] = sprintf(
				'<meta name="description" content="%s">',
				$this->escape_html( $options['description'] )
			);
		}

		return implode( "\n\t", $meta_tags );
	}

	/**
	 * Convert plain text content to HTML with proper formatting.
	 *
	 * @param string $content Plain text content.
	 * @param array  $options Formatting options.
	 * @return string HTML formatted content.
	 */
	public function text_to_html( $content, $options = array() ) {
		$defaults = array(
			'preserve_whitespace' => false,
			'convert_markdown'    => false,
			'auto_paragraphs'     => true,
		);

		$options = wp_parse_args( $options, $defaults );

		// Escape HTML entities.
		$content = $this->escape_html( $content );

		// Convert double line breaks to paragraphs.
		if ( $options['auto_paragraphs'] ) {
			$content = $this->convert_to_paragraphs( $content );
		}

		return $content;
	}

	/**
	 * Convert sections array to HTML.
	 *
	 * @param array $sections Array of sections with heading and content.
	 * @param array $options  Formatting options.
	 * @return string HTML formatted sections.
	 */
	public function sections_to_html( $sections, $options = array() ) {
		$html_parts = array();

		foreach ( $sections as $section ) {
			if ( empty( $section ) ) {
				continue;
			}

			// Add heading if present.
			if ( ! empty( $section['heading'] ) ) {
				$level = isset( $section['level'] ) ? absint( $section['level'] ) : 2;
				$level = max( 1, min( 6, $level ) ); // Ensure level is 1-6.

				$html_parts[] = sprintf(
					'<h%d>%s</h%d>',
					$level,
					$this->escape_html( $section['heading'] ),
					$level
				);
			}

			// Add content if present.
			if ( ! empty( $section['content'] ) ) {
				$html_parts[] = $this->convert_to_paragraphs( $this->escape_html( $section['content'] ) );
			}
		}

		return implode( "\n", $html_parts );
	}

	/**
	 * Convert text with line breaks to HTML paragraphs.
	 *
	 * @param string $text Text content.
	 * @return string HTML paragraphs.
	 */
	protected function convert_to_paragraphs( $text ) {
		// Split on double line breaks.
		$paragraphs = preg_split( '/\n\s*\n/', $text );
		$html_parts = array();

		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( empty( $paragraph ) ) {
				continue;
			}

			// Convert single line breaks to <br> tags.
			$paragraph = nl2br( $paragraph );

			$html_parts[] = '<p>' . $paragraph . '</p>';
		}

		return implode( "\n", $html_parts );
	}

	/**
	 * Create an HTML table from data.
	 *
	 * @param array $data    Table data (2D array).
	 * @param array $options Table options.
	 * @return string HTML table.
	 */
	public function create_table( $data, $options = array() ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return '';
		}

		$defaults = array(
			'headers'     => true,
			'striped'     => false,
			'bordered'    => true,
			'hover'       => false,
			'align'       => 'left',
		);

		$options = wp_parse_args( $options, $defaults );

		$html = '<table>';

		// Add headers if enabled and data is available.
		if ( $options['headers'] && ! empty( $data[0] ) ) {
			$html .= '<thead><tr>';
			foreach ( $data[0] as $header ) {
				$html .= '<th>' . $this->escape_html( $header ) . '</th>';
			}
			$html .= '</tr></thead>';
			array_shift( $data ); // Remove header row from data.
		}

		// Add body rows.
		if ( ! empty( $data ) ) {
			$html .= '<tbody>';
			foreach ( $data as $row ) {
				$html .= '<tr>';
				foreach ( $row as $cell ) {
					$html .= '<td>' . $this->escape_html( $cell ) . '</td>';
				}
				$html .= '</tr>';
			}
			$html .= '</tbody>';
		}

		$html .= '</table>';

		return $html;
	}

	/**
	 * Create an HTML list.
	 *
	 * @param array  $items   List items.
	 * @param string $type    List type ('ul' or 'ol').
	 * @param array  $options List options.
	 * @return string HTML list.
	 */
	public function create_list( $items, $type = 'ul', $options = array() ) {
		if ( empty( $items ) || ! is_array( $items ) ) {
			return '';
		}

		$type = in_array( $type, array( 'ul', 'ol' ), true ) ? $type : 'ul';

		$html = "<{$type}>";
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				// Nested list.
				$html .= '<li>' . $this->escape_html( $item['text'] );
				if ( ! empty( $item['children'] ) ) {
					$html .= $this->create_list( $item['children'], $type, $options );
				}
				$html .= '</li>';
			} else {
				$html .= '<li>' . $this->escape_html( $item ) . '</li>';
			}
		}
		$html .= "</{$type}>";

		return $html;
	}

	/**
	 * Apply inline styles to HTML element.
	 *
	 * @param string $html   HTML content.
	 * @param array  $styles Associative array of CSS properties and values.
	 * @return string HTML with inline styles.
	 */
	public function apply_inline_styles( $html, $styles ) {
		if ( empty( $styles ) ) {
			return $html;
		}

		$style_string = '';
		foreach ( $styles as $property => $value ) {
			$style_string .= esc_attr( $property ) . ': ' . esc_attr( $value ) . '; ';
		}

		// Add style attribute to the first tag in the HTML.
		$html = preg_replace( '/^<([a-z0-9]+)/', '<$1 style="' . trim( $style_string ) . '"', $html );

		return $html;
	}

	/**
	 * Escape HTML for safe output.
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	protected function escape_html( $text ) {
		return esc_html( $text );
	}

	/**
	 * Add page break to HTML content.
	 *
	 * @return string Page break HTML.
	 */
	public function add_page_break() {
		return '<div class="page-break"></div>';
	}

	/**
	 * Wrap content to avoid page breaks.
	 *
	 * @param string $content Content to wrap.
	 * @return string Wrapped content.
	 */
	public function avoid_page_break( $content ) {
		return '<div class="avoid-break">' . $content . '</div>';
	}
}
