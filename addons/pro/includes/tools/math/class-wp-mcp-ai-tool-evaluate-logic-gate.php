<?php
/**
 * Evaluate Logic Gate Tool.
 *
 * Computes the output of a Boolean logic gate (AND, OR, NOT, NAND, NOR, XOR,
 * XNOR) for a given list of inputs and, optionally, decomposes the gate into a
 * NAND-only equivalent. NAND is the universal binary operator for Boolean
 * logic — every other gate can be expressed using NAND alone — and this tool
 * makes that constructive proof available to assistants and learners.
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
 * Evaluate a Boolean logic gate, optionally decomposed to NAND-only form.
 *
 * Companion to {@see WP_MCP_AI_Tool_Evaluate_Eml}, which provides the
 * continuous-mathematics analogue of universal-operator decomposition.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Tool_Evaluate_Logic_Gate implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Supported gate names (uppercase).
	 *
	 * @var array<int,string>
	 */
	private static $supported_gates = array( 'AND', 'OR', 'NOT', 'NAND', 'NOR', 'XOR', 'XNOR' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'evaluate_logic_gate';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Evaluate Logic Gate', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Evaluate a Boolean logic gate (AND, OR, NOT, NAND, NOR, XOR, XNOR) for a given list of inputs. Optionally returns a NAND-only decomposition that demonstrates NAND universality.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'gate'              => array(
					'type'        => 'string',
					'enum'        => self::$supported_gates,
					'description' => __( 'Gate to evaluate. NOT takes exactly one input; the rest take two or more (n-ary fold).', 'mcp-ai-wpoos-pro' ),
				),
				'inputs'            => array(
					'type'        => 'array',
					'description' => __( 'Boolean inputs. Accepts true/false, 1/0, or "1"/"0".', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => array( 'boolean', 'integer', 'string' ),
					),
					'minItems'    => 1,
					'maxItems'    => 16,
				),
				'decompose_to_nand' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, also return the equivalent expression built only from NAND gates.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'format'            => array(
					'type'        => 'string',
					'enum'        => array( 'latex', 'text', 'both' ),
					'description' => __( 'Output format for the symbolic representation.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'both',
				),
			),
			'required'   => array( 'gate', 'inputs' ),
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

		$gate_raw = isset( $arguments['gate'] ) ? sanitize_text_field( (string) $arguments['gate'] ) : '';
		$gate     = strtoupper( $gate_raw );
		if ( ! in_array( $gate, self::$supported_gates, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_gate',
				/* translators: %s: gate name */
				sprintf( __( 'Unknown gate "%s". Supported gates: AND, OR, NOT, NAND, NOR, XOR, XNOR.', 'mcp-ai-wpoos-pro' ), $gate_raw )
			);
		}

		if ( ! isset( $arguments['inputs'] ) || ! is_array( $arguments['inputs'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_inputs', __( 'The "inputs" array is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$bools = array();
		foreach ( $arguments['inputs'] as $value ) {
			$coerced = $this->coerce_to_bool( $value );
			if ( null === $coerced ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_input',
					__( 'Inputs must be booleans, 0/1, or "0"/"1".', 'mcp-ai-wpoos-pro' )
				);
			}
			$bools[] = $coerced;
		}

		if ( 'NOT' === $gate ) {
			if ( count( $bools ) !== 1 ) {
				return new WP_Error( 'wp_mcp_ai_invalid_arity', __( 'NOT requires exactly one input.', 'mcp-ai-wpoos-pro' ) );
			}
		} elseif ( count( $bools ) < 2 ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arity',
				/* translators: %s: gate name */
				sprintf( __( 'Gate %s requires at least two inputs.', 'mcp-ai-wpoos-pro' ), $gate )
			);
		}

		$result = $this->evaluate_gate( $gate, $bools );

		$labels       = $this->generate_labels( count( $bools ) );
		$symbolic_txt = $this->build_symbolic_text( $gate, $labels );
		$symbolic_tex = $this->build_symbolic_latex( $gate, $labels );

		$format = isset( $arguments['format'] ) ? sanitize_key( (string) $arguments['format'] ) : 'both';
		if ( ! in_array( $format, array( 'latex', 'text', 'both' ), true ) ) {
			$format = 'both';
		}

		$row = array();
		foreach ( $bools as $idx => $bit ) {
			$row[ $labels[ $idx ] ] = $bit ? 1 : 0;
		}
		$row['result'] = $result ? 1 : 0;

		$response = array(
			'success'   => true,
			'gate'      => $gate,
			'inputs'    => array_map(
				static function ( $b ) {
					return $b ? 1 : 0;
				},
				$bools
			),
			'result'    => $result,
			'truth_row' => $row,
			'message'   => sprintf(
				/* translators: 1: gate name, 2: result value */
				__( 'Gate %1$s evaluated to %2$s.', 'mcp-ai-wpoos-pro' ),
				$gate,
				$result ? '1' : '0'
			),
		);

		if ( 'text' === $format || 'both' === $format ) {
			$response['symbolic'] = $symbolic_txt;
		}
		if ( 'latex' === $format || 'both' === $format ) {
			$response['latex'] = $symbolic_tex;
		}

		if ( ! empty( $arguments['decompose_to_nand'] ) ) {
			$response['nand_decomposition'] = array(
				'text'  => $this->decompose_to_nand_text( $gate, $labels ),
				'latex' => $this->decompose_to_nand_latex( $gate, $labels ),
				'note'  => __( 'NAND is functionally complete for Boolean logic: every gate can be implemented using NAND alone.', 'mcp-ai-wpoos-pro' ),
			);
		}

		return $response;
	}

	/**
	 * Coerce a value to a strict boolean.
	 *
	 * Accepts boolean, integer 0/1, or strings "0"/"1"/"true"/"false".
	 * Returns null if the value cannot be coerced unambiguously.
	 *
	 * @param mixed $value Raw input value.
	 * @return bool|null
	 */
	private function coerce_to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			if ( 0 === $value ) {
				return false;
			}
			if ( 1 === $value ) {
				return true;
			}
			return null;
		}
		if ( is_string( $value ) ) {
			$trimmed = strtolower( trim( $value ) );
			if ( '1' === $trimmed || 'true' === $trimmed ) {
				return true;
			}
			if ( '0' === $trimmed || 'false' === $trimmed ) {
				return false;
			}
		}
		return null;
	}

	/**
	 * Evaluate the gate over the given inputs.
	 *
	 * Multi-input gates are evaluated as left-folds of their two-input
	 * counterparts; XOR/XNOR fold as parity.
	 *
	 * @param string      $gate  Gate name (uppercase).
	 * @param array<bool> $bools Boolean inputs.
	 * @return bool
	 */
	private function evaluate_gate( $gate, array $bools ) {
		switch ( $gate ) {
			case 'NOT':
				return ! $bools[0];
			case 'AND':
				foreach ( $bools as $b ) {
					if ( ! $b ) {
						return false;
					}
				}
				return true;
			case 'OR':
				foreach ( $bools as $b ) {
					if ( $b ) {
						return true;
					}
				}
				return false;
			case 'NAND':
				foreach ( $bools as $b ) {
					if ( ! $b ) {
						return true;
					}
				}
				return false;
			case 'NOR':
				foreach ( $bools as $b ) {
					if ( $b ) {
						return false;
					}
				}
				return true;
			case 'XOR':
				$parity = false;
				foreach ( $bools as $b ) {
					$parity = $parity !== (bool) $b;
				}
				return $parity;
			case 'XNOR':
				$parity = false;
				foreach ( $bools as $b ) {
					$parity = $parity !== (bool) $b;
				}
				return ! $parity;
		}
		return false;
	}

	/**
	 * Generate single-letter labels A, B, C, ... for n inputs.
	 *
	 * @param int $count Number of inputs.
	 * @return array<int,string>
	 */
	private function generate_labels( $count ) {
		$labels = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$labels[] = chr( ord( 'A' ) + $i );
		}
		return $labels;
	}

	/**
	 * Build a plain-text symbolic form.
	 *
	 * @param string            $gate   Gate name.
	 * @param array<int,string> $labels Variable labels.
	 * @return string
	 */
	private function build_symbolic_text( $gate, array $labels ) {
		if ( 'NOT' === $gate ) {
			return 'NOT ' . $labels[0];
		}
		return implode( ' ' . $gate . ' ', $labels );
	}

	/**
	 * Build a LaTeX symbolic form.
	 *
	 * @param string            $gate   Gate name.
	 * @param array<int,string> $labels Variable labels.
	 * @return string
	 */
	private function build_symbolic_latex( $gate, array $labels ) {
		switch ( $gate ) {
			case 'NOT':
				return '\\overline{' . $labels[0] . '}';
			case 'AND':
				return implode( ' \\cdot ', $labels );
			case 'OR':
				return implode( ' + ', $labels );
			case 'NAND':
				return '\\overline{' . implode( ' \\cdot ', $labels ) . '}';
			case 'NOR':
				return '\\overline{' . implode( ' + ', $labels ) . '}';
			case 'XOR':
				return implode( ' \\oplus ', $labels );
			case 'XNOR':
				return '\\overline{' . implode( ' \\oplus ', $labels ) . '}';
		}
		return implode( ',', $labels );
	}

	/**
	 * Build a NAND-only decomposition (plain text) for the chosen gate.
	 *
	 * Uses the standard universal-NAND identities, generalised to n inputs by
	 * left-association where applicable.
	 *
	 * @param string            $gate   Gate name (uppercase).
	 * @param array<int,string> $labels Variable labels.
	 * @return string
	 */
	private function decompose_to_nand_text( $gate, array $labels ) {
		if ( 'NAND' === $gate ) {
			return 'NAND(' . implode( ', ', $labels ) . ')';
		}
		if ( 'NOT' === $gate ) {
			$a = $labels[0];
			return 'NAND(' . $a . ', ' . $a . ')';
		}
		// Reduce to two-input form by left fold so the decomposition stays.
		// syntactically simple and demonstrably correct.
		$current = $labels[0];
		// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found
		for ( $i = 1; $i < count( $labels ); $i++ ) {
			$current = $this->two_input_nand_text( $gate, $current, $labels[ $i ] );
		}
		return $current;
	}

	/**
	 * NAND-only decomposition of a two-input gate (plain text).
	 *
	 * @param string $gate Gate name.
	 * @param string $a    First operand expression.
	 * @param string $b    Second operand expression.
	 * @return string
	 */
	private function two_input_nand_text( $gate, $a, $b ) {
		switch ( $gate ) {
			case 'AND':
				return 'NAND(NAND(' . $a . ', ' . $b . '), NAND(' . $a . ', ' . $b . '))';
			case 'OR':
				return 'NAND(NAND(' . $a . ', ' . $a . '), NAND(' . $b . ', ' . $b . '))';
			case 'NOR':
				$or = 'NAND(NAND(' . $a . ', ' . $a . '), NAND(' . $b . ', ' . $b . '))';
				return 'NAND(' . $or . ', ' . $or . ')';
			case 'XOR':
				$nab = 'NAND(' . $a . ', ' . $b . ')';
				return 'NAND(NAND(' . $a . ', ' . $nab . '), NAND(' . $b . ', ' . $nab . '))';
			case 'XNOR':
				$nab = 'NAND(' . $a . ', ' . $b . ')';
				$xor = 'NAND(NAND(' . $a . ', ' . $nab . '), NAND(' . $b . ', ' . $nab . '))';
				return 'NAND(' . $xor . ', ' . $xor . ')';
		}
		return 'NAND(' . $a . ', ' . $b . ')';
	}

	/**
	 * Build a NAND-only decomposition (LaTeX) for the chosen gate.
	 *
	 * @param string            $gate   Gate name.
	 * @param array<int,string> $labels Variable labels.
	 * @return string
	 */
	private function decompose_to_nand_latex( $gate, array $labels ) {
		// Render NAND as the conventional \uparrow (Sheffer stroke) for.
		// a compact, mathematically idiomatic LaTeX form.
		$text = $this->decompose_to_nand_text( $gate, $labels );
		// Convert NAND(x, y) into (x \uparrow y).
		return $this->nand_text_to_latex( $text );
	}

	/**
	 * Convert a NAND(...) plain-text expression to a LaTeX expression that
	 * uses the Sheffer stroke (\uparrow) and parentheses.
	 *
	 * @param string $expression Plain-text NAND expression.
	 * @return string
	 */
	private function nand_text_to_latex( $expression ) {
		$length = strlen( $expression );
		if ( 0 === $length ) {
			return '';
		}
		$pos          = 0;
		$expression_p = $expression;

		$parse = null;
		$parse = function () use ( &$pos, &$expression_p, $length, &$parse ) {
			// Skip whitespace.
			while ( $pos < $length && ctype_space( $expression_p[ $pos ] ) ) {
				++$pos;
			}
			// NAND prefix?
			if ( $pos < $length && 0 === substr_compare( $expression_p, 'NAND(', $pos, 5 ) ) {
				$pos += 5;
				$left = $parse();
				// Skip ", ".
				while ( $pos < $length && ( ',' === $expression_p[ $pos ] || ctype_space( $expression_p[ $pos ] ) ) ) {
					++$pos;
				}
				$right = $parse();
				// Skip ")".
				while ( $pos < $length && ')' !== $expression_p[ $pos ] ) {
					++$pos;
				}
				if ( $pos < $length && ')' === $expression_p[ $pos ] ) {
					++$pos;
				}
				return '(' . $left . ' \\uparrow ' . $right . ')';
			}
			// Identifier (single letter or alphanumerics).
			$start = $pos;
			while ( $pos < $length && ( ctype_alnum( $expression_p[ $pos ] ) || '_' === $expression_p[ $pos ] ) ) {
				++$pos;
			}
			return substr( $expression_p, $start, $pos - $start );
		};

		return $parse();
	}
}
