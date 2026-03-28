#!/usr/bin/env node
/**
 * Language Detection Service
 *
 * Detects language of text using the franc package and provides
 * language code information via iso-639-1.
 *
 * Usage:
 *   node lang-detect-service.js detect '{"text":"Hello world"}'
 *   node lang-detect-service.js list_languages '{}'
 *   node lang-detect-service.js validate_code '{"code":"en"}'
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 */

const path = require( 'path' );

const nodeModules = path.resolve( __dirname, '../node_modules' );
const franc       = require( path.join( nodeModules, 'franc' ) );
const ISO6391     = require( path.join( nodeModules, 'iso-639-1' ) );

// iso-639-1 may export as default or directly.
const iso = ISO6391.default || ISO6391;

/**
 * Detect the language of a text string.
 *
 * @param {string} text - Input text.
 * @returns {Object} Detection result with code, name, confidence, alternatives.
 */
function detectLanguage( text ) {
	// franc.all() returns array of [langCode, confidence] sorted by confidence desc.
	const all = franc.all( text, { minLength: 3, only: [] } );

	if ( ! all || all.length === 0 || all[ 0 ][ 0 ] === 'und' ) {
		return {
			code:         'und',
			name:         'Undetermined',
			confidence:   0,
			alternatives: [],
		};
	}

	const top         = all[ 0 ];
	const topCode3    = top[ 0 ];
	const topConf     = parseFloat( top[ 1 ].toFixed( 4 ) );

	// franc uses ISO 639-3 codes; iso-639-1 uses ISO 639-1 (2-letter).
	// iso-639-1 provides getCode1() to convert from 639-3 where available.
	const topCode1 = iso.getCode1 ? iso.getCode1( topCode3 ) : '';
	const topCode  = topCode1 || topCode3;
	const topName  = topCode1 ? iso.getName( topCode1 ) : topCode3;

	const alternatives = all.slice( 1, 5 ).map( ( [ code3, conf ] ) => {
		const c1   = iso.getCode1 ? iso.getCode1( code3 ) : '';
		const code = c1 || code3;
		const name = c1 ? iso.getName( c1 ) : code3;
		return { code, name, confidence: parseFloat( conf.toFixed( 4 ) ) };
	} ).filter( ( a ) => a.code !== 'und' );

	return {
		code:         topCode,
		name:         topName || topCode,
		confidence:   topConf,
		alternatives,
	};
}

/**
 * List all ISO 639-1 languages.
 *
 * @returns {Array} Array of {code, name, nativeName}.
 */
function listLanguages() {
	const codes = iso.getAllCodes ? iso.getAllCodes() : [];
	return codes.map( ( code ) => ( {
		code,
		name:       iso.getName( code ),
		nativeName: iso.getNativeName( code ),
	} ) );
}

/**
 * Validate a language code against ISO 639-1.
 *
 * @param {string} code - Language code to validate.
 * @returns {Object} Validation result.
 */
function validateCode( code ) {
	const valid = iso.validate ? iso.validate( code ) : false;
	return {
		valid,
		code,
		name:       valid ? iso.getName( code ) : null,
		nativeName: valid ? iso.getNativeName( code ) : null,
	};
}

// ---------------------------------------------------------------------------
// CLI entry point
// ---------------------------------------------------------------------------

if ( require.main === module ) {
	const action   = process.argv[ 2 ];
	const rawParam = process.argv[ 3 ] || '{}';

	let params;
	try {
		params = JSON.parse( rawParam );
	} catch ( e ) {
		process.stderr.write(
			JSON.stringify( { success: false, error: 'Invalid JSON params: ' + e.message } ) + '\n'
		);
		process.exit( 1 );
	}

	try {
		let result;
		switch ( action ) {
			case 'detect':
				if ( ! params.text ) {
					throw new Error( 'text is required' );
				}
				result = detectLanguage( params.text );
				break;

			case 'list_languages':
				result = listLanguages();
				break;

			case 'validate_code':
				if ( ! params.code ) {
					throw new Error( 'code is required' );
				}
				result = validateCode( params.code );
				break;

			default:
				throw new Error( 'Unknown action: ' + action );
		}

		process.stdout.write( JSON.stringify( { success: true, result } ) + '\n' );
	} catch ( err ) {
		process.stderr.write(
			JSON.stringify( { success: false, error: err.message } ) + '\n'
		);
		process.exit( 1 );
	}
}

module.exports = { detectLanguage, listLanguages, validateCode };
