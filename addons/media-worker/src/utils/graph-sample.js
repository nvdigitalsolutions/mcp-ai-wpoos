// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview Sample a compiled math expression across an x window.
 *
 * Deliberately free of express and canvas so the decisions live somewhere they
 * can be tested without the optional native binding: where the gaps are, what
 * the automatic y window is, and when a request has produced too little to plot
 * are all rules of this module, not of the renderer.
 *
 * A point that cannot be evaluated is a **gap**, not a failure. Asking for
 * `1/x` over `[-10, 10]` includes `x = 0`, and a plot that refused to render
 * because one sample in four hundred is undefined would be useless. The request
 * only fails when the window yields fewer than two finite points, because at
 * that stage there is no line to draw and an empty chart would look like a bug.
 */

/** Fewer samples than this cannot describe a curve at any useful resolution. */
export const MIN_SAMPLES = 50;

/** More samples than this cost render time without adding visible detail. */
export const MAX_SAMPLES = 2000;

/** The sample count used when the caller does not choose one. */
export const DEFAULT_SAMPLES = 400;

/** Fraction added above and below the data when the y window is automatic. */
const Y_PADDING = 0.1;

/**
 * Sample `compiled` at `samples` points from `xMin` to `xMax`.
 *
 * Returns the points, how many of them were finite, and the y window to draw.
 * `yRange` is null when the window holds fewer than two finite values, which
 * the caller must treat as a refusal rather than an empty plot.
 *
 * @param {{ evaluate: (scope: object) => unknown }} compiled Compiled mathjs node.
 * @param {{ xMin: number, xMax: number, samples: number, yMin?: number|null, yMax?: number|null }} options Sampling window.
 * @return {{ points: Array<{x: number, y: number|null}>, finite: number, yRange: number[]|null, firstError: string|null }}
 */
export function sampleFunction( compiled, options ) {
	const { xMin, xMax, samples } = options;
	const yMin = null === options.yMin || undefined === options.yMin ? null : options.yMin;
	const yMax = null === options.yMax || undefined === options.yMax ? null : options.yMax;

	const points = [];
	const step = ( xMax - xMin ) / ( samples - 1 );

	let finite = 0;
	let lowest = Infinity;
	let highest = -Infinity;
	let firstError = null;

	for ( let i = 0; i < samples; i++ ) {
		// Pin the last sample to xMax instead of trusting accumulated addition.
		const x = i === samples - 1 ? xMax : xMin + ( step * i );
		let y = null;

		try {
			const value = compiled.evaluate( { x } );

			// A complex result (sqrt of a negative) is a gap for a real-axis plot,
			// not a value to draw; `typeof` excludes it without inspecting fields.
			if ( 'number' === typeof value && Number.isFinite( value ) ) {
				y = value;
				finite += 1;

				if ( value < lowest ) {
					lowest = value;
				}

				if ( value > highest ) {
					highest = value;
				}
			}
		} catch ( err ) {
			// A pole, or a domain edge the evaluator rejects. Both are gaps. The
			// first message is kept: when NOTHING evaluates, the message is usually
			// `Undefined symbol a`, which is a caller error rather than a gap.
			if ( null === firstError && err && err.message ) {
				firstError = err.message;
			}

			y = null;
		}

		points.push( { x, y } );
	}

	if ( finite < 2 ) {
		return { points, finite, yRange: null, firstError };
	}

	if ( null !== yMin && null !== yMax ) {
		return { points, finite, yRange: [ yMin, yMax ], firstError };
	}

	let low = lowest;
	let high = highest;

	if ( low === high ) {
		// A constant function would otherwise give a zero-height axis.
		low -= 1;
		high += 1;
	} else {
		const pad = ( high - low ) * Y_PADDING;
		low -= pad;
		high += pad;
	}

	return { points, finite, yRange: [ low, high ], firstError };
}

/**
 * Build the Chart.js config for a sampled function.
 *
 * Pure, so the shape that the optional renderer receives is testable on a
 * machine where the native canvas binding cannot load. Two details matter:
 *
 *  - `parsing: false` with `{x, y}` points keeps the x axis numeric and evenly
 *    scaled (a category axis built from 400 labels is not the same plot);
 *  - `spanGaps: false` makes a `null` y a visible break, so a pole is a gap
 *    rather than a vertical line the function never takes.
 *
 * @param {{ expression: string, points: Array<{x: number, y: number|null}>, xRange: number[], yRange: number[], title: string, showGrid: boolean, lineColor: string }} input Render inputs.
 * @return {object} Chart.js configuration.
 */
export function graphConfig( input ) {
	const { expression, points, xRange, yRange, title, showGrid, lineColor } = input;

	return {
		type: 'line',
		data: {
			datasets: [ {
				label: expression,
				data: points,
				borderColor: lineColor,
				backgroundColor: lineColor,
				borderWidth: 2,
				pointRadius: 0,
				fill: false,
				spanGaps: false,
			} ],
		},
		options: {
			parsing: false,
			responsive: false,
			animation: false,
			plugins: {
				legend: { display: false },
				title: { display: '' !== title, text: title },
			},
			scales: {
				x: {
					type: 'linear',
					min: xRange[ 0 ],
					max: xRange[ 1 ],
					grid: { display: showGrid },
					title: { display: true, text: 'x' },
				},
				y: {
					type: 'linear',
					min: yRange[ 0 ],
					max: yRange[ 1 ],
					grid: { display: showGrid },
					title: { display: true, text: 'y' },
				},
			},
		},
	};
}

/**
 * Coerce a JSON boolean without the `(bool)` trap.
 *
 * WordPress read `show_grid` with `(bool)`, for which the string `'false'` is
 * true — the defect this port exists to fix. Absent means the default.
 *
 * @param {unknown} value Candidate value.
 * @param {boolean} fallback Value when absent.
 * @return {boolean}
 */
export function boolValue( value, fallback ) {
	if ( undefined === value || null === value ) {
		return fallback;
	}

	if ( 'boolean' === typeof value ) {
		return value;
	}

	if ( 'string' === typeof value ) {
		return ! [ 'false', '0', 'no', 'off', '' ].includes( value.trim().toLowerCase() );
	}

	return Boolean( value );
}

/**
 * Why an axis range is unusable, or null.
 *
 * Both ends must be finite numbers and the window must have width: an inverted
 * or zero-width range cannot become a Chart.js scale.
 *
 * @param {unknown} range Candidate range.
 * @param {string}  label Field name for the message.
 * @return {string|null}
 */
export function rangeProblem( range, label ) {
	if ( ! Array.isArray( range ) || 2 !== range.length ) {
		return `${ label } must be [min, max]`;
	}

	for ( const end of range ) {
		if ( 'number' !== typeof end || ! Number.isFinite( end ) ) {
			return `${ label } must contain finite numbers`;
		}
	}

	if ( ! ( range[ 1 ] > range[ 0 ] ) ) {
		return `${ label } must have max greater than min`;
	}

	return null;
}

/**
 * Clamp an integer to an inclusive window.
 *
 * Out-of-range sizes are clamped rather than rejected, matching how the
 * WordPress tool read `absint()` and then reported the width it used. The
 * response carries the effective value, so nothing is silently different.
 */
export function clampInt( value, min, max, fallback ) {
	const number = Number.parseInt( value, 10 );

	if ( ! Number.isFinite( number ) ) {
		return fallback;
	}

	if ( number < min ) {
		return min;
	}

	if ( number > max ) {
		return max;
	}

	return number;
}

/**
 * Normalise a hex colour to six digits, or null when it is not hex.
 *
 * Accepts the three-digit shorthand WordPress accepts and expands it, so
 * `#abc` and `#aabbcc` reach the renderer as one shape.
 */
export function normaliseHex( value ) {
	if ( 'string' !== typeof value ) {
		return null;
	}

	const match = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec( value.trim() );

	if ( null === match ) {
		return null;
	}

	if ( 3 === match[ 1 ].length ) {
		const [ r, g, b ] = match[ 1 ];

		return `#${ r }${ r }${ g }${ g }${ b }${ b }`;
	}

	return `#${ match[ 1 ] }`;
}
