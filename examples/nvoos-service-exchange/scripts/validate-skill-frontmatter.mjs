#!/usr/bin/env node
/**
 * Validate SKILL.md frontmatter across the repo + global skill roots.
 *
 * Catches the failure mode that silently removes skills from Zed's catalog:
 * invalid YAML in frontmatter (e.g. an unquoted description containing
 * ": "), missing/unterminated delimiters, name/directory mismatches, and
 * non-string descriptions.
 *
 * Requires the `yaml` package (dev-only). Run from a directory whose
 * node_modules can resolve it (e.g. examples/nvoos-service-exchange).
 *
 * Usage: node scripts/validate-skill-frontmatter.mjs
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parse } from 'yaml';

const REPO_ROOT = fileURLToPath( new URL( '../../..', import.meta.url ) );

const ROOTS = [
	{ label: 'repo', dir: path.join( REPO_ROOT, '.agents/skills' ) },
	{ label: 'global', dir: 'C:/Users/rasta/.agents/skills' },
];

function extractFrontmatter( text ) {
	const lines = text.split( /\r?\n/ );
	if ( lines[ 0 ] !== '---' ) {
		return { error: 'missing opening --- delimiter' };
	}
	const close = lines.indexOf( '---', 1 );
	if ( close === -1 ) {
		return { error: 'missing closing --- delimiter' };
	}
	return { yaml: lines.slice( 1, close ).join( '\n' ), bodyLines: lines.length - close - 1 };
}

const problems = [];
const rows = [];
let totalDescBytes = 0;

for ( const root of ROOTS ) {
	if ( ! fs.existsSync( root.dir ) ) {
		continue;
	}
	for ( const dir of fs.readdirSync( root.dir ) ) {
		const file = path.join( root.dir, dir, 'SKILL.md' );
		if ( ! fs.existsSync( file ) ) {
			continue;
		}
		const rel = `${ root.label }:${ dir }`;
		const text = fs.readFileSync( file, 'utf8' );
		const extracted = extractFrontmatter( text );
		if ( extracted.error ) {
			problems.push( { rel, issue: extracted.error } );
			continue;
		}

		let data;
		try {
			data = parse( extracted.yaml );
		} catch ( error ) {
			const line = error.linePos?.[ 0 ]?.line ?? '?';
			const col = error.linePos?.[ 0 ]?.col ?? '?';
			problems.push( {
				rel,
				issue: `YAML parse error at ${ line }:${ col } — ${ error.message.split( '\n' )[ 0 ] }`,
			} );
			continue;
		}

		if ( ! data || typeof data !== 'object' ) {
			problems.push( { rel, issue: 'frontmatter did not parse to an object' } );
			continue;
		}
		if ( typeof data.name !== 'string' || data.name.trim() === '' ) {
			problems.push( { rel, issue: 'missing name' } );
		} else if ( data.name !== dir ) {
			problems.push( { rel, issue: `name "${ data.name }" does not match directory` } );
		}
		if ( typeof data.description !== 'string' || data.description.trim() === '' ) {
			problems.push( { rel, issue: 'missing or non-string description' } );
		} else {
			totalDescBytes += Buffer.byteLength( data.description, 'utf8' );
			rows.push( { rel, bytes: Buffer.byteLength( data.description, 'utf8' ) } );
		}
	}
}

rows.sort( ( a, b ) => b.bytes - a.bytes );

if ( problems.length ) {
	console.log( `Frontmatter problems (${ problems.length }):` );
	for ( const problem of problems ) {
		console.log( `  ✗ ${ problem.rel } — ${ problem.issue }` );
	}
} else {
	console.log( `Frontmatter OK for ${ rows.length } skills.` );
}

console.log( `Description payload: ${ totalDescBytes } bytes (cap ~50000)` );
console.log( `Largest: ${ rows
	.slice( 0, 5 )
	.map( ( row ) => `${ row.bytes } ${ row.rel }` )
	.join( ', ' ) }` );

process.exit( problems.length ? 1 : 0 );
