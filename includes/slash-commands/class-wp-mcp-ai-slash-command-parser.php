<?php
/**
 * Slash Command Parser
 *
 * Parses slash command syntax and extracts command name, arguments, and flags.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slash Command Parser Class
 *
 * Handles parsing of slash command input with support for:
 * - Command name extraction
 * - Positional arguments
 * - Long flags (--flag=value)
 * - Short flags (-f value)
 * - Quoted strings
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Slash_Command_Parser {

	/**
	 * Parse slash command input
	 *
	 * @param string $input Raw user input starting with /.
	 * @return array|WP_Error Parsed command data or error.
	 */
	public function parse( $input ) {
		$input = trim( $input );

		// Validate input starts with /
		if ( empty( $input ) || '/' !== $input[0] ) {
			return new WP_Error(
				'invalid_command_syntax',
				__( 'Commands must start with /', 'mcp-ai-wpoos' )
			);
		}

		// Remove leading slash
		$input = substr( $input, 1 );

		// Extract command name (allowing alphanumeric, underscores, and hyphens for multi-word commands)
		if ( ! preg_match( '/^([a-zA-Z0-9_-]+)(.*)$/s', $input, $matches ) ) {
			return new WP_Error(
				'invalid_command_name',
				__( 'Invalid command name', 'mcp-ai-wpoos' )
			);
		}

		$command     = $matches[1];
		$args_string = trim( $matches[2] );

		// Parse arguments and flags
		$parsed_args = $this->parse_arguments( $args_string );

		return array(
			'command'   => $command,
			'raw_input' => '/' . $input,
			'args'      => $parsed_args['positional'],
			'flags'     => $parsed_args['flags'],
			'raw_args'  => $args_string,
		);
	}

	/**
	 * Parse command arguments and flags
	 *
	 * Supports:
	 * - Positional arguments
	 * - Long flags: --key=value or --key value
	 * - Short flags: -k value
	 * - Quoted strings: "value with spaces"
	 *
	 * @param string $args_string Raw arguments string.
	 * @return array Associative array with 'positional' and 'flags' keys.
	 */
	private function parse_arguments( $args_string ) {
		$parsed = array(
			'positional' => array(),
			'flags'      => array(),
		);

		if ( empty( $args_string ) ) {
			return $parsed;
		}

		// Tokenize the arguments string
		$tokens = $this->tokenize( $args_string );

		$i = 0;
		while ( $i < count( $tokens ) ) {
			$token = $tokens[ $i ];

			// Long flag: --key=value
			if ( preg_match( '/^--([a-zA-Z0-9_-]+)=(.+)$/', $token, $matches ) ) {
				$parsed['flags'][ $matches[1] ] = $this->unquote( $matches[2] );
				++$i;
				continue;
			}

			// Long flag: --key (value in next token or boolean true)
			if ( preg_match( '/^--([a-zA-Z0-9_-]+)$/', $token, $matches ) ) {
				$key = $matches[1];
				// Check if next token is a value (not another flag)
				if ( isset( $tokens[ $i + 1 ] ) && ! $this->is_flag( $tokens[ $i + 1 ] ) ) {
					$parsed['flags'][ $key ] = $this->unquote( $tokens[ $i + 1 ] );
					$i                      += 2;
				} else {
					// Boolean flag
					$parsed['flags'][ $key ] = true;
					++$i;
				}
				continue;
			}

			// Short flag: -k value
			if ( preg_match( '/^-([a-zA-Z0-9])$/', $token, $matches ) ) {
				$key = $matches[1];
				// Check if next token is a value
				if ( isset( $tokens[ $i + 1 ] ) && ! $this->is_flag( $tokens[ $i + 1 ] ) ) {
					$parsed['flags'][ $key ] = $this->unquote( $tokens[ $i + 1 ] );
					$i                      += 2;
				} else {
					// Boolean flag
					$parsed['flags'][ $key ] = true;
					++$i;
				}
				continue;
			}

			// Positional argument
			$parsed['positional'][] = $this->unquote( $token );
			++$i;
		}

		return $parsed;
	}

	/**
	 * Tokenize arguments string respecting quotes
	 *
	 * @param string $input Arguments string.
	 * @return array Array of tokens.
	 */
	private function tokenize( $input ) {
		$tokens        = array();
		$length        = strlen( $input );
		$current_token = '';
		$in_quotes     = false;
		$quote_char    = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $input[ $i ];

			// Handle quotes
			if ( ( '"' === $char || "'" === $char ) && ! $in_quotes ) {
				$in_quotes      = true;
				$quote_char     = $char;
				$current_token .= $char;
				continue;
			}

			if ( $in_quotes && $char === $quote_char ) {
				$in_quotes      = false;
				$current_token .= $char;
				continue;
			}

			// Handle whitespace (token separator when not in quotes)
			if ( ! $in_quotes && ( ' ' === $char || "\t" === $char ) ) {
				if ( ! empty( $current_token ) ) {
					$tokens[]      = $current_token;
					$current_token = '';
				}
				continue;
			}

			$current_token .= $char;
		}

		// Add last token
		if ( ! empty( $current_token ) ) {
			$tokens[] = $current_token;
		}

		return $tokens;
	}

	/**
	 * Check if token is a flag
	 *
	 * @param string $token Token to check.
	 * @return bool True if token is a flag.
	 */
	private function is_flag( $token ) {
		return 0 === strpos( $token, '-' );
	}

	/**
	 * Remove quotes from string
	 *
	 * @param string $value Value to unquote.
	 * @return string Unquoted value.
	 */
	private function unquote( $value ) {
		$value = trim( $value );
		if ( ( '"' === $value[0] && '"' === $value[ strlen( $value ) - 1 ] ) ||
			( "'" === $value[0] && "'" === $value[ strlen( $value ) - 1 ] ) ) {
			return substr( $value, 1, -1 );
		}
		return $value;
	}

	/**
	 * Validate command syntax
	 *
	 * @param string $input Raw command input.
	 * @return bool True if valid syntax.
	 */
	public function is_valid_syntax( $input ) {
		$result = $this->parse( $input );
		return ! is_wp_error( $result );
	}

	/**
	 * Get command name from input
	 *
	 * @param string $input Raw command input.
	 * @return string|false Command name or false on error.
	 */
	public function get_command_name( $input ) {
		$result = $this->parse( $input );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		return $result['command'];
	}
}
