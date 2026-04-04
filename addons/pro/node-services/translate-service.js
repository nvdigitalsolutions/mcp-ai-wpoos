#!/usr/bin/env node
/**
 * Translation Service
 *
 * Translates text using the google-translate-api-x NPM package.
 *
 * Usage:
 *   node translate-service.js translate '{"text":"Hello world","target_language":"es"}'
 *   node translate-service.js detect '{"text":"Bonjour le monde"}'
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 */

const path = require( 'path' );

const nodeModules = path.resolve( __dirname, '../node_modules' );
const translate   = require( path.join( nodeModules, 'google-translate-api-x' ) );

// google-translate-api-x may export default or directly.
const translateFn = translate.default || translate;

/**
 * Translate text to a target language.
 *
 * @param {string} text           - Text to translate.
 * @param {string} targetLanguage - Target ISO 639-1 language code (e.g. "es").
 * @param {string} sourceLangauge - Source language code or 'auto'.
 * @returns {Promise<Object>} Translation result.
 */
async function translateText( text, targetLanguage, sourceLangauge ) {
	const options = { to: targetLanguage };
	if ( sourceLangauge && sourceLangauge !== 'auto' ) {
		options.from = sourceLangauge;
	}

	const res = await translateFn( text, options );

	return {
		translated_text:           res.text,
		detected_source_language:  res.from && res.from.language ? res.from.language.iso : null,
		confidence:                res.from && res.from.language ? ( res.from.language.didYouMean ? 0.7 : 1.0 ) : null,
	};
}

/**
 * Detect the language of a text string.
 *
 * @param {string} text - Input text.
 * @returns {Promise<Object>} Detection result.
 */
async function detectLanguage( text ) {
	// Translate to 'en' just to get the detected language back.
	const res = await translateFn( text, { to: 'en' } );
	const lang = res.from && res.from.language ? res.from.language.iso : null;

	return {
		language:   lang,
		confidence: lang ? 1.0 : 0,
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

	( async () => {
		try {
			let result;
			switch ( action ) {
				case 'translate':
					if ( ! params.text || ! params.target_language ) {
						throw new Error( 'text and target_language are required' );
					}
					result = await translateText(
						params.text,
						params.target_language,
						params.source_language || 'auto'
					);
					break;

				case 'detect':
					if ( ! params.text ) {
						throw new Error( 'text is required' );
					}
					result = await detectLanguage( params.text );
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
	} )();
}

module.exports = { translateText, detectLanguage };
