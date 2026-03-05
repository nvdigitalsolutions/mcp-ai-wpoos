#!/usr/bin/env node
/**
 * QR Code Generation Service
 *
 * Generates QR codes using the vendored qrcode package so it works on plugin
 * activation without requiring a separate `npm install` step.
 *
 * The qrcode library and its runtime dependencies (dijkstrajs, pngjs) are
 * pre-packaged under assets/vendor/qrcode/ and loaded via an explicit path.
 *
 * Usage:
 *   node qrcode-service.js generate '{"data":"https://wa.me/15550001234","format":"data-url","options":{"width":200}}'
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.1
 */

const path = require( 'path' );

// Load from the pre-packaged vendor directory so no npm install is needed.
const QRCode = require( path.resolve( __dirname, '../assets/vendor/qrcode' ) );

/**
 * Generate a QR code.
 *
 * @param {string} data    - Data to encode.
 * @param {string} format  - 'data-url' | 'base64' | 'svg'.
 * @param {Object} options - QR options (width, margin, errorCorrectionLevel, color).
 * @returns {Promise<string>} Encoded QR code string.
 */
async function generateQRCode( data, format, options ) {
	const qrOptions = {
		width: options.width || 200,
		margin: options.margin || 2,
		errorCorrectionLevel: options.errorCorrectionLevel || 'M',
		color: options.color || { dark: '#000000', light: '#ffffff' },
	};

	switch ( format ) {
		case 'base64': {
			const dataUrl = await QRCode.toDataURL( data, qrOptions );
			// Strip the data:image/png;base64, prefix.
			return dataUrl.split( ',' )[ 1 ];
		}
		case 'svg':
			return QRCode.toString( data, { ...qrOptions, type: 'svg' } );
		case 'data-url':
		default:
			return QRCode.toDataURL( data, qrOptions );
	}
}

// --------------------------------------------------------------------------
// CLI entry point
// --------------------------------------------------------------------------

if ( require.main === module ) {
	const action   = process.argv[ 2 ];
	const rawParam = process.argv[ 3 ] || '{}';

	if ( 'generate' !== action ) {
		process.stderr.write(
			JSON.stringify( { success: false, error: 'Unknown action: ' + action } ) + '\n'
		);
		process.exit( 1 );
	}

	let params;
	try {
		params = JSON.parse( rawParam );
	} catch ( e ) {
		process.stderr.write(
			JSON.stringify( { success: false, error: 'Invalid JSON params: ' + e.message } ) + '\n'
		);
		process.exit( 1 );
	}

	if ( ! params.data ) {
		process.stderr.write(
			JSON.stringify( { success: false, error: 'data is required' } ) + '\n'
		);
		process.exit( 1 );
	}

	generateQRCode( params.data, params.format || 'data-url', params.options || {} )
		.then( ( result ) => {
			process.stdout.write( JSON.stringify( { success: true, result } ) + '\n' );
		} )
		.catch( ( err ) => {
			process.stderr.write(
				JSON.stringify( { success: false, error: err.message } ) + '\n'
			);
			process.exit( 1 );
		} );
}

module.exports = { generateQRCode };
