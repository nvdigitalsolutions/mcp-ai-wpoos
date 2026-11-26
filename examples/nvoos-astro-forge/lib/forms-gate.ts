// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// forms-gate.ts — the enquiry contract in the BUILT page (`verify --forms`).
//
// The design gates score a page that can look perfect while its enquiry path is
// dead. Three shapes have shipped at least once:
//
//   1. a `form` band that renders a heading and NO form at all (the pre-bridge
//      ECA site: both enquiry bands degraded to contact cards);
//   2. a form whose fields were dropped in classification, so it renders a
//      submit button and nothing to fill in (Elementor renders inputs
//      client-side — the extractor saw a container, not its fields);
//   3. a form posting to the wrong place: a `#` action, the CMS host, or a
//      stale origin after cutover.
//
// None of those is visible to the band, fact or text gates, and `13/14 gates
// passed` while the ECA front end had no enquiry form anywhere. This gate reads
// the OUTPUT and judges the contract a browser (or a no-JS visitor) would use.
//
// Deliberately narrowed to what is decidable from the built HTML: a live
// dispatch probe is `forge forms check --send`, a different question (does the
// bridge's handler accept it) and a different failure surface.
// ---------------------------------------------------------------------------

import { DomHandler, Parser } from 'htmlparser2';

/** The structural subset of htmlparser2's node model this gate reads. */
interface FormNode {
	type: string;
	name?: string;
	attribs?: Record< string, string >;
	children?: FormNode[];
}

/**
 * Reserved carriers the forge's own form script owns. A form made of ONLY
 * these has nothing for a visitor to fill in — the dead-control case.
 */
export const RESERVED_FORM_FIELDS = new Set( [
	'form',
	'post_id',
	'honeypot',
	'timestamp',
	'_redirect',
	'_wpnonce',
] );

/** One `<form>` the build emitted. */
export interface BuiltForm {
	/** `action` exactly as emitted ('' when the attribute is absent). */
	action: string;
	method: string;
	/** Fillable field names (`input`/`textarea`/`select`), reserved carriers removed. */
	fields: string[];
	/** Hidden carrier values by name — `form`, `post_id`, `honeypot`, … */
	carriers: Record< string, string >;
	/** True when the component marks the form for its own JSON interception. */
	enhanced: boolean;
}

/** One band (or bare component) that should collect an enquiry. */
export interface FormBandAudit {
	/** The built file the band was read from. */
	file: string;
	/** `data-section-id` when the band is a design section, else the marker. */
	sectionId: string;
	/** What identified this surface: `band` or `block`. */
	source: 'band' | 'block';
	forms: BuiltForm[];
	/** Human-readable defects; empty means this surface is fine. */
	problems: string[];
}

export interface FormGateReport {
	status: 'pass' | 'fail' | 'na';
	note: string;
	bands: FormBandAudit[];
	/** Every problem, in document order. */
	problems: string[];
}

export interface FormGateOptions {
	/**
	 * The endpoint the profile declares (`manifest.forms.endpoint`). When set,
	 * every submit form must post at or below it, and an Elementor form must
	 * carry a non-zero `post_id` carrier (the handler resolves the widget
	 * inside that document — see the bridge's 0.3.4 typed 400).
	 * When absent, a band with no form is the documented degrade-to-contacts
	 * behaviour and is reported as such instead of failing.
	 */
	endpoint?: string;
}

function parse( html: string ): FormNode[] {
	const handler = new DomHandler( ( error ) => {
		if ( error ) {
			throw error;
		}
	} );
	const parser = new Parser( handler, { decodeEntities: true } );
	parser.write( html );
	parser.end();
	return ( handler.dom ?? [] ) as FormNode[];
}

function walk( nodes: FormNode[], visit: ( node: FormNode ) => void ): void {
	for ( const node of nodes ) {
		visit( node );
		if ( node.children?.length ) {
			walk( node.children, visit );
		}
	}
}

/** Every `<form>` under a node, in document order. */
function formsIn( node: FormNode ): BuiltForm[] {
	const found: BuiltForm[] = [];
	walk( [ node ], ( child ) => {
		if ( child.name !== 'form' ) {
			return;
		}
		const attribs = child.attribs ?? {};
		const fields: string[] = [];
		const carriers: Record< string, string > = {};
		let enhanced = false;
		walk( child.children ?? [], ( field ) => {
			if ( field.name !== 'input' && field.name !== 'textarea' && field.name !== 'select' ) {
				return;
			}
			const attrs = field.attribs ?? {};
			const name = ( attrs.name ?? '' ).trim();
			if ( 'data-ds-form' in attrs || 'data-ds-contact' in attrs ) {
				enhanced = true;
			}
			if ( name === '' ) {
				return;
			}
			if ( attrs.type === 'hidden' || RESERVED_FORM_FIELDS.has( name ) ) {
				carriers[ name ] = ( attrs.value ?? '' ).trim();
				return;
			}
			fields.push( name );
		} );
		if ( 'data-ds-form' in attribs || 'data-ds-contact' in attribs ) {
			enhanced = true;
		}
		found.push( {
			action: ( attribs.action ?? '' ).trim(),
			method: ( attribs.method ?? '' ).trim().toLowerCase() || 'get',
			fields,
			carriers,
			enhanced,
		} );
	} );
	return found;
}

/** The host+path prefix of an endpoint, tolerant of a trailing slash. */
function endpointPrefix( endpoint: string ): string {
	return endpoint.trim().replace( /\/+$/, '' );
}

/**
 * Judge one built form against the profile's contract.
 *
 * A GET form is a site search (the forge routes those to `/search/`), so the
 * endpoint and carrier rules do not apply to it — a search form with no
 * `post_id` is correct.
 */
function judgeForm( form: BuiltForm, options: FormGateOptions ): string[] {
	const problems: string[] = [];
	if ( form.method === 'get' ) {
		return problems;
	}

	const isSubmit = form.enhanced || form.action !== '' || form.fields.length > 0;
	if ( ! isSubmit ) {
		return problems;
	}

	if ( form.action === '' ) {
		problems.push( 'submit form has no action (it can only ever reload the page)' );
	} else if ( form.action === '#' ) {
		problems.push( 'submit form posts to "#"' );
	}

	if ( form.fields.length === 0 ) {
		problems.push(
			'submit form has no fillable field (only carriers: ' +
				( Object.keys( form.carriers ).join( ', ' ) || 'none' ) +
				')'
		);
	}

	const endpoint = options.endpoint ? endpointPrefix( options.endpoint ) : '';
	if ( endpoint !== '' && form.action !== '' ) {
		const external = /^https?:\/\//i.test( form.action );
		const atEndpoint = external
			? form.action.startsWith( endpoint )
			: form.action.startsWith( '/' );
		if ( ! atEndpoint ) {
			problems.push( `submit form posts outside the configured endpoint (${ form.action } vs ${ endpoint })` );
		}
		// Elementor resolves the widget inside the carrier document; post 0 can
		// never dispatch (bridge 0.3.4 answers 400 for it).
		if ( 'post_id' in form.carriers && ( form.carriers.post_id === '' || form.carriers.post_id === '0' ) ) {
			problems.push( 'Elementor carrier `post_id` is empty or 0 — the handler cannot resolve the widget' );
		}
		if ( form.carriers.form === undefined || form.carriers.form === '' ) {
			problems.push( 'submit form carries no `form` identifier' );
		}
	}

	return problems;
}

/**
 * Audit every enquiry surface in the built pages.
 *
 * @param files  Built HTML files (`{ file, html }`).
 * @param options The profile's endpoint contract.
 */
export function auditFormBands(
	files: Array< { file: string; html: string } >,
	options: FormGateOptions = {}
): FormGateReport {
	const bands: FormBandAudit[] = [];

	for ( const { file, html } of files ) {
		const dom = parse( html );
		const claimed = new Set< FormNode >();

		walk( dom, ( node ) => {
			const attribs = node.attribs ?? {};
			const section = attribs[ 'data-section' ];
			if ( section !== 'form' ) {
				return;
			}
			const forms = formsIn( node );
			for ( const form of forms ) {
				// Mark the nodes claimed so the block pass below does not re-judge them.
				walk( [ node ], ( child ) => {
					if ( child.name === 'form' ) {
						claimed.add( child );
					}
				} );
			}
			const problems = forms.flatMap( ( form ) => judgeForm( form, options ) );
			if ( forms.length === 0 ) {
				problems.push(
					options.endpoint
						? 'the band is classified as a form but the build renders no form (the profile declares a bridge, so this band should post through it)'
						: 'the band is classified as a form and renders no form — the documented degrade-to-contacts behaviour (no forms endpoint on this profile)'
				);
			}
			bands.push( {
				file,
				sectionId: attribs[ 'data-section-id' ] ?? '',
				source: 'band',
				forms,
				// A missing form is only a DEFECT when a bridge was declared.
				problems: forms.length === 0 && ! options.endpoint ? [] : problems,
			} );
		} );

		// The `#contact` block component is not a design band, but it is an
		// enquiry surface on the same page and fails the same ways.
		const blocks: BuiltForm[] = [];
		walk( dom, ( node ) => {
			if ( node.name !== 'form' || claimed.has( node ) ) {
				return;
			}
			const attribs = node.attribs ?? {};
			if ( ! ( 'data-ds-form' in attribs ) && ! ( 'data-ds-contact' in attribs ) ) {
				return;
			}
			blocks.push( ...formsIn( { type: 'tag', name: 'div', children: [ node ] } ) );
		} );
		if ( blocks.length > 0 ) {
			bands.push( {
				file,
				sectionId: '#contact',
				source: 'block',
				forms: blocks,
				problems: blocks.flatMap( ( form ) => judgeForm( form, options ) ),
			} );
		}
	}

	const problems = bands.flatMap( ( band ) => band.problems );
	if ( bands.length === 0 ) {
		return { status: 'na', note: 'no enquiry surface in the build', bands, problems };
	}
	if ( problems.length > 0 ) {
		return {
			status: 'fail',
			note: `${ problems.length } defect(s) across ${ bands.length } enquiry surface(s)`,
			bands,
			problems,
		};
	}

	const forms = bands.reduce( ( total, band ) => total + band.forms.length, 0 );
	const open = bands.filter( ( band ) => band.forms.length === 0 ).length;
	const note =
		open > 0
			? `${ forms } form(s) across ${ bands.length } surface(s); ${ open } degraded to contacts`
			: `${ forms } form(s) across ${ bands.length } surface(s)`;
	return { status: 'pass', note, bands, problems };
}

/** A one-line-per-defect printable summary (the gate and the CLI share it). */
export function describeFormProblems( report: FormGateReport ): string[] {
	return report.bands.flatMap( ( band ) =>
		band.problems.map( ( problem ) => `${ band.source } ${ band.sectionId || '(no id)' }: ${ problem }` )
	);
}
