#!/usr/bin/env node
/**
 * Canvas Image Generation Service
 *
 * Provides server-side image generation and manipulation using the optional
 * canvas npm package (HTML5 Canvas API for Node.js).
 *
 * Install: npm install canvas@2
 * Requires system libraries: cairo, pango, libpng, libjpeg.
 * On shared hosts (EACCES): mkdir node_modules && chmod 775 node_modules first.
 *
 * Supported actions:
 *   generate  - Create a new image from drawing instructions and save to disk.
 *   render_chart - Render a Chart.js configuration to a PNG image.
 *
 * Usage:
 *   node canvas-service.js generate '{"output":"/tmp/out.png","width":800,"height":600,"background":"#ffffff","commands":[{"type":"text","text":"Hello","x":10,"y":50,"font":"24px sans-serif","color":"#000000"}]}'
 *   node canvas-service.js render_chart '{"output":"/tmp/chart.png","width":800,"height":400,"config":{"type":"bar","data":{...},"options":{...}}}'
 *
 * Output (JSON to stdout):
 *   success      - true/false
 *   output_path  - Absolute path to the generated PNG file
 *   width        - Image width in pixels
 *   height       - Image height in pixels
 *   error        - Error message (on failure)
 *
 * Exit codes:
 *   0 - Success
 *   1 - Invalid arguments or unsupported action
 *   2 - Missing canvas dependency
 *   3 - Image generation error
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.5
 */

'use strict';

const fs   = require( 'fs' );
const path = require( 'path' );

// ---------------------------------------------------------------------------
// Load canvas dependency
// ---------------------------------------------------------------------------

let createCanvas, Image, loadImage;

try {
	// Try vendor directory first (pre-packaged distribution).
	const vendorCanvasPath = path.join( __dirname, '..', 'assets', 'vendor', 'canvas' );
	if ( fs.existsSync( vendorCanvasPath ) ) {
		const mod  = require( vendorCanvasPath );
		createCanvas = mod.createCanvas;
		Image        = mod.Image;
		loadImage    = mod.loadImage;
	} else {
		// Fall back to node_modules (user-installed).
		const mod  = require( 'canvas' );
		createCanvas = mod.createCanvas;
		Image        = mod.Image;
		loadImage    = mod.loadImage;
	}
} catch ( e ) {
	// Output actionable error; the missing-canvas admin notice already tells
	// the user to run `npm install canvas@2`.
	process.stdout.write(
		JSON.stringify( {
			success: false,
			error: 'Canvas package is not installed. Run: npm install canvas@2',
		} )
	);
	process.exit( 2 );
}

// ---------------------------------------------------------------------------
// Action: generate
// ---------------------------------------------------------------------------

/**
 * Create an image from a list of drawing commands.
 *
 * @param {object} params
 * @param {string} params.output      - Absolute output path (PNG).
 * @param {number} [params.width]     - Canvas width (default 800).
 * @param {number} [params.height]    - Canvas height (default 600).
 * @param {string} [params.background] - CSS background colour (default #ffffff).
 * @param {Array}  [params.commands]  - Drawing commands (see inline docs).
 * @returns {object} Result with output_path, width, height.
 */
async function generate( params ) {
	const width      = Number( params.width )  || 800;
	const height     = Number( params.height ) || 600;
	const background = params.background || '#ffffff';
	const outputPath = params.output;
	const commands   = Array.isArray( params.commands ) ? params.commands : [];

	if ( ! outputPath ) {
		throw new Error( 'Missing required parameter: output' );
	}

	const canvas = createCanvas( width, height );
	const ctx    = canvas.getContext( '2d' );

	// Fill background.
	ctx.fillStyle = background;
	ctx.fillRect( 0, 0, width, height );

	// Execute drawing commands.
	for ( const cmd of commands ) {
		switch ( cmd.type ) {
			case 'text':
				ctx.font      = cmd.font  || '16px sans-serif';
				ctx.fillStyle = cmd.color || '#000000';
				if ( cmd.align ) {
					ctx.textAlign = cmd.align;
				}
				ctx.fillText( cmd.text || '', Number( cmd.x ) || 0, Number( cmd.y ) || 0 );
				break;

			case 'rect':
				ctx.fillStyle = cmd.color || '#000000';
				ctx.fillRect(
					Number( cmd.x ) || 0,
					Number( cmd.y ) || 0,
					Number( cmd.width ) || 0,
					Number( cmd.height ) || 0
				);
				break;

			case 'stroke_rect':
				ctx.strokeStyle = cmd.color || '#000000';
				ctx.lineWidth   = Number( cmd.lineWidth ) || 1;
				ctx.strokeRect(
					Number( cmd.x ) || 0,
					Number( cmd.y ) || 0,
					Number( cmd.width ) || 0,
					Number( cmd.height ) || 0
				);
				break;

			case 'circle':
				ctx.beginPath();
				ctx.arc(
					Number( cmd.x ) || 0,
					Number( cmd.y ) || 0,
					Number( cmd.radius ) || 10,
					0,
					Math.PI * 2
				);
				if ( cmd.fill ) {
					ctx.fillStyle = cmd.fill;
					ctx.fill();
				}
				if ( cmd.stroke ) {
					ctx.strokeStyle  = cmd.stroke;
					ctx.lineWidth    = Number( cmd.lineWidth ) || 1;
					ctx.stroke();
				}
				ctx.closePath();
				break;

			case 'line':
				ctx.beginPath();
				ctx.moveTo( Number( cmd.x1 ) || 0, Number( cmd.y1 ) || 0 );
				ctx.lineTo( Number( cmd.x2 ) || 0, Number( cmd.y2 ) || 0 );
				ctx.strokeStyle = cmd.color || '#000000';
				ctx.lineWidth   = Number( cmd.lineWidth ) || 1;
				ctx.stroke();
				break;

			case 'image': {
				const imgPath = cmd.src || '';
				if ( ! imgPath || ! fs.existsSync( imgPath ) ) {
					break;
				}
				const img = await loadImage( imgPath );
				ctx.drawImage(
					img,
					Number( cmd.x ) || 0,
					Number( cmd.y ) || 0,
					Number( cmd.width )  || img.width,
					Number( cmd.height ) || img.height
				);
				break;
			}

			default:
				// Unknown command types are silently skipped.
				break;
		}
	}

	// Write PNG to disk.
	const buffer = canvas.toBuffer( 'image/png' );
	fs.writeFileSync( outputPath, buffer );

	return { output_path: outputPath, width, height };
}

// ---------------------------------------------------------------------------
// Action: render_chart
// ---------------------------------------------------------------------------

/**
 * Render a Chart.js configuration to a PNG image.
 *
 * Requires chart.js to be installed alongside canvas. The service first tries
 * the pre-packaged Chart.js UMD bundle under assets/vendor/chart.js, then
 * falls back to node_modules.
 *
 * @param {object} params
 * @param {string} params.output    - Absolute output path (PNG).
 * @param {number} [params.width]   - Canvas width (default 800).
 * @param {number} [params.height]  - Canvas height (default 400).
 * @param {object} params.config    - Chart.js configuration object.
 * @returns {object} Result with output_path, width, height.
 */
async function renderChart( params ) {
	const width      = Number( params.width )  || 800;
	const height     = Number( params.height ) || 400;
	const outputPath = params.output;
	const chartConfig = params.config;

	if ( ! outputPath ) {
		throw new Error( 'Missing required parameter: output' );
	}
	if ( ! chartConfig || typeof chartConfig !== 'object' ) {
		throw new Error( 'Missing required parameter: config (Chart.js configuration object)' );
	}

	// Load Chart.js.
	let Chart;
	const vendorChartPath = path.join( __dirname, '..', 'assets', 'vendor', 'chart.js', 'chart.umd.js' );
	if ( fs.existsSync( vendorChartPath ) ) {
		Chart = require( vendorChartPath );
	} else {
		try {
			Chart = require( 'chart.js' );
		} catch ( e ) {
			throw new Error( 'chart.js is not installed. Run: npm install chart.js' );
		}
	}

	// Chart.js may export itself as Chart or as { Chart }.
	if ( Chart && Chart.Chart ) {
		Chart = Chart.Chart;
	}

	// Register Chart.js components required for server-side rendering.
	if ( Chart.register ) {
		const {
			CategoryScale,
			LinearScale,
			BarElement,
			LineElement,
			PointElement,
			ArcElement,
			Title,
			Tooltip,
			Legend,
			Filler,
		} = Chart;
		const components = [
			CategoryScale, LinearScale, BarElement, LineElement, PointElement,
			ArcElement, Title, Tooltip, Legend, Filler,
		].filter( Boolean );
		if ( components.length > 0 ) {
			Chart.register( ...components );
		}
	}

	// Create canvas and render chart.
	const canvas = createCanvas( width, height );
	const ctx    = canvas.getContext( '2d' );

	// Fill white background (chart is transparent by default).
	ctx.fillStyle = '#ffffff';
	ctx.fillRect( 0, 0, width, height );

	// Chart.js v3/v4 server-side rendering: pass the canvas element directly.
	const chart = new Chart( canvas, chartConfig );

	// Flush rendering pipeline.
	chart.render();

	// Write PNG to disk.
	const buffer = canvas.toBuffer( 'image/png' );
	fs.writeFileSync( outputPath, buffer );

	// Clean up chart instance to free memory.
	chart.destroy();

	return { output_path: outputPath, width, height };
}

// ---------------------------------------------------------------------------
// CLI entry point
// ---------------------------------------------------------------------------

if ( require.main === module ) {
	const action   = process.argv[ 2 ];
	const rawParam = process.argv[ 3 ] || '{}';

	if ( ! action || ! [ 'generate', 'render_chart' ].includes( action ) ) {
		process.stdout.write(
			JSON.stringify( {
				success: false,
				error: 'Invalid usage. Valid actions: generate, render_chart',
				usage: 'node canvas-service.js <action> <json-params>',
			} )
		);
		process.exit( 1 );
	}

	( async () => {
		let params;
		try {
			params = JSON.parse( rawParam );
		} catch ( e ) {
			process.stdout.write(
				JSON.stringify( {
					success: false,
					error: 'Invalid JSON parameters: ' + e.message,
				} )
			);
			process.exit( 1 );
		}

		try {
			let result;
			if ( action === 'generate' ) {
				result = await generate( params );
			} else {
				result = await renderChart( params );
			}
			process.stdout.write( JSON.stringify( { success: true, ...result } ) );
			process.exit( 0 );
		} catch ( e ) {
			const response = { success: false, error: e.message, action };
			if ( process.env.NODE_ENV === 'development' || process.env.DEBUG ) {
				response.stack = e.stack;
			}
			process.stdout.write( JSON.stringify( response ) );
			process.exit( 3 );
		}
	} )();
}

module.exports = { generate, renderChart };
