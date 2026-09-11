<?php
/**
 * Markdown to HTML converter for email delivery.
 *
 * A small, dependency-free converter covering the subset of Markdown that
 * assistant-produced results emit: ATX headings, emphasis, inline code,
 * fenced code blocks, unordered/ordered lists, blockquotes, links, GFM
 * tables and horizontal rules. Every text node is HTML-escaped before any
 * markup is applied and the assembled fragment is re-validated through a
 * wp_kses allowlist, so no raw assistant HTML can reach an email body.
 * Styling is inline-only (email-safe: no <style> blocks or external CSS).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Markdown_Converter' ) ) {

	/**
	 * Markdown to safe, email-friendly HTML converter.
	 */
	class WP_MCP_AI_Markdown_Converter {

		/**
		 * Convert Markdown source to sanitized, email-safe HTML.
		 *
		 * @param string $markdown Markdown source.
		 * @return string Sanitized HTML fragment, or empty string on empty input.
		 */
		public static function to_html( $markdown ) {
			$md = (string) $markdown;
			$md = str_replace( array( "\r\n", "\r" ), "\n", $md );

			if ( '' === trim( $md ) ) {
				return '';
			}

			// Unforgeable per-call token prefix used for inline placeholders.
			$salt   = substr( md5( uniqid( 'mdx', true ) ), 0, 8 );
			$tokens = array();
			$br     = 'BR' . $salt;

			$lines = explode( "\n", $md );
			$count = count( $lines );
			$html  = array();

			$i = 0;
			while ( $i < $count ) {
				$line = $lines[ $i ];

				// Blank line.
				if ( '' === trim( $line ) ) {
					++$i;
					continue;
				}

				// Fenced code block (``` or ~~~).
				if ( preg_match( '/^[ \t]{0,3}(`{3,}|~{3,})/', $line, $fence ) ) {
					$fence_char = $fence[1][0];
					$code_lines = array();
					++$i;
					while ( $i < $count ) {
						$probe = trim( $lines[ $i ] );
						if ( '`' === $fence_char && preg_match( '/^`{3,}[ \t]*$/', $probe ) ) {
							++$i;
							break;
						}
						if ( '~' === $fence_char && preg_match( '/^~{3,}[ \t]*$/', $probe ) ) {
							++$i;
							break;
						}
						$code_lines[] = $lines[ $i ];
						++$i;
					}
					$html[] = self::code_block_html( implode( "\n", $code_lines ) );
					continue;
				}

				// Horizontal rule (also matches "---", "* * *", "___").
				if ( preg_match( '/^[ \t]{0,3}((-[ \t]*){3,}|(\*[ \t]*){3,}|(_[ \t]*){3,})$/', $line ) ) {
					$html[] = '<hr style="border:0;border-top:1px solid #d0d7de;margin:16px 0">';
					++$i;
					continue;
				}

				// ATX heading.
				if ( preg_match( '/^(#{1,6})[ \t]+(.+)$/', $line, $heading ) ) {
					$level  = strlen( $heading[1] );
					$html[] = '<h' . $level . ' style="margin:16px 0 8px;line-height:1.3">'
						. self::render_inline( $heading[2], $tokens, $salt )
						. '</h' . $level . '>';
					++$i;
					continue;
				}

				// GFM table.
				if ( self::is_table_start( $lines, $i, $count ) ) {
					$html[] = self::render_table_block( $lines, $i, $tokens, $salt );
					continue;
				}

				// Blockquote.
				if ( preg_match( '/^[ \t]{0,3}>[ \t]?(.*)$/', $line, $quote ) ) {
					$quote_lines = array( $quote[1] );
					++$i;
					while ( $i < $count && preg_match( '/^[ \t]{0,3}>[ \t]?(.*)$/', $lines[ $i ], $quote_more ) ) {
						$quote_lines[] = $quote_more[1];
						++$i;
					}
					$html[] = '<blockquote style="border-left:4px solid #d0d7de;margin:0 0 16px;padding:2px 12px;color:#57606a">'
						. self::render_inline( implode( ' ', $quote_lines ), $tokens, $salt )
						. '</blockquote>';
					continue;
				}

				// Unordered list.
				if ( preg_match( '/^[ \t]{0,3}[-*+][ \t]+(.+)$/', $line ) ) {
					$items = array();
					while ( $i < $count && preg_match( '/^[ \t]{0,3}[-*+][ \t]+(.+)$/', $lines[ $i ], $list_item ) ) {
						$items[] = '<li style="margin:4px 0">' . self::render_inline( $list_item[1], $tokens, $salt ) . '</li>';
						++$i;
					}
					$html[] = '<ul style="margin:8px 0 16px;padding-left:24px">' . implode( '', $items ) . '</ul>';
					continue;
				}

				// Ordered list.
				if ( preg_match( '/^[ \t]{0,3}\d{1,9}[.)][ \t]+(.+)$/', $line ) ) {
					$items = array();
					while ( $i < $count && preg_match( '/^[ \t]{0,3}\d{1,9}[.)][ \t]+(.+)$/', $lines[ $i ], $list_item ) ) {
						$items[] = '<li style="margin:4px 0">' . self::render_inline( $list_item[1], $tokens, $salt ) . '</li>';
						++$i;
					}
					$html[] = '<ol style="margin:8px 0 16px;padding-left:24px">' . implode( '', $items ) . '</ol>';
					continue;
				}

				// Paragraph — accumulate until a block-level boundary.
				$para = array( $line );
				++$i;
				while ( $i < $count ) {
					$next = $lines[ $i ];
					if ( '' === trim( $next )
						|| preg_match( '/^[ \t]{0,3}(`{3,}|~{3,})/', $next )
						|| preg_match( '/^[ \t]{0,3}((-[ \t]*){3,}|(\*[ \t]*){3,}|(_[ \t]*){3,})$/', $next )
						|| preg_match( '/^(#{1,6})[ \t]+(.+)$/', $next )
						|| self::is_table_start( $lines, $i, $count )
						|| preg_match( '/^[ \t]{0,3}>[ \t]?/', $next )
						|| preg_match( '/^[ \t]{0,3}[-*+][ \t]+/', $next )
						|| preg_match( '/^[ \t]{0,3}\d{1,9}[.)][ \t]+/', $next )
					) {
						break;
					}
					$para[] = $next;
					++$i;
				}

				$text = implode( "\n", $para );
				// Two or more trailing spaces signal a hard line break.
				$text   = preg_replace( '/ {2,}\n/', $br, $text );
				$text   = str_replace( "\n", ' ', $text );
				$inline = self::render_inline( $text, $tokens, $salt );
				$inline = str_replace( $br, '<br>', $inline );
				$html[] = '<p style="margin:0 0 12px">' . $inline . '</p>';
			}

			// Defense in depth: re-validate the assembled fragment. Only the
			// allowlisted tags (and safe attribute values) can survive.
			return wp_kses( implode( "\n", $html ), self::allowed_html() );
		}

		/**
		 * Convert inline Markdown (emphasis, code, links) to safe HTML.
		 *
		 * Text is escaped first; Markdown punctuation survives escaping and is
		 * then replaced with markup. Protected spans (inline code, links) are
		 * restored last so their content is never re-processed.
		 *
		 * @param string   $text   Inline Markdown.
		 * @param string[] $tokens Placeholder map (token key => safe HTML).
		 * @param string   $salt   Per-call token salt.
		 * @return string Inline HTML.
		 */
		protected static function render_inline( $text, &$tokens, $salt ) {
			// Protect inline code spans.
			$text = preg_replace_callback(
				'/`([^`\n]+)`/',
				function ( $matches ) use ( &$tokens, $salt ) {
					$key            = 'I' . $salt . count( $tokens );
					$tokens[ $key ] = '<code style="background:#f6f8fa;border-radius:3px;padding:1px 5px;font-size:13px">' . self::escape_code( $matches[1] ) . '</code>';
					return $key;
				},
				$text
			);

			// Protect links (protocols restricted to http, https, mailto).
			$text = preg_replace_callback(
				'/\[([^\]\n]+)\]\(([^)\s]+)\)/',
				function ( $matches ) use ( &$tokens, $salt ) {
					$url = self::safe_url( $matches[2] );
					if ( '' === $url ) {
						// Unsafe destination — drop the URL, keep the label so the
						// text stays readable.
						return $matches[1];
					}
					$key            = 'I' . $salt . count( $tokens );
					$tokens[ $key ] = '<a href="' . $url . '">' . self::escape_text( $matches[1] ) . '</a>';
					return $key;
				},
				$text
			);

			// Escape remaining text.
			$text = self::escape_text( $text );

			// Emphasis (Markdown punctuation survives escaping).
			$text = preg_replace( '/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $text );
			$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
			$text = preg_replace( '/__(.+?)__/', '<strong>$1</strong>', $text );
			$text = preg_replace( '/(^|[^\w*])\*([^*\n]+)\*(?!\*)/', '$1<em>$2</em>', $text );
			$text = preg_replace( '/(^|[^\w])_([^_\n]+)_(?!\w)/', '$1<em>$2</em>', $text );

			// Restore protected spans.
			if ( ! empty( $tokens ) ) {
				$text = str_replace( array_keys( $tokens ), array_values( $tokens ), $text );
			}

			return $text;
		}

		/**
		 * Determine whether a GFM table starts at the given line index.
		 *
		 * A table header line contains a pipe and the following line must be
		 * a delimiter row. When the header does not lead with a pipe, the
		 * delimiter must carry at least two cells to avoid treating ordinary
		 * "text | text" paragraphs followed by a rule as tables.
		 *
		 * @param string[] $lines All source lines.
		 * @param int      $index Candidate header line index.
		 * @param int      $count Total line count.
		 * @return bool True when a table starts at $index.
		 */
		protected static function is_table_start( array $lines, $index, $count ) {
			if ( ( $index + 1 ) >= $count ) {
				return false;
			}
			$header = $lines[ $index ];
			if ( false === strpos( $header, '|' ) ) {
				return false;
			}
			if ( ! self::is_table_delimiter( $lines[ $index + 1 ] ) ) {
				return false;
			}
			if ( '|' === substr( ltrim( $header ), 0, 1 ) ) {
				return true;
			}
			return count( self::split_table_row( $lines[ $index + 1 ] ) ) > 1;
		}

		/**
		 * Check whether a line is a GFM table delimiter row (e.g. "--- | :---:").
		 *
		 * @param string $line Source line.
		 * @return bool True when every cell matches the delimiter shape.
		 */
		protected static function is_table_delimiter( $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '-' ) ) {
				return false;
			}
			$cells = self::split_table_row( $line );
			if ( empty( $cells ) ) {
				return false;
			}
			foreach ( $cells as $cell ) {
				if ( ! preg_match( '/^:?-+:?$/', $cell ) ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * Split a table row into trimmed cells, honouring escaped pipes.
		 *
		 * @param string $line Table row source line.
		 * @return string[] Trimmed cells.
		 */
		protected static function split_table_row( $line ) {
			$line  = str_replace( '\|', "\x01", trim( $line ) );
			$line  = trim( $line, '|' );
			$cells = array();
			foreach ( explode( '|', $line ) as $cell ) {
				$cells[] = str_replace( "\x01", '|', trim( $cell ) );
			}
			return $cells;
		}

		/**
		 * Render a GFM table block (header + delimiter + body rows).
		 *
		 * @param string[] $lines  All source lines.
		 * @param int      $index  Header line index (advanced past the block).
		 * @param string[] $tokens Inline placeholder map.
		 * @param string   $salt   Per-call token salt.
		 * @return string HTML table.
		 */
		protected static function render_table_block( array $lines, &$index, &$tokens, $salt ) {
			$header_cells = self::split_table_row( $lines[ $index ] );
			$delim_cells  = self::split_table_row( $lines[ $index + 1 ] );
			$aligns       = array();
			foreach ( $delim_cells as $cell ) {
				$left  = ':' === substr( $cell, 0, 1 );
				$right = ':' === substr( $cell, -1 );
				if ( $left && $right ) {
					$aligns[] = 'center';
				} elseif ( $left ) {
					$aligns[] = 'left';
				} elseif ( $right ) {
					$aligns[] = 'right';
				} else {
					$aligns[] = '';
				}
			}

			$index += 2;
			$count  = count( $lines );
			$rows   = array();
			while ( $index < $count ) {
				$probe = trim( $lines[ $index ] );
				if ( '' === $probe || '|' !== substr( $probe, 0, 1 ) ) {
					break;
				}
				$rows[] = self::split_table_row( $lines[ $index ] );
				++$index;
			}

			$table  = '<table role="presentation" style="border-collapse:collapse;width:100%;margin:12px 0;font-size:14px">';
			$table .= '<thead><tr>';
			foreach ( $header_cells as $idx => $cell ) {
				$align  = isset( $aligns[ $idx ] ) && '' !== $aligns[ $idx ] ? 'text-align:' . $aligns[ $idx ] . ';' : '';
				$table .= '<th style="border:1px solid #d0d7de;background:#f6f8fa;padding:6px 10px;' . $align . '">'
					. self::render_inline( $cell, $tokens, $salt )
					. '</th>';
			}
			$table .= '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$table .= '<tr>';
				foreach ( $header_cells as $idx => $unused ) {
					$cell   = isset( $row[ $idx ] ) ? $row[ $idx ] : '';
					$align  = isset( $aligns[ $idx ] ) && '' !== $aligns[ $idx ] ? 'text-align:' . $aligns[ $idx ] . ';' : '';
					$table .= '<td style="border:1px solid #d0d7de;padding:6px 10px;' . $align . '">'
						. self::render_inline( $cell, $tokens, $salt )
						. '</td>';
				}
				$table .= '</tr>';
			}
			$table .= '</tbody></table>';

			return $table;
		}

		/**
		 * Sanitize a link destination, allowing only http, https and mailto.
		 *
		 * @param string $url Raw link destination.
		 * @return string Sanitized URL or empty string when rejected.
		 */
		protected static function safe_url( $url ) {
			$safe = esc_url_raw( trim( (string) $url, '<>' ), array( 'http', 'https', 'mailto' ) );
			return is_string( $safe ) ? $safe : '';
		}

		/**
		 * Build a styled <pre><code> block from fenced code content.
		 *
		 * @param string $code Code block content.
		 * @return string HTML pre block with escaped content.
		 */
		protected static function code_block_html( $code ) {
			return '<pre style="background:#f6f8fa;border:1px solid #d0d7de;border-radius:4px;padding:12px;overflow-x:auto;font-size:13px;line-height:1.5;margin:12px 0"><code>'
				. self::escape_code( $code )
				. '</code></pre>';
		}

		/**
		 * Escape regular text for HTML output.
		 *
		 * @param string $text Raw text.
		 * @return string Escaped text.
		 */
		protected static function escape_text( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		}

		/**
		 * Escape code content for HTML output (quotes preserved).
		 *
		 * @param string $code Raw code.
		 * @return string Escaped code.
		 */
		protected static function escape_code( $code ) {
			return htmlspecialchars( (string) $code, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		}

		/**
		 * Return the wp_kses allowlist applied to converted output.
		 *
		 * Only these tags (and safe attribute values) can survive the final
		 * sanitation pass; everything else is stripped.
		 *
		 * @return array<string,array> wp_kses allowlist.
		 */
		public static function allowed_html() {
			return array(
				'p'          => array( 'style' => true ),
				'br'         => array(),
				'hr'         => array( 'style' => true ),
				'h1'         => array( 'style' => true ),
				'h2'         => array( 'style' => true ),
				'h3'         => array( 'style' => true ),
				'h4'         => array( 'style' => true ),
				'h5'         => array( 'style' => true ),
				'h6'         => array( 'style' => true ),
				'strong'     => array(),
				'em'         => array(),
				'code'       => array( 'style' => true ),
				'pre'        => array( 'style' => true ),
				'a'          => array( 'href' => true ),
				'ul'         => array( 'style' => true ),
				'ol'         => array( 'style' => true ),
				'li'         => array( 'style' => true ),
				'blockquote' => array( 'style' => true ),
				'table'      => array(
					'style' => true,
					'role'  => true,
				),
				'thead'      => array(),
				'tbody'      => array(),
				'tr'         => array(),
				'th'         => array( 'style' => true ),
				'td'         => array( 'style' => true ),
			);
		}
	}
}
