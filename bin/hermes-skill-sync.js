#!/usr/bin/env node
/**
 * Skill-sync engine for the Hermes WebUI bridge.
 *
 * Pure logic (no HTTP): reads the repo's .agents/skills/ tree and diffs it
 * against the remote Hermes agent's skills through the WebUI skills API.
 * The caller injects an `authedRequest` function (see hermes-mcp-server.js)
 * so the same engine backs the MCP tool, the startup auto-sync, and the
 * standalone CLI (bin/sync-skills-to-hermes.js).
 *
 * WebUI API used (see api/routes.py in nesquena/hermes-webui):
 *   GET  /api/skills                    → { skills: [{ name, ... }] }
 *   GET  /api/skills/content?name=X     → { success, content, ... }
 *   POST /api/skills/save   {name, content, category?} → { ok, name, path }
 *   POST /api/skills/delete {name}      → { ok }
 *
 * The save endpoint writes SKILL.md only — extra files inside a skill
 * directory (scripts, references, templates) cannot be uploaded and are
 * reported in `skipped_files`.
 *
 * @package WP_MCP_AI
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

/**
 * Default repo skills directory (resolved from this file's location so it
 * works regardless of the CWD Zed spawns us with).
 */
const DEFAULT_SKILLS_DIR = path.join( __dirname, '..', '.agents', 'skills' );

/**
 * Normalize line endings so a CRLF checkout on Windows never looks different
 * from the LF content the Linux box stores — otherwise every sync would
 * re-upload unchanged skills.
 *
 * @param {string} content  Raw SKILL.md content.
 * @returns {string} Content with CRLF normalized to LF.
 */
function normalizeContent( content ) {
	return String( content ).replace( /\r\n/g, '\n' );
}

/**
 * Read all local skills from a skills directory (one folder per skill, each
 * with a SKILL.md, agentskills.io layout — the same format Hermes uses).
 *
 * @param {string} [skillsDir]  Directory to scan (default: repo .agents/skills).
 * @returns {Array<{name:string, dir:string, content:string, extraFiles:string[]}>}
 */
function readLocalSkills( skillsDir ) {
	const root = skillsDir || DEFAULT_SKILLS_DIR;
	if ( ! fs.existsSync( root ) || ! fs.statSync( root ).isDirectory() ) {
		throw new Error( `Skills directory not found: ${ root }` );
	}

	const out = [];
	for ( const entry of fs.readdirSync( root, { withFileTypes: true } ) ) {
		if ( ! entry.isDirectory() ) {
			continue;
		}
		const dir = path.join( root, entry.name );
		const skillMd = path.join( dir, 'SKILL.md' );
		if ( ! fs.existsSync( skillMd ) ) {
			continue;
		}
		const extraFiles = fs.readdirSync( dir )
			.filter( ( f ) => 'SKILL.md' !== f && fs.statSync( path.join( dir, f ) ).isFile() )
			.sort();
		out.push( {
			name: entry.name,
			dir,
			content: fs.readFileSync( skillMd, 'utf8' ),
			extraFiles,
		} );
	}
	out.sort( ( a, b ) => a.name.localeCompare( b.name ) );
	return out;
}

/**
 * Sync local skills to the Hermes agent through the WebUI API.
 *
 * Upserts changed/new SKILL.md files and (optionally) removes remote skills
 * that no longer exist locally. Idempotent — unchanged skills are skipped.
 *
 * @param {object}   opts                       Options.
 * @param {Function} opts.authedRequest         `authedRequest(method, route, body, timeoutMs)` — handles login/cookies.
 * @param {string}   [opts.skillsDir]           Local skills directory.
 * @param {string[]} [opts.names]               Only sync these skill names.
 * @param {boolean}  [opts.removeMissing]       Delete remote skills absent locally. DANGER: the remote
 *                                              agent may have self-authored skills — review before enabling.
 * @param {Function} [opts.log]                 Diagnostic sink (stderr-safe).
 * @returns {Promise<object>} Summary: {local_count, remote_count, synced[], unchanged[], removed[], failed[], skipped_files[]}.
 */
async function syncSkillsToWebui( opts ) {
	const { authedRequest, skillsDir, names = null, removeMissing = false } = opts;
	const log = opts.log || ( () => {} );

	const local = readLocalSkills( skillsDir ).filter(
		( s ) => ! Array.isArray( names ) || 0 === names.length || names.includes( s.name )
	);
	const summary = {
		local_count: local.length,
		remote_count: 0,
		synced: [],
		unchanged: [],
		removed: [],
		failed: [],
		skipped_files: [],
	};

	const listRes = await authedRequest( 'GET', '/api/skills', null, 30000 );
	if ( 200 !== listRes.statusCode || ! listRes.data ) {
		throw new Error( `GET /api/skills failed (HTTP ${ listRes.statusCode }): ${ JSON.stringify( listRes.data ) }` );
	}
	const remoteSkills = Array.isArray( listRes.data.skills ) ? listRes.data.skills : [];
	const remoteNames = new Set( remoteSkills.map( ( s ) => s.name ) );
	summary.remote_count = remoteNames.size;

	for ( const skill of local ) {
		const localContent = normalizeContent( skill.content );
		let remoteContent = null;

		if ( remoteNames.has( skill.name ) ) {
			const view = await authedRequest(
				'GET',
				`/api/skills/content?name=${ encodeURIComponent( skill.name ) }`,
				null,
				30000
			);
			if ( 200 === view.statusCode && view.data && true === view.data.success && 'string' === typeof view.data.content ) {
				remoteContent = normalizeContent( view.data.content );
			}
		}

		if ( null !== remoteContent && remoteContent === localContent ) {
			summary.unchanged.push( skill.name );
			continue;
		}

		const save = await authedRequest(
			'POST',
			'/api/skills/save',
			{ name: skill.name, content: localContent },
			30000
		);
		if ( 200 === save.statusCode && save.data && true === save.data.ok ) {
			summary.synced.push( skill.name );
			log( `synced skill ${ skill.name }${ null === remoteContent ? ' (new)' : ' (updated)' }` );
		} else {
			summary.failed.push( `${ skill.name } (HTTP ${ save.statusCode }: ${ JSON.stringify( save.data ) })` );
		}

		if ( skill.extraFiles.length ) {
			summary.skipped_files.push(
				`${ skill.name }: ${ skill.extraFiles.join( ', ' ) } — WebUI API writes SKILL.md only`
			);
		}
	}

	if ( removeMissing ) {
		const allLocalNames = new Set( readLocalSkills( skillsDir ).map( ( s ) => s.name ) );
		for ( const remoteName of remoteNames ) {
			if ( allLocalNames.has( remoteName ) ) {
				continue;
			}
			// With a names filter, only prune remote skills that were in scope.
			if ( Array.isArray( names ) && names.length && ! names.includes( remoteName ) ) {
				continue;
			}
			const del = await authedRequest( 'POST', '/api/skills/delete', { name: remoteName }, 30000 );
			if ( 200 === del.statusCode && del.data && true === del.data.ok ) {
				summary.removed.push( remoteName );
				log( `removed remote skill ${ remoteName }` );
			} else {
				summary.failed.push( `delete ${ remoteName } (HTTP ${ del.statusCode }: ${ JSON.stringify( del.data ) })` );
			}
		}
	}

	return summary;
}

module.exports = { DEFAULT_SKILLS_DIR, normalizeContent, readLocalSkills, syncSkillsToWebui };
