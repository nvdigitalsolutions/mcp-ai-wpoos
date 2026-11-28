// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview Symbolic and definite integration, with a verification gate.
 *
 * nerdamer is the only maintained JavaScript CAS that still ships an
 * integrator, but it fails in two ways that matter, and both were observed
 * before this module was written:
 *
 *   integrate('sin(x^2)', 'x')  ->  integrate(sin(x^2), x)      (unevaluated)
 *   integrate('e^(-x^2)', 'x')  ->  (1/2)*pi^(1/2)*erf*x        (malformed)
 *
 * The second is the dangerous one: it looks like an answer. `erf` has lost its
 * argument, so differentiating it gives `0.886...*erf`, which is not a number.
 *
 * So an antiderivative is never returned on the engine's word. It is
 * differentiated and compared against the integrand at several points, and
 * both sides must evaluate to finite numbers that agree. A result that cannot
 * be checked is refused, not trusted.
 *
 * Definite integrals need no such gate: `defint` either yields a number or it
 * does not, and it integrates numerically where no elementary antiderivative
 * exists — `e^(-x^2)` over 0..1 comes back `0.746824132812427`.
 */

/** Points at which an antiderivative is checked. Deliberately non-round. */
const SAMPLE_POINTS = [ 0.37, 0.71, 1.13, 1.57, 2.03, 2.61, 3.11 ];

/** Relative agreement required between the derivative and the integrand. */
const RELATIVE_TOLERANCE = 1e-6;

/** Fewer comparable points than this is not evidence of anything. */
const MINIMUM_SAMPLES = 3;

/** A residual call to the engine's own integrator means it gave up. */
const UNEVALUATED = /\b(?:integrate|defint)\s*\(/;

/**
 * Evaluate an expression at a point, or null when it is not a finite number.
 *
 * `null` covers three different situations the caller must not confuse: the
 * expression is undefined here (a pole), it names a symbol that has no value
 * (`erf` with no argument), or it cannot be parsed.
 *
 * @param {Function} nerdamer nerdamer entry point.
 * @param {string}   expression Expression to evaluate.
 * @param {string}   variable   Variable to substitute.
 * @param {number}   point      Value to substitute.
 * @return {number|null}
 */
function valueAt( nerdamer, expression, variable, point ) {
	try {
		const evaluated = nerdamer( expression ).sub( variable, String( point ) ).evaluate();
		const text = 'function' === typeof evaluated.text ? evaluated.text( 'decimals' ) : String( evaluated );
		const value = Number( String( text ) );

		return Number.isFinite( value ) ? value : null;
	} catch ( err ) {
		return null;
	}
}

/**
 * Evaluate a closed expression to a finite number, or null.
 *
 * Used for definite results, which carry no free variable: substituting one
 * would corrupt a result that happened to keep a symbol.
 */
function numericOf( nerdamer, expression ) {
	try {
		const evaluated = nerdamer( expression ).evaluate();
		const text = 'function' === typeof evaluated.text ? evaluated.text( 'decimals' ) : String( evaluated );
		const value = Number( String( text ) );

		return Number.isFinite( value ) ? value : null;
	} catch ( err ) {
		return null;
	}
}

/**
 * TeX for an expression, or an empty string.
 *
 * `integrate()` returns an object without `toTeX`, so the string form is fed
 * back through the parser.
 */
function teXOf( nerdamer, expression ) {
	try {
		return String( nerdamer( expression ).toTeX() );
	} catch ( err ) {
		return '';
	}
}

/**
 * Check that `antiderivative` really differentiates back to `integrand`.
 *
 * @return {{ verified: boolean, samples: number, maxError: number, reason: string|null }}
 */
export function verifyAntiderivative( nerdamer, calculus, integrand, antiderivative, variable ) {
	let derivative;

	try {
		derivative = String( calculus.diff( antiderivative, variable ) );
	} catch ( err ) {
		return { verified: false, samples: 0, maxError: 0, reason: 'its derivative could not be computed' };
	}

	let samples = 0;
	let maxError = 0;

	for ( const point of SAMPLE_POINTS ) {
		const expected = valueAt( nerdamer, integrand, variable, point );

		// The integrand is undefined here, so there is nothing to compare.
		if ( null === expected ) {
			continue;
		}

		const actual = valueAt( nerdamer, derivative, variable, point );

		// The integrand is a number here but the derivative is not, which is
		// exactly the malformed-result signature: a symbol left unbound.
		if ( null === actual ) {
			return {
				verified: false,
				samples,
				maxError,
				reason: 'its derivative is not a number at x = ' + point,
			};
		}

		const scale = Math.max( 1, Math.abs( actual ), Math.abs( expected ) );
		maxError = Math.max( maxError, Math.abs( actual - expected ) / scale );
		samples += 1;
	}

	if ( samples < MINIMUM_SAMPLES ) {
		return {
			verified: false,
			samples,
			maxError,
			reason: 'it could only be checked at ' + samples + ' point(s)',
		};
	}

	if ( maxError > RELATIVE_TOLERANCE ) {
		return {
			verified: false,
			samples,
			maxError,
			reason: 'its derivative disagreed with the integrand',
		};
	}

	return { verified: true, samples, maxError, reason: null };
}

/**
 * The indefinite integral, if it can be produced *and* verified.
 *
 * @param {Function} nerdamer   nerdamer entry point.
 * @param {object}   calculus   `nerdamer/calculus` namespace.
 * @param {string}   expression Integrand.
 * @param {string}   variable   Variable of integration.
 * @return {{ antiderivative: string|null, tex: string, verified: boolean, samples: number, problem: string|null }}
 */
export function indefiniteIntegral( nerdamer, calculus, expression, variable ) {
	let raw;

	try {
		raw = String( calculus.integrate( expression, variable ) );
	} catch ( err ) {
		return {
			antiderivative: null,
			tex: '',
			verified: false,
			samples: 0,
			problem: 'The engine could not integrate this expression.',
		};
	}

	if ( UNEVALUATED.test( raw ) ) {
		return {
			antiderivative: null,
			tex: '',
			verified: false,
			samples: 0,
			problem: 'The engine could not find an antiderivative for this expression.',
		};
	}

	const check = verifyAntiderivative( nerdamer, calculus, expression, raw, variable );

	if ( ! check.verified ) {
		return {
			antiderivative: null,
			tex: '',
			verified: false,
			samples: check.samples,
			problem: 'An antiderivative was produced but not trusted: ' + check.reason + '.',
		};
	}

	return {
		antiderivative: raw,
		tex: teXOf( nerdamer, raw ),
		verified: true,
		samples: check.samples,
		problem: null,
	};
}

/**
 * The definite integral, exactly where the engine can and numerically otherwise.
 *
 * @param {Function} nerdamer   nerdamer entry point.
 * @param {object}   calculus   `nerdamer/calculus` namespace.
 * @param {string}   expression Integrand.
 * @param {string}   variable   Variable of integration.
 * @param {number}   lower      Lower limit.
 * @param {number}   upper      Upper limit.
 * @return {{ value: number|null, exact: string, tex: string, problem: string|null }}
 */
export function definiteIntegral( nerdamer, calculus, expression, variable, lower, upper ) {
	let raw;

	try {
		raw = String( calculus.defint( expression, variable, lower, upper ) );
	} catch ( err ) {
		return {
			value: null,
			exact: '',
			tex: '',
			problem: 'The engine could not evaluate this integral over those limits.',
		};
	}

	if ( UNEVALUATED.test( raw ) ) {
		return {
			value: null,
			exact: '',
			tex: '',
			problem: 'The engine could not evaluate this integral over those limits.',
		};
	}

	const value = numericOf( nerdamer, raw );

	if ( null === value ) {
		return {
			value: null,
			exact: raw,
			tex: teXOf( nerdamer, raw ),
			problem: 'The result is not a finite number.',
		};
	}

	return { value, exact: raw, tex: teXOf( nerdamer, raw ), problem: null };
}
