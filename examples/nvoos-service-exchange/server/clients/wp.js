/**
 * WordPress / WP-CLI provisioner primitives.
 *
 * Two build-safe layers:
 *  1. Pure command builders — every interpolated value passes a strict
 *     allowlist (defence in depth; these commands run on a shell).
 *  2. An executor over an injectable runner (WP Manager REST or SSH).
 *
 * Tier resolution: wp_manager → ssh → manual (paste-block). The manual tier
 * is a first-class product path (ADR-06): it renders the same commands with
 * verification hints and the known "every pasted line ends with ;" quirk.
 */

import { ApiError } from '../util/envelope.js';

const BUNDLE_SLUG = 'nvdigital-open-operator-system-oos-complete';
const SAFE_SYS_USER_RE = /^[a-z0-9]{6,32}$/;
const SAFE_GLOB_RE = /^[A-Za-z0-9._*-]+$/;
const SAFE_PATH_RE = /^[A-Za-z0-9._/-]+$/;
const SAFE_DOMAIN_RE = /^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i;

function assertSysUser( sysUser ) {
	if ( typeof sysUser !== 'string' || ! SAFE_SYS_USER_RE.test( sysUser ) ) {
		throw new ApiError( 'bad_request', 'Invalid Cloudways sys_user', { field: 'sysUser' }, 400 );
	}
	return sysUser;
}

function assertSafePath( value, field = 'path' ) {
	if ( typeof value !== 'string' || ! SAFE_PATH_RE.test( value ) || value.includes( '..' ) || value.startsWith( '/' ) ) {
		throw new ApiError( 'bad_request', `Unsafe ${ field }`, { field }, 400 );
	}
	return value;
}

function assertPositiveInt( value, field ) {
	const n = Number( value );
	if ( ! Number.isInteger( n ) || n < 1 ) {
		throw new ApiError( 'bad_request', `Invalid ${ field }`, { field }, 400 );
	}
	return n;
}

export function assertBundleZipGlob( glob ) {
	if ( typeof glob !== 'string' || ! SAFE_GLOB_RE.test( glob ) ) {
		throw new ApiError( 'bad_request', 'Unsafe zip glob', { field: 'zipGlob' }, 400 );
	}
	return glob;
}

/**
 * The production-proven install cycle: deactivate → delete → install →
 * activate (plus cache flush). Install resolves the newest uploaded zip
 * sitting one level above public_html.
 */
export function buildInstallCycle( { zipGlob = `${ BUNDLE_SLUG }-*.zip` } = {} ) {
	assertBundleZipGlob( zipGlob );
	return [
		`wp plugin deactivate ${ BUNDLE_SLUG }`,
		`wp plugin delete ${ BUNDLE_SLUG }`,
		`wp plugin install "$(ls -t ../${ zipGlob } 2>/dev/null | head -1)"`,
		`wp plugin activate ${ BUNDLE_SLUG }`,
		'wp cache flush',
	];
}

export function buildBundleImportCommand( { file } ) {
	return [ `wp mcp-ai assistant import --file="${ assertSafePath( file, 'bundle file' ) }"` ];
}

export function buildCredentialIssueCommand( { assistantId } ) {
	return [ `wp mcp-ai credential issue ${ assertPositiveInt( assistantId, 'assistantId' ) }` ];
}

export function buildHealthCommand() {
	return [ 'wp mcp-ai health' ];
}

/**
 * Which execution tier is available for this estate?
 */
export function resolveTier( { wpManager = false, sshKeyPresent = false } = {} ) {
	if ( wpManager ) {
		return 'wp_manager';
	}
	if ( sshKeyPresent ) {
		return 'ssh';
	}
	return 'manual';
}

/**
 * Render the manual-tier paste-block. Every line is terminated with `;`
 * because the owner's terminal merges pasted lines without them.
 */
export function renderPasteBlock( { sysUser, domain = null, commands } ) {
	assertSysUser( sysUser );
	if ( domain !== null && ( typeof domain !== 'string' || ! SAFE_DOMAIN_RE.test( domain ) ) ) {
		throw new ApiError( 'bad_request', 'Invalid domain', { field: 'domain' }, 400 );
	}
	if ( ! Array.isArray( commands ) || commands.length === 0 ) {
		throw new ApiError( 'bad_request', 'No commands to render', {}, 400 );
	}
	const header = [ `# Estate: ${ domain ?? sysUser }`, `cd ~/applications/${ sysUser }/public_html` ];
	const body = commands.map( ( command ) => {
		const trimmed = String( command ).trim().replace( /;+$/, '' );
		return `${ trimmed };`;
	} );
	return [ ...header, ...body ].join( '\n' );
}

/**
 * Executor over an injectable runner.
 *
 * Runner contract: `(command: string) => Promise<{ stdout: string, stderr?: string }>`.
 * The runner is responsible for the actual transport (WP Manager REST, SSH,
 * or a fake in tests). Commands run sequentially; the first failure aborts.
 */
export function createWpExecutor( { runner, tier = 'manual' } ) {
	if ( tier === 'manual' ) {
		throw new ApiError(
			'provisioning_failed',
			'Manual tier selected — render a paste-block instead of executing',
			{ tier },
			409
		);
	}
	if ( typeof runner !== 'function' ) {
		throw new Error( 'A runner function is required for automated tiers' );
	}

	async function run( commands ) {
		const results = [];
		for ( const command of commands ) {
			try {
				const result = await runner( command );
				results.push( { command, ok: true, stdout: result?.stdout ?? '' } );
			} catch ( error ) {
				throw new ApiError(
					'provisioning_failed',
					`WP-CLI command failed: ${ error.message }`,
					{ tier, command, remediation: 'Re-run the step; the install cycle is idempotent.' },
					502
				);
			}
		}
		return results;
	}

	return { run };
}

export const WP_BUNDLE_SLUG = BUNDLE_SLUG;
