#!/usr/bin/env node
/**
 * Phone Number Format/Validation Service
 *
 * Formats, validates, and parses phone numbers using the libphonenumber-js
 * NPM package.
 *
 * Usage:
 *   node phone-format-service.js format '{"phone":"+14155552671","country_code":"US"}'
 *   node phone-format-service.js validate '{"phone":"415-555-2671","country_code":"US"}'
 *   node phone-format-service.js parse '{"phone":"+14155552671"}'
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 */

const path = require( 'path' );

const nodeModules   = path.resolve( __dirname, '../node_modules' );
const libphonenumber = require( path.join( nodeModules, 'libphonenumber-js' ) );

const {
	parsePhoneNumberFromString,
	isValidPhoneNumber,
	isPossiblePhoneNumber,
	getCountries,
} = libphonenumber;

/**
 * Format a phone number.
 *
 * @param {string} phone       - Phone number string.
 * @param {string} countryCode - ISO 3166-1 alpha-2 country code (e.g. "US").
 * @returns {Object} Formatted phone data.
 */
function formatPhone( phone, countryCode ) {
	try {
		const parsed = parsePhoneNumberFromString( phone, countryCode || 'US' );

		if ( ! parsed ) {
			return {
				formatted:     phone,
				national:      phone,
				international: phone,
				valid:         false,
				country:       countryCode || null,
				type:          null,
			};
		}

		return {
			formatted:     parsed.formatInternational(),
			national:      parsed.formatNational(),
			international: parsed.formatInternational(),
			valid:         parsed.isValid(),
			country:       parsed.country || countryCode || null,
			type:          parsed.getType() || null,
		};
	} catch ( e ) {
		return {
			formatted:     phone,
			national:      phone,
			international: phone,
			valid:         false,
			country:       countryCode || null,
			type:          null,
			error:         e.message,
		};
	}
}

/**
 * Validate a phone number.
 *
 * @param {string} phone       - Phone number string.
 * @param {string} countryCode - ISO 3166-1 alpha-2 country code.
 * @returns {Object} Validation result.
 */
function validatePhone( phone, countryCode ) {
	try {
		const parsed = parsePhoneNumberFromString( phone, countryCode || 'US' );
		const valid  = parsed ? parsed.isValid() : false;

		return {
			valid,
			possible: parsed ? isPossiblePhoneNumber( phone, countryCode || 'US' ) : false,
			country:  parsed ? ( parsed.country || null ) : null,
			type:     parsed ? ( parsed.getType() || null ) : null,
		};
	} catch ( e ) {
		return {
			valid:    false,
			possible: false,
			country:  null,
			type:     null,
			error:    e.message,
		};
	}
}

/**
 * Parse a phone number (best-effort, no country hint required).
 *
 * @param {string} phone - Phone number string (should include country code prefix).
 * @returns {Object} Parse result.
 */
function parsePhone( phone ) {
	try {
		const parsed = parsePhoneNumberFromString( phone );

		if ( ! parsed ) {
			return { number: phone, country: null, possible: false, valid: false };
		}

		return {
			number:   parsed.number,
			national: parsed.nationalNumber,
			country:  parsed.country || null,
			possible: isPossiblePhoneNumber( phone ),
			valid:    parsed.isValid(),
			type:     parsed.getType() || null,
		};
	} catch ( e ) {
		return { number: phone, country: null, possible: false, valid: false, error: e.message };
	}
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
			case 'format':
				if ( ! params.phone ) {
					throw new Error( 'phone is required' );
				}
				result = formatPhone( params.phone, params.country_code || 'US' );
				break;

			case 'validate':
				if ( ! params.phone ) {
					throw new Error( 'phone is required' );
				}
				result = validatePhone( params.phone, params.country_code || 'US' );
				break;

			case 'parse':
				if ( ! params.phone ) {
					throw new Error( 'phone is required' );
				}
				result = parsePhone( params.phone );
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

module.exports = { formatPhone, validatePhone, parsePhone };
