// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Symbolic mathematics — the engine behind the `math` toolkit's compute tools.
 *
 * Endpoints:
 *   POST /api/math/derivative — symbolic differentiation      (mathjs)
 *   POST /api/math/simplify   — algebraic simplification      (mathjs)
 *   POST /api/math/matrix     — linear algebra                (mathjs, plus rank)
 *
 * ## Why these three and not seven
 *
 * The Pro tools ask for seven operations. mathjs covers four of them —
 * derivative, simplify, and the matrix operations — and `render-math` in the
 * data group covers a fifth. It has **no** symbolic integral (an open feature
 * request upstream) and reaches only polynomials for equation solving, so
 * `/integral` and `/solve` are deliberately absent rather than stubbed: a route
 * that answered "not implemented" would be indistinguishable from a broken one.
 *
 * ## Every route answers `text` and `tex`
 *
 * mathjs can print a node two ways: `toString()` gives `2 * x + 5`, and
 * `toTex()` gives `2 x + 5`. The WordPress tools returned the first and labelled
 * it LaTeX, so anything with a fraction or a multiplication sign rendered as
 * source code. Both are returned, computed from the same node, so the two
 * spellings cannot describe different expressions.
 *
 * ## `rank` is not a mathjs function
 *
 * mathjs has `det`, `inv`, `trace`, `eigs`, `lup`, `qr` and `svd`, and no rank.
 * It is computed here by Gaussian elimination with partial pivoting, which is
 * exact enough for the integer and small-decimal matrices these tools receive
 * and has no dependency of its own.
 */

import { Router } from 'express';

export const mathRouter = Router();

/** Compute is cheaper than rendering, but an unbounded parse is still a denial of service. */
const MAX_EXPRESSION_LENGTH = 2000;

/** The operations the matrix route performs. */
const OPERATIONS = [ 'add', 'subtract', 'multiply', 'transpose', 'inverse', 'determinant', 'eigenvalues', 'rank', 'trace' ];

/** The operations that read a second matrix. */
const BINARY = [ 'add', 'subtract', 'multiply' ];

/** The highest derivative order the tools offer; each order is another pass over the tree. */
const MAX_ORDER = 5;

/** Below this a pivot is zero. Integer matrices are exact; decimals are not. */
const PIVOT_EPSILON = 1e-10;

/**
 * Why an expression cannot be accepted, or null.
 *
 * The length cap is the only structural check: mathjs is the parser, and a
 * message from it names the offending token better than a regex here would.
 */
function expressionProblem( expression ) {
	if ( 'string' !== typeof expression || '' === expression.trim() ) {
		return 'Missing expression';
	}

	if ( expression.length > MAX_EXPRESSION_LENGTH ) {
		return `Expression is longer than ${ MAX_EXPRESSION_LENGTH } characters`;
	}

	return null;
}

/** Whether a value is a rectangular 2D array of finite numbers. */
function matrixProblem( matrix, label ) {
	if ( ! Array.isArray( matrix ) || 0 === matrix.length ) {
		return `Missing or empty ${ label }`;
	}

	const width = Array.isArray( matrix[ 0 ] ) ? matrix[ 0 ].length : 0;

	if ( 0 === width ) {
		return `${ label } must be a 2D array`;
	}

	for ( const row of matrix ) {
		if ( ! Array.isArray( row ) || row.length !== width ) {
			return `${ label } rows must all have ${ width } columns`;
		}

		for ( const cell of row ) {
			if ( 'number' !== typeof cell || ! Number.isFinite( cell ) ) {
				return `${ label } must contain finite numbers only`;
			}
		}
	}

	return null;
}

/** A node as both plain text and LaTeX, from one tree. */
function spell( node ) {
	const text = node.toString();

	let tex;

	try {
		tex = node.toTex();
	} catch ( err ) {
		// `toTex()` throws on a handful of node types; the text form is still
		// true, and claiming LaTeX that does not exist is how the WordPress
		// tools shipped `2 * x + 5` in a `latex` field.
		tex = text;
	}

	return { text, tex };
}

/** A number as the shortest honest string. */
function numberText( value ) {
	return Number.isInteger( value ) ? String( value ) : String( Number( value.toFixed( 10 ) ) );
}

/** A row vector or matrix as a `bmatrix`. */
function matrixTex( rows ) {
	const asRows = Array.isArray( rows[ 0 ] ) ? rows : [ rows ];
	const body = asRows
		.map( ( row ) => row.map( ( cell ) => ( 'number' === typeof cell ? numberText( cell ) : String( cell ) ) ).join( ' & ' ) )
		.join( ' \\\\ ' );

	return `\\begin{bmatrix} ${ body } \\end{bmatrix}`;
}

/** Rank by Gaussian elimination with partial pivoting. */
function matrixRank( rows ) {
	const work = rows.map( ( row ) => row.slice() );
	const height = work.length;
	const width = work[ 0 ].length;

	let rank = 0;

	for ( let col = 0; col < width && rank < height; col++ ) {
		let pivot = rank;

		for ( let row = rank; row < height; row++ ) {
			if ( Math.abs( work[ row ][ col ] ) > Math.abs( work[ pivot ][ col ] ) ) {
				pivot = row;
			}
		}

		if ( Math.abs( work[ pivot ][ col ] ) <= PIVOT_EPSILON ) {
			continue;
		}

		const swap = work[ rank ];
		work[ rank ] = work[ pivot ];
		work[ pivot ] = swap;

		const divisor = work[ rank ][ col ];

		for ( let row = rank + 1; row < height; row++ ) {
			const factor = work[ row ][ col ] / divisor;

			if ( 0 === factor ) {
				continue;
			}

			for ( let c = col; c < width; c++ ) {
				work[ row ][ c ] -= factor * work[ rank ][ c ];
			}
		}

		rank++;
	}

	return rank;
}

/** A mathjs matrix object as a plain array, so `JSON.stringify` is not surprising. */
function plain( value ) {
	if ( value && 'function' === typeof value.toArray ) {
		return value.toArray();
	}

	return value;
}

// ── POST /derivative — symbolic differentiation ─────────────
mathRouter.post( '/derivative', async ( req, res ) => {
	const { expression, variable = 'x', order = 1, simplify = true } = req.body || {};

	const problem = expressionProblem( expression )
		|| ( 'string' === typeof variable && /^[A-Za-z][A-Za-z0-9_]*$/.test( variable ) ? null : 'Variable must be an identifier' )
		|| ( Number.isInteger( order ) && order >= 1 && order <= MAX_ORDER ? null : `Order must be an integer from 1 to ${ MAX_ORDER }` );

	if ( problem ) {
		return res.status( 400 ).json( { success: false, error: problem } );
	}

	try {
		const mathjs = await import( 'mathjs' );

		let node = mathjs.parse( expression );

		for ( let pass = 0; pass < order; pass++ ) {
			node = mathjs.derivative( node, variable );
		}

		if ( false !== simplify ) {
			node = mathjs.simplify( node );
		}

		const derivative = spell( node );

		res.json( {
			success: true,
			expression,
			variable,
			order,
			derivative,
			// Kept flat as well as nested: the tools read one shape, and a caller
			// poking at the route by hand should not have to guess which.
			text: derivative.text,
			tex: derivative.tex,
		} );
	} catch ( err ) {
		// A parse failure is the caller's input, not this server's health.
		res.status( 400 ).json( { success: false, error: err.message } );
	}
} );

// ── POST /simplify — algebraic simplification ───────────────
mathRouter.post( '/simplify', async ( req, res ) => {
	const { expression } = req.body || {};

	const problem = expressionProblem( expression );

	if ( problem ) {
		return res.status( 400 ).json( { success: false, error: problem } );
	}

	try {
		const mathjs = await import( 'mathjs' );

		const node = mathjs.simplify( mathjs.parse( expression ) );
		const simplified = spell( node );

		res.json( {
			success: true,
			expression,
			simplified,
			text: simplified.text,
			tex: simplified.tex,
		} );
	} catch ( err ) {
		res.status( 400 ).json( { success: false, error: err.message } );
	}
} );

// ── POST /matrix — linear algebra ──────────────────────────
mathRouter.post( '/matrix', async ( req, res ) => {
	const { operation, a, b } = req.body || {};

	if ( ! OPERATIONS.includes( operation ) ) {
		return res.status( 400 ).json( { success: false, error: `Unknown operation: ${ operation }` } );
	}

	const problem = matrixProblem( a, 'matrix_a' )
		|| ( BINARY.includes( operation ) ? matrixProblem( b, 'matrix_b' ) : null );

	if ( problem ) {
		return res.status( 400 ).json( { success: false, error: problem } );
	}

	try {
		const mathjs = await import( 'mathjs' );

		let result;

		switch ( operation ) {
			case 'add':
				result = plain( mathjs.add( a, b ) );
				break;
			case 'subtract':
				result = plain( mathjs.subtract( a, b ) );
				break;
			case 'multiply':
				result = plain( mathjs.multiply( a, b ) );
				break;
			case 'transpose':
				result = plain( mathjs.transpose( a ) );
				break;
			case 'inverse':
				result = plain( mathjs.inv( a ) );
				break;
			case 'determinant':
				result = plain( mathjs.det( a ) );
				break;
			case 'trace':
				result = plain( mathjs.trace( a ) );
				break;
			case 'rank':
				result = matrixRank( a );
				break;
			case 'eigenvalues':
				// `eigs()` reports complex values as `{re, im}`. A real eigenvalue
				// is a number here, not an object with a zero imaginary part, so a
				// caller can compare it without unwrapping.
				result = mathjs.eigs( a ).values.map( ( value ) => {
					if ( 'number' === typeof value ) {
						return value;
					}

					return Math.abs( value.im ) <= PIVOT_EPSILON ? value.re : { re: value.re, im: value.im };
				} );
				break;
		}

		const text = Array.isArray( result ) ? JSON.stringify( result ) : numberText( result );
		const tex = Array.isArray( result ) ? matrixTex( result ) : numberText( result );

		res.json( { success: true, operation, result, text, tex } );
	} catch ( err ) {
		// A singular matrix, mismatched dimensions, a non-square transpose — all
		// the caller's input, and all named by the message.
		res.status( 400 ).json( { success: false, error: err.message } );
	}
} );
