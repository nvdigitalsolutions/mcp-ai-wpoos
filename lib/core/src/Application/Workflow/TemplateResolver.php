<?php
/**
 * Workflow template and condition resolver.
 *
 * The workflow graph is data: tool arguments carry `{{ … }}` references and
 * condition nodes carry a small expression (`node_2.pageviews < 100`). This
 * class resolves both against a run context without `eval`, so a stored graph
 * can never execute arbitrary PHP.
 *
 * Grammar, deliberately tiny:
 *   template   {{ input.key }} | {{ node_id.key.0.nested }} | any text around them
 *   expression <ref|literal> [ === !== == != > >= < <= ] <ref|literal>
 *   literal    42 | 3.14 | 'text' | "text" | true | false | null
 *
 * A bare reference is a truthiness test. Anything else is not an expression
 * and evaluates to null — the executor reports a failed condition node rather
 * than guessing.
 *
 * @package NV_oOS
 * @since   2.5.0
 * @license MIT
 * SPDX-License-Identifier: MIT
 */

declare( strict_types=1 );

namespace Nvoos\Core\Application\Workflow;

/**
 * Resolves graph templates and condition expressions.
 */
final class TemplateResolver {

	/**
	 * Matches a string that is exactly one template, so the typed value can be
	 * returned instead of a stringified one.
	 */
	private const SINGLE_TEMPLATE = '/^\s*\{\{\s*([^{}]+?)\s*\}\}\s*$/';

	/** Matches one template anywhere in a string. */
	private const TEMPLATE = '/\{\{\s*([^{}]+?)\s*\}\}/';

	/** Matches a reference path. */
	private const REFERENCE = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)*$/';

	/** Comparison operators, longest first so `>=` wins over `>`. */
	private const OPERATORS = '===|!==|==|!=|>=|<=|>|<';

	/**
	 * Resolve templates inside any value.
	 *
	 * An exact single template keeps its type (array, number, bool); templates
	 * embedded in a longer string are stringified and substituted.
	 *
	 * @param mixed                $value   Value to resolve.
	 * @param array<string, mixed> $context Run context (`input`, `nodes`).
	 * @return mixed
	 */
	public static function resolve( mixed $value, array $context ): mixed {
		if ( is_string( $value ) ) {
			if ( preg_match( self::SINGLE_TEMPLATE, $value, $matches ) ) {
				return self::lookup( $matches[1], $context );
			}

			return preg_replace_callback(
				self::TEMPLATE,
				static function ( array $matches ) use ( $context ): string {
					return self::stringify( self::lookup( $matches[1], $context ) );
				},
				$value
			);
		}

		if ( is_array( $value ) ) {
			$resolved = array();

			foreach ( $value as $key => $item ) {
				$resolved[ $key ] = self::resolve( $item, $context );
			}

			return $resolved;
		}

		return $value;
	}

	/**
	 * Resolve one dotted reference against the run context.
	 *
	 * `input.x` reads the run input; every other first segment is a node id and
	 * reads that node's output.
	 *
	 * @param string               $path    Dotted path.
	 * @param array<string, mixed> $context Run context.
	 * @return mixed Null when the path does not resolve.
	 */
	public static function lookup( string $path, array $context ): mixed {
		$segments = explode( '.', trim( $path ) );
		$head     = array_shift( $segments );

		if ( null === $head || '' === $head ) {
			return null;
		}

		if ( 'input' === $head ) {
			$current = $context['input'] ?? array();
		} else {
			$nodes   = isset( $context['nodes'] ) && is_array( $context['nodes'] ) ? $context['nodes'] : array();
			$current = $nodes[ $head ] ?? null;
		}

		foreach ( $segments as $segment ) {
			if ( is_array( $current ) && array_key_exists( $segment, $current ) ) {
				$current = $current[ $segment ];
				continue;
			}

			if ( $current instanceof \ArrayAccess && $current->offsetExists( $segment ) ) {
				$current = $current[ $segment ];
				continue;
			}

			return null;
		}

		return $current;
	}

	/**
	 * Evaluate a condition expression to a boolean.
	 *
	 * @param string               $expression Expression.
	 * @param array<string, mixed> $context    Run context.
	 * @return bool|null Null when the expression cannot be parsed.
	 */
	public static function evaluate( string $expression, array $context ): ?bool {
		$expression = trim( $expression );

		if ( '' === $expression ) {
			return null;
		}

		$pattern = '/^\s*(.+?)\s*(' . self::OPERATORS . ')\s*(.+?)\s*$/';

		if ( ! preg_match( $pattern, $expression, $matches ) ) {
			// No operator: a bare reference is a truthiness test.
			return preg_match( self::REFERENCE, $expression ) === 1
				? (bool) self::lookup( $expression, $context )
				: null;
		}

		$left  = self::operand( $matches[1], $context );
		$right = self::operand( $matches[3], $context );

		switch ( $matches[2] ) {
			case '===':
				return $left === $right;
			case '!==':
				return $left !== $right;
			case '==':
				// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- documented loose comparison.
				return $left == $right;
			case '!=':
				// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- documented loose comparison.
				return $left != $right;
		}

		// Ordering needs two comparable scalars; anything else is false rather
		// than a PHP 8 comparison surprise.
		if ( ! is_scalar( $left ) || ! is_scalar( $right ) ) {
			return false;
		}

		if ( is_numeric( $left ) && is_numeric( $right ) ) {
			$left  = (float) $left;
			$right = (float) $right;

			return match ( $matches[2] ) {
				'>' => $left > $right,
				'>=' => $left >= $right,
				'<' => $left < $right,
				default => $left <= $right,
			};
		}

		$comparison = strcmp( (string) $left, (string) $right );

		return match ( $matches[2] ) {
			'>' => $comparison > 0,
			'>=' => $comparison >= 0,
			'<' => $comparison < 0,
			default => $comparison <= 0,
		};
	}

	/**
	 * Parse one operand: a literal or a reference.
	 *
	 * @param string               $operand Raw operand text.
	 * @param array<string, mixed> $context Run context.
	 * @return mixed
	 */
	private static function operand( string $operand, array $context ): mixed {
		$operand = trim( $operand );

		if ( preg_match( '/^([\'"])(.*)\1$/s', $operand, $matches ) ) {
			return $matches[2];
		}

		$lower = strtolower( $operand );

		if ( 'true' === $lower ) {
			return true;
		}

		if ( 'false' === $lower ) {
			return false;
		}

		if ( 'null' === $lower ) {
			return null;
		}

		if ( is_numeric( $operand ) ) {
			return str_contains( $operand, '.' ) ? (float) $operand : (int) $operand;
		}

		if ( preg_match( self::REFERENCE, $operand ) ) {
			return self::lookup( $operand, $context );
		}

		return null;
	}

	/**
	 * Stringify a resolved value for interpolation.
	 *
	 * @param mixed $value Resolved value.
	 */
	private static function stringify( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return '';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return false === $encoded ? '' : $encoded;
	}
}
