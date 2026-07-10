<?php
/**
 * DOM Context Provider (Phase 3 Feature)
 *
 * Pre-processes page HTML using WP_HTML_Tag_Processor (WP 6.2+)
 * to extract interactive elements without regex or DOMDocument.
 *
 * This reduces the context size sent to the LLM by only including
 * actionable elements (buttons, links, inputs, selects, etc.).
 *
 * @package NV_oOS_Page_Agent
 * @since   0.3.0
 *
 * @link    https://developer.wordpress.org/reference/classes/wp_html_tag_processor/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DOM context pre-processor for Page Agent.
 *
 * @since 0.3.0
 */
class WP_MCP_AI_Page_Agent_DOM_Context {

	/**
	 * Tags considered interactive for Page Agent purposes.
	 *
	 * @since 0.3.0
	 * @var array
	 */
	const INTERACTIVE_TAGS = array(
		'A',
		'BUTTON',
		'INPUT',
		'SELECT',
		'TEXTAREA',
		'FORM',
		'DETAILS',
		'SUMMARY',
		'OPTION',
		'LABEL',
	);

	/**
	 * Extract interactive elements from an HTML string.
	 *
	 * Uses WP_HTML_Tag_Processor (WP 6.2+) to safely extract
	 * buttons, links, inputs, selects, and other actionable elements.
	 *
	 * @since 0.3.0
	 *
	 * @param string $html Raw HTML content.
	 * @return array Extracted interactive elements.
	 */
	public function extract_interactive_elements( $html ) {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return array();
		}

		$processor = new WP_HTML_Tag_Processor( $html );
		$elements  = array();

		while ( $processor->next_tag() ) {
			$tag = strtoupper( $processor->get_tag() );

			if ( ! in_array( $tag, self::INTERACTIVE_TAGS, true ) ) {
				continue;
			}

			$element = $this->describe_element( $processor, $tag );

			if ( ! empty( $element ) ) {
				$elements[] = $element;
			}
		}

		return $elements;
	}

	/**
	 * Describe an interactive element as an associative array.
	 *
	 * @since 0.3.0
	 *
	 * @param WP_HTML_Tag_Processor $processor The tag processor positioned at the element.
	 * @param string                 $tag       The element tag name (uppercased).
	 * @return array Associative array describing the element.
	 */
	private function describe_element( $processor, $tag ) {
		$element = array(
			'tag' => strtolower( $tag ),
		);

		// Common attributes.
		$id    = $processor->get_attribute( 'id' );
		$class = $processor->get_attribute( 'class' );
		$role  = $processor->get_attribute( 'role' );

		if ( is_string( $id ) ) {
			$element['id'] = $id;
		}
		if ( is_string( $class ) ) {
			$element['class'] = $class;
		}
		if ( is_string( $role ) ) {
			$element['role'] = $role;
		}

		// Tag-specific attributes.
		switch ( $tag ) {
			case 'A':
				$href = $processor->get_attribute( 'href' );
				if ( is_string( $href ) ) {
					$element['href'] = $href;
				}
				break;

			case 'INPUT':
				$type  = $processor->get_attribute( 'type' );
				$name  = $processor->get_attribute( 'name' );
				$value = $processor->get_attribute( 'value' );

				if ( is_string( $type ) ) {
					$element['type'] = $type;
				}
				if ( is_string( $name ) ) {
					$element['name'] = $name;
				}
				if ( is_string( $value ) ) {
					$element['value'] = $value;
				}
				// Skip hidden inputs — not interactive.
				if ( 'hidden' === $element['type'] ) {
					return array();
				}
				break;

			case 'BUTTON':
				$type = $processor->get_attribute( 'type' );
				if ( is_string( $type ) ) {
					$element['type'] = $type;
				}
				break;

			case 'SELECT':
			case 'TEXTAREA':
				$name = $processor->get_attribute( 'name' );
				if ( is_string( $name ) ) {
					$element['name'] = $name;
				}
				break;

			case 'FORM':
				$action = $processor->get_attribute( 'action' );
				$method = $processor->get_attribute( 'method' );
				if ( is_string( $action ) ) {
					$element['action'] = $action;
				}
				if ( is_string( $method ) ) {
					$element['method'] = $method;
				}
				break;
		}

		// Extract text content (simplified — uses `get_modifiable_text` in WP 6.9+).
		if ( method_exists( $processor, 'get_modifiable_text' ) ) {
			$text = $processor->get_modifiable_text();
			if ( is_string( $text ) ) {
				$element['text'] = trim( $text );
			}
		}

		// Aria attributes.
		$aria_label = $processor->get_attribute( 'aria-label' );
		if ( is_string( $aria_label ) ) {
			$element['ariaLabel'] = $aria_label;
		}

		return $element;
	}

	/**
	 * Filter elements to only those matching allowed CSS classes.
	 *
	 * Useful for admin pages where you want to limit what the agent sees.
	 *
	 * @since 0.3.0
	 *
	 * @param array $elements      Extracted interactive elements.
	 * @param array $allowed_classes Whitelist of CSS classes.
	 * @return array Filtered elements.
	 */
	public function filter_by_class( $elements, $allowed_classes ) {
		if ( empty( $allowed_classes ) ) {
			return $elements;
		}

		return array_filter(
			$elements,
			function ( $el ) use ( $allowed_classes ) {
				if ( empty( $el['class'] ) ) {
					return false;
				}

				$classes = explode( ' ', $el['class'] );
				return (bool) array_intersect( $classes, $allowed_classes );
			}
		);
	}

	/**
	 * Filter elements to exclude those matching blocked CSS classes.
	 *
	 * Safe default for admin pages — hide sensitive elements.
	 *
	 * @since 0.3.0
	 *
	 * @param array $elements        Extracted interactive elements.
	 * @param array $blocked_classes Blacklist of CSS classes.
	 * @return array Filtered elements.
	 */
	public function filter_out_by_class( $elements, $blocked_classes ) {
		if ( empty( $blocked_classes ) ) {
			return $elements;
		}

		return array_filter(
			$elements,
			function ( $el ) use ( $blocked_classes ) {
				if ( empty( $el['class'] ) ) {
					return true; // No class means can't match.
				}

				$classes = explode( ' ', $el['class'] );
				return ! (bool) array_intersect( $classes, $blocked_classes );
			}
		);
	}

	/**
	 * Build a compact text representation of elements for LLM context.
	 *
	 * @since 0.3.0
	 *
	 * @param array $elements Extracted interactive elements.
	 * @param int   $max_elements Maximum number of elements to include.
	 * @return string Text representation.
	 */
	public function build_context_text( $elements, $max_elements = 100 ) {
		if ( empty( $elements ) ) {
			return __( 'No interactive elements found on the page.', 'nvoos-page-agent' );
		}

		$elements = array_slice( $elements, 0, $max_elements );
		$lines    = array();

		foreach ( $elements as $i => $el ) {
			$line = sprintf( '[%d] <%s', $i + 1, $el['tag'] );

			if ( ! empty( $el['type'] ) ) {
				$line .= sprintf( ' type="%s"', $el['type'] );
			}
			if ( ! empty( $el['id'] ) ) {
				$line .= sprintf( ' id="%s"', $el['id'] );
			}
			if ( ! empty( $el['role'] ) ) {
				$line .= sprintf( ' role="%s"', $el['role'] );
			}
			if ( ! empty( $el['text'] ) ) {
				$line .= sprintf( '>%s', $el['text'] );
			}
			if ( ! empty( $el['ariaLabel'] ) ) {
				$line .= sprintf( ' (label: "%s")', $el['ariaLabel'] );
			}

			$line    .= '>';
			$lines[]  = $line;
		}

		if ( count( $elements ) >= $max_elements ) {
			$lines[] = sprintf(
				/* translators: %d: maximum number of elements */
				__( '... (%d elements shown, more available)', 'nvoos-page-agent' ),
				$max_elements
			);
		}

		return implode( "\n", $lines );
	}
}
