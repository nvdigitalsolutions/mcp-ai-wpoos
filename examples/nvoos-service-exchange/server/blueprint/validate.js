/**
 * Service Blueprint v1 validator.
 *
 * The Exchange validator owns *manifest* semantics. Assistant-bundle
 * semantics stay with the portability engine (`format: "nvoos-assistant"`,
 * `format_version: 1`, `assistants[].title`) — this module checks the
 * bundle envelope only and delegates deep validation to the engine at
 * import time (never re-implements it).
 *
 * Rules (see docs/service-blueprint-v1.md):
 *  - version pinned to 1; unknown keys rejected everywhere (`_meta` is the
 *    only escape hatch);
 *  - globally unique slug;
 *  - no secret-shaped keys or credential-shaped values outside `_meta`;
 *  - `gateway.visibility: "public"` requires a tool allowlist;
 *  - enabling a storefront requires naming its template.
 */

const BLUEPRINT_VERSION = 1;

const INDUSTRIES = new Set( [
	'healthcare',
	'food_beverage',
	'professional_services',
	'ecommerce',
	'retail',
	'education',
	'real_estate',
	'hospitality',
	'other',
] );

const SLUG_RE = /^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/;
const TOOLKIT_RE = /^[a-z0-9_]{2,40}$/;
const TOOL_NAME_RE = /^[a-z0-9_]{2,64}$/i;
const HOSTNAME_RE = /^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i;
const PRICE_RE = /^price_[A-Za-z0-9]{8,}$/;
const CREDENTIAL_VALUE_RE = /(?:op_|cred_)[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}/;

const BANNED_KEYS = new Set( [
	'token',
	'secret',
	'password',
	'api_key',
	'apikey',
	'client_secret',
	'access_token',
	'refresh_token',
	'oauth_data',
	'authorization',
	'credentials',
	'_wp_mcp_ai_credentials',
] );

const ALLOWED_TOP = [
	'blueprint_version',
	'name',
	'slug',
	'tagline',
	'industry',
	'icon',
	'bundle',
	'toolkits',
	'mcp_apps',
	'site',
	'console',
	'storefront',
	'gateway',
	'commerce',
	'listing',
	'_meta',
];

function isPlainObject( value ) {
	return (
		value !== null &&
		typeof value === 'object' &&
		! Array.isArray( value ) &&
		Object.getPrototypeOf( value ) === Object.prototype
	);
}

function pushError( errors, path, message ) {
	errors.push( { path, message } );
}

function checkAllowedKeys( value, allowed, path, errors ) {
	for ( const key of Object.keys( value ) ) {
		if ( ! allowed.includes( key ) ) {
			pushError( errors, path ? `${ path }.${ key }` : key, `Unknown field "${ key }"` );
		}
	}
}

function requireString( value, path, { min = 1, max = 500 } = {}, errors ) {
	if ( typeof value !== 'string' ) {
		pushError( errors, path, 'Expected a string' );
		return false;
	}
	const length = value.length;
	if ( length < min || length > max ) {
		pushError( errors, path, `Expected ${ min }–${ max } characters, got ${ length }` );
		return false;
	}
	return true;
}

function optionalBool( value, fallback, path, errors ) {
	if ( value === undefined ) {
		return fallback;
	}
	if ( typeof value !== 'boolean' ) {
		pushError( errors, path, 'Expected a boolean' );
		return fallback;
	}
	return value;
}

function optionalInt( value, fallback, path, { min = 0, max = 1_000_000 } = {}, errors ) {
	if ( value === undefined ) {
		return fallback;
	}
	if ( ! Number.isInteger( value ) || value < min || value > max ) {
		pushError( errors, path, `Expected an integer between ${ min } and ${ max }` );
		return fallback;
	}
	return value;
}

/**
 * Walk every object except `_meta` looking for secret-shaped keys and
 * credential-shaped string values.
 */
function scanSecrets( value, path, errors ) {
	if ( Array.isArray( value ) ) {
		value.forEach( ( item, index ) => scanSecrets( item, `${ path }[${ index }]`, errors ) );
		return;
	}
	if ( ! isPlainObject( value ) ) {
		if ( typeof value === 'string' && CREDENTIAL_VALUE_RE.test( value ) ) {
			pushError( errors, path, 'Value looks like a live credential; secrets must never travel in blueprints' );
		}
		return;
	}
	for ( const [ key, child ] of Object.entries( value ) ) {
		const childPath = path ? `${ path }.${ key }` : key;
		if ( key === '_meta' ) {
			continue; // free-form escape hatch, still not a secret store by policy
		}
		if ( BANNED_KEYS.has( String( key ).toLowerCase() ) ) {
			pushError( errors, childPath, `Secret-shaped field "${ key }" is not allowed outside _meta` );
		}
		scanSecrets( child, childPath, errors );
	}
}

function validateBundle( bundle, errors ) {
	if ( ! isPlainObject( bundle ) ) {
		pushError( errors, 'bundle', 'Expected the assistant-portability bundle object' );
		return;
	}
	if ( bundle.format !== 'nvoos-assistant' ) {
		pushError( errors, 'bundle.format', 'Expected "nvoos-assistant"' );
	}
	if ( bundle.format_version !== 1 ) {
		pushError( errors, 'bundle.format_version', 'Only bundle format_version 1 is supported' );
	}
	if ( ! Array.isArray( bundle.assistants ) || bundle.assistants.length < 1 || bundle.assistants.length > 500 ) {
		pushError( errors, 'bundle.assistants', 'Expected 1–500 assistants' );
		return;
	}
	bundle.assistants.forEach( ( assistant, index ) => {
		const path = `bundle.assistants[${ index }]`;
		if ( ! isPlainObject( assistant ) ) {
			pushError( errors, path, 'Expected an object' );
			return;
		}
		if ( ! requireString( assistant.title, `${ path }.title`, { min: 1, max: 200 }, errors ) ) {
			return;
		}
		if ( assistant.slug !== undefined && typeof assistant.slug !== 'string' ) {
			pushError( errors, `${ path }.slug`, 'Expected a string' );
		}
		if ( assistant.meta !== undefined && ! isPlainObject( assistant.meta ) ) {
			pushError( errors, `${ path }.meta`, 'Expected an object' );
		}
	} );
}

function validateSite( site, errors ) {
	const path = 'site';
	if ( ! isPlainObject( site ) ) {
		pushError( errors, path, 'Expected an object' );
		return null;
	}
	checkAllowedKeys( site, [ 'region', 'size_hint', 'php', 'domain', 'wp_manager' ], path, errors );
	requireString( site.region, `${ path }.region`, { min: 2, max: 40 }, errors );

	const sizeHint = site.size_hint ?? 'small';
	if ( ! [ 'small', 'medium', 'large' ].includes( sizeHint ) ) {
		pushError( errors, `${ path }.size_hint`, 'Expected small | medium | large' );
	}
	const php = site.php ?? '8.1';
	if ( typeof php !== 'string' || ! /^8\.\d$/.test( php ) ) {
		pushError( errors, `${ path }.php`, 'Expected a PHP 8.x version string' );
	}
	if ( site.domain !== null && site.domain !== undefined ) {
		if ( typeof site.domain !== 'string' || ! HOSTNAME_RE.test( site.domain ) ) {
			pushError( errors, `${ path }.domain`, 'Expected null or a valid hostname' );
		}
	}
	const wpManager = optionalBool( site.wp_manager, true, `${ path }.wp_manager`, errors );

	return {
		region: site.region,
		size_hint: sizeHint,
		php,
		domain: site.domain ?? null,
		wp_manager: wpManager,
	};
}

function validateMcpApps( mcpApps, errors ) {
	if ( mcpApps === undefined ) {
		return [];
	}
	if ( ! Array.isArray( mcpApps ) ) {
		pushError( errors, 'mcp_apps', 'Expected an array of connection references' );
		return [];
	}
	const normalised = [];
	mcpApps.forEach( ( entry, index ) => {
		const path = `mcp_apps[${ index }]`;
		if ( ! isPlainObject( entry ) ) {
			pushError( errors, path, 'Expected an object' );
			return;
		}
		checkAllowedKeys( entry, [ 'connection_ref', 'label', 'position' ], path, errors );
		if ( ! requireString( entry.connection_ref, `${ path }.connection_ref`, { min: 3, max: 200 }, errors ) ) {
			return;
		}
		normalised.push( {
			connection_ref: entry.connection_ref,
			...( entry.label !== undefined ? { label: String( entry.label ).slice( 0, 120 ) } : {} ),
			...( entry.position !== undefined ? { position: optionalInt( entry.position, 0, `${ path }.position`, { min: 0, max: 50 }, errors ) } : {} ),
		} );
	} );
	return normalised;
}

function validateGateway( gateway, errors ) {
	const path = 'gateway';
	if ( ! isPlainObject( gateway ) ) {
		pushError( errors, path, 'Expected an object' );
		return null;
	}
	checkAllowedKeys( gateway, [ 'visibility', 'tool_allowlist', 'rate_limit_profile' ], path, errors );

	const visibility = gateway.visibility ?? 'private';
	if ( ! [ 'public', 'private' ].includes( visibility ) ) {
		pushError( errors, `${ path }.visibility`, 'Expected public | private' );
	}

	let allowlist = [];
	if ( gateway.tool_allowlist !== undefined ) {
		if ( ! Array.isArray( gateway.tool_allowlist ) ) {
			pushError( errors, `${ path }.tool_allowlist`, 'Expected an array of tool names' );
		} else {
			allowlist = gateway.tool_allowlist.filter( ( tool, index ) => {
				if ( typeof tool !== 'string' || ! TOOL_NAME_RE.test( tool ) ) {
					pushError( errors, `${ path }.tool_allowlist[${ index }]`, 'Expected a tool-name slug' );
					return false;
				}
				return true;
			} );
		}
	}

	if ( visibility === 'public' && allowlist.length === 0 ) {
		pushError( errors, `${ path }.tool_allowlist`, 'A public estate requires a non-empty tool allowlist' );
	}

	const profile = gateway.rate_limit_profile ?? 'standard';
	if ( ! [ 'standard', 'elevated' ].includes( profile ) ) {
		pushError( errors, `${ path }.rate_limit_profile`, 'Expected standard | elevated' );
	}

	return { visibility, tool_allowlist: allowlist, rate_limit_profile: profile };
}

function validateListing( listing, errors ) {
	const path = 'listing';
	if ( ! isPlainObject( listing ) ) {
		pushError( errors, path, 'Expected an object' );
		return null;
	}
	checkAllowedKeys( listing, [ 'summary', 'use_cases', 'pricing_note', 'connect_snippets' ], path, errors );
	requireString( listing.summary, `${ path }.summary`, { min: 10, max: 500 }, errors );

	let useCases = [];
	if ( listing.use_cases !== undefined ) {
		if ( ! Array.isArray( listing.use_cases ) || listing.use_cases.length > 12 ) {
			pushError( errors, `${ path }.use_cases`, 'Expected an array of at most 12 strings' );
		} else {
			useCases = listing.use_cases.filter( ( item, index ) => {
				if ( typeof item !== 'string' || item.length < 3 || item.length > 160 ) {
					pushError( errors, `${ path }.use_cases[${ index }]`, 'Expected 3–160 characters' );
					return false;
				}
				return true;
			} );
		}
	}

	const pricingNote = listing.pricing_note ?? '';
	if ( typeof pricingNote !== 'string' || pricingNote.length > 300 ) {
		pushError( errors, `${ path }.pricing_note`, 'Expected a string of at most 300 characters' );
	}

	return {
		summary: listing.summary,
		use_cases: useCases,
		pricing_note: pricingNote,
		connect_snippets: optionalBool( listing.connect_snippets, true, `${ path }.connect_snippets`, errors ),
	};
}

/**
 * Validate and normalise a blueprint.
 *
 * @param {unknown} input
 * @param {{existingSlugs?: string[]}} [options]
 * @return {{ok:true, blueprint:object}|{ok:false, errors:Array<{path:string,message:string}>}}
 */
export function validateBlueprint( input, options = {} ) {
	const errors = [];
	if ( ! isPlainObject( input ) ) {
		return { ok: false, errors: [ { path: '', message: 'Blueprint must be an object' } ] };
	}

	checkAllowedKeys( input, ALLOWED_TOP, '', errors );

	if ( input.blueprint_version !== BLUEPRINT_VERSION ) {
		pushError( errors, 'blueprint_version', 'Only blueprint_version 1 is supported' );
	}

	requireString( input.name, 'name', { min: 3, max: 80 }, errors );
	if ( ! requireString( input.slug, 'slug', { min: 3, max: 40 }, errors ) ) {
		// slug unset/invalid — no uniqueness check possible.
	} else if ( ! SLUG_RE.test( input.slug ) ) {
		pushError( errors, 'slug', 'Expected lowercase letters, digits and hyphens (3–40 chars, no leading/trailing hyphen)' );
	} else if ( ( options.existingSlugs ?? [] ).includes( input.slug ) ) {
		pushError( errors, 'slug', `Slug "${ input.slug }" is already used by an estate or blueprint` );
	}

	requireString( input.tagline, 'tagline', { min: 5, max: 140 }, errors );
	if ( ! INDUSTRIES.has( input.industry ) ) {
		pushError( errors, 'industry', `Expected one of: ${ [ ...INDUSTRIES ].join( ', ' ) }` );
	}
	if ( input.icon !== undefined ) {
		requireString( input.icon, 'icon', { min: 1, max: 200 }, errors );
	}

	validateBundle( input.bundle, errors );

	let toolkits = [];
	if ( input.toolkits !== undefined ) {
		if ( ! Array.isArray( input.toolkits ) || input.toolkits.length > 50 ) {
			pushError( errors, 'toolkits', 'Expected an array of at most 50 toolkit slugs' );
		} else {
			toolkits = input.toolkits.filter( ( toolkit, index ) => {
				if ( typeof toolkit !== 'string' || ! TOOLKIT_RE.test( toolkit ) ) {
					pushError( errors, `toolkits[${ index }]`, 'Expected a toolkit slug' );
					return false;
				}
				return true;
			} );
		}
	}

	const mcpApps = validateMcpApps( input.mcp_apps, errors );
	const site = validateSite( input.site, errors );
	const gateway = validateGateway( input.gateway, errors );
	const listing = validateListing( input.listing, errors );

	const consoleBlock = input.console ?? { enable: true, template: 'complete-velocity' };
	if ( ! isPlainObject( consoleBlock ) ) {
		pushError( errors, 'console', 'Expected an object' );
	} else {
		checkAllowedKeys( consoleBlock, [ 'enable', 'template' ], 'console', errors );
	}
	const consoleEnabled = isPlainObject( consoleBlock )
		? optionalBool( consoleBlock.enable, true, 'console.enable', errors )
		: true;
	const consoleTemplate = isPlainObject( consoleBlock ) ? consoleBlock.template ?? 'complete-velocity' : 'complete-velocity';
	if ( consoleEnabled && consoleTemplate !== 'complete-velocity' ) {
		pushError( errors, 'console.template', 'Enabled console must use template "complete-velocity"' );
	}

	const storefrontBlock = input.storefront ?? { enable: false, template: null };
	if ( ! isPlainObject( storefrontBlock ) ) {
		pushError( errors, 'storefront', 'Expected an object' );
	}
	const storefrontEnabled = isPlainObject( storefrontBlock )
		? optionalBool( storefrontBlock.enable, false, 'storefront.enable', errors )
		: false;
	const storefrontTemplate = isPlainObject( storefrontBlock ) ? storefrontBlock.template ?? null : null;
	if ( storefrontEnabled && ! [ 'astro-storefront', 'forge' ].includes( storefrontTemplate ) ) {
		pushError( errors, 'storefront.template', 'Enabled storefront requires "astro-storefront" or "forge"' );
	}

	const commerceBlock = input.commerce ?? {};
	if ( ! isPlainObject( commerceBlock ) ) {
		pushError( errors, 'commerce', 'Expected an object' );
	}
	const commerce = {
		stripe_price_id: null,
		trial_days: 14,
		metered: false,
	};
	if ( isPlainObject( commerceBlock ) ) {
		checkAllowedKeys( commerceBlock, [ 'stripe_price_id', 'trial_days', 'metered' ], 'commerce', errors );
		if ( commerceBlock.stripe_price_id !== null && commerceBlock.stripe_price_id !== undefined ) {
			if ( typeof commerceBlock.stripe_price_id !== 'string' || ! PRICE_RE.test( commerceBlock.stripe_price_id ) ) {
				pushError( errors, 'commerce.stripe_price_id', 'Expected null or a Stripe price id (price_…)' );
			} else {
				commerce.stripe_price_id = commerceBlock.stripe_price_id;
			}
		}
		commerce.trial_days = optionalInt( commerceBlock.trial_days, 14, 'commerce.trial_days', { min: 0, max: 90 }, errors );
		commerce.metered = optionalBool( commerceBlock.metered, false, 'commerce.metered', errors );
	}

	scanSecrets( input, '', errors );

	if ( errors.length ) {
		return { ok: false, errors };
	}

	const blueprint = {
		blueprint_version: BLUEPRINT_VERSION,
		name: input.name,
		slug: input.slug,
		tagline: input.tagline,
		industry: input.industry,
		...( input.icon !== undefined ? { icon: input.icon } : {} ),
		bundle: input.bundle,
		toolkits,
		mcp_apps: mcpApps,
		site,
		console: { enable: consoleEnabled, template: consoleTemplate },
		storefront: { enable: storefrontEnabled, template: storefrontTemplate },
		gateway,
		commerce,
		listing,
		...( input._meta !== undefined ? { _meta: input._meta } : {} ),
	};

	return { ok: true, blueprint };
}

export const BLUEPRINT_SCHEMA_VERSION = BLUEPRINT_VERSION;
export { INDUSTRIES };
