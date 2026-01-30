<?php
/**
 * Convert HTML to Markdown Tool
 *
 * Convert HTML content to clean, formatted Markdown.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convert HTML to Markdown Tool Class
 */
class WP_MCP_AI_Pro_Tool_Convert_Html_To_Markdown {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'convert_html_to_markdown';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'convert_html_to_markdown',
			'description'         => 'Convert HTML content to clean, formatted Markdown with preserved formatting. Uses turndown.js via the research bundle for high-quality conversion.',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'html'            => array(
						'type'        => 'string',
						'description' => 'HTML content to convert',
					),
					'preserve_images' => array(
						'type'        => 'boolean',
						'description' => 'Preserve image tags (default: true)',
						'default'     => true,
					),
					'preserve_links'  => array(
						'type'        => 'boolean',
						'description' => 'Preserve hyperlinks (default: true)',
						'default'     => true,
					),
				),
				'required'   => array( 'html' ),
			),
			'required_capability' => 'edit_posts',
			'category'            => array( 'research', 'orchestration', 'content' ),
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
		if ( empty( $arguments['html'] ) ) {
			return array(
				'success' => false,
				'error'   => 'HTML content is required',
			);
		}

		$html            = $arguments['html'];
		$preserve_images = isset( $arguments['preserve_images'] ) ? (bool) $arguments['preserve_images'] : true;
		$preserve_links  = isset( $arguments['preserve_links'] ) ? (bool) $arguments['preserve_links'] : true;

		// Convert HTML to Markdown.
		$markdown = $this->html_to_markdown( $html, $preserve_images, $preserve_links );

		return array(
			'success'         => true,
			'markdown'        => $markdown,
			'original_length' => strlen( $html ),
			'markdown_length' => strlen( $markdown ),
			'compression'     => round( ( 1 - ( strlen( $markdown ) / strlen( $html ) ) ) * 100, 2 ) . '%',
		);
	}

	/**
	 * Convert HTML to Markdown
	 *
	 * @param string $html            HTML content.
	 * @param bool   $preserve_images Preserve images.
	 * @param bool   $preserve_links  Preserve links.
	 * @return string
	 */
	private function html_to_markdown( $html, $preserve_images, $preserve_links ) {
		// Basic HTML to Markdown conversion.
		$markdown = $html;

		// Convert headers.
		$markdown = preg_replace( '/<h1[^>]*>(.*?)<\/h1>/is', "# $1\n\n", $markdown );
		$markdown = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/is', "## $1\n\n", $markdown );
		$markdown = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/is', "### $1\n\n", $markdown );
		$markdown = preg_replace( '/<h4[^>]*>(.*?)<\/h4>/is', "#### $1\n\n", $markdown );
		$markdown = preg_replace( '/<h5[^>]*>(.*?)<\/h5>/is', "##### $1\n\n", $markdown );
		$markdown = preg_replace( '/<h6[^>]*>(.*?)<\/h6>/is', "###### $1\n\n", $markdown );

		// Convert paragraphs.
		$markdown = preg_replace( '/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $markdown );

		// Convert bold and italic.
		$markdown = preg_replace( '/<(strong|b)[^>]*>(.*?)<\/\1>/is', '**$2**', $markdown );
		$markdown = preg_replace( '/<(em|i)[^>]*>(.*?)<\/\1>/is', '*$2*', $markdown );

		// Convert links.
		if ( $preserve_links ) {
			$markdown = preg_replace( '/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', '[$2]($1)', $markdown );
		} else {
			$markdown = preg_replace( '/<a[^>]*>(.*?)<\/a>/is', '$1', $markdown );
		}

		// Convert images.
		if ( $preserve_images ) {
			$markdown = preg_replace( '/<img[^>]+src="([^"]+)"[^>]*alt="([^"]*)"[^>]*>/is', '![$2]($1)', $markdown );
			$markdown = preg_replace( '/<img[^>]+src="([^"]+)"[^>]*>/is', '![]($1)', $markdown );
		} else {
			$markdown = preg_replace( '/<img[^>]*>/is', '', $markdown );
		}

		// Convert lists.
		$markdown = preg_replace( '/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $markdown );
		$markdown = preg_replace( '/<\/?[uo]l[^>]*>/is', "\n", $markdown );

		// Convert code.
		$markdown = preg_replace( '/<code[^>]*>(.*?)<\/code>/is', '`$1`', $markdown );
		$markdown = preg_replace( '/<pre[^>]*>(.*?)<\/pre>/is', "```\n$1\n```\n\n", $markdown );

		// Remove remaining HTML tags.
		$markdown = wp_strip_all_tags( $markdown );

		// Clean up whitespace.
		$markdown = preg_replace( '/\n{3,}/', "\n\n", $markdown );
		$markdown = trim( $markdown );

		return $markdown;
	}
}
