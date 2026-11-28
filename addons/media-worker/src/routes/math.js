// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview Symbolic mathematics — the engine behind the `math` toolkit's compute tools.
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

import { isCanvasUnavailable, renderChartPng } from '../utils/chart-render.js';
import {
	boolValue,
	clampInt,
	DEFAULT_SAMPLES,
	graphConfig,
	MAX_SAMPLES,
	MIN_SAMPLES,
	normaliseHex,
	rangeProblem,
	sampleFunction,
} from '../utils/graph-sample.js';
import { rootText, solveEquation } from '../utils/polynomial.js';
import { definiteIntegral, indefiniteIntegral } from '../utils/integrate.js';

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

// ── POST /graph — sample a function and render it ──────────

/** Width, height and title bounds, matching the WordPress tool schema. */
const GRAPH_BOUNDS = {
	width: [ 400, 2000 ],
	height: [ 300, 1500 ],
};

/** The title is drawn inside the image, so it is capped rather than wrapped. */
const MAX_TITLE_LENGTH = 200;

/** The colour the WordPress tool used when none was supplied. */
const DEFAULT_LINE_COLOR = '#0066cc';

mathRouter.post( '/graph', async ( req, res ) => {
	const body = req.body || {};
	const { expression, x_range: xRange, y_range: yRange, title, samples } = body;

	const problem = expressionProblem( expression )
		|| rangeProblem( xRange, 'x_range' )
		|| ( null === yRange || undefined === yRange ? null : rangeProblem( yRange, 'y_range' ) );

	if ( problem ) {
		return res.status( 400 ).json( { success: false, error: problem } );
	}

	const width = clampInt( body.width, GRAPH_BOUNDS.width[ 0 ], GRAPH_BOUNDS.width[ 1 ], 800 );
	const height = clampInt( body.height, GRAPH_BOUNDS.height[ 0 ], GRAPH_BOUNDS.height[ 1 ], 600 );
	const sampleCount = clampInt( samples, MIN_SAMPLES, MAX_SAMPLES, DEFAULT_SAMPLES );
	const showGrid = boolValue( body.show_grid, true );
	const lineColor = normaliseHex( body.line_color ) || DEFAULT_LINE_COLOR;
	const heading = 'string' === typeof title ? title.trim().slice( 0, MAX_TITLE_LENGTH ) : '';

	try {
		const mathjs = await import( 'mathjs' );
		const compiled = mathjs.compile( expression );

		const { points, finite, yRange: window, firstError } = sampleFunction( compiled, {
			xMin: xRange[ 0 ],
			xMax: xRange[ 1 ],
			samples: sampleCount,
			yMin: yRange ? yRange[ 0 ] : null,
			yMax: yRange ? yRange[ 1 ] : null,
		} );

		if ( null === window ) {
			// Nothing to draw. An empty chart would look like a rendering bug, so the
			// request is refused with the reason instead. When NOTHING evaluated the
			// evaluator's own message is the useful one (an unknown symbol is a caller
			// error, not a window full of gaps).
			if ( 0 === finite && firstError ) {
				return res.status( 400 ).json( { success: false, error: firstError } );
			}

			return res.status( 422 ).json( {
				success: false,
				error: `The expression has fewer than two finite values over [${ xRange[ 0 ] }, ${ xRange[ 1 ] }]`,
				finite_samples: finite,
			} );
		}

		const config = graphConfig( {
			expression,
			points,
			xRange,
			yRange: window,
			title: heading,
			showGrid,
			lineColor,
		} );

		const { image } = await renderChartPng( config, { width, height } );

		res.json( {
			success: true,
			expression,
			mime_type: 'image/png',
			data_base64: image.toString( 'base64' ),
			size: image.length,
			width,
			height,
			samples: sampleCount,
			finite_samples: finite,
			x_range: [ xRange[ 0 ], xRange[ 1 ] ],
			y_range: window,
			grid: showGrid,
			line_color: lineColor,
		} );
	} catch ( err ) {
		if ( isCanvasUnavailable( err ) ) {
			return res.status( 503 ).json( {
				error: 'capability_unavailable',
				capability: 'chart-rendering',
				message: 'Graph rendering is unavailable: chartjs-node-canvas or its native canvas dependency is not installed on this server.',
			} );
		}

		// A parse failure names the offending token better than a regex could.
		res.status( 400 ).json( { success: false, error: err.message } );
	}
} );

// ── POST /solve — solve a polynomial equation ──────────────

/** The variable is interpolated into expressions, so it must be a bare name. */
const VARIABLE_PATTERN = /^[A-Za-z][A-Za-z0-9_]*$/;

/** Above this, roots cost more to find than the answer is worth. */
const MAX_DEGREE = 12;

/**
 * Why a solve request cannot be attempted, or null.
 *
 * A missing side is a bad request rather than a parse failure, so it is
 * separated from whatever mathjs would say about an empty string.
 */
function solveProblem( left, right, variable ) {
	if ( 'string' !== typeof left || '' === left.trim() ) {
		return 'Missing left expression';
	}

	if ( 'string' !== typeof right || '' === right.trim() ) {
		return 'Missing right expression';
	}

	if ( left.length > MAX_EXPRESSION_LENGTH || right.length > MAX_EXPRESSION_LENGTH ) {
		return `Expression is longer than ${ MAX_EXPRESSION_LENGTH } characters`;
	}

	if ( 'string' !== typeof variable || ! VARIABLE_PATTERN.test( variable ) ) {
		return 'variable must be a bare identifier such as x';
	}

	return null;
}

/** `x = 5`, or `x1 = 2, x2 = -2` when there is more than one. */
function solutionText( variable, solutions ) {
	if ( 1 === solutions.length ) {
		return `${ variable } = ${ rootText( solutions[ 0 ] ) }`;
	}

	return solutions
		.map( ( root, index ) => `${ variable }${ index + 1 } = ${ rootText( root ) }` )
		.join( ', ' );
}

/** `${ variable }_1 = 2, \quad ${ variable }_2 = -2`. */
function solutionTex( variable, solutions ) {
	return solutions
		.map( ( root, index ) => `${ variable }_{${ index + 1 }} = ${ rootText( root ) }` )
		.join( ', \\quad ' );
}

mathRouter.post( '/solve', async ( req, res ) => {
	const { left, right, variable } = req.body || {};
	const symbol = undefined === variable || null === variable ? 'x' : variable;

	const problem = solveProblem( left, right, symbol );

	if ( problem ) {
		return res.status( 400 ).json( { success: false, error: problem } );
	}

	try {
		const mathjs = await import( 'mathjs' );
		const solved = solveEquation( mathjs, left, right, symbol );

		// Not a polynomial: the caller asked for a solver this contract does not
		// offer. The WordPress fallback guessed here, and guessed wrong.
		if ( null !== solved.problem ) {
			return res.status( 422 ).json( { success: false, error: solved.problem } );
		}

		if ( solved.degree > MAX_DEGREE ) {
			return res.status( 422 ).json( {
				success: false,
				error: `Degree ${ solved.degree } is above the supported maximum of ${ MAX_DEGREE }`,
			} );
		}

		const text = solved.infinite
			? `Every value of ${ symbol } satisfies the equation`
			: 0 === solved.solutions.length
				? 'No solutions'
				: solutionText( symbol, solved.solutions );

		res.json( {
			success: true,
			equation: { left, right },
			variable: symbol,
			solutions: solved.solutions,
			degree: solved.degree,
			infinite: solved.infinite,
			text,
			tex: 0 === solved.solutions.length ? '' : solutionTex( symbol, solved.solutions ),
		} );
	} catch ( err ) {
		// mathjs names the offending token better than a regex could.
		res.status( 400 ).json( { success: false, error: err.message } );
	}
} );

// ── POST /integral — symbolic and definite integration ─────

/** The two modes the tool offers. */
const INTEGRAL_TYPES = [ 'indefinite', 'definite' ];

/** A limit must be a finite number. */
function limitProblem( value, label ) {
	if ( 'number' !== typeof value || ! Number.isFinite( value ) ) {
		return `${ label } must be a finite number`;
	}

	return null;
}

mathRouter.post( '/integral', async ( req, res ) => {
	const body = req.body || {};
	const { expression, variable, type, lower, upper } = body;
	const symbol = undefined === variable || null === variable ? 'x' : variable;
	const kind = undefined === type || null === type ? 'indefinite' : type;

	const problem = expressionProblem( expression )
		|| ( 'string' !== typeof symbol || ! VARIABLE_PATTERN.test( symbol )
			? 'variable must be a bare identifier such as x'
			: null )
		|| ( ! INTEGRAL_TYPES.includes( kind )
			? `type must be one of: ${ INTEGRAL_TYPES.join( ', ' ) }`
			: null )
		|| ( 'definite' === kind
			? ( limitProblem( lower, 'lower limit' ) || limitProblem( upper, 'upper limit' ) )
			: null );

	if ( problem ) {
		return res.status( 400 ).json( { success: false, error: problem } );
	}

	try {
		const nerdamer = ( await import( 'nerdamer' ) ).default;
		const calculus = await import( 'nerdamer/calculus' );

		if ( 'definite' === kind ) {
			const result = definiteIntegral( nerdamer, calculus, expression, symbol, lower, upper );

			// No elementary antiderivative is not a failure here: defint integrates
			// numerically. Only a result that is not a number is refused.
			if ( null !== result.problem ) {
				return res.status( 422 ).json( { success: false, error: result.problem } );
			}

			return res.json( {
				success: true,
				type: kind,
				expression,
				variable: symbol,
				value: result.value,
				exact: result.exact,
				tex: result.tex,
				lower,
				upper,
			} );
		}

		const result = indefiniteIntegral( nerdamer, calculus, expression, symbol );

		// An antiderivative is returned only after it has been differentiated and
		// checked against the integrand; the engine's word is not enough.
		if ( null !== result.problem ) {
			return res.status( 422 ).json( {
				success: false,
				error: result.problem,
				verified: result.verified,
			} );
		}

		res.json( {
			success: true,
			type: kind,
			expression,
			variable: symbol,
			antiderivative: result.antiderivative,
			tex: result.tex,
			verified: result.verified,
			samples: result.samples,
		} );
	} catch ( err ) {
		res.status( 400 ).json( { success: false, error: err.message } );
	}
} );
