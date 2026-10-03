#!/usr/bin/env node
/**
 * Dependency-free validation gate for the plugin package (Node built-ins
 * only — the mirror repo must never need `npm install`).
 *
 * Checks:
 *   1. All JSON files parse.
 *   2. Manifest identity matches between plugin.json and
 *      .codex-plugin/plugin.json (name + version lockstep).
 *   3. MCP configs carry valid HTTPS URLs or the YOUR-SITE.DOMAIN
 *      placeholder (a stamped production URL must never be committed).
 *   4. Manifest interface URLs point at the mirror repo, and no path
 *      references escape the plugin root.
 *   5. Every skill has frontmatter with `name` and `description`, and the
 *      skill directory matches the frontmatter name.
 *
 * Usage: node bin/validate.mjs
 * Exit code 0 = pass, 1 = fail. Used by .github/workflows/ci.yml.
 */
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { dirname, join, resolve, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const failures = [];
const ok = ( msg ) => console.log( 'OK   ' + msg );
const fail = ( msg ) => {
	failures.push( msg );
	console.error( 'FAIL ' + msg );
};

const readJson = ( rel ) => {
	const path = join( root, rel );
	try {
		return JSON.parse( readFileSync( path, 'utf8' ) );
	} catch ( error ) {
		fail( rel + ' — invalid JSON: ' + error.message );
		return null;
	}
};

// 1. JSON files parse.
const portable = readJson( 'plugin.json' );
const overlay = readJson( '.codex-plugin/plugin.json' );
const portableMcp = readJson( 'mcp.json' );
const legacyMcp = readJson( '.mcp.json' );
const marketplace = readJson( 'marketplace.json' );
[ 'plugin.json', '.codex-plugin/plugin.json', 'mcp.json', '.mcp.json', 'marketplace.json' ]
	.filter( ( f ) => existsSync( join( root, f ) ) )
	.forEach( () => {} );
ok( 'JSON files present and parsed' );

// 2. Identity lockstep between the two manifests.
if ( portable && overlay ) {
	for ( const field of [ 'name', 'version' ] ) {
		if ( portable[ field ] !== overlay[ field ] ) {
			fail(
				field + ' mismatch: plugin.json=' + portable[ field ] +
				' vs .codex-plugin/plugin.json=' + overlay[ field ]
			);
		}
	}
	if ( portable.name && !/^[a-z0-9-]+$/.test( portable.name ) ) {
		fail( 'plugin name must be kebab-case: ' + portable.name );
	}
	ok( 'Manifest identity (name + version) in lockstep' );
}

// 3. MCP URL integrity — placeholder or valid https, never a stamped URL
//    leaked into a commit.
for ( const [ label, doc ] of [ [ 'mcp.json', portableMcp ], [ '.mcp.json', legacyMcp ] ] ) {
	if ( !doc ) {
		continue;
	}
	for ( const [ key, server ] of Object.entries( doc.mcpServers || {} ) ) {
		const url = server && server.url ? server.url : '';
		if ( url.includes( 'YOUR-SITE.DOMAIN' ) ) {
			ok( label + ' — ' + key + ' uses placeholder (safe to commit)' );
			continue;
		}
		try {
			const parsed = new URL( url );
			if ( parsed.protocol !== 'https:' ) {
				fail( label + ' — ' + key + ' URL is not https: ' + url );
			} else {
				fail(
					label + ' — ' + key + ' carries a stamped site URL (' + url +
					'). Restore the YOUR-SITE.DOMAIN placeholder before committing.'
				);
			}
		} catch {
			fail( label + ' — ' + key + ' has an invalid URL: ' + url );
		}
	}
}
ok( 'MCP URL integrity checked' );

// 4. Metadata URLs point at the distribution mirror, not monorepo paths.
if ( portable ) {
	const urls = [
		portable.homepage,
		portable.repository,
		portable.extensions && portable.extensions[ 'com.openai' ] &&
			portable.extensions[ 'com.openai' ].interface &&
			portable.extensions[ 'com.openai' ].interface.websiteURL,
	].filter( Boolean );
	for ( const url of urls ) {
		if ( url.includes( '/mcp-ai-wpoos/tree/' ) || url.includes( '/mcp-ai-wpoos/blob/' ) ) {
			fail( 'manifest URL points into the monorepo tree; use the mirror repo: ' + url );
		}
	}
	ok( 'Manifest metadata URLs checked' );
}

// 5. Skills: frontmatter name/description, folder name matches.
const skillsDir = join( root, 'skills' );
if ( existsSync( skillsDir ) ) {
	for ( const folder of readdirSync( skillsDir ) ) {
		const path = join( skillsDir, folder, 'SKILL.md' );
		if ( !existsSync( path ) ) {
			fail( 'skills/' + folder + '/SKILL.md missing' );
			continue;
		}
		const raw = readFileSync( path, 'utf8' );
		const match = /^---\n([\s\S]*?)\n---/.exec( raw );
		if ( !match ) {
			fail( 'skills/' + folder + '/SKILL.md has no YAML frontmatter' );
			continue;
		}
		const nameMatch = /^name:\s*(.+)$/m.exec( match[ 1 ] );
		const descMatch = /^description:\s*(.+)$/m.exec( match[ 1 ] );
		if ( !nameMatch || !nameMatch[ 1 ].trim() ) {
			fail( 'skills/' + folder + '/SKILL.md frontmatter missing name' );
		} else if ( nameMatch[ 1 ].trim() !== folder ) {
			fail(
				'skills/' + folder + '/SKILL.md name "' + nameMatch[ 1 ].trim() +
				'" does not match folder "' + folder + '"'
			);
		}
		if ( !descMatch || !descMatch[ 1 ].trim() ) {
			fail( 'skills/' + folder + '/SKILL.md frontmatter missing description' );
		}
	}
	ok( 'Skill frontmatter validated (' + readdirSync( skillsDir ).length + ' skills)' );
}

// 6. Marketplace entry points at the plugin root and stays inside it.
if ( marketplace ) {
	for ( const entry of marketplace.plugins || [] ) {
		const path = ( entry.source && entry.source.path ) || '';
		if ( !path.startsWith( './' ) || path.includes( '..' ) ) {
			fail( 'marketplace entry "' + entry.name + '" path must start with ./ and stay inside: ' + path );
		}
		if ( entry.name !== portable.name ) {
			fail( 'marketplace entry name "' + entry.name + '" must match plugin name "' + portable.name + '"' );
		}
	}
	ok( 'Marketplace entry checked' );
}

if ( failures.length ) {
	console.error( '\n' + failures.length + ' validation failure(s).' );
	process.exit( 1 );
}
console.log( '\nAll checks passed.' );
