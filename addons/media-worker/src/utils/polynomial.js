// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview Polynomial equation solving on top of mathjs.
 *
 * `rationalize()` turns `left - right` into the coefficients of one variable and
 * throws for anything that is not a polynomial, which is exactly the boundary
 * this module wants: the WordPress tool declared only linear, quadratic and
 * polynomial equations, and its fallback guessed at everything else — `3x^2 + 2
 * = 5` was matched as `3x` and answered `x = 1`, a wrong answer under a success
 * envelope. Here a non-polynomial is a refusal.
 *
 * `polynomialRoot()` stops at cubics, so higher degrees go through the companion
 * matrix and `eigs()`. Both paths are normalised the same way: a near-zero
 * imaginary part collapses to a real number, a value within 1e-9 of an integer
 * is rounded to it, and numerical duplicates are removed, so a caller never sees
 * `2.0000000000000004` where the answer is 2.
 */

/** Below this, an imaginary part is floating-point noise rather than a root. */
const IMAGINARY_EPSILON = 1e-10;

/** Within this of an integer, a root is that integer. */
const INTEGER_EPSILON = 1e-9;

/** Trim trailing zero coefficients so the degree is the array length minus one. */
function trim( coefficients ) {
	let end = coefficients.length - 1;

	while ( end > 0 && 0 === coefficients[ end ] ) {
		end -= 1;
	}

	return coefficients.slice( 0, end + 1 );
}

/** Round floating-point noise to the integer it is trying to be. */
function tidy( value ) {
	const rounded = Math.round( value );

	return Math.abs( value - rounded ) <= INTEGER_EPSILON ? rounded : value;
}

/** A stable key for duplicate detection. */
function keyOf( value ) {
	if ( 'number' === typeof value ) {
		return `r:${ value.toPrecision( 12 ) }`;
	}

	return `c:${ value.re.toPrecision( 12 ) }:${ value.im.toPrecision( 12 ) }`;
}

/**
 * Turn one raw root into either a real number or a `{re, im}` pair.
 *
 * mathjs reports eigenvalues as numbers when real and as `{re, im}` otherwise.
 *
 * @param {number|{re: number, im: number}} value Raw root.
 * @return {number|{re: number, im: number}}
 */
export function normaliseRoot( value ) {
	if ( 'number' === typeof value ) {
		return tidy( value );
	}

	const re = tidy( value.re );
	const im = tidy( value.im );

	return Math.abs( im ) <= IMAGINARY_EPSILON * Math.max( 1, Math.abs( re ) ) ? re : { re, im };
}

/**
 * Remove roots that are numerically the same.
 *
 * @param {Array<number|{re: number, im: number}>} roots Roots to filter.
 * @return {Array<number|{re: number, im: number}>}
 */
export function dedupeRoots( roots ) {
	const out = [];

	for ( const root of roots ) {
		const key = keyOf( root );

		if ( ! out.some( ( seen ) => keyOf( seen ) === key ) ) {
			out.push( root );
		}
	}

	return out;
}

/**
 * The companion matrix of a polynomial, whose eigenvalues are its roots.
 *
 * @param {number[]} coefficients Ascending: constant first, leading last.
 * @return {number[][]}
 */
export function companionMatrix( coefficients ) {
	const degree = coefficients.length - 1;
	const lead = coefficients[ degree ];
	const matrix = [];

	for ( let row = 0; row < degree; row++ ) {
		const line = new Array( degree ).fill( 0 );

		// Subdiagonal of ones; the final column carries the negated,
		// leading-coefficient-normalised constants.
		if ( row > 0 ) {
			line[ row - 1 ] = 1;
		}

		line[ degree - 1 ] = -coefficients[ row ] / lead;
		matrix.push( line );
	}

	return matrix;
}

/**
 * Coefficients of `left - right` in `variable`, or a problem.
 *
 * @param {object} mathjs   mathjs namespace.
 * @param {string} left     Left-hand expression.
 * @param {string} right    Right-hand expression.
 * @param {string} variable Variable to solve for.
 * @return {{ coefficients: number[]|null, problem: string|null }}
 */
export function coefficientsFor( mathjs, left, right, variable ) {
	let rational;

	try {
		rational = mathjs.rationalize( `(${ left }) - (${ right })`, {}, true );
	} catch ( err ) {
		// `rationalize` refuses an expression it cannot write as a polynomial.
		return {
			coefficients: null,
			problem: `The equation is not a polynomial in ${ variable }.`,
		};
	}

	const variables = Array.isArray( rational.variables ) ? rational.variables : [];

	// No variables at all is a constant equation (`0 = 5`): it is still a
	// polynomial of degree zero, and the caller decides what that means.
	if ( variables.length > 1 || ( 1 === variables.length && variable !== variables[ 0 ] ) ) {
		const found = variables.join( ', ' );

		return {
			coefficients: null,
			problem: `The equation is not a polynomial in ${ variable } alone (found: ${ found }).`,
		};
	}

	const coefficients = [];
	const symbolic = [];

	for ( const raw of rational.coefficients ) {
		const value = Number( String( raw ) );

		if ( ! Number.isFinite( value ) ) {
			// `2x = a` rationalises to a polynomial in x whose coefficient is the
			// symbol a. That is a parametric equation, not a solvable one.
			symbolic.push( String( raw ) );
			continue;
		}

		coefficients.push( value );
	}

	if ( symbolic.length > 0 ) {
		return {
			coefficients: null,
			problem: `The equation has coefficients that are not numbers (${ symbolic.join( ', ' ) }).`,
		};
	}

	return { coefficients: trim( coefficients ), problem: null };
}

/**
 * The roots of a polynomial given its ascending coefficients.
 *
 * Degree 1 is solved directly, degrees 2 and 3 use mathjs's own root finder
 * (exact where it can be), and higher degrees use the companion matrix.
 *
 * @param {object}   mathjs       mathjs namespace.
 * @param {number[]} coefficients Ascending coefficients.
 * @return {Array<number|{re: number, im: number}>}
 */
export function rootsOf( mathjs, coefficients ) {
	const degree = coefficients.length - 1;

	if ( degree < 1 ) {
		return [];
	}

	if ( 1 === degree ) {
		return [ normaliseRoot( -coefficients[ 0 ] / coefficients[ 1 ] ) ];
	}

	let roots;

	// `polynomialRoot` is typed as (constant, linear, quadratic?, cubic?) and
	// rejects an explicit `undefined` for an omitted degree, so the arity has
	// to match the degree exactly.
	if ( 2 === degree ) {
		roots = mathjs.polynomialRoot( coefficients[ 0 ], coefficients[ 1 ], coefficients[ 2 ] );
	} else if ( 3 === degree ) {
		roots = mathjs.polynomialRoot(
			coefficients[ 0 ],
			coefficients[ 1 ],
			coefficients[ 2 ],
			coefficients[ 3 ]
		);
	} else {
		const eigenvalues = mathjs.eigs( companionMatrix( coefficients ) );
		roots = eigenvalues.values;
	}

	return dedupeRoots( roots.map( normaliseRoot ) );
}

/**
 * Solve `left = right` for `variable`.
 *
 * @param {object} mathjs   mathjs namespace.
 * @param {string} left     Left-hand expression.
 * @param {string} right    Right-hand expression.
 * @param {string} variable Variable to solve for.
 * @return {{ solutions: Array<number|{re: number, im: number}>, degree: number, infinite: boolean, problem: string|null }}
 */
export function solveEquation( mathjs, left, right, variable ) {
	const { coefficients, problem } = coefficientsFor( mathjs, left, right, variable );

	if ( null === coefficients ) {
		return { solutions: [], degree: -1, infinite: false, problem };
	}

	const degree = coefficients.length - 1;

	// Every coefficient zero: the two sides are the same expression.
	if ( 0 === degree && 0 === coefficients[ 0 ] ) {
		return { solutions: [], degree: 0, infinite: true, problem: null };
	}

	// A non-zero constant: no value of the variable can satisfy it.
	if ( degree < 1 ) {
		return { solutions: [], degree: 0, infinite: false, problem: null };
	}

	return {
		solutions: rootsOf( mathjs, coefficients ),
		degree,
		infinite: false,
		problem: null,
	};
}

/**
 * Render one root as plain text.
 *
 * @param {number|{re: number, im: number}} root Root to render.
 * @return {string}
 */
export function rootText( root ) {
	if ( 'number' === typeof root ) {
		return String( root );
	}

	const sign = root.im < 0 ? '-' : '+';

	return `${ root.re } ${ sign } ${ Math.abs( root.im ) }i`;
}
