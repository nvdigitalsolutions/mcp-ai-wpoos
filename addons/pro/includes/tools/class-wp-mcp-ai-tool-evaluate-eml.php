<?php
/**
 * Evaluate EML Tool — the continuous-mathematics universal operator.
 *
 * The EML operator, defined as
 *
 *     eml(x, y) = exp(x) - ln(y),
 *
 * was introduced by Andrzej Odrzywołek (Institute of Theoretical Physics,
 * Jagiellonian University) in "All elementary functions from a single
 * operator". With the constant `1` it generates the standard repertoire of a
 * scientific calculator — constants such as e, π, i; arithmetic +, −, ×, ÷;
 * exponentiation; and the usual transcendental and algebraic functions. Every
 * such expression becomes a binary tree of identical nodes following the
 * grammar `S → 1 | eml(S, S)`, which is the continuous analogue of how every
 * Boolean expression becomes a binary tree of NAND gates.
 *
 * This tool exposes two modes:
 *
 *  - `evaluate`: parse and numerically evaluate an EML expression tree.
 *  - `decompose`: emit a canonical EML tree for a given elementary function
 *                 using the paper's constructive identities.
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
 * Evaluate or decompose using the EML universal operator.
 *
 * Reference: Odrzywołek, A. "All elementary functions from a single operator,"
 * Institute of Theoretical Physics, Jagiellonian University, Kraków.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Tool_Evaluate_Eml implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const MAX_TREE_DEPTH = 12;
	const MAX_NODE_COUNT = 256;
	const EXP_INPUT_MAX  = 700.0; // exp(700) is finite; exp(710) overflows IEEE-754 doubles.

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'evaluate_eml';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Evaluate EML (Universal Operator)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Evaluate or decompose expressions using the universal binary operator eml(x, y) = exp(x) − ln(y) introduced by Odrzywołek (2024). With the constant 1, EML generates every elementary function — the continuous-mathematics analogue of NAND universality.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'mode'       => array(
					'type'        => 'string',
					'enum'        => array( 'evaluate', 'decompose' ),
					'description' => __( '"evaluate" computes an EML tree; "decompose" returns the EML tree for a named elementary function.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'evaluate',
				),
				'expression' => array(
					'type'        => 'string',
					'description' => __( 'EML expression for evaluate mode, e.g. "eml(1, x)" or "eml(eml(1, x), 1)".', 'mcp-ai-wpoos-pro' ),
				),
				'variables'  => array(
					'type'                 => 'object',
					'description'          => __( 'Map of variable names to numeric values for evaluate mode.', 'mcp-ai-wpoos-pro' ),
					'additionalProperties' => array( 'type' => 'number' ),
				),
				'function'   => array(
					'type'        => 'string',
					'enum'        => array( 'exp', 'ln', 'one', 'e' ),
					'description' => __( 'Function to decompose for decompose mode. Only identities published in the paper are encoded.', 'mcp-ai-wpoos-pro' ),
				),
				'arity_args' => array(
					'type'        => 'array',
					'description' => __( 'Symbolic argument names, e.g. ["x"] for exp.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'mode' ),
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
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$mode = isset( $arguments['mode'] ) ? sanitize_key( (string) $arguments['mode'] ) : 'evaluate';
		if ( ! in_array( $mode, array( 'evaluate', 'decompose' ), true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_mode', __( 'Mode must be "evaluate" or "decompose".', 'mcp-ai-wpoos-pro' ) );
		}

		if ( 'evaluate' === $mode ) {
			return $this->execute_evaluate( $arguments );
		}
		return $this->execute_decompose( $arguments );
	}

	/**
	 * Handle the `evaluate` mode.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function execute_evaluate( array $arguments ) {
		if ( empty( $arguments['expression'] ) || ! is_string( $arguments['expression'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_expression', __( 'Expression is required for evaluate mode.', 'mcp-ai-wpoos-pro' ) );
		}
		$expression = (string) $arguments['expression'];
		if ( strlen( $expression ) > 1000 ) {
			return new WP_Error( 'wp_mcp_ai_expression_too_long', __( 'Expression is too long (1000 char limit).', 'mcp-ai-wpoos-pro' ) );
		}

		$variables = array();
		if ( isset( $arguments['variables'] ) && is_array( $arguments['variables'] ) ) {
			foreach ( $arguments['variables'] as $name => $value ) {
				$name = sanitize_text_field( (string) $name );
				if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name ) ) {
					return new WP_Error( 'wp_mcp_ai_invalid_variable_name', __( 'Variable names must be valid identifiers.', 'mcp-ai-wpoos-pro' ) );
				}
				if ( ! is_numeric( $value ) ) {
					return new WP_Error( 'wp_mcp_ai_invalid_variable_value', __( 'Variable values must be numeric.', 'mcp-ai-wpoos-pro' ) );
				}
				$variables[ $name ] = (float) $value;
			}
		}

		$tokens = $this->tokenize( $expression );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$state         = new \stdClass();
		$state->tokens = $tokens;
		$state->pos    = 0;
		$state->depth  = 0;
		$state->nodes  = 0;

		$ast = $this->parse_expr( $state );
		if ( is_wp_error( $ast ) ) {
			return $ast;
		}
		if ( $state->pos < count( $state->tokens ) ) {
			return new WP_Error( 'wp_mcp_ai_unexpected_token', __( 'Unexpected token at end of expression.', 'mcp-ai-wpoos-pro' ) );
		}

		$value = $this->eval_ast( $ast, $variables );
		if ( is_wp_error( $value ) ) {
			return $value;
		}

		$canonical = $this->ast_to_string( $ast );

		return array(
			'success'    => true,
			'mode'       => 'evaluate',
			'expression' => $expression,
			'canonical'  => $canonical,
			'value'      => $value,
			'latex'      => '\\mathrm{eml}(x,y) = e^{x} - \\ln y',
			'message'    => sprintf(
				/* translators: 1: canonical expression, 2: numeric result */
				__( 'EML expression %1$s = %2$s.', 'mcp-ai-wpoos-pro' ),
				$canonical,
				(string) $value
			),
		);
	}

	/**
	 * Handle the `decompose` mode.
	 *
	 * Only paper-published identities are encoded:
	 *  - `exp(x) = eml(x, 1)`
	 *  - `ln(x)  = eml(1, eml(eml(1, x), 1))`
	 *  - `e      = eml(1, 1)`
	 *  - `one    = 1`
	 *
	 * Other functions are documented in the paper's Appendix table; this tool
	 * deliberately does not fabricate decompositions for functions whose
	 * identities are not encoded here. Future revisions may add them as the
	 * upstream catalogue is transcribed.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function execute_decompose( array $arguments ) {
		if ( empty( $arguments['function'] ) || ! is_string( $arguments['function'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_function', __( 'Function name is required for decompose mode.', 'mcp-ai-wpoos-pro' ) );
		}
		$function = sanitize_key( (string) $arguments['function'] );

		$args_raw = array();
		if ( isset( $arguments['arity_args'] ) && is_array( $arguments['arity_args'] ) ) {
			foreach ( $arguments['arity_args'] as $name ) {
				$name = sanitize_text_field( (string) $name );
				if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name ) ) {
					return new WP_Error( 'wp_mcp_ai_invalid_argument', __( 'arity_args entries must be valid identifiers.', 'mcp-ai-wpoos-pro' ) );
				}
				$args_raw[] = $name;
			}
		}

		$tree = $this->lookup_decomposition( $function, $args_raw );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$canonical = $this->ast_to_string( $tree );
		$depth     = $this->ast_depth( $tree );
		$size      = $this->ast_size( $tree );

		return array(
			'success'    => true,
			'mode'       => 'decompose',
			'function'   => $function,
			'arity_args' => $args_raw,
			'tree'       => $tree,
			'canonical'  => $canonical,
			'latex'      => $this->canonical_to_latex( $canonical ),
			'depth'      => $depth,
			'size'       => $size,
			'reference'  => 'Odrzywołek, "All elementary functions from a single operator", IFT, Jagiellonian Univ.',
			'message'    => sprintf(
				/* translators: 1: function name, 2: canonical expression */
				__( '%1$s decomposes to %2$s.', 'mcp-ai-wpoos-pro' ),
				$function,
				$canonical
			),
		);
	}

	/**
	 * Look up a published EML decomposition.
	 *
	 * @param string        $function Function name.
	 * @param array<string> $args     Symbolic argument names.
	 * @return array|WP_Error
	 */
	private function lookup_decomposition( $function, array $args ) {
		switch ( $function ) {
			case 'one':
				return array( 'type' => 'NUM', 'value' => 1.0 );
			case 'e':
				// e = exp(1) = eml(1, 1) — verify: e^1 − ln 1 = e − 0 = e.
				return array(
					'type'  => 'EML',
					'left'  => array( 'type' => 'NUM', 'value' => 1.0 ),
					'right' => array( 'type' => 'NUM', 'value' => 1.0 ),
				);
			case 'exp':
				$x = isset( $args[0] ) ? $args[0] : 'x';
				// exp(x) = eml(x, 1) — since ln 1 = 0.
				return array(
					'type'  => 'EML',
					'left'  => array( 'type' => 'VAR', 'name' => $x ),
					'right' => array( 'type' => 'NUM', 'value' => 1.0 ),
				);
			case 'ln':
				$x = isset( $args[0] ) ? $args[0] : 'x';
				// ln(x) = eml(1, eml(eml(1, x), 1)) — verified algebraically:
				// inner_a = eml(1, x) = e − ln x.
				// inner_b = eml(inner_a, 1) = exp(e − ln x) − 0 = e^e / x.
				// outer    = eml(1, inner_b) = e − ln(e^e / x) = e − (e − ln x) = ln x.
				$xnode = array( 'type' => 'VAR', 'name' => $x );
				$one   = array( 'type' => 'NUM', 'value' => 1.0 );
				$a     = array( 'type' => 'EML', 'left' => $one, 'right' => $xnode );
				$b     = array( 'type' => 'EML', 'left' => $a, 'right' => $one );
				return array( 'type' => 'EML', 'left' => $one, 'right' => $b );
		}
		return new WP_Error(
			'wp_mcp_ai_unsupported_decomposition',
			/* translators: %s: function name */
			sprintf(
				__( 'Decomposition for "%s" is not encoded in this tool. See the paper\'s Appendix table for the full catalogue.', 'mcp-ai-wpoos-pro' ),
				$function
			)
		);
	}

	/**
	 * Tokenize an EML expression.
	 *
	 * @param string $expression Source expression.
	 * @return array<int,array<string,string>>|WP_Error
	 */
	private function tokenize( $expression ) {
		$tokens = array();
		$length = strlen( $expression );
		$i      = 0;
		while ( $i < $length ) {
			$ch = $expression[ $i ];
			if ( ctype_space( $ch ) ) {
				++$i;
				continue;
			}
			if ( '(' === $ch ) {
				$tokens[] = array( 'type' => 'LPAREN', 'value' => '(' );
				++$i;
				continue;
			}
			if ( ')' === $ch ) {
				$tokens[] = array( 'type' => 'RPAREN', 'value' => ')' );
				++$i;
				continue;
			}
			if ( ',' === $ch ) {
				$tokens[] = array( 'type' => 'COMMA', 'value' => ',' );
				++$i;
				continue;
			}
			if ( '-' === $ch || '+' === $ch || ctype_digit( $ch ) || '.' === $ch ) {
				// Number literal (allow leading sign only at the start of a token).
				$start = $i;
				if ( '-' === $ch || '+' === $ch ) {
					++$i;
				}
				$has_digit = false;
				while ( $i < $length && ( ctype_digit( $expression[ $i ] ) || '.' === $expression[ $i ] ) ) {
					if ( ctype_digit( $expression[ $i ] ) ) {
						$has_digit = true;
					}
					++$i;
				}
				// Optional exponent.
				if ( $i < $length && ( 'e' === $expression[ $i ] || 'E' === $expression[ $i ] ) ) {
					++$i;
					if ( $i < $length && ( '+' === $expression[ $i ] || '-' === $expression[ $i ] ) ) {
						++$i;
					}
					while ( $i < $length && ctype_digit( $expression[ $i ] ) ) {
						++$i;
					}
				}
				$lex = substr( $expression, $start, $i - $start );
				if ( ! $has_digit || ! is_numeric( $lex ) ) {
					return new WP_Error( 'wp_mcp_ai_invalid_number', __( 'Invalid numeric literal in EML expression.', 'mcp-ai-wpoos-pro' ) );
				}
				$tokens[] = array( 'type' => 'NUM', 'value' => $lex );
				continue;
			}
			if ( ctype_alpha( $ch ) || '_' === $ch ) {
				$start = $i;
				while ( $i < $length && ( ctype_alnum( $expression[ $i ] ) || '_' === $expression[ $i ] ) ) {
					++$i;
				}
				$word = substr( $expression, $start, $i - $start );
				if ( 0 === strcasecmp( $word, 'eml' ) ) {
					$tokens[] = array( 'type' => 'EML', 'value' => 'eml' );
				} else {
					$tokens[] = array( 'type' => 'IDENT', 'value' => $word );
				}
				continue;
			}
			return new WP_Error(
				'wp_mcp_ai_invalid_character',
				/* translators: %s: offending character */
				sprintf( __( 'Invalid character "%s" in EML expression.', 'mcp-ai-wpoos-pro' ), $ch )
			);
		}
		return $tokens;
	}

	/**
	 * Parse an EML expression into an AST node.
	 *
	 * Grammar:
	 *   expr := number | identifier | 'eml' '(' expr ',' expr ')'
	 *
	 * @param object $state Parser state.
	 * @return array|WP_Error
	 */
	private function parse_expr( $state ) {
		++$state->depth;
		if ( $state->depth > self::MAX_TREE_DEPTH ) {
			return new WP_Error( 'wp_mcp_ai_too_deep', __( 'EML expression exceeds maximum depth.', 'mcp-ai-wpoos-pro' ) );
		}
		++$state->nodes;
		if ( $state->nodes > self::MAX_NODE_COUNT ) {
			return new WP_Error( 'wp_mcp_ai_too_large', __( 'EML expression exceeds maximum node count.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $state->pos >= count( $state->tokens ) ) {
			return new WP_Error( 'wp_mcp_ai_unexpected_eof', __( 'Unexpected end of EML expression.', 'mcp-ai-wpoos-pro' ) );
		}
		$tok = $state->tokens[ $state->pos ];

		if ( 'NUM' === $tok['type'] ) {
			++$state->pos;
			--$state->depth;
			return array( 'type' => 'NUM', 'value' => (float) $tok['value'] );
		}
		if ( 'IDENT' === $tok['type'] ) {
			++$state->pos;
			--$state->depth;
			return array( 'type' => 'VAR', 'name' => $tok['value'] );
		}
		if ( 'EML' === $tok['type'] ) {
			++$state->pos;
			if ( $state->pos >= count( $state->tokens ) || 'LPAREN' !== $state->tokens[ $state->pos ]['type'] ) {
				return new WP_Error( 'wp_mcp_ai_expected_lparen', __( 'Expected "(" after eml.', 'mcp-ai-wpoos-pro' ) );
			}
			++$state->pos;
			$left = $this->parse_expr( $state );
			if ( is_wp_error( $left ) ) {
				return $left;
			}
			if ( $state->pos >= count( $state->tokens ) || 'COMMA' !== $state->tokens[ $state->pos ]['type'] ) {
				return new WP_Error( 'wp_mcp_ai_expected_comma', __( 'Expected "," in eml(...).', 'mcp-ai-wpoos-pro' ) );
			}
			++$state->pos;
			$right = $this->parse_expr( $state );
			if ( is_wp_error( $right ) ) {
				return $right;
			}
			if ( $state->pos >= count( $state->tokens ) || 'RPAREN' !== $state->tokens[ $state->pos ]['type'] ) {
				return new WP_Error( 'wp_mcp_ai_expected_rparen', __( 'Expected ")" closing eml(...).', 'mcp-ai-wpoos-pro' ) );
			}
			++$state->pos;
			--$state->depth;
			return array( 'type' => 'EML', 'left' => $left, 'right' => $right );
		}
		return new WP_Error(
			'wp_mcp_ai_unexpected_token',
			/* translators: %s: token */
			sprintf( __( 'Unexpected token "%s" in EML expression.', 'mcp-ai-wpoos-pro' ), $tok['value'] )
		);
	}

	/**
	 * Evaluate an EML AST.
	 *
	 * @param array $node AST node.
	 * @param array $env  Variable environment.
	 * @return float|WP_Error
	 */
	private function eval_ast( $node, array $env ) {
		switch ( $node['type'] ) {
			case 'NUM':
				return (float) $node['value'];
			case 'VAR':
				if ( ! array_key_exists( $node['name'], $env ) ) {
					return new WP_Error(
						'wp_mcp_ai_unbound_variable',
						/* translators: %s: variable name */
						sprintf( __( 'Variable "%s" is not bound.', 'mcp-ai-wpoos-pro' ), $node['name'] )
					);
				}
				return (float) $env[ $node['name'] ];
			case 'EML':
				$x = $this->eval_ast( $node['left'], $env );
				if ( is_wp_error( $x ) ) {
					return $x;
				}
				$y = $this->eval_ast( $node['right'], $env );
				if ( is_wp_error( $y ) ) {
					return $y;
				}
				if ( ! is_finite( $x ) || ! is_finite( $y ) ) {
					return new WP_Error( 'wp_mcp_ai_non_finite', __( 'Non-finite intermediate value during EML evaluation.', 'mcp-ai-wpoos-pro' ) );
				}
				if ( $y <= 0.0 ) {
					return new WP_Error( 'wp_mcp_ai_ln_domain', __( 'EML domain error: second argument must be positive (ln domain).', 'mcp-ai-wpoos-pro' ) );
				}
				if ( $x > self::EXP_INPUT_MAX ) {
					return new WP_Error( 'wp_mcp_ai_exp_overflow', __( 'EML overflow: exp argument exceeds finite range.', 'mcp-ai-wpoos-pro' ) );
				}
				$result = exp( $x ) - log( $y );
				if ( ! is_finite( $result ) ) {
					return new WP_Error( 'wp_mcp_ai_non_finite_result', __( 'EML produced a non-finite result.', 'mcp-ai-wpoos-pro' ) );
				}
				return $result;
		}
		return new WP_Error( 'wp_mcp_ai_unknown_node', __( 'Unknown AST node.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Convert an AST to its canonical S-expression string.
	 *
	 * @param array $node AST node.
	 * @return string
	 */
	private function ast_to_string( $node ) {
		switch ( $node['type'] ) {
			case 'NUM':
				$v = (float) $node['value'];
				if ( (float) (int) $v === $v ) {
					return (string) (int) $v;
				}
				return rtrim( rtrim( sprintf( '%.10F', $v ), '0' ), '.' );
			case 'VAR':
				return (string) $node['name'];
			case 'EML':
				return 'eml(' . $this->ast_to_string( $node['left'] ) . ', ' . $this->ast_to_string( $node['right'] ) . ')';
		}
		return '';
	}

	/**
	 * Compute AST depth.
	 *
	 * @param array $node AST node.
	 * @return int
	 */
	private function ast_depth( $node ) {
		if ( 'EML' !== $node['type'] ) {
			return 1;
		}
		return 1 + max( $this->ast_depth( $node['left'] ), $this->ast_depth( $node['right'] ) );
	}

	/**
	 * Compute AST node count.
	 *
	 * @param array $node AST node.
	 * @return int
	 */
	private function ast_size( $node ) {
		if ( 'EML' !== $node['type'] ) {
			return 1;
		}
		return 1 + $this->ast_size( $node['left'] ) + $this->ast_size( $node['right'] );
	}

	/**
	 * Convert a canonical EML S-expression to a LaTeX string using \mathrm{eml}.
	 *
	 * @param string $canonical Canonical S-expression.
	 * @return string
	 */
	private function canonical_to_latex( $canonical ) {
		// Replace `eml(` with `\mathrm{eml}(` for LaTeX rendering. The argument
		// list is otherwise already valid LaTeX (numbers, identifiers, commas,
		// parentheses).
		return preg_replace( '/\beml\(/', '\\mathrm{eml}(', $canonical );
	}
}
