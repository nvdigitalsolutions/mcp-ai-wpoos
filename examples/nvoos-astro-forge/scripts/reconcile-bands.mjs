// SPDX-License-Identifier: MIT
// Section-by-section reconciliation: source band facts vs the built band.
// Read-only. Prints one row per band per page so the gaps are a work list.
//
//   node scripts/reconcile-bands.mjs sites/eca.json ./eca-catalogue-frontend
//
// The page list is the profile's own `pageBands` (the pages `analyze --pages`
// measured) plus the front page; a page the profile never measured is reported
// as such rather than scored against the homepage's facts.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const profilePath = process.argv[ 2 ] ?? 'sites/eca.json';
const targetDir = process.argv[ 3 ] ?? './eca-catalogue-frontend';
const profile = JSON.parse( readFileSync( resolve( profilePath ), 'utf8' ) );
const dist = resolve( targetDir, 'dist' );

const pages = [
	[ 'home', 'index.html', null ],
	...Object.keys( profile.pageBands ?? {} ).map( ( key ) => [ key, `${ key }/index.html`, key ] ),
];

/** Every `<div data-section=…>` wrapper with its `data-section-id`. */
function builtBands( html ) {
	const out = [];
	const re = /<div\s+data-section="([^"]*)"\s+data-section-id="([^"]*)"/g;
	let match;
	while ( ( match = re.exec( html ) ) ) {
		out.push( { kind: match[ 1 ], id: match[ 2 ], at: match.index } );
	}
	return out.map( ( band, index ) => {
		const end = out[ index + 1 ]?.at ?? html.length;
		const slice = html.slice( band.at, end );
		const body = slice.replace( /<style[\s\S]*?<\/style>/g, ' ' );
		return {
			...band,
			height: null,
			text: body.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim().length,
			raw: /class="[^"]*post-body/.test( body ),
			img: ( body.match( /<img\b/g ) ?? [] ).length,
			bgImage: ( body.match( /background-image:/g ) ?? [] ).length,
		};
	} );
}

for ( const [ label, file, pageKey ] of pages ) {
	let html;
	try {
		html = readFileSync( resolve( dist, file ), 'utf8' );
	} catch {
		console.log( `\n## ${ label } — NOT BUILT` );
		continue;
	}
	const bands = builtBands( html );
	const source = pageKey
		? { sections: profile.pageBands?.[ pageKey ]?.sections ?? [], facts: profile.pageBands?.[ pageKey ]?.bandFacts ?? {}, geometry: profile.pageBands?.[ pageKey ]?.geometry ?? {} }
		: { sections: profile.design?.sections ?? [], facts: profile.bandFacts ?? {}, geometry: profile.geometry ?? {} };

	console.log( `\n## ${ label } — source ${ source.sections.length } band(s), built ${ bands.length }` );
	source.sections.forEach( ( section, index ) => {
		const fact = source.facts[ section.id ] ?? {};
		const geo = source.geometry[ section.id ]?.desktop;
		const elements = fact.elements ?? [];
		const images = elements.filter( ( element ) => /background-image/.test( element.bg ?? '' ) );
		const built = bands.find( ( band ) => band.id === section.id );
		const builtIndex = bands.findIndex( ( band ) => band.id === section.id );
		console.log(
			[
				`#${ index + 1 } ${ section.id }`,
				`src ${ section.columns?.length ?? 0 }col`,
				`els ${ elements.length }(${ images.length }img)`,
				geo ? `src ${ geo.width }x${ geo.height }` : 'src ?',
				built ? `→ built[${ builtIndex + 1 }] ${ built.kind }` : '→ built MISSING',
				built ? `${ built.raw ? 'raw' : 'component'}` : '',
				built ? `img ${ built.img } bg ${ built.bgImage } text ${ built.text }` : '',
			].join( ' · ' )
		);
	} );
	const ids = new Set( source.sections.map( ( section ) => section.id ) );
	for ( const band of bands ) {
		if ( ! ids.has( band.id ) ) {
			console.log( `   extra built band ${ band.id } (${ band.kind }) — no source id` );
		}
	}
}
