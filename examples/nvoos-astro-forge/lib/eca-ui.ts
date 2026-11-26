// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// eca-ui.ts — the ECA catalogue's generated front-end (Proposal 163 §P2).
//
// Everything here is a pure function of the site profile: a list of
// `{ path, content }` pairs the scaffold writes and then forgets. The generated
// code is plain Astro + one small vanilla island — no framework, no runtime
// dependency, and it reads the site's own `--forge-*` design tokens so a
// catalogue looks like the site it belongs to.
//
// Architectural decisions worth keeping:
//
//   PROGRESSIVE ENHANCEMENT   Every activity renders server-side. The island
//                             only HIDES cards, so a no-JS visitor still gets
//                             the full catalogue and the landings.
//   LANDINGS OVER QUERY URLS  /day/tuesday/ and /stage/junior-school/ are real
//                             pages with real counts — crawlable and cacheable.
//                             The island is for combinations the build cannot
//                             enumerate.
//   ONE CARD, MANY PAGES      Card, Catalogue and Banner are shared by the index
//                             and all four landing families.
// ---------------------------------------------------------------------------

import type { SiteProfile } from './types.ts';
import { ECA_COLLECTION } from './presets.ts';
import { ecaCollection, deriveTaxonomyRestBase } from './eca-scaffold.ts';

export interface ForgeFile {
	path: string;
	content: string;
}

/** Everything the ECA front-end needs, ready to write. */
export function ecaRouteFiles( profile: SiteProfile ): ForgeFile[] {
	const collection = ecaCollection( profile );
	const slug = collection.slug;
	const basePath = ( profile.eca?.basePath ?? ECA_COLLECTION.basePath ?? '/activities' ).replace( /\/+$/, '' ) || '/activities';
	const schoolName = profile.eca?.schoolName ?? profile.name ?? 'the school';
	const taxonomyBase = ( profile.eca as { taxonomyRestBase?: string } | undefined )?.taxonomyRestBase ?? deriveTaxonomyRestBase( collection.restBase );
	// The CMS pages that are really data views (a day timetable, the full list).
	const dataPages = ecaDataPages( profile ).map( ( page ) => ( {
		path: `src/pages/${ page.slug }.astro`,
		content: dataPage( page, basePath ),
	} ) );

	return [
		{ path: 'src/lib/eca-site.ts', content: siteLib( slug ) },
		{ path: 'src/styles/eca.css', content: ECA_CSS },
		{ path: 'src/components/eca/ActivityCard.astro', content: activityCard() },
		{ path: 'src/components/eca/BookingBanner.astro', content: bookingBanner() },
		{ path: 'src/components/eca/Catalogue.astro', content: catalogue() },
		{ path: 'src/pages/activities/index.astro', content: catalogueIndex( basePath, schoolName ) },
		{ path: 'src/pages/activities/[...slug].astro', content: activityDetail( basePath, schoolName ) },
		{ path: 'src/pages/day/[day].astro', content: dayLanding( basePath ) },
		{ path: 'src/pages/stage/[stage].astro', content: stageLanding( basePath ) },
		{ path: 'src/pages/category/[category].astro', content: categoryLanding( basePath ) },
		{ path: 'src/pages/type/[type].astro', content: typeLanding( basePath ) },
		{ path: 'src/lib/eca-shortlist.ts', content: SHORTLIST_LIB },
		{ path: 'src/components/eca/ShortlistButton.astro', content: shortlistButton() },
		{ path: 'src/pages/shortlist/index.astro', content: shortlistPage() },
		{ path: 'src/pages/help/index.astro', content: helpPage() },
		{ path: 'src/pages/eca-index.json.ts', content: indexEndpoint() },
		{ path: 'src/pages/calendar/index.astro', content: calendarPage() },
		{ path: 'src/pages/timetable/[stage].astro', content: timetablePage() },
		...dataPages,
		{ path: 'src/pages/terms/index.astro', content: termsPage() },
		{ path: 'src/pages/feeds/[slug].ics.ts', content: icsEndpoint( taxonomyBase ) },
	];
}

// ---------------------------------------------------------------------------
// Build-time data layer
// ---------------------------------------------------------------------------

function siteLib( slug: string ): string {
	return `// ---------------------------------------------------------------------------
// eca-site.ts — the build-time catalogue (Proposal 163 §P2).
//
// Normalises once per build and caches: every page and every landing reads the
// same array, so 105 activities cost one pass, not one per route.
// ---------------------------------------------------------------------------

import { getCollection } from 'astro:content';

import { sanitizeHtml } from '../forge/sanitize.ts';
import { buildFacets, buildTermCalendar, filterEca, monthGrid, monthLabel, monthsBetween, normalizeEca } from '../forge/eca.ts';
import type { EcaCalendarEntry, EcaFacets, EcaFilter, EcaRecord, ForgeEca, ForgeEcaTerm, RawEcaPost } from '../forge/eca.ts';
import { ecaConfig, ecaRestBase, ecaSourceOrigin, ecaTerms } from './eca.generated.ts';

type CollectionKey = Parameters< typeof getCollection >[ 0 ];

const DAY_ORDER = [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ];

let cache: Promise< EcaRecord[] > | null = null;

/** A REST body may arrive as an object with a rendered key, or a bare string. */
function rawHtml( content: unknown ): string {
	if ( typeof content === 'string' ) return content;
	if ( content && typeof content === 'object' && 'rendered' in content ) {
		return String( ( content as { rendered?: unknown } ).rendered ?? '' );
	}
	return '';
}

/** Every published activity, normalised and in timetable order. */
export function loadActivities(): Promise< EcaRecord[] > {
	if ( ! cache ) {
		cache = ( async () => {
			const [ terms, entries ] = await Promise.all( [
				ecaTerms(),
				// The generated collection union does not include the profile's own
				// collection name, so the lookup narrows to 'never' — pin the shape
				// the loader writes instead of casting at each use site.
				getCollection( ${ JSON.stringify( slug ) } as CollectionKey ) as Promise<
					Array< { id: string; data: Record< string, unknown > } >
				>,
			] );
			const records = entries
				.map( ( entry ) => {
					const data = entry.data;
					// meta, the taxonomy ids and the raw body arrive only because the
					// collection declares them as passthrough — the loader projects a
					// fixed field set. Passthrough bypasses the loader's sanitiser, so
					// the body is sanitised again here.
					const post: RawEcaPost = {
						id: Number( data.id ),
						slug: String( data.slug ?? entry.id ),
						title: typeof data.title === 'string' ? data.title : '',
						content: { rendered: sanitizeHtml( rawHtml( data.content ), { internalOrigins: [ ecaSourceOrigin ] } ) },
						meta: ( data.meta ?? {} ) as Record< string, unknown >,
						mcp_ai_eca_category: Array.isArray( data.mcp_ai_eca_category ) ? ( data.mcp_ai_eca_category as number[] ) : [],
					};
					return normalizeEca( post, {
						origin: ecaSourceOrigin,
						// Relative URLs: the card link is site-local, and the canonical is
						// built from Astro.url on the page that renders it.
						publicOrigin: '',
						restBase: ecaRestBase,
						config: ecaConfig,
						terms,
					} );
				} )
				.filter( ( record ) => record.status === 'active' );

			return records.sort( ( a, b ) => {
				const dayA = a.schedule.day ? DAY_ORDER.indexOf( a.schedule.day ) : 99;
				const dayB = b.schedule.day ? DAY_ORDER.indexOf( b.schedule.day ) : 99;
				if ( dayA !== dayB ) return dayA - dayB;
				if ( ( a.schedule.startMinutes ?? 9999 ) !== ( b.schedule.startMinutes ?? 9999 ) ) {
					return ( a.schedule.startMinutes ?? 9999 ) - ( b.schedule.startMinutes ?? 9999 );
				}
				return a.title.localeCompare( b.title );
			} );
		} )();
	}
	return cache;
}

/** The whole catalogue plus its global facet counts. */
export async function catalogue(): Promise< { records: EcaRecord[]; facets: EcaFacets } > {
	const records = await loadActivities();
	return { records, facets: buildFacets( records, ecaConfig ) };
}

/** A filtered slice for a landing page. */
export async function catalogueFor( filter: EcaFilter ): Promise< { records: EcaRecord[]; facets: EcaFacets; all: EcaRecord[] } > {
	const { records, facets } = await catalogue();
	return { records: filterEca( records, filter ), facets, all: records };
}

/** Stage id → its configured label. */
export function stageLabel( id: string ): string {
	return ecaConfig.stages?.find( ( stage ) => stage.id === id )?.label ?? id;
}

/** Time-band id → its configured label. */
export function timeBandLabel( id: string ): string | null {
	return ecaConfig.timeBands?.find( ( band ) => band.id === id )?.label ?? null;
}

/** A month of the term, already chunked into rendered weeks. */
export interface EcaCalendarMonthView {
	label: string;
	weeks: Array< Array< { date: string | null; entry: EcaCalendarEntry | null } > >;
}

/** The term as month grids for /calendar/. Null when no term is configured. */
export async function termCalendar(): Promise< { term: ForgeEcaTerm; months: EcaCalendarMonthView[] } | null > {
	const term = ecaConfig.terms?.[ 0 ];
	if ( ! term ) return null;

	const records = await loadActivities();
	const entries = buildTermCalendar( records, term );
	const byDate = new Map( entries.map( ( entry ) => [ entry.date, entry ] ) );
	// An open-ended term is bounded by its last occurrence, not by today.
	const last = term.end ?? entries[ entries.length - 1 ]?.date ?? term.start;

	const months = monthsBetween( term.start, last ).map( ( { year, month } ) => {
		const cells = monthGrid( year, month ).map( ( cell ) => ( {
			date: cell.date,
			entry: cell.date ? byDate.get( cell.date ) ?? null : null,
		} ) );
		const weeks: EcaCalendarMonthView['weeks'] = [];
		for ( let index = 0; index < cells.length; index += 7 ) weeks.push( cells.slice( index, index + 7 ) );
		return { label: monthLabel( year, month ), weeks };
	} );

	return { term, months };
}

/** The weekly grid for one stage: which days and bands are in use, and by what. */
export async function timetableFor( stage: string ): Promise< {
	days: string[];
	bands: NonNullable< ForgeEca['timeBands'] >;
	cells: Map< string, EcaRecord[] >;
	term: ForgeEcaTerm | null;
	total: number;
} > {
	const records = ( await loadActivities() ).filter(
		( record ) => record.stages.includes( stage ) && record.schedule.day !== null && record.schedule.startMinutes !== null,
	);

	const days = ( ecaConfig.days ?? DAY_ORDER ).filter( ( day ) =>
		records.some( ( record ) => record.schedule.days.includes( day ) ),
	);
	const bands = ( ecaConfig.timeBands ?? [] ).filter( ( band ) =>
		records.some( ( record ) => record.schedule.timeOfDay === band.id ),
	);

	const cells = new Map< string, EcaRecord[] >();
	for ( const record of records ) {
		for ( const day of record.schedule.days ) {
			const key = day + '|' + ( record.schedule.timeOfDay ?? '' );
			const list = cells.get( key ) ?? [];
			list.push( record );
			cells.set( key, list );
		}
	}
	for ( const list of cells.values() ) {
		list.sort( ( a, b ) => ( a.schedule.startMinutes ?? 0 ) - ( b.schedule.startMinutes ?? 0 ) );
	}

	return { days, bands, cells, term: ecaConfig.terms?.[ 0 ] ?? null, total: records.length };
}
`;
}

// ---------------------------------------------------------------------------
// Styles
// ---------------------------------------------------------------------------

const ECA_CSS = `/* eca.css — the catalogue's own surface (Proposal 163 §P2).
   Uses the site's measured design tokens, so a forged catalogue inherits the
   source brand rather than inventing one. */

.eca-shell {
	max-width: var(--forge-measure);
	margin: 0 auto;
	padding: var(--forge-space-lg) var(--forge-space);
}

.eca-shell h1 { font-family: var(--forge-font-display); font-size: var(--forge-scale-h1); line-height: 1.1; }
.eca-shell h2 { font-family: var(--forge-font-display); font-size: var(--forge-scale-h3); }
.eca-lede { color: var(--forge-muted); font-size: var(--forge-scale-body); max-width: 60ch; }
.eca-count { color: var(--forge-muted); font-size: var(--forge-scale-small); }

/* --- booking banner --- */
.eca-banner {
	display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.4rem 0.75rem;
	padding: var(--forge-space-sm) var(--forge-space);
	border-left: 4px solid var(--forge-accent);
	background: var(--forge-surface-alt);
	border-radius: var(--forge-radius-sm);
	margin-bottom: var(--forge-space);
}
.eca-banner strong { font-family: var(--forge-font-display); }
.eca-banner span { color: var(--forge-muted); font-size: var(--forge-scale-small); }
.eca-banner a { color: var(--forge-accent); font-weight: 600; }
.eca-banner[data-phase="open"] { border-left-color: var(--forge-cta); }
.eca-banner[data-phase="before"] { border-left-color: var(--forge-line); }

/* --- layout: rail + grid --- */
.eca-layout { display: grid; gap: var(--forge-space); align-items: start; }
@media (min-width: 60rem) { .eca-layout { grid-template-columns: 16rem minmax(0, 1fr); } }
/* No rail: a landing / day / list page is one dimension already, so the content
 * takes the whole measure. Without this the section sat in the 16rem rail track
 * and every row title wrapped to a few characters. */
@media (min-width: 60rem) { .eca-layout--full { grid-template-columns: minmax(0, 1fr); } }

.eca-rail { display: grid; gap: var(--forge-space); position: sticky; top: 1rem; }
.eca-rail fieldset { border: 0; margin: 0; padding: 0; }
.eca-rail legend {
	font-family: var(--forge-font-display); font-weight: 600; font-size: var(--forge-scale-small);
	text-transform: uppercase; letter-spacing: 0.06em; color: var(--forge-muted); margin-bottom: 0.4rem;
}
.eca-rail label { display: flex; align-items: center; gap: 0.45rem; padding: 0.15rem 0; font-size: var(--forge-scale-small); cursor: pointer; }
.eca-rail label:hover { color: var(--forge-accent); }
.eca-rail .eca-n {
	margin-left: auto; color: var(--forge-muted); font-variant-numeric: tabular-nums;
}
.eca-rail input[type="search"], .eca-rail select {
	width: 100%; padding: 0.45rem 0.55rem; border: 1px solid var(--forge-line);
	border-radius: var(--forge-radius-sm); font: inherit; font-size: var(--forge-scale-small);
	background: var(--forge-surface); color: var(--forge-ink);
}

/* --- cards --- */
.eca-grid { list-style: none; margin: 0; padding: 0; display: grid; gap: var(--forge-space-sm); }
@media (min-width: 40rem) { .eca-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (min-width: 72rem) { .eca-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }

.eca-card {
	border: 1px solid var(--forge-line); border-radius: var(--forge-radius);
	background: var(--forge-surface); box-shadow: var(--forge-shadow-card);
	transition: border-color 120ms ease, transform 120ms ease;
}
.eca-card:hover { border-color: var(--forge-accent); transform: translateY(-2px); }
.eca-card[hidden] { display: none; }
.eca-card__link { display: grid; gap: 0.45rem; padding: var(--forge-space-sm); color: inherit; text-decoration: none; }
.eca-card__top { display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center; }
.eca-card__type {
	font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.08em;
	font-weight: 700; color: var(--forge-accent);
}
.eca-card__title { font-family: var(--forge-font-display); font-size: var(--forge-scale-h4); margin: 0; line-height: 1.2; }
.eca-card__when { font-weight: 600; font-size: var(--forge-scale-small); }
.eca-card__meta { display: grid; gap: 0.2rem; color: var(--forge-muted); font-size: var(--forge-scale-small); }
.eca-card__foot { display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: center; margin-top: 0.2rem; }

/* ---------------------------------------------------------------------------
 * The LIST view (.eca-rows).
 *
 * A builder's day page is a timetable, not a catalogue: eca.directory's
 * /tuesday/ is 31 rows of 51px, one line each — title, time, venue, year and
 * type — separated by a hairline. Replaying that page's widgets as cards made
 * it 5603px where the source is 2312. The grid here is the row's own:
 * two flexible text columns, then fixed time/venue/meta/action tracks.
 * ------------------------------------------------------------------------- */
.eca-rows { list-style: none; margin: 0; padding: 0; }
.eca-row {
	display: grid;
	/* time is max-content: a 13-char range ("12:10 pm – 12:40 pm") overflows a
	 * fixed rem track and printed over the venue. */
	grid-template-columns: minmax(0, 1fr) minmax(9.5rem, max-content) minmax(0, 9rem) minmax(0, 13rem) auto;
	gap: var(--forge-space-sm) var(--forge-space);
	align-items: center;
	padding: 0.6rem 0;
	border-bottom: 1px solid var(--forge-line);
}
.eca-row[hidden] { display: none; }
.eca-row__title {
	font-family: var(--forge-font-display);
	font-size: var(--forge-scale-body);
	font-weight: 600;
	color: inherit;
	text-decoration: none;
}
.eca-row__title:hover { color: var(--forge-accent); text-decoration: underline; }
.eca-row__day { font-weight: 600; }
.eca-row__when { font-variant-numeric: tabular-nums; white-space: nowrap; }
.eca-row__venue { color: var(--forge-muted); font-size: var(--forge-scale-small); }
.eca-row__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 0.35rem;
	align-items: center;
	color: var(--forge-muted);
	font-size: var(--forge-scale-small);
}
.eca-row__actions { justify-self: end; }

/* A FULL LIST page packs its rows: the source shows a stage's whole catalogue 3
 * across (105 activities over 35 lines), not one 51px line per viewport row. */
.eca-rows--multi {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(19rem, 1fr));
	column-gap: var(--forge-space);
}
.eca-rows--multi .eca-row {
	grid-template-columns: minmax(0, 1fr);
	row-gap: 0.15rem;
	align-items: start;
}
.eca-rows--multi .eca-row__actions { justify-self: start; }

@media (max-width: 900px) {
	.eca-row {
		grid-template-columns: minmax(0, 1fr) auto;
		row-gap: 0.25rem;
	}
	.eca-row__title { grid-column: 1 / -1; }
	.eca-row__meta,
	.eca-row__venue { grid-column: 1 / -1; }
}

.eca-tag {
	display: inline-flex; align-items: center; gap: 0.25rem;
	padding: 0.12rem 0.5rem; border-radius: 999px; border: 1px solid var(--forge-line);
	font-size: 0.72rem; color: var(--forge-muted);
}
.eca-tag--price { border-color: var(--forge-accent); color: var(--forge-accent); font-weight: 600; }
.eca-tag--free { border-color: var(--forge-line); color: var(--forge-muted); }
.eca-tag--full { border-color: #b3261e; color: #b3261e; }

.eca-empty {
	padding: var(--forge-space-lg); border: 1px dashed var(--forge-line);
	border-radius: var(--forge-radius); text-align: center; color: var(--forge-muted);
}

/* --- detail --- */
.eca-detail { display: grid; gap: var(--forge-space); }
@media (min-width: 60rem) { .eca-detail { grid-template-columns: minmax(0, 1fr) 18rem; } }
.eca-facts { display: grid; gap: 0.5rem; align-content: start; }
.eca-facts div { display: grid; gap: 0.1rem; }
.eca-facts dt { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--forge-muted); }
.eca-facts dd { margin: 0; font-weight: 600; }
.eca-cta {
	display: inline-block; padding: var(--forge-cta-pad, 0.7rem 1.2rem);
	border-radius: var(--forge-cta-radius, var(--forge-radius-sm));
	background: var(--forge-cta); color: var(--forge-cta-ink);
	font-weight: 700; text-decoration: none;
}
.eca-cta:hover { filter: brightness(1.06); }
.eca-cta[aria-disabled="true"] { background: var(--forge-surface-alt); color: var(--forge-muted); cursor: default; }
.eca-note { font-size: var(--forge-scale-small); color: var(--forge-muted); }
.eca-prose :is(p, ul, ol) { max-width: 65ch; }

/* --- section nav --- */
.eca-subnav { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: var(--forge-space-sm); font-size: var(--forge-scale-small); }
.eca-subnav a { color: var(--forge-accent); font-weight: 600; text-decoration: none; }
.eca-subnav a:hover { text-decoration: underline; }

/* --- calendar --- */
.eca-month + .eca-month { margin-top: var(--forge-space-lg); }
.eca-cal { width: 100%; border-collapse: collapse; table-layout: fixed; }
.eca-cal caption { text-align: left; font-family: var(--forge-font-display); font-size: var(--forge-scale-h3); padding-bottom: 0.4rem; }
.eca-cal th { text-align: left; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--forge-muted); padding: 0.3rem 0.35rem; border-bottom: 1px solid var(--forge-line); }
.eca-cal td { vertical-align: top; height: 5.5rem; padding: 0.3rem 0.35rem; border-bottom: 1px solid var(--forge-line); font-size: 0.78rem; }
.eca-cal td time { display: block; font-weight: 700; color: var(--forge-muted); margin-bottom: 0.15rem; }
.eca-cal td ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.1rem; }
.eca-cal td a { color: var(--forge-ink); text-decoration: none; }
.eca-cal td a:hover { color: var(--forge-accent); text-decoration: underline; }
.eca-cal .eca-closed { background: var(--forge-surface-alt); color: var(--forge-muted); font-style: italic; }
.eca-cal .eca-outside { border-bottom-color: transparent; }
.eca-term th { width: 12rem; text-align: left; border-bottom: 1px solid var(--forge-line); padding: 0.4rem 0; }
.eca-term td { height: auto; }

/* --- timetable --- */
.eca-tt { width: 100%; border-collapse: collapse; }
.eca-tt caption { text-align: left; font-family: var(--forge-font-display); font-size: var(--forge-scale-h3); padding-bottom: 0.5rem; }
.eca-tt th, .eca-tt td { border: 1px solid var(--forge-line); padding: 0.4rem 0.45rem; vertical-align: top; font-size: var(--forge-scale-small); }
.eca-tt thead th { background: var(--forge-surface-alt); text-align: left; }
.eca-tt tbody th { text-align: left; width: 9rem; background: var(--forge-surface-alt); }
.eca-tt ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.3rem; }
.eca-tt li strong { display: block; font-weight: 600; }
.eca-tt li span { color: var(--forge-muted); }

.eca-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }

/* --- shortlist --- */
.eca-card__actions { padding: 0 var(--forge-space-sm) var(--forge-space-sm); }
.eca-save {
	font: inherit; font-size: var(--forge-scale-small); cursor: pointer;
	padding: 0.2rem 0.6rem; border-radius: 999px;
	border: 1px solid var(--forge-line); background: var(--forge-surface); color: var(--forge-muted);
}
.eca-save[aria-pressed="true"] { border-color: var(--forge-accent); color: var(--forge-accent); font-weight: 600; }
.eca-save[hidden] { display: none; }
.eca-shortlist-item { display: grid; gap: 0.35rem; }
.eca-shortlist-item .eca-save { justify-self: start; }

/* --- help --- */
.eca-help h2 { margin-top: var(--forge-space); }
.eca-steps { max-width: 60ch; display: grid; gap: 0.5rem; padding-left: 1.3rem; }
.eca-faq { display: grid; gap: 0.9rem; max-width: 65ch; margin: 0; }
.eca-faq dt { font-weight: 700; }
.eca-faq dd { margin: 0.15rem 0 0; color: var(--forge-muted); }
.eca-contacts { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.6rem; }
.eca-contacts strong { font-weight: 700; }
.eca-contacts span, .eca-contacts a { color: var(--forge-muted); font-size: var(--forge-scale-small); }

/* --- print: schools print the timetable --- */
@media print {
	.eca-rail, .eca-subnav, .eca-banner, .eca-cta { display: none !important; }
	.eca-tt, .eca-cal { font-size: 10pt; }
	.eca-cal td { height: auto; }
	.eca-card { break-inside: avoid; box-shadow: none; }
}
`;

// ---------------------------------------------------------------------------
// Components
// ---------------------------------------------------------------------------

function activityCard(): string {
	return `---
// ActivityCard — one activity, rendered server-side in one of two shapes.
//
// \`row\` is the LIST view a builder's day pages use: a single line of title,
// time, venue, year groups and type, 51px tall in the source's own measurement
// (eca.directory /tuesday/: 31 rows, /junior-school-list/: 105). \`card\` is the
// catalogue tile. Both carry the SAME data-* contract, so the facet island
// filters either without knowing which one it is looking at.
import { ecaBasePath } from '../../lib/eca.generated.ts';
import { bookingLabel, formatClock } from '../../forge/eca.ts';
import type { EcaRecord } from '../../forge/eca.ts';
import ShortlistButton from './ShortlistButton.astro';

interface Props {
	record: EcaRecord;
	variant?: 'card' | 'row';
	/** Print the day inside the row (a list page; a day page implies it). */
	showDay?: boolean;
}

const { record, variant = 'card', showDay = false } = Astro.props;
const href = ecaBasePath + '/' + record.slug + '/';
const days = record.schedule.days.length > 0 ? record.schedule.days.join( ' & ' ) : null;
const start = formatClock( record.schedule.start );
const end = formatClock( record.schedule.end );
const when = days && start ? ( end ? days + ' · ' + start + ' – ' + end : days + ' · ' + start ) : days ?? 'Schedule to be confirmed';
const time = start ? ( end ? start + ' – ' + end : start ) : null;
const years =
	record.yearGroups === null
		? null
		: record.yearGroups.from === record.yearGroups.to
			? 'Year ' + record.yearGroups.from
			: 'Years ' + record.yearGroups.from + '–' + record.yearGroups.to;
const typeLabel = record.type ? record.type.replace( /[-_]+/g, ' ' ) : 'activity';
const text = [ record.title, record.summary, record.provider ?? '', record.venue ?? '' ].join( ' ' ).toLowerCase();
---

<li
	class:list={ [ variant === 'row' ? 'eca-row' : 'eca-card' ] }
	data-eca-card
	data-day={ record.schedule.days.join( ' ' ) }
	data-stage={ record.stages.join( ' ' ) }
	data-type={ record.type ?? '' }
	data-year-from={ record.yearGroups?.from ?? '' }
	data-year-to={ record.yearGroups?.to ?? '' }
	data-time={ record.schedule.timeOfDay ?? '' }
	data-availability={ record.capacity.state }
	data-price={ record.pricing.paid ? 'paid' : 'free' }
	data-text={ text }
>
	{variant === 'row' ? (
		<>
			<a class="eca-row__title" href={ href }>{ record.title }</a>
			{ showDay && days && <span class="eca-row__day">{ days }</span> }
			<span class="eca-row__when">{ time ?? when }</span>
			<span class="eca-row__venue">{ record.venue ?? '' }</span>
			<span class="eca-row__meta">
				{ years && <span class="eca-row__years">{ years }</span> }
				<span class="eca-row__type">{ typeLabel }</span>
				{ record.pricing.paid && <span class="eca-tag eca-tag--price">{ record.pricing.display ?? 'Paid' }</span> }
				{ record.capacity.state === 'full' && <span class="eca-tag eca-tag--full">Full</span> }
				{ record.capacity.state === 'waitlist' && <span class="eca-tag eca-tag--full">Waitlist</span> }
			</span>
			<span class="eca-row__actions">
				<ShortlistButton slug={ record.slug } />
			</span>
		</>
	) : (
		<>
			<a class="eca-card__link" href={ href }>
		<span class="eca-card__top">
			<span class="eca-card__type">{ typeLabel }</span>
			{ record.code && <span class="eca-tag">{ record.code }</span> }
		</span>
		<h3 class="eca-card__title">{ record.title }</h3>
		<span class="eca-card__when">{ when }</span>
		<span class="eca-card__meta">
			{ record.venue && <span>{ record.venue }</span> }
			{ years && <span>{ years }</span> }
			{ record.provider && <span>with { record.provider }</span> }
		</span>
		<span class="eca-card__foot">
					{ record.pricing.paid ? (
						<span class="eca-tag eca-tag--price">{ record.pricing.display ?? 'Paid' }</span>
					) : (
						<span class="eca-tag eca-tag--free">Free</span>
					) }
					<span class="eca-tag">{ bookingLabel( record.booking.type, record.booking.requiresAudition ) }</span>
					{ record.capacity.state === 'full' && <span class="eca-tag eca-tag--full">Full</span> }
					{ record.capacity.state === 'waitlist' && <span class="eca-tag eca-tag--full">Waitlist</span> }
				</span>
			</a>
			<div class="eca-card__actions">
				<ShortlistButton slug={ record.slug } />
			</div>
		</>
	)}
</li>
`;
}

function bookingBanner(): string {
	return `---
// BookingBanner — the one fact a family plans around.
//
// Rendered at BUILD time (correct in the static output, and the no-JS answer),
// then corrected by a ~20-line island on load, because a build that predates
// the booking window would otherwise tell parents the wrong thing during the
// exact three days that matter.
import { bookingState, zonedStamp } from '../../forge/eca.ts';
import { ecaConfig } from '../../lib/eca.generated.ts';

const window = ecaConfig.booking?.window;
const timezone = ecaConfig.timezone ?? 'UTC';
const termStart = ecaConfig.terms?.[ 0 ]?.start;
const url = ecaConfig.booking?.url ?? null;
const label = ecaConfig.booking?.label ?? 'Book';
const built = window ? bookingState( zonedStamp( new Date(), timezone ), window, termStart ) : null;
---

{ built && (
	<aside class="eca-banner" data-eca-banner data-phase={ built.phase } data-window={ JSON.stringify( window ) } data-term={ termStart ?? '' }>
		<strong data-eca-banner-label>{ built.label }</strong>
		{ built.detail && <span data-eca-banner-detail>{ built.detail }</span> }
		{ url && <a href={ url }>{ label }</a> }
	</aside>
) }

{ built && (
	<script>
		// Mirrors bookingState() in src/forge/eca.ts — keep the vocabulary in
		// sync: before | open | closed | allocations | running.
		const el = document.querySelector( '[data-eca-banner]' );
		if ( el ) {
			const win = JSON.parse( el.getAttribute( 'data-window' ) || 'null' );
			const term = el.getAttribute( 'data-term' ) || '';
			if ( win && win.opens ) {
				const now = new Date();
				const pad = ( n: number ) => String( n ).padStart( 2, '0' );
				const stamp = now.getFullYear() + '-' + pad( now.getMonth() + 1 ) + '-' + pad( now.getDate() ) + 'T' + pad( now.getHours() ) + ':' + pad( now.getMinutes() );
				let phase = 'running';
				if ( now < new Date( win.opens ) ) phase = 'before';
				else if ( now <= new Date( win.closes ) ) phase = 'open';
				else if ( win.allocations && now < new Date( win.allocations ) ) phase = 'closed';
				else if ( term && stamp < term ) phase = 'allocations';
				el.setAttribute( 'data-phase', phase );
			}
		}
	</script>
) }
`;
}

function catalogue(): string {
	return `---
// Catalogue — the facet rail + the card grid + the island.
import '../../styles/eca.css';
import ActivityCard from './ActivityCard.astro';
import type { EcaFacets, EcaRecord } from '../../forge/eca.ts';
import { ecaBasePath } from '../../lib/eca.generated.ts';

interface Props {
	title: string;
	intro?: string;
	records: EcaRecord[];
	facets: EcaFacets;
	/** Set on a landing page: the facet that is already applied server-side. */
	active?: { key: string; value: string; label: string };
	/** Landing pages keep the rail off — they are one dimension already. */
	showFacets?: boolean;
	total?: number;
	/** \`rows\` is the LIST view a builder’s day and list pages use: one line per
	 * activity. \`cards\` is the catalogue tile grid. */
	layout?: 'cards' | 'rows';
	/** A list page prints the day inside each row; a day page implies it. */
	showDay?: boolean;
	/** Rows per line. A day page is a timetable (1); a full list page packs 3
	 * across, as the source does (105 activities in 6435px). */
	rowColumns?: number;
}

const { title, intro, records, facets, active, showFacets = true, total, layout = 'cards', showDay = false, rowColumns = 1 } = Astro.props;
const yearValues = facets.yearGroup.map( ( entry ) => entry.value );
---

<section class="eca-shell" data-pagefind-body>
	<nav class="eca-subnav" aria-label="ECA sections">
		<a href={ ecaBasePath + '/' }>All activities</a>
		<a href="/calendar/">Calendar</a>
		<a href="/terms/">Term dates</a>
		<a href="/help/">Help</a>
		<a href="/shortlist/">Shortlist</a>
	</nav>
	<h1>{ title }</h1>
	{ intro && <p class="eca-lede">{ intro }</p> }
	{ active && (
		<p class="eca-count">
			Showing { records.length } of { total ?? facets.total } · <a href={ ecaBasePath + '/' }>all activities</a>
		</p>
	) }

	<div class:list={ [ 'eca-layout', showFacets ? '' : 'eca-layout--full' ] }>
		{ showFacets && (
			<form class="eca-rail" data-eca-facets aria-label="Filter activities">
				<fieldset>
					<legend>Search</legend>
					<input type="search" name="q" placeholder="Coding, chess, sailing…" data-eca-search />
				</fieldset>

				{ facets.day.length > 0 && (
					<fieldset data-eca-group="day">
						<legend>Day</legend>
						{ facets.day.map( ( entry ) => (
							<label><input type="checkbox" value={ entry.value } /> { entry.label } <span class="eca-n">{ entry.count }</span></label>
						) ) }
					</fieldset>
				) }

				{ facets.stage.length > 0 && (
					<fieldset data-eca-group="stage">
						<legend>School</legend>
						{ facets.stage.map( ( entry ) => (
							<label><input type="checkbox" value={ entry.value } /> { entry.label } <span class="eca-n">{ entry.count }</span></label>
						) ) }
					</fieldset>
				) }

				{ facets.type.length > 0 && (
					<fieldset data-eca-group="type">
						<legend>Type</legend>
						{ facets.type.map( ( entry ) => (
							<label><input type="checkbox" value={ entry.value } /> { entry.label } <span class="eca-n">{ entry.count }</span></label>
						) ) }
					</fieldset>
				) }

				{ yearValues.length > 0 && (
					<fieldset>
						<legend>Year group</legend>
						<select data-eca-year>
							<option value="">Any year</option>
							{ yearValues.map( ( year ) => <option value={ year }>Year { year }</option> ) }
						</select>
					</fieldset>
				) }

				{ facets.timeOfDay.length > 0 && (
					<fieldset data-eca-group="time">
						<legend>Time of day</legend>
						{ facets.timeOfDay.map( ( entry ) => (
							<label><input type="checkbox" value={ entry.value } /> { entry.label } <span class="eca-n">{ entry.count }</span></label>
						) ) }
					</fieldset>
				) }

				{ facets.price.length > 0 && (
					<fieldset data-eca-group="price">
						<legend>Cost</legend>
						{ facets.price.map( ( entry ) => (
							<label><input type="checkbox" value={ entry.value } /> { entry.label } <span class="eca-n">{ entry.count }</span></label>
						) ) }
					</fieldset>
				) }

				{ facets.category.length > 0 && (
					<fieldset>
						<legend>Category</legend>
						{ facets.category.map( ( entry ) => (
							<a href={ '/category/' + entry.value + '/' }>{ entry.label } <span class="eca-n">{ entry.count }</span></a>
						) ) }
					</fieldset>
				) }
			</form>
		) }

		<div>
			{ showFacets && <p class="eca-count" data-eca-result-count aria-live="polite">{ records.length } activities</p> }
			<ul class:list={ [ layout === 'rows' ? 'eca-rows' : 'eca-grid', layout === 'rows' && rowColumns > 1 ? 'eca-rows--multi' : '' ] } data-eca-grid>
				{ records.map( ( record ) => <ActivityCard record={ record } variant={ layout === 'rows' ? 'row' : 'card' } showDay={ showDay } /> ) }
			</ul>
			<p class="eca-empty" data-eca-empty hidden>
				No activities match those filters. <button type="button" data-eca-reset>Clear them</button>
			</p>
		</div>
	</div>
</section>

{ showFacets && (
	<script>
		// Progressive enhancement: every card is already in the DOM, so the island
		// only toggles [hidden]. No-JS visitors get the full catalogue.
		const rail = document.querySelector< HTMLElement >( '[data-eca-facets]' );
		const grid = document.querySelector< HTMLElement >( '[data-eca-grid]' );
		if ( rail && grid ) {
			const cards = Array.from( grid.querySelectorAll< HTMLElement >( '[data-eca-card]' ) );
			const empty = document.querySelector< HTMLElement >( '[data-eca-empty]' );
			const count = document.querySelector< HTMLElement >( '[data-eca-result-count]' );
			const year = rail.querySelector< HTMLInputElement >( '[data-eca-year]' );
			const search = rail.querySelector< HTMLInputElement >( '[data-eca-search]' );

			const checked = ( name: string ) =>
				Array.from( rail.querySelectorAll< HTMLInputElement >( '[data-eca-group="' + name + '"] input:checked' ) )
					.map( ( input ) => input.value );

			const apply = () => {
				const days = checked( 'day' );
				const stages = checked( 'stage' );
				const types = checked( 'type' );
				const times = checked( 'time' );
				const prices = checked( 'price' );
				const wantYear = year && year.value ? Number( year.value ) : null;
				const query = search && search.value ? search.value.trim().toLowerCase() : '';
				let shown = 0;

				for ( const card of cards ) {
					const read = ( name: string ) => ( card.getAttribute( 'data-' + name ) || '' ).split( ' ' ).filter( Boolean );
					const from = Number( card.getAttribute( 'data-year-from' ) || 0 );
					const to = Number( card.getAttribute( 'data-year-to' ) || 0 );
					let visible = true;
					if ( days.length && ! days.some( ( value ) => read( 'day' ).indexOf( value ) !== -1 ) ) visible = false;
					if ( stages.length && ! stages.some( ( value ) => read( 'stage' ).indexOf( value ) !== -1 ) ) visible = false;
					if ( types.length && types.indexOf( card.getAttribute( 'data-type' ) || '' ) === -1 ) visible = false;
					if ( times.length && times.indexOf( card.getAttribute( 'data-time' ) || '' ) === -1 ) visible = false;
					if ( prices.length && prices.indexOf( card.getAttribute( 'data-price' ) || '' ) === -1 ) visible = false;
					if ( wantYear !== null ) {
						if ( ! from || ! to || wantYear < from || wantYear > to ) visible = false;
					}
					if ( query && ( card.getAttribute( 'data-text' ) || '' ).indexOf( query ) === -1 ) visible = false;
					card.hidden = ! visible;
					if ( visible ) shown += 1;
				}

				if ( count ) count.textContent = shown === 1 ? '1 activity' : shown + ' activities';
				if ( empty ) empty.hidden = shown > 0;
			};

			rail.addEventListener( 'change', apply );
			if ( search ) search.addEventListener( 'input', apply );
			const reset = document.querySelector< HTMLElement >( '[data-eca-reset]' );
			if ( reset ) {
				reset.addEventListener( 'click', () => {
					( rail as HTMLFormElement ).reset();
					apply();
				} );
			}
			apply();
		}
	</script>
) }

<script>
	// Outside the facet-rail conditional on purpose: a landing page hides the
	// rail but still renders save buttons.
	import { initShortlistButtons } from '../../lib/eca-shortlist.ts';
	initShortlistButtons();
</script>
`;
}

// ---------------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------------

function catalogueIndex( basePath: string, schoolName: string ): string {
	return `---
import Base from '../../layouts/Base.astro';
import BookingBanner from '../../components/eca/BookingBanner.astro';
import Catalogue from '../../components/eca/Catalogue.astro';
import { catalogue, stageLabel } from '../../lib/eca-site.ts';

const { records, facets } = await catalogue();

const description =
	'Every extra-curricular activity at ${ schoolName } — filter by day, school, year group, type, time and cost.';

const canonical = new URL( ${ JSON.stringify( basePath + '/' ) }, Astro.site ?? Astro.url ).toString();
	const listed = records.slice( 0, 50 );
	const itemList = {
		'@context': 'https://schema.org',
		'@type': 'ItemList',
		name: 'Activities',
		numberOfItems: listed.length,
		itemListElement: listed.map( ( record, index ) => ( {
			'@type': 'ListItem',
			position: index + 1,
			item: {
				'@type': 'Course',
				name: record.title,
				url: new URL( record.url, Astro.site ?? Astro.url ).toString(),
				provider: {
					'@type': 'Organization',
					name: record.provider ?? '${ schoolName }',
				},
				...( record.pricing.paid && record.pricing.amount
					? {
							offers: {
								'@type': 'Offer',
								price: record.pricing.amount,
								priceCurrency: record.pricing.currency ?? 'LKR',
							},
					  }
					: {} ),
			},
		} ) ),
	};
---

<Base title={ 'Activities | ${ schoolName }' } description={ description } canonical={ canonical }>
	<main>
		<Catalogue title="Activities" intro={ description } records={ records } facets={ facets } total={ records.length } />
		<BookingBanner />
	</main>
	<script type="application/ld+json" set:html={ JSON.stringify( itemList ) } />
</Base>
`;
}

function activityDetail( basePath: string, schoolName: string ): string {
	return `---
// The activity page — facts, the booking hand-off, and a calendar feed.
import Base from '../../layouts/Base.astro';
import BookingBanner from '../../components/eca/BookingBanner.astro';
import ShortlistButton from '../../components/eca/ShortlistButton.astro';
import { loadActivities } from '../../lib/eca-site.ts';
import { ecaConfig } from '../../lib/eca.generated.ts';
import { bookingCtaLabel, bookingUrl, formatClock } from '../../forge/eca.ts';

export async function getStaticPaths() {
	const records = await loadActivities();
	return records.flatMap( ( record ) => [
		{ params: { slug: record.slug }, props: { record } },
		// Alias: the source's /eca/<slug>/ 301s still target the generic
		// /<base>/<id>/<slug>/ shape, which must resolve rather than 404. Both
		// forms canonicalise to /<base>/<slug>/ below.
		{ params: { slug: record.id + '/' + record.slug }, props: { record } },
	] );
}

const { record } = Astro.props;
const origin = Astro.site ?? Astro.url;
const canonical = new URL( record.url, origin ).toString();
const url = bookingUrl( record, ecaConfig );
const cta = bookingCtaLabel( record.booking.type, record.booking.requiresAudition, ecaConfig.booking?.label );
const days = record.schedule.days.length > 0 ? record.schedule.days.join( ' & ' ) : null;
// A published timetable is enough for a CourseInstance: a start time with no
// explicit day list must not silently drop the instance (Google needs
// hasCourseInstance on the Course list carousel).
const hasInstance = record.schedule.days.length > 0 || Boolean( record.schedule.start );
const time = record.schedule.start
	? formatClock( record.schedule.start ) + ( record.schedule.end ? ' – ' + formatClock( record.schedule.end ) : '' )
	: null;
const facts = [
	[ 'When', days ? days + ( time ? ' · ' + time : '' ) : null ],
	[ 'Where', record.venue ],
	[ 'Year groups', record.yearGroups ? ( record.yearGroups.from === record.yearGroups.to ? 'Year ' + record.yearGroups.from : 'Years ' + record.yearGroups.from + '–' + record.yearGroups.to ) : null ],
	[ 'Places', record.capacity.max ? 'Up to ' + record.capacity.max : null ],
	[ 'Run by', record.provider ?? '${ schoolName } staff' ],
	[ 'Cost', record.pricing.paid ? record.pricing.display ?? 'Paid' : 'Free' ],
	[ 'How places are allocated', record.booking.requiresAudition ? 'Audition required' : record.booking.type.replace( /[-_]+/g, ' ' ) ],
].filter( ( pair ) => Boolean( pair[ 1 ] ) );

const jsonLd = {
	'@context': 'https://schema.org',
	'@type': 'Course',
	name: record.title,
	description: record.summary,
	url: canonical,
	provider: { '@type': 'Organization', name: record.provider ?? '${ schoolName }' },
	...( record.pricing.paid && record.pricing.amount
		? { offers: { '@type': 'Offer', price: record.pricing.amount, priceCurrency: record.pricing.currency ?? 'LKR', url: canonical } }
		: {} ),
	...( hasInstance
		? {
				hasCourseInstance: {
					'@type': 'CourseInstance',
					courseMode: 'onsite',
					...( record.venue ? { location: { '@type': 'Place', name: record.venue } } : {} ),
					...( record.schedule.start
						? {
								courseSchedule: {
									'@type': 'Schedule',
									...( record.schedule.days.length > 0
										? { byDay: record.schedule.days.map( ( day ) => 'https://schema.org/' + day ) }
										: {} ),
									startTime: record.schedule.start,
									endTime: record.schedule.end ?? record.schedule.start,
								},
							}
						: {} ),
				},
		  }
		: {} ),
};
---

<Base title={ record.title + ' | ${ schoolName }' } description={ record.summary } canonical={ canonical }>
	<main class="eca-shell">
		<p class="eca-count"><a href="${ basePath }/">All activities</a></p>
		<h1>{ record.title }</h1>
		<p class="eca-lede">{ record.summary }</p>

		<div class="eca-detail">
			<div>
				<div class="eca-prose" set:html={ record.descriptionHtml } />

				<p>
					{ url ? (
						<a class="eca-cta" href={ url } rel="noopener">{ cta }</a>
					) : (
						<span class="eca-cta" aria-disabled="true">{ cta }</span>
					) }
				</p>
				<p><ShortlistButton slug={ record.slug } /></p>
				{ record.pricing.paid && ecaConfig.booking?.note && <p class="eca-note">{ ecaConfig.booking.note }</p> }
				{ record.schedule.day && record.schedule.start && (
					<p class="eca-note">Add to your calendar: <a href={ record.links.ics }>weekly .ics</a></p>
				) }
			</div>

			<aside>
				<dl class="eca-facts">
					{ facts.map( ( [ label, value ] ) => (
						<div><dt>{ label }</dt><dd>{ value }</dd></div>
					) ) }
				</dl>
			</aside>
		</div>

		<BookingBanner />
	</main>
	<script type="application/ld+json" set:html={ JSON.stringify( jsonLd ) } />
	<script>
		import { initShortlistButtons } from '../../lib/eca-shortlist.ts';
		initShortlistButtons();
	</script>
</Base>
`;
}

function dayLanding( basePath: string ): string {
	return `---
import Base from '../../layouts/Base.astro';
import Catalogue from '../../components/eca/Catalogue.astro';
import { catalogueFor, catalogue } from '../../lib/eca-site.ts';

export async function getStaticPaths() {
	const { facets } = await catalogue();
	return facets.day.map( ( entry ) => ( { params: { day: entry.value.toLowerCase() }, props: { day: entry.value } } ) );
}

const { day } = Astro.props;
const { records, facets, all } = await catalogueFor( { day: [ day ] } );
const title = day + ' activities';
---

<Base title={ title } description={ 'Activities that run on ' + day + '.' }>
	<main>
		<Catalogue title={ title } records={ records } facets={ facets } active={ { key: 'day', value: day, label: day } } total={ all.length } showFacets={ false } />
	</main>
</Base>
`;
}

/**
 * A CMS PAGE THAT IS REALLY A DATA VIEW, claimed for the product template.
 *
 * A builder's listing page (Elementor + JetEngine's `jet-listing-grid`) is 600+
 * widgets whose only job is to print rows of records. Replaying those widgets as
 * rebuilt markup is always wrong: eca.directory's `/tuesday/` rendered 5603px
 * against the source's 2312, and `/junior-school-list/` 19844 against 6435,
 * because 105 rows became 105 stacked cards.
 *
 * The claim is derived from the site's own inventory + measured pages, so it is
 * driven by what the site HAS, not by a hardcoded slug list:
 *   - a slug that IS a weekday → that day's timetable, in rows;
 *   - a page whose TITLE names a weekday → that day's timetable (the slug is
 *     opaque: `eca-days` is titled “Monday- Junior School” and lists the Monday
 *     activities; the source's 14th row is lorem-ipsum demo content, the record
 *     set has 13);
 *   - a slug ending in `-list` → the full catalogue, in rows, day shown.
 * `select-list` (a chooser page with no rows) is deliberately NOT claimed.
 */
export interface EcaDataPage {
	/** The page slug (also the route segment). */
	slug: string;
	/** The day this page lists, title-cased; null for the full listing. */
	day: string | null;
	/** The page's own title, from the measurement when it has one. */
	title: string;
	/** Print the day inside each row (a list page; a day page implies it). */
	showDay: boolean;
}

const ECA_WEEKDAYS = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];

/** `junior-school-list` → `Junior School List`. */
function titleFromSlug( slug: string ): string {
	return slug
		.split( '-' )
		.filter( Boolean )
		.map( ( word ) => word.charAt( 0 ).toUpperCase() + word.slice( 1 ) )
		.join( ' ' );
}

/**
 * The weekday a page TITLE names, title-cased (`Monday- Junior School` →
 * `Monday`). Only the first word counts: a builder's listing page names the
 * slice it renders up front, and an opaque slug (`eca-days`) says nothing.
 */
function titleWeekday( title: string ): string | null {
	const word = /^\s*([a-z]+)/i.exec( title )?.[ 1 ]?.toLowerCase();
	if ( ! word || ! ECA_WEEKDAYS.includes( word ) ) {
		return null;
	}
	return word.charAt( 0 ).toUpperCase() + word.slice( 1 );
}

/** The CMS pages this preset takes over (see `EcaDataPage`). */
export function ecaDataPages( profile: SiteProfile ): EcaDataPage[] {
	const excluded = new Set(
		( profile.inventory?.excluded ?? [] ).map( ( path ) => String( path ).replace( /^\/+|\/+$/g, '' ) )
	);
	const candidates: string[] = [];
	for ( const path of profile.inventory?.covered ?? [] ) {
		candidates.push( String( path ).replace( /^\/+|\/+$/g, '' ) );
	}
	for ( const slug of Object.keys( profile.pageBands ?? {} ) ) {
		candidates.push( slug );
	}

	const seen = new Set< string >();
	const out: EcaDataPage[] = [];
	for ( const slug of candidates ) {
		if ( ! slug || seen.has( slug ) || excluded.has( slug ) || slug === profile.frontPage ) {
			continue;
		}
		seen.add( slug );
		const bands = profile.pageBands?.[ slug ];
		const measuredTitle = bands
			? Object.values( bands.bandFacts ?? {} )
					.map( ( facts ) => facts.titleText )
					.find( ( text ): text is string => Boolean( text ) )
			: undefined;
		if ( ECA_WEEKDAYS.includes( slug ) ) {
			const day = slug.charAt( 0 ).toUpperCase() + slug.slice( 1 );
			out.push( { slug, day, title: measuredTitle ?? `${ day } activities`, showDay: false } );
			continue;
		}
		// A page whose own TITLE names a weekday is that day's listing, whatever its
		// slug says — see `titleWeekday`.
		const wpTitle = profile.inventory?.titles?.[ `/${ slug }/` ] ?? '';
		const titleDay = titleWeekday( wpTitle );
		if ( titleDay ) {
			out.push( { slug, day: titleDay, title: measuredTitle ?? wpTitle, showDay: false } );
			continue;
		}
		if ( /-list$/.test( slug ) ) {
			// Only a page that actually RENDERS a listing. `select-list` is a
			// chooser (two 2-lane teaser bands, no rows) despite the suffix.
			const listing = bands?.sections.some( ( section ) => {
				const geometry = section.id ? bands.geometry?.[ section.id ]?.desktop : undefined;
				const lanes = section.columns?.length ?? 0;
				const height = geometry?.height ?? 0;
				return lanes >= 20 || height >= 2000;
			} );
			if ( listing ) {
				out.push( { slug, day: null, title: measuredTitle ?? titleFromSlug( slug ), showDay: true } );
			}
		}
	}
	return out;
}

/** One claimed page: the product template at the CMS page's own URL. */
function dataPage( page: EcaDataPage, basePath: string ): string {
	const filter = page.day ? `{ day: [ ${ JSON.stringify( page.day ) } ] }` : '{}';
	const active = page.day
		? `{ key: 'day', value: ${ JSON.stringify( page.day ) }, label: ${ JSON.stringify( page.day ) } }`
		: 'undefined';
	return `---
// /${ page.slug }/ — a CMS page that is really a DATA VIEW (Proposal 163 §P4).
//
// The builder rendered this page from a JetEngine listing; the record set is
// the source of truth, so the route renders the catalogue's ROW layout at the
// page's own URL. Generated by nvoos-astro-forge — edits are overwritten.
import Base from '../layouts/Base.astro';
import Catalogue from '../components/eca/Catalogue.astro';
import { catalogueFor } from '../lib/eca-site.ts';

const { records, facets, all } = await catalogueFor( ${ filter } );
const title = ${ JSON.stringify( page.title ) };
---

<Base title={ title } description={ ${ JSON.stringify( `${ page.title } — the full list, with times, venues and year groups.` ) } }>
	<main>
		<Catalogue
			title={ title }
			records={ records }
			facets={ facets }
			active={ ${ active } }
			total={ all.length }
			showFacets={ false }
			layout="rows"
			showDay={ ${ page.showDay ? 'true' : 'false' } }
			rowColumns={ ${ page.showDay ? '3' : '1' } }
		/>
	</main>
</Base>
`;
}

function stageLanding( basePath: string ): string {
	return `---
import Base from '../../layouts/Base.astro';
import Catalogue from '../../components/eca/Catalogue.astro';
import { catalogueFor, catalogue, stageLabel } from '../../lib/eca-site.ts';
import { ecaConfig } from '../../lib/eca.generated.ts';

export async function getStaticPaths() {
	const { facets } = await catalogue();
	return facets.stage.map( ( entry ) => ( { params: { stage: entry.value }, props: { stage: entry.value } } ) );
}

const { stage } = Astro.props;
const label = stageLabel( stage );
const blurb = ecaConfig.stages?.find( ( entry ) => entry.id === stage )?.blurb;
const { records, facets, all } = await catalogueFor( { stage: [ stage ] } );
---

<Base title={ label + ' activities' } description={ blurb ?? 'Activities for ' + label + '.' }>
	<main>
		<Catalogue title={ label + ' activities' } intro={ blurb } records={ records } facets={ facets } active={ { key: 'stage', value: stage, label } } total={ all.length } showFacets={ false } />
	</main>
</Base>
`;
}

function categoryLanding( basePath: string ): string {
	return `---
import Base from '../../layouts/Base.astro';
import Catalogue from '../../components/eca/Catalogue.astro';
import { catalogueFor, catalogue } from '../../lib/eca-site.ts';

export async function getStaticPaths() {
	const { facets } = await catalogue();
	return facets.category.map( ( entry ) => ( { params: { category: entry.value }, props: { label: entry.label, slug: entry.value } } ) );
}

const { label, slug } = Astro.props;
const { records, facets, all } = await catalogueFor( { category: [ slug ] } );
---

<Base title={ label + ' activities' } description={ 'Activities in ' + label + '.' }>
	<main>
		<Catalogue title={ label } records={ records } facets={ facets } active={ { key: 'category', value: slug, label } } total={ all.length } showFacets={ false } />
	</main>
</Base>
`;
}

function typeLanding( basePath: string ): string {
	return `---
import Base from '../../layouts/Base.astro';
import Catalogue from '../../components/eca/Catalogue.astro';
import { catalogueFor, catalogue } from '../../lib/eca-site.ts';

export async function getStaticPaths() {
	const { facets } = await catalogue();
	return facets.type.map( ( entry ) => ( { params: { type: entry.value }, props: { label: entry.label, slug: entry.value } } ) );
}

const { label, slug } = Astro.props;
const { records, facets, all } = await catalogueFor( { type: [ slug ] } );
---

<Base title={ label + 's' } description={ 'Every ' + label.toLowerCase() + ' on offer.' }>
	<main>
		<Catalogue title={ label + 's' } records={ records } facets={ facets } active={ { key: 'type', value: slug, label } } total={ all.length } showFacets={ false } />
	</main>
</Base>
`;
}

function icsEndpoint( taxonomyBase: string ): string {
	return `// One activity's weekly calendar feed (RFC 5545). A record with no known
// day/time — or a site with no term configured — emits nothing rather than a
// guessed schedule.
import type { APIRoute, GetStaticPaths } from 'astro';
import { buildIcs } from '../../forge/eca.ts';
import { ecaConfig } from '../../lib/eca.generated.ts';
import { loadActivities } from '../../lib/eca-site.ts';

export const getStaticPaths = ( async () => {
	const records = await loadActivities();
	return records
		.filter( ( record ) => record.schedule.day && record.schedule.start )
		.map( ( record ) => ( { params: { slug: record.slug }, props: { record } } ) );
} ) satisfies GetStaticPaths;

export const GET: APIRoute = ( { props, url, site } ) => {
	// An .ics URL must be absolute — a relative one is useless to a calendar.
	const pageUrl = new URL( props.record.url, site ?? url ).toString();
	const ics = buildIcs( props.record, { config: ecaConfig, pageUrl } );
	return new Response( ics, {
		headers: {
			'content-type': 'text/calendar; charset=utf-8',
			'cache-control': 'public, max-age=3600',
		},
	} );
};
`;
}

function calendarPage(): string {
	return `---
// /calendar/ — the term as month grids.
//
// Server-rendered tables, no island: the dates are fixed at build time, and a
// real <table> with a <caption> is navigable by a screen reader in a way a
// div grid of dates never is.
import Base from '../../layouts/Base.astro';
import { termCalendar } from '../../lib/eca-site.ts';
import { formatClock, formatDate } from '../../forge/eca.ts';

const data = await termCalendar();
const HEAD = [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ];
const description = data ? 'Every ' + data.term.label + ' activity date, week by week.' : 'Term calendar';
---

<Base title="Term calendar" description={ description }>
	<main class="eca-shell">
		<nav class="eca-subnav" aria-label="ECA sections">
			<a href="/activities/">All activities</a>
			<a href="/terms/">Term dates</a>
		</nav>
		<h1>Term calendar</h1>

		{ data ? (
			<>
				<p class="eca-lede">
					{ data.term.label } · activities begin <time datetime={ data.term.start }>{ formatDate( data.term.start ) }</time>
					{ data.term.end && <> · last day <time datetime={ data.term.end }>{ formatDate( data.term.end ) }</time></> }
				</p>

				{ data.months.map( ( month ) => (
					<section class="eca-month">
						<table class="eca-cal">
							<caption>{ month.label }</caption>
							<thead>
								<tr>
									{ HEAD.map( ( day ) => (
										<th scope="col" abbr={ day }>{ day.slice( 0, 3 ) }<span class="eca-sr"> { day }</span></th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ month.weeks.map( ( week ) => (
									<tr>
										{ week.map( ( cell ) => (
											<td class={ cell.date === null ? 'eca-outside' : cell.entry?.closure ? 'eca-closed' : undefined }>
												{ cell.date && (
													<>
														<time datetime={ cell.date }>{ Number( cell.date.slice( 8 ) ) }</time>
														{ cell.entry?.closure && <em>{ cell.entry.closure }</em> }
														{ cell.entry && cell.entry.items.length > 0 && (
															<ul>
																{ cell.entry.items.map( ( item ) => (
																	<li>
																		<a href={ item.url }>{ item.title }</a>
																		{ item.start && <span> { formatClock( item.start ) }</span> }
																	</li>
																) ) }
															</ul>
														) }
													</>
												) }
											</td>
										) ) }
									</tr>
								) ) }
							</tbody>
						</table>
					</section>
				) ) }
			</>
		) : (
			<p class="eca-empty">No term is configured yet, so there is no calendar to show.</p>
		) }
	</main>
</Base>
`;
}

function timetablePage(): string {
	return `---
// /timetable/<stage>/
//
// A real table: <th scope="col"> for days, <th scope="row"> for time bands.
// A div grid would look the same and be unnavigable, and it would not print.
import Base from '../../layouts/Base.astro';
import { catalogue, stageLabel, timetableFor } from '../../lib/eca-site.ts';
import { formatClock } from '../../forge/eca.ts';

export async function getStaticPaths() {
	const { facets } = await catalogue();
	return facets.stage.map( ( entry ) => ( { params: { stage: entry.value }, props: { stage: entry.value } } ) );
}

const { stage } = Astro.props;
const label = stageLabel( stage );
const { days, bands, cells, term, total } = await timetableFor( stage );
const grid = days.map( ( day ) => ( {
	day,
	columns: bands.map( ( band ) => cells.get( day + '|' + band.id ) ?? [] ),
} ) );
const description = 'Weekly timetable of ' + label + ' activities.';
---

<Base title={ label + ' timetable' } description={ description }>
	<main class="eca-shell">
		<nav class="eca-subnav" aria-label="ECA sections">
			<a href="/activities/">All activities</a>
			<a href="/calendar/">Calendar</a>
			<a href="/terms/">Term dates</a>
		</nav>
		<h1>{ label } timetable</h1>
		<p class="eca-lede">
			{ term ? term.label + ' · ' : '' }{ total } activities with a published day and time. Columns are weekdays, rows are time of day.
		</p>

		{ days.length > 0 && bands.length > 0 ? (
			<table class="eca-tt">
				<caption>{ label } — one week</caption>
				<thead>
					<tr>
						<th scope="col">Time</th>
						{ days.map( ( day ) => <th scope="col">{ day }</th> ) }
					</tr>
				</thead>
				<tbody>
					{ bands.map( ( band, bandIndex ) => (
						<tr>
							<th scope="row">{ band.label }</th>
							{ grid.map( ( column ) => (
								<td>
									{ column.columns[ bandIndex ].length === 0 ? (
										<span aria-hidden="true">—</span>
									) : (
										<ul>
											{ column.columns[ bandIndex ].map( ( record ) => (
												<li>
													<strong><a href={ record.url }>{ record.title }</a></strong>
													<span>{ formatClock( record.schedule.start ) }{ record.venue ? ' · ' + record.venue : '' }</span>
												</li>
											) ) }
										</ul>
									) }
								</td>
							) ) }
						</tr>
					) ) }
				</tbody>
			</table>
		) : (
			<p class="eca-empty">No { label } activity has a published day and time yet.</p>
		) }

		<p class="eca-note">Each activity page offers a weekly .ics reminder for your own calendar.</p>
	</main>
</Base>
`;
}

function termsPage(): string {
	return `---
// /terms/ — term dates, closures and the booking window in one place, so the
// dates a family needs are not buried in a PDF.
import Base from '../../layouts/Base.astro';
import BookingBanner from '../../components/eca/BookingBanner.astro';
import { ecaConfig } from '../../lib/eca.generated.ts';
import { formatDate, formatStamp } from '../../forge/eca.ts';

const term = ecaConfig.terms?.[ 0 ] ?? null;
const window = ecaConfig.booking?.window ?? null;
const closures = term?.closures ?? [];
---

<Base title="Term dates" description="Term dates, closures and the ECA booking window.">
	<main class="eca-shell">
		<nav class="eca-subnav" aria-label="ECA sections">
			<a href="/activities/">All activities</a>
			<a href="/calendar/">Calendar</a>
		</nav>
		<h1>Term dates</h1>
		<BookingBanner />

		{ term ? (
			<table class="eca-cal eca-term">
				<caption>{ term.label }</caption>
				<tbody>
					<tr>
						<th scope="row">Activities begin</th>
						<td><time datetime={ term.start }>{ formatDate( term.start ) }</time></td>
					</tr>
					{ term.end && (
						<tr>
							<th scope="row">Activities end</th>
							<td><time datetime={ term.end }>{ formatDate( term.end ) }</time></td>
						</tr>
					) }
					{ closures.map( ( closure ) => (
						<tr>
							<th scope="row">{ closure.label ?? 'Closed' }</th>
							<td>
								<time datetime={ closure.from }>{ formatDate( closure.from ) }</time>
								–
								<time datetime={ closure.to }>{ formatDate( closure.to ) }</time>
							</td>
						</tr>
					) ) }
					{ window && (
						<tr>
							<th scope="row">Booking opens</th>
							<td>{ formatStamp( window.opens ) }</td>
						</tr>
					) }
					{ window && (
						<tr>
							<th scope="row">Booking closes</th>
							<td>{ formatStamp( window.closes ) }</td>
						</tr>
					) }
					{ window?.allocations && (
						<tr>
							<th scope="row">Allocations published</th>
							<td>{ formatStamp( window.allocations ) }</td>
						</tr>
					) }
				</tbody>
			</table>
		) : (
			<p class="eca-empty">Term dates are not published yet.</p>
		) }

		{ ecaConfig.booking?.note && <p class="eca-note">{ ecaConfig.booking.note }</p> }
	</main>
</Base>
`;
}

// ---------------------------------------------------------------------------
// P5 — shortlist, help, and the JSON index
// ---------------------------------------------------------------------------

const SHORTLIST_LIB = `// eca-shortlist.ts — the shortlist, in this browser only (Proposal 163 §P5).
//
// The key is versioned: a shape change must never crash a returning visitor
// holding the previous payload. Nothing leaves the browser until the family
// books on the school system, which is the whole point of a shortlist.
export const SHORTLIST_KEY = 'nvoos-eca:shortlist:v1';
export const SHORTLIST_EVENT = 'nvoos-eca:shortlist';

export function readShortlist(): string[] {
	try {
		const raw = localStorage.getItem( SHORTLIST_KEY );
		if ( ! raw ) return [];
		const parsed: unknown = JSON.parse( raw );
		return Array.isArray( parsed ) ? parsed.filter( ( entry ): entry is string => typeof entry === 'string' ) : [];
	} catch {
		return [];
	}
}

export function writeShortlist( slugs: string[] ): void {
	try {
		localStorage.setItem( SHORTLIST_KEY, JSON.stringify( [ ...new Set( slugs ) ] ) );
	} catch {
		// Private mode or a full quota: the catalogue still works, the shortlist
		// silently does not. Never throw from a preference write.
	}
	window.dispatchEvent( new CustomEvent( SHORTLIST_EVENT ) );
}

export function toggleShortlist( slug: string ): string[] {
	const current = readShortlist();
	const next = current.includes( slug ) ? current.filter( ( entry ) => entry !== slug ) : [ ...current, slug ];
	writeShortlist( next );
	return next;
}

/**
 * Wire every save button in a subtree.
 *
 * Buttons ship hidden, so a no-JS visitor never sees a control that cannot
 * work — the same progressive rule the facet rail follows.
 */
export function initShortlistButtons( root: ParentNode = document ): void {
	const buttons = Array.from( root.querySelectorAll< HTMLButtonElement >( '[data-eca-shortlist]' ) );
	if ( buttons.length === 0 ) return;

	const paint = (): void => {
		const saved = readShortlist();
		for ( const button of buttons ) {
			const slug = button.getAttribute( 'data-eca-shortlist' ) ?? '';
			const on = saved.includes( slug );
			button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			button.textContent = on ? 'Saved' : 'Save';
		}
	};

	for ( const button of buttons ) {
		button.hidden = false;
		button.addEventListener( 'click', () => {
			toggleShortlist( button.getAttribute( 'data-eca-shortlist' ) ?? '' );
			paint();
		} );
	}

	paint();
	window.addEventListener( SHORTLIST_EVENT, paint );
}
`;

function shortlistButton(): string {
	return `---
// The save control. Hidden until the island wires it.
interface Props {
	slug: string;
}

const { slug } = Astro.props;
---

<button type="button" class="eca-save" data-eca-shortlist={ slug } aria-pressed="false" hidden>Save</button>
`;
}

function indexEndpoint(): string {
	return `// /eca-index.json — the catalogue as data (Proposal 163 §P5).
//
// The shortlist reads this instead of scraping the DOM: one request, a stable
// shape, and no coupling to the rendered card markup.
import type { APIRoute } from 'astro';

import { loadActivities } from '../lib/eca-site.ts';

export const GET: APIRoute = async () => {
	const records = await loadActivities();
	const body = records.map( ( record ) => ( {
		slug: record.slug,
		title: record.title,
		url: record.url,
		day: record.schedule.days.join( ' ' ),
		start: record.schedule.start,
		type: record.type,
		stages: record.stages,
		years: record.yearGroups,
		venue: record.venue,
		price: record.pricing.display,
		free: ! record.pricing.paid,
		summary: record.summary,
	} ) );

	return new Response( JSON.stringify( body ), {
		headers: { 'content-type': 'application/json; charset=utf-8' },
	} );
};
`;
}

function shortlistPage(): string {
	return `---
// /shortlist/ — noindex: a per-visitor page has no business in an index, and
// it is empty for everyone but its owner anyway.
import Base from '../../layouts/Base.astro';
---

<Base title="Your shortlist" description="Activities you have saved in this browser." seo={ { robots: 'noindex, follow' } }>
	<main class="eca-shell">
		<nav class="eca-subnav" aria-label="ECA sections">
			<a href="/activities/">All activities</a>
			<a href="/calendar/">Calendar</a>
			<a href="/terms/">Term dates</a>
			<a href="/help/">Help</a>
		</nav>
		<h1>Your shortlist</h1>
		<p class="eca-lede">
			Saved in this browser only — nothing is sent to the school until you book on the booking system.
		</p>

		<ul class="eca-grid" data-eca-shortlist-list></ul>
		<p class="eca-empty" data-eca-shortlist-empty hidden>
			Nothing saved yet. Use “Save” on any activity to build a shortlist.
		</p>
	</main>

	<script>
		import { SHORTLIST_EVENT, readShortlist, writeShortlist } from '../../lib/eca-shortlist.ts';

		interface IndexEntry {
			slug: string;
			title: string;
			url: string;
			day: string;
			start: string | null;
			venue: string | null;
			price: string | null;
			free: boolean;
		}

		const list = document.querySelector< HTMLUListElement >( '[data-eca-shortlist-list]' );
		const empty = document.querySelector< HTMLElement >( '[data-eca-shortlist-empty]' );

		const render = async (): Promise< void > => {
			if ( ! list || ! empty ) return;
			const slugs = readShortlist();
			list.replaceChildren();
			empty.hidden = slugs.length > 0;
			if ( slugs.length === 0 ) return;

			let index: IndexEntry[] = [];
			try {
				const response = await fetch( '/eca-index.json' );
				if ( response.ok ) index = ( await response.json() ) as IndexEntry[];
			} catch {
				index = [];
			}
			const bySlug = new Map( index.map( ( entry ) => [ entry.slug, entry ] ) );

			for ( const slug of slugs ) {
				const entry = bySlug.get( slug );
				const item = document.createElement( 'li' );
				item.className = 'eca-card eca-shortlist-item';

				const link = document.createElement( 'a' );
				link.className = 'eca-card__link';
				link.href = entry ? entry.url : '/activities/' + slug + '/';
				const title = document.createElement( 'strong' );
				title.textContent = entry ? entry.title : slug;
				link.append( title );
				if ( entry ) {
					const meta = document.createElement( 'span' );
					meta.className = 'eca-card__meta';
					meta.textContent = [ entry.day, entry.start ?? '', entry.venue ?? '', entry.free ? 'Free' : entry.price ?? '' ]
						.filter( Boolean )
						.join( ' · ' );
					link.append( meta );
				}

				const remove = document.createElement( 'button' );
				remove.type = 'button';
				remove.className = 'eca-save';
				remove.textContent = 'Remove';
				remove.setAttribute( 'aria-pressed', 'true' );
				remove.addEventListener( 'click', () => {
					writeShortlist( readShortlist().filter( ( value ) => value !== slug ) );
					void render();
				} );

				item.append( link, remove );
				list.append( item );
			}
		};

		void render();
		window.addEventListener( SHORTLIST_EVENT, () => void render() );
	</script>
</Base>
`;
}

function helpPage(): string {
	return `---
// /help/ — how booking works, who to ask, and the questions the school actually
// gets asked. The answers come from the site config, not from this template.
import Base from '../../layouts/Base.astro';
import BookingBanner from '../../components/eca/BookingBanner.astro';
import { ecaConfig } from '../../lib/eca.generated.ts';

const contacts = ecaConfig.contacts ?? [];
const faqs = ecaConfig.faqs ?? [];
const url = ecaConfig.booking?.url ?? null;
const label = ecaConfig.booking?.label ?? 'Book';
---

<Base title="How booking works" description="How to book an activity, who to contact, and what happens after the booking window.">
	<main class="eca-shell eca-help" data-pagefind-body>
		<nav class="eca-subnav" aria-label="ECA sections">
			<a href="/activities/">All activities</a>
			<a href="/calendar/">Calendar</a>
			<a href="/terms/">Term dates</a>
			<a href="/shortlist/">Shortlist</a>
		</nav>
		<h1>How booking works</h1>
		<BookingBanner />

		<section>
			<h2>Four steps</h2>
			<ol class="eca-steps">
				<li>Shortlist what you like here, then log in to the booking system with your registered email address.</li>
				<li>Choose activities for the term. Some are allocated by preference, others first come, first served.</li>
				<li>Allocations are published after the booking window closes, and confirmations arrive through the system.</li>
				<li>Activities begin on the first day of the term and run weekly until the term ends, except during closures.</li>
			</ol>
			{ url && <p><a class="eca-cta" href={ url } rel="noopener">{ label }</a></p> }
		</section>

		{ faqs.length > 0 && (
			<section>
				<h2>Questions</h2>
				<dl class="eca-faq">
					{ faqs.map( ( entry ) => (
						<>
							<dt>{ entry.question }</dt>
							<dd>{ entry.answer }</dd>
						</>
					) ) }
				</dl>
			</section>
		) }

		{ contacts.length > 0 && (
			<section>
				<h2>Who to contact</h2>
				{/*
				 * Cloudflare's Email Address Obfuscation rewrites a mailto anchor's href and
				 * the address text, then restores them with JS — every address reads
				 * "[email protected]" without it, which is the opposite of what a help page
				 * is for. These markers are Cloudflare's documented opt-out.
				 */}
				<!--email_off-->
				<ul class="eca-contacts">
					{ contacts.map( ( contact ) => (
						<li>
							<strong>{ contact.label }</strong>
							{ contact.role && <span> — { contact.role }</span> }
							{ contact.email && <span> · <a href={ 'mailto:' + contact.email }>{ contact.email }</a></span> }
						</li>
					) ) }
				</ul>
				<!--/email_off-->
			</section>
		) }
	</main>
</Base>
`;
}
