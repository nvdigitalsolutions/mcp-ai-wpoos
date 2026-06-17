<?php
/**
 * Generate Truth Table Tool.
 *
 * Parses a Boolean expression and enumerates its truth table over all
 * combinations of the variables it references. Supports keyword operators
 * (AND, OR, NOT, NAND, NOR, XOR, XNOR) and standard symbolic operators
 * (·, +, ', ⊕, ↑, ↓).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate a truth table for a Boolean expression.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Tool_Generate_Truth_Table implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const MAX_VARIABLES   = 8;
	const MAX_PARSE_DEPTH = 32;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_truth_table';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Truth Table', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Parse a Boolean expression and return its full truth table. Supports keyword operators (AND, OR, NOT, NAND, NOR, XOR, XNOR) and symbolic operators (·, +, \', ⊕, ↑, ↓). Limited to 8 variables.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'expression' => array(
					'type'        => 'string',
					'description' => __( 'Boolean expression, e.g. "A NAND B", "(A AND B) OR NOT C", or "A↑B".', 'mcp-ai-wpoos-pro' ),
				),
				'variables'  => array(
					'type'        => 'array',
					'description' => __( 'Optional explicit ordering of variables. If omitted, variables are auto-detected and ordered alphabetically.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'format'     => array(
					'type'        => 'string',
					'enum'        => array( 'latex', 'markdown_table', 'text', 'both' ),
					'description' => __( 'Output format for the table.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'both',
				),
			),
			'required'   => array( 'expression' ),
		);
	}

	/**
	 * Required WordPress capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * Whether this tool requires the Pro addon.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'cacheable', 'local-only', 'idempotent' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $arguments['expression'] ) || ! is_string( $arguments['expression'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_expression', __( 'Expression is required.', 'mcp-ai-wpoos-pro' ) );
		}
		$expression = (string) $arguments['expression'];
		// Cap the raw input length to bound parse cost.
		if ( strlen( $expression ) > 1000 ) {
			return new WP_Error( 'wp_mcp_ai_expression_too_long', __( 'Expression is too long (1000 char limit).', 'mcp-ai-wpoos-pro' ) );
		}

		$tokens = $this->tokenize( $expression );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$parser         = new \stdClass();
		$parser->tokens = $tokens;
		$parser->pos    = 0;
		$parser->depth  = 0;

		$ast = $this->parse_expr( $parser );
		if ( is_wp_error( $ast ) ) {
			return $ast;
		}
		if ( $parser->pos < count( $parser->tokens ) ) {
			return new WP_Error( 'wp_mcp_ai_unexpected_token', __( 'Unexpected token at end of expression.', 'mcp-ai-wpoos-pro' ) );
		}

		// Detect variables.
		$detected = array();
		$this->collect_vars( $ast, $detected );
		ksort( $detected );
		$detected_vars = array_keys( $detected );

		$vars = array();
		if ( ! empty( $arguments['variables'] ) && is_array( $arguments['variables'] ) ) {
			foreach ( $arguments['variables'] as $v ) {
				$v = sanitize_text_field( (string) $v );
				if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/', $v ) ) {
					return new WP_Error( 'wp_mcp_ai_invalid_variable', __( 'Variables must be alphanumeric identifiers.', 'mcp-ai-wpoos-pro' ) );
				}
				if ( ! in_array( $v, $vars, true ) ) {
					$vars[] = $v;
				}
			}
			// Add detected vars not provided.
			foreach ( $detected_vars as $dv ) {
				if ( ! in_array( $dv, $vars, true ) ) {
					$vars[] = $dv;
				}
			}
		} else {
			$vars = $detected_vars;
		}

		if ( count( $vars ) > self::MAX_VARIABLES ) {
			return new WP_Error(
				'wp_mcp_ai_too_many_variables',
				/* translators: %d: cap on number of variables */
				sprintf( __( 'Too many variables (limit %d).', 'mcp-ai-wpoos-pro' ), self::MAX_VARIABLES )
			);
		}

		$rows  = array();
		$total = 1 << count( $vars );
		for ( $mask = 0; $mask < $total; $mask++ ) {
			$env = array();
			foreach ( $vars as $idx => $name ) {
				// MSB-first so the table reads in canonical order.
				$bit_index    = count( $vars ) - 1 - $idx;
				$env[ $name ] = (bool) ( ( $mask >> $bit_index ) & 1 );
			}
			$value      = $this->eval_ast( $ast, $env );
			$row_values = array();
			foreach ( $vars as $name ) {
				$row_values[ $name ] = $env[ $name ] ? 1 : 0;
			}
			$row_values['result'] = $value ? 1 : 0;
			$rows[]               = $row_values;
		}

		$format = isset( $arguments['format'] ) ? sanitize_key( (string) $arguments['format'] ) : 'both';
		if ( ! in_array( $format, array( 'latex', 'markdown_table', 'text', 'both' ), true ) ) {
			$format = 'both';
		}

		$response = array(
			'success'    => true,
			'expression' => $expression,
			'variables'  => $vars,
			'rows'       => $rows,
			'message'    => sprintf(
				/* translators: 1: row count, 2: variable count */
				__( 'Truth table generated: %1$d rows over %2$d variable(s).', 'mcp-ai-wpoos-pro' ),
				count( $rows ),
				count( $vars )
			),
		);

		if ( 'markdown_table' === $format || 'both' === $format ) {
			$response['markdown_table'] = $this->render_markdown_table( $vars, $rows );
		}
		if ( 'text' === $format || 'both' === $format ) {
			$response['text'] = $this->render_plain_table( $vars, $rows );
		}
		if ( 'latex' === $format || 'both' === $format ) {
			$response['latex'] = $this->render_latex_table( $vars, $rows );
		}

		return $response;
	}

	/**
	 * Tokenize a Boolean expression into a flat list of tokens.
	 *
	 * Token shape: array( 'type' => 'OP'|'VAR'|'LPAREN'|'RPAREN', 'value' => string ).
	 * Operator tokens use canonical names: AND, OR, NOT, NAND, NOR, XOR, XNOR.
	 *
	 * @param string $expression Source expression.
	 * @return array<int,array<string,string>>|WP_Error
	 */
	private function tokenize( $expression ) {
		$tokens = array();
		// Convert multi-byte symbols to ASCII operator words first.
		$replacements = array(
			'⊕' => ' XOR ',
			'·' => ' AND ',
			'↑' => ' NAND ',
			'↓' => ' NOR ',
		);
		$expression   = strtr( $expression, $replacements );

		$length = strlen( $expression );
		$i      = 0;
		while ( $i < $length ) {
			$ch = $expression[ $i ];
			if ( ctype_space( $ch ) ) {
				++$i;
				continue;
			}
			if ( '(' === $ch ) {
				$tokens[] = array(
					'type'  => 'LPAREN',
					'value' => '(',
				);
				++$i;
				continue;
			}
			if ( ')' === $ch ) {
				$tokens[] = array(
					'type'  => 'RPAREN',
					'value' => ')',
				);
				++$i;
				continue;
			}
			if ( '+' === $ch ) {
				$tokens[] = array(
					'type'  => 'OP',
					'value' => 'OR',
				);
				++$i;
				continue;
			}
			if ( "'" === $ch ) {
				// Postfix complement: convert to a NOT applied to the previous.
				// operand by wrapping retroactively. We model it as a postfix.
				// operator token so the parser handles it explicitly.
				$tokens[] = array(
					'type'  => 'OP',
					'value' => 'POSTNOT',
				);
				++$i;
				continue;
			}
			if ( '!' === $ch || '~' === $ch ) {
				$tokens[] = array(
					'type'  => 'OP',
					'value' => 'NOT',
				);
				++$i;
				continue;
			}
			if ( '&' === $ch ) {
				$tokens[] = array(
					'type'  => 'OP',
					'value' => 'AND',
				);
				++$i;
				continue;
			}
			if ( '|' === $ch ) {
				$tokens[] = array(
					'type'  => 'OP',
					'value' => 'OR',
				);
				++$i;
				continue;
			}
			if ( '^' === $ch ) {
				$tokens[] = array(
					'type'  => 'OP',
					'value' => 'XOR',
				);
				++$i;
				continue;
			}
			if ( ctype_alpha( $ch ) || '_' === $ch ) {
				$start = $i;
				while ( $i < $length && ( ctype_alnum( $expression[ $i ] ) || '_' === $expression[ $i ] ) ) {
					++$i;
				}
				$word     = substr( $expression, $start, $i - $start );
				$upper    = strtoupper( $word );
				$keywords = array( 'AND', 'OR', 'NOT', 'NAND', 'NOR', 'XOR', 'XNOR', 'TRUE', 'FALSE' );
				if ( in_array( $upper, $keywords, true ) ) {
					if ( 'TRUE' === $upper ) {
						$tokens[] = array(
							'type'  => 'CONST',
							'value' => '1',
						);
					} elseif ( 'FALSE' === $upper ) {
						$tokens[] = array(
							'type'  => 'CONST',
							'value' => '0',
						);
					} else {
						$tokens[] = array(
							'type'  => 'OP',
							'value' => $upper,
						);
					}
				} elseif ( preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/', $word ) ) {
					$tokens[] = array(
						'type'  => 'VAR',
						'value' => $word,
					);
				} else {
					return new WP_Error( 'wp_mcp_ai_invalid_token', __( 'Invalid identifier in expression.', 'mcp-ai-wpoos-pro' ) );
				}
				continue;
			}
			if ( '0' === $ch || '1' === $ch ) {
				$tokens[] = array(
					'type'  => 'CONST',
					'value' => $ch,
				);
				++$i;
				continue;
			}
			return new WP_Error(
				'wp_mcp_ai_invalid_character',
				/* translators: %s: offending character */
				sprintf( __( 'Invalid character "%s" in expression.', 'mcp-ai-wpoos-pro' ), $ch )
			);
		}
		return $tokens;
	}

	/**
	 * Parse the lowest-precedence level (OR / NOR / XOR / XNOR / NAND).
	 *
	 * Precedence (highest to lowest): NOT (prefix) and postfix complement,
	 * AND, NAND, XOR/XNOR, OR/NOR. NAND is grouped with AND and NOR with OR
	 * for a familiar left-to-right reading; this matches how the IGCSE
	 * curriculum presents the operators.
	 *
	 * @param object $parser Parser state.
	 * @return array|WP_Error
	 */
	private function parse_expr( $parser ) {
		return $this->parse_or( $parser );
	}

	/**
	 * Parse OR / NOR layer.
	 *
	 * @param object $parser Parser state.
	 * @return array|WP_Error
	 */
	private function parse_or( $parser ) {
		++$parser->depth;
		if ( $parser->depth > self::MAX_PARSE_DEPTH ) {
			return new WP_Error( 'wp_mcp_ai_parse_too_deep', __( 'Expression nests too deeply.', 'mcp-ai-wpoos-pro' ) );
		}

		$left = $this->parse_xor( $parser );
		if ( is_wp_error( $left ) ) {
			--$parser->depth;
			return $left;
		}
		while ( $this->peek_op( $parser, array( 'OR', 'NOR' ) ) ) {
			$op = $parser->tokens[ $parser->pos ]['value'];
			++$parser->pos;
			$right = $this->parse_xor( $parser );
			if ( is_wp_error( $right ) ) {
				--$parser->depth;
				return $right;
			}
			$left = array(
				'type'  => $op,
				'left'  => $left,
				'right' => $right,
			);
		}
		--$parser->depth;
		return $left;
	}

	/**
	 * Parse XOR / XNOR layer.
	 *
	 * @param object $parser Parser state.
	 * @return array|WP_Error
	 */
	private function parse_xor( $parser ) {
		++$parser->depth;
		if ( $parser->depth > self::MAX_PARSE_DEPTH ) {
			return new WP_Error( 'wp_mcp_ai_parse_too_deep', __( 'Expression nests too deeply.', 'mcp-ai-wpoos-pro' ) );
		}
		$left = $this->parse_and( $parser );
		if ( is_wp_error( $left ) ) {
			--$parser->depth;
			return $left;
		}
		while ( $this->peek_op( $parser, array( 'XOR', 'XNOR' ) ) ) {
			$op = $parser->tokens[ $parser->pos ]['value'];
			++$parser->pos;
			$right = $this->parse_and( $parser );
			if ( is_wp_error( $right ) ) {
				--$parser->depth;
				return $right;
			}
			$left = array(
				'type'  => $op,
				'left'  => $left,
				'right' => $right,
			);
		}
		--$parser->depth;
		return $left;
	}

	/**
	 * Parse AND / NAND layer.
	 *
	 * @param object $parser Parser state.
	 * @return array|WP_Error
	 */
	private function parse_and( $parser ) {
		++$parser->depth;
		if ( $parser->depth > self::MAX_PARSE_DEPTH ) {
			return new WP_Error( 'wp_mcp_ai_parse_too_deep', __( 'Expression nests too deeply.', 'mcp-ai-wpoos-pro' ) );
		}
		$left = $this->parse_unary( $parser );
		if ( is_wp_error( $left ) ) {
			--$parser->depth;
			return $left;
		}
		while ( $this->peek_op( $parser, array( 'AND', 'NAND' ) ) ) {
			$op = $parser->tokens[ $parser->pos ]['value'];
			++$parser->pos;
			$right = $this->parse_unary( $parser );
			if ( is_wp_error( $right ) ) {
				--$parser->depth;
				return $right;
			}
			$left = array(
				'type'  => $op,
				'left'  => $left,
				'right' => $right,
			);
		}
		--$parser->depth;
		return $left;
	}

	/**
	 * Parse a unary (prefix NOT) expression with optional postfix complement.
	 *
	 * @param object $parser Parser state.
	 * @return array|WP_Error
	 */
	private function parse_unary( $parser ) {
		++$parser->depth;
		if ( $parser->depth > self::MAX_PARSE_DEPTH ) {
			return new WP_Error( 'wp_mcp_ai_parse_too_deep', __( 'Expression nests too deeply.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $this->peek_op( $parser, array( 'NOT' ) ) ) {
			++$parser->pos;
			$inner = $this->parse_unary( $parser );
			if ( is_wp_error( $inner ) ) {
				--$parser->depth;
				return $inner;
			}
			--$parser->depth;
			return $this->maybe_postfix_complement(
				$parser,
				array(
					'type'  => 'NOT',
					'right' => $inner,
				)
			);
		}
		$primary = $this->parse_primary( $parser );
		if ( is_wp_error( $primary ) ) {
			--$parser->depth;
			return $primary;
		}
		--$parser->depth;
		return $this->maybe_postfix_complement( $parser, $primary );
	}

	/**
	 * If a postfix complement (`'`) immediately follows, wrap in NOT.
	 *
	 * @param object $parser Parser state.
	 * @param array  $node   Current AST node.
	 * @return array
	 */
	private function maybe_postfix_complement( $parser, $node ) {
		while ( $this->peek_op( $parser, array( 'POSTNOT' ) ) ) {
			++$parser->pos;
			$node = array(
				'type'  => 'NOT',
				'right' => $node,
			);
		}
		return $node;
	}

	/**
	 * Parse a primary expression (variable, constant, or parenthesised group).
	 *
	 * @param object $parser Parser state.
	 * @return array|WP_Error
	 */
	private function parse_primary( $parser ) {
		if ( $parser->pos >= count( $parser->tokens ) ) {
			return new WP_Error( 'wp_mcp_ai_unexpected_eof', __( 'Unexpected end of expression.', 'mcp-ai-wpoos-pro' ) );
		}
		$tok = $parser->tokens[ $parser->pos ];
		if ( 'LPAREN' === $tok['type'] ) {
			++$parser->pos;
			$inner = $this->parse_expr( $parser );
			if ( is_wp_error( $inner ) ) {
				return $inner;
			}
			if ( $parser->pos >= count( $parser->tokens ) || 'RPAREN' !== $parser->tokens[ $parser->pos ]['type'] ) {
				return new WP_Error( 'wp_mcp_ai_unbalanced_parens', __( 'Unbalanced parentheses.', 'mcp-ai-wpoos-pro' ) );
			}
			++$parser->pos;
			return $inner;
		}
		if ( 'VAR' === $tok['type'] ) {
			++$parser->pos;
			return array(
				'type' => 'VAR',
				'name' => $tok['value'],
			);
		}
		if ( 'CONST' === $tok['type'] ) {
			++$parser->pos;
			return array(
				'type'  => 'CONST',
				'value' => '1' === $tok['value'],
			);
		}
		return new WP_Error(
			'wp_mcp_ai_unexpected_token',
			/* translators: %s: token value */
			sprintf( __( 'Unexpected token "%s".', 'mcp-ai-wpoos-pro' ), $tok['value'] )
		);
	}

	/**
	 * Peek the current token to see if it is one of the listed operators.
	 *
	 * @param object        $parser Parser state.
	 * @param array<string> $ops    List of operator names.
	 * @return bool
	 */
	private function peek_op( $parser, array $ops ) {
		if ( $parser->pos >= count( $parser->tokens ) ) {
			return false;
		}
		$tok = $parser->tokens[ $parser->pos ];
		return 'OP' === $tok['type'] && in_array( $tok['value'], $ops, true );
	}

	/**
	 * Collect variable names from an AST.
	 *
	 * @param array $node     AST node.
	 * @param array $detected Output map (name => true).
	 * @return void
	 */
	private function collect_vars( $node, array &$detected ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( isset( $node['type'] ) && 'VAR' === $node['type'] ) {
			$detected[ $node['name'] ] = true;
			return;
		}
		if ( isset( $node['left'] ) ) {
			$this->collect_vars( $node['left'], $detected );
		}
		if ( isset( $node['right'] ) ) {
			$this->collect_vars( $node['right'], $detected );
		}
	}

	/**
	 * Evaluate the AST under the given variable environment.
	 *
	 * @param array $node AST node.
	 * @param array $env  Variable environment.
	 * @return bool
	 */
	private function eval_ast( $node, array $env ) {
		switch ( $node['type'] ) {
			case 'VAR':
				return ! empty( $env[ $node['name'] ] );
			case 'CONST':
				return (bool) $node['value'];
			case 'NOT':
				return ! $this->eval_ast( $node['right'], $env );
			case 'AND':
				return $this->eval_ast( $node['left'], $env ) && $this->eval_ast( $node['right'], $env );
			case 'OR':
				return $this->eval_ast( $node['left'], $env ) || $this->eval_ast( $node['right'], $env );
			case 'NAND':
				return ! ( $this->eval_ast( $node['left'], $env ) && $this->eval_ast( $node['right'], $env ) );
			case 'NOR':
				return ! ( $this->eval_ast( $node['left'], $env ) || $this->eval_ast( $node['right'], $env ) );
			case 'XOR':
				return $this->eval_ast( $node['left'], $env ) !== $this->eval_ast( $node['right'], $env );
			case 'XNOR':
				return $this->eval_ast( $node['left'], $env ) === $this->eval_ast( $node['right'], $env );
			default:
				// Defensive fallback. The parser only emits the node types.
				// listed above, so this branch is unreachable in normal use;.
				// returning false is the safe Boolean default and avoids.
				// silently propagating an unexpected truthy value.
				return false;
		}
	}

	/**
	 * Render a markdown-style truth table.
	 *
	 * @param array<string> $vars Variable names.
	 * @param array         $rows Row data.
	 * @return string
	 */
	private function render_markdown_table( array $vars, array $rows ) {
		$headers = array_merge( $vars, array( 'Result' ) );
		$lines   = array();
		$lines[] = '| ' . implode( ' | ', $headers ) . ' |';
		$sep     = array();
		foreach ( $headers as $h ) {
			$sep[] = str_repeat( '-', max( 3, strlen( $h ) ) );
		}
		$lines[] = '| ' . implode( ' | ', $sep ) . ' |';
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $vars as $v ) {
				$cells[] = (string) $row[ $v ];
			}
			$cells[] = (string) $row['result'];
			$lines[] = '| ' . implode( ' | ', $cells ) . ' |';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Render a plain-text truth table.
	 *
	 * @param array<string> $vars Variable names.
	 * @param array         $rows Row data.
	 * @return string
	 */
	private function render_plain_table( array $vars, array $rows ) {
		$lines   = array();
		$lines[] = implode( ' ', $vars ) . ' | Result';
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $vars as $v ) {
				$cells[] = (string) $row[ $v ];
			}
			$lines[] = implode( ' ', $cells ) . ' | ' . (string) $row['result'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * Render the truth table as a LaTeX `array` environment.
	 *
	 * @param array<string> $vars Variable names.
	 * @param array         $rows Row data.
	 * @return string
	 */
	private function render_latex_table( array $vars, array $rows ) {
		$cols   = str_repeat( 'c', count( $vars ) ) . '|c';
		$header = implode( ' & ', $vars ) . ' & \\text{Result}';
		$body   = array();
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $vars as $v ) {
				$cells[] = (string) $row[ $v ];
			}
			$cells[] = (string) $row['result'];
			$body[]  = implode( ' & ', $cells ) . ' \\\\';
		}
		return "\\begin{array}{" . $cols . '} ' . $header . ' \\\\ \\hline ' . implode( ' ', $body ) . " \\end{array}";
	}
}
