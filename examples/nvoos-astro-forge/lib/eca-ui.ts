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
import { buildFacets, filterEca, normalizeEca } from '../forge/eca.ts';
import type { EcaFacets, EcaFilter, EcaRecord, RawEcaPost } from '../forge/eca.ts';
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
				getCollection( ${ JSON.stringify( slug ) } as CollectionKey ),
			] );
			const records = entries
				.map( ( entry ) => {
					const data = entry.data as Record< string, unknown >;
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
`;

// ---------------------------------------------------------------------------
// Components
// ---------------------------------------------------------------------------

function activityCard(): string {
	return `---
// ActivityCard — one activity, rendered server-side. The data-* attributes are
// the island's contract; a card with no JS still reads perfectly.
import { ecaBasePath } from '../../lib/eca.generated.ts';
import { bookingLabel, formatClock } from '../../forge/eca.ts';
import type { EcaRecord } from '../../forge/eca.ts';

interface Props {
	record: EcaRecord;
}

const { record } = Astro.props;
const href = ecaBasePath + '/' + record.slug + '/';
const days = record.schedule.days.length > 0 ? record.schedule.days.join( ' & ' ) : null;
const start = formatClock( record.schedule.start );
const end = formatClock( record.schedule.end );
const when = days && start ? ( end ? days + ' · ' + start + ' – ' + end : days + ' · ' + start ) : days ?? 'Schedule to be confirmed';
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
	class="eca-card"
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
				const pad = ( n ) => String( n ).padStart( 2, '0' );
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
}

const { title, intro, records, facets, active, showFacets = true, total } = Astro.props;
const yearValues = facets.yearGroup.map( ( entry ) => entry.value );
---

<section class="eca-shell">
	<h1>{ title }</h1>
	{ intro && <p class="eca-lede">{ intro }</p> }
	{ active && (
		<p class="eca-count">
			Showing { records.length } of { total ?? facets.total } · <a href={ ecaBasePath + '/' }>all activities</a>
		</p>
	) }

	<div class="eca-layout">
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
			<ul class="eca-grid" data-eca-grid>
				{ records.map( ( record ) => <ActivityCard record={ record } /> ) }
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
		const rail = document.querySelector( '[data-eca-facets]' );
		const grid = document.querySelector( '[data-eca-grid]' );
		if ( rail && grid ) {
			const cards = Array.prototype.slice.call( grid.querySelectorAll( '[data-eca-card]' ) );
			const empty = document.querySelector( '[data-eca-empty]' );
			const count = document.querySelector( '[data-eca-result-count]' );
			const year = rail.querySelector( '[data-eca-year]' );
			const search = rail.querySelector( '[data-eca-search]' );

			const checked = ( name ) =>
				Array.prototype.slice
					.call( rail.querySelectorAll( '[data-eca-group="' + name + '"] input:checked' ) )
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
					const read = ( name ) => ( card.getAttribute( 'data-' + name ) || '' ).split( ' ' ).filter( Boolean );
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
			const reset = document.querySelector( '[data-eca-reset]' );
			if ( reset ) {
				reset.addEventListener( 'click', () => {
					rail.reset();
					apply();
				} );
			}
			apply();
		}
	</script>
) }
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
const itemList = {
	'@context': 'https://schema.org',
	'@type': 'ItemList',
	name: 'Activities',
	numberOfItems: records.length,
	itemListElement: records.slice( 0, 50 ).map( ( record, index ) => ( {
		'@type': 'ListItem',
		position: index + 1,
		name: record.title,
		url: new URL( record.url, Astro.site ?? Astro.url ).toString(),
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
		? { offers: { '@type': 'Offer', price: record.pricing.amount, priceCurrency: record.pricing.currency ?? 'LKR' } }
		: {} ),
	...( days
		? {
				hasCourseInstance: {
					'@type': 'CourseInstance',
					courseMode: 'onsite',
					...( record.venue ? { location: { '@type': 'Place', name: record.venue } } : {} ),
					...( record.schedule.start
						? {
								courseSchedule: {
									'@type': 'Schedule',
									byDay: record.schedule.days.map( ( day ) => 'https://schema.org/' + day ),
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
