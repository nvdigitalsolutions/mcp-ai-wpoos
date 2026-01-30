<?php
/**
 * Extract Structured Data Tool
 *
 * Extract structured data from HTML/text using CSS selectors and patterns.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extract Structured Data Tool Class
 */
class WP_MCP_AI_Pro_Tool_Extract_Structured_Data {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'extract_structured_data';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'extract_structured_data',
			'description'         => 'Extract structured data from HTML or text content using CSS selectors, regex patterns, or semantic analysis. Perfect for scraping, data mining, and content analysis.',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'content'       => array(
						'type'        => 'string',
						'description' => 'HTML or text content to parse',
					),
					'selectors'     => array(
						'type'        => 'object',
						'description' => 'CSS selectors or field patterns to extract (e.g., {"title": "h1", "price": ".product-price"})',
					),
					'output_format' => array(
						'type'        => 'string',
						'enum'        => array( 'json', 'markdown', 'csv' ),
						'description' => 'Output format (default: json)',
						'default'     => 'json',
					),
				),
				'required'   => array( 'content', 'selectors' ),
			),
			'required_capability' => 'edit_posts',
			'category'            => array( 'research', 'orchestration' ),
		);
	}

	/**
	 * Execute tool
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( empty( $arguments['content'] ) || empty( $arguments['selectors'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Content and selectors are required',
			);
		}

		$content       = $arguments['content'];
		$selectors     = $arguments['selectors'];
		$output_format = isset( $arguments['output_format'] ) ? $arguments['output_format'] : 'json';

		// Extract data based on selectors.
		$extracted_data = $this->extract_data( $content, $selectors );

		// Format output.
		$formatted = $this->format_output( $extracted_data, $output_format );

		return array(
			'success'          => true,
			'extracted_data'   => $extracted_data,
			'formatted_output' => $formatted,
			'fields_extracted' => count( $extracted_data ),
			'output_format'    => $output_format,
		);
	}

	/**
	 * Extract data from content
	 *
	 * @param string $content   Content to parse.
	 * @param array  $selectors Selectors to use.
	 * @return array
	 */
	private function extract_data( $content, $selectors ) {
		$data = array();

		// Simple extraction using regex patterns.
		foreach ( $selectors as $field => $selector ) {
			// If selector looks like HTML tag, extract it.
			if ( preg_match( '/^[a-z0-9\-\.#\[\]]+$/i', $selector ) ) {
				$data[ $field ] = $this->extract_by_tag( $content, $selector );
			} else {
				// Treat as regex pattern.
				$data[ $field ] = $this->extract_by_pattern( $content, $selector );
			}
		}

		return $data;
	}

	/**
	 * Extract by HTML tag
	 *
	 * @param string $content Content.
	 * @param string $tag     Tag name.
	 * @return string|array
	 */
	private function extract_by_tag( $content, $tag ) {
		// Simple regex-based extraction.
		$pattern = '/<' . preg_quote( $tag, '/' ) . '[^>]*>(.*?)<\/' . preg_quote( $tag, '/' ) . '>/is';
		if ( preg_match_all( $pattern, $content, $matches ) ) {
			return count( $matches[1] ) === 1 ? $matches[1][0] : $matches[1];
		}
		return '';
	}

	/**
	 * Extract by pattern
	 *
	 * @param string $content Content.
	 * @param string $pattern Pattern.
	 * @return string|array
	 */
	private function extract_by_pattern( $content, $pattern ) {
		if ( preg_match_all( '/' . $pattern . '/i', $content, $matches ) ) {
			return count( $matches[0] ) === 1 ? $matches[0][0] : $matches[0];
		}
		return '';
	}

	/**
	 * Format output
	 *
	 * @param array  $data   Extracted data.
	 * @param string $format Output format.
	 * @return string
	 */
	private function format_output( $data, $format ) {
		if ( 'json' === $format ) {
			return wp_json_encode( $data, JSON_PRETTY_PRINT );
		}

		if ( 'csv' === $format ) {
			$csv = '';
			foreach ( $data as $key => $value ) {
				$csv .= sprintf( "%s,%s\n", $key, is_array( $value ) ? implode( ';', $value ) : $value );
			}
			return $csv;
		}

		// Markdown.
		$md = "# Extracted Data\n\n";
		foreach ( $data as $key => $value ) {
			$md .= '## ' . ucfirst( $key ) . "\n\n";
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					$md .= "- {$item}\n";
				}
			} else {
				$md .= "{$value}\n";
			}
			$md .= "\n";
		}
		return $md;
	}
}
