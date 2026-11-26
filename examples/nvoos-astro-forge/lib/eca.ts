// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// eca.ts — the ECA front-end format `nvoos-eca/v1` (Proposal 163, Phase 0).
//
// The ECA toolkit stores an activity across three planes that disagree:
//
//   meta    `_eca_*` — authoritative, but only when `_fields` asks for `meta`
//           AND the site registers the keys with `show_in_rest`. On
//           eca.directory (105 records, 2026-11-24) they ARE exposed.
//   prose   the `content.rendered` block — the fallback plane. Imported
//           booklets put a labelled key/value stack in one <p>
//           (`ECA Day & Time: … Year Groups: … Venue: … Capacity: …`), and
//           titles carry shorthand (`Sailing (Y4-6 Sunday)`).
//   taxonomy `mcp_ai_eca_category` — sparse. 44 of 105 records are
//           uncategorised, so it can never be the primary browse facet.
//
// This module normalises all three into one record and records EVERY
// derivation in `inferred[]`, so a site owner can see what the forge asserted
// on its behalf. Nothing here invents a fact silently: an unknown stays
// `null` and the UI renders an explicit unknown state.
//
// Measured live distribution the defaults are tuned against:
//   _eca_type        club 42 · activity 21 · sport_academy 15 · society 14 · sport_squad 13
//   _eca_booking_type preference_based 48 · first_come_first_served 46 · preselected 6 · signup 5
//   _eca_cost_period  "" · term · year · month        (NOT per_term/per_year)
//   _eca_year_groups  empty for 105/105               (prose/title only)
//   _eca_is_paid      null for 105/105                (`_eca_cost > 0` is the signal)
//   stage             83/105 derivable; 22 unknown     (must stay browseable)
//
// Erasable-only TS (no enums/namespaces): forge.mjs imports this file under
// Node native type stripping.
// ---------------------------------------------------------------------------

/** One derivation the forge made, so the UI can badge it and the owner audit it. */
export interface EcaInference {
	/** Record field the value landed in (`stage`, `pricing.amount`, …). */
	field: string;
	/** The value asserted, stringified for display. */
	value: string;
	/** The rule that produced it — stable identifiers, not prose. */
	rule: string;
}

/** A taxonomy term the activity belongs to. */
export interface EcaCategoryRef {
	id: number;
	name: string;
	slug: string;
}

/** Parsed schedule. Absent fields mean "unknown", never zero. */
export interface EcaSchedule {
	/** The primary day (`Monday`…`Sunday`), or null when unknown. */
	day: string | null;
	/**
	 * EVERY day the activity runs, primary first.
	 *
	 * An activity can meet twice a week — the source booklet's tables read
	 * "Wednesday & Thursday" — and a single-day model silently drops half the
	 * timetable. `day` stays the primary for sorting and landings.
	 */
	days: string[];
	/** `HH:MM` 24-hour, or null. */
	start: string | null;
	/** `HH:MM` 24-hour, or null. */
	end: string | null;
	/** Inclusive start, minutes from midnight — the sort key. */
	startMinutes: number | null;
	endMinutes: number | null;
	durationMinutes: number | null;
	/** Config `timeBands` id (`after-school`, `lunch`, …), or null. */
	timeOfDay: string | null;
}

/** Money. `paid` is the only field always known. */
export interface EcaPricing {
	paid: boolean;
	amount: number | null;
	currency: string | null;
	/** Normalised period: `term` | `year` | `month` | `one-time` | null. */
	period: string | null;
	/** Human string (`Rs. 22,500 per term`) or null when free/unknown. */
	display: string | null;
}

/** Places left, as far as the build knows. */
export interface EcaCapacity {
	/** `_eca_max_students`, or null when unset/0. */
	max: number | null;
	enrolled: number | null;
	waitlisted: number | null;
	/**
	 * `open` | `full` | `waitlist` | `unknown`. D2 (Proposal 163): the static
	 * tier knows only what the build saw, so `asOf` travels with the verdict.
	 */
	state: 'open' | 'full' | 'waitlist' | 'unknown';
	/** ISO timestamp of the count, or null when nothing was counted. */
	asOf: string | null;
}

/** How a family actually gets a place. */
export interface EcaBooking {
	/** Normalised: `first-come` | `preference` | `invite` | `signup` | `audition` | `other`. */
	type: string;
	/** The untouched source value (`first_come_first_served`), for debugging. */
	rawType: string | null;
	requiresAudition: boolean;
}

/** The `nvoos-eca/v1` record. */
export interface EcaRecord {
	id: number;
	/** `_eca_code`, normalised to `ECA-<n>` where the source is bare. */
	code: string | null;
	slug: string;
	url: string;
	title: string;
	/** Plain-text one-liner for cards, derived from the body. */
	summary: string;
	/** Body HTML with import commentary stripped; safe to render. */
	descriptionHtml: string;
	/** Plain text of `descriptionHtml`, for search and word counts. */
	descriptionText: string;
	categories: EcaCategoryRef[];
	/** `_eca_type` — the complete browse facet. */
	type: string | null;
	/** Config stage ids this activity belongs to (empty when unknown). */
	stages: string[];
	/** Inclusive year range `[lo, hi]`, or null when unknown. */
	yearGroups: { from: number; to: number } | null;
	schedule: EcaSchedule;
	venue: string | null;
	/** Named external provider, or null for a school-run activity. */
	provider: string | null;
	staff: string[];
	capacity: EcaCapacity;
	pricing: EcaPricing;
	booking: EcaBooking;
	/** `_eca_status`, defaulted to `active`. */
	status: string;
	media: { id: number | null; alt: string | null };
	links: { ics: string | null };
	/** Where the record came from — the CMS origin, never the public origin. */
	source: { origin: string; restBase: string; fetchedAt: string | null };
	inferred: EcaInference[];
}

/** One stage template (`junior-school`, `senior-school`) — CONFIG, not code. */
export interface ForgeEcaStage {
	/** URL id — `/stage/<id>/`. */
	id: string;
	label: string;
	/** Inclusive year range that maps INTO this stage. */
	yearGroups: { from: number; to: number };
	/** Case-insensitive tokens that identify the stage in a title or slug. */
	match: string[];
	/** One-line intro rendered above the catalogue. */
	blurb?: string;
}

/** A named time window (`lunch`, `after-school`) for filtering and the timetable. */
export interface ForgeEcaTimeBand {
	id: string;
	label: string;
	/** Inclusive, minutes from midnight. */
	from: number;
	to: number;
}

/** A week (or range) the school is closed inside a term — half-term, a holiday. */
export interface ForgeEcaClosure {
	/** `YYYY-MM-DD`, inclusive. */
	from: string;
	/** `YYYY-MM-DD`, inclusive. */
	to: string;
	label?: string;
}

/** A school term — the ICS recurrence horizon and the availability anchor. */
export interface ForgeEcaTerm {
	id: string;
	label: string;
	/** `YYYY-MM-DD` — the first day an activity can run (not the term opening). */
	start: string;
	/**
	 * `YYYY-MM-DD`, inclusive. OPTIONAL: without it the feed recurs indefinitely.
	 *
	 * An open-ended RRULE is honest ("weekly until the school says otherwise") and
	 * far better than withholding the feed, which is what the first cut did.
	 */
	end?: string;
	/** Closed weeks inside the term — emitted as EXDATE, so half-term is empty. */
	closures?: ForgeEcaClosure[];
}

/** The booking window a family actually plans around. */
export interface ForgeEcaBookingWindow {
	/** `YYYY-MM-DDTHH:MM` in the site timezone. */
	opens: string;
	/** `YYYY-MM-DDTHH:MM` in the site timezone. */
	closes: string;
	/** When confirmed allocations become visible. */
	allocations?: string;
	/** Free text rendered under the banner (payments, who to contact). */
	note?: string;
}

/**
 * The `profile.eca` block. Every field is optional: absent means the defaults
 * below, which were measured against eca.directory.
 */
export interface ForgeEca {
	/** Catalogue URL prefix. Default `/activities`. */
	basePath?: string;
	schoolName?: string;
	/** IANA zone for ICS + display. Default `UTC`. */
	timezone?: string;
	/** ISO 4217. Default `LKR`. */
	currency?: string;
	/** Display symbol. Default `Rs.`. */
	currencySymbol?: string;
	terms?: ForgeEcaTerm[];
	stages?: ForgeEcaStage[];
	/** Canonical day order for facets and the timetable. */
	days?: string[];
	timeBands?: ForgeEcaTimeBand[];
	/**
	 * The registration hand-off (D1). `inline` renders the school's own form,
	 * `external` links out, `woo` adds to the Woo cart, `laravel` posts to the
	 * app's own flow. v1 ships `external` with `url` per activity or a template.
	 */
	booking?: {
		mode?: 'inline' | 'external' | 'woo' | 'laravel';
		/** `{slug}` / `{id}` / `{code}` are substituted. Wins over `url`. */
		urlTemplate?: string;
		/**
		 * One URL for every activity — the shape a school booking platform
		 * (SOCS, SchoolsBuddy) forces, since it exposes a single parent login and
		 * no per-activity deep link.
		 */
		url?: string;
		label?: string;
		/** Rendered on paid activities, e.g. "fees are settled with the provider". */
		note?: string;
		window?: ForgeEcaBookingWindow;
	};
	/** Rename a venue without editing content (`'SS 18'` → `'Senior School, Room 18'`). */
	venueAliases?: Record< string, string >;
	/**
	 * Who to contact, rendered on `/help/`. A school's real answer is a named
	 * person and an address, not a contact form, so the config carries both.
	 */
	contacts?: Array< {
		label: string;
		role?: string;
		email?: string;
	} >;
	/** Questions answered on `/help/` — booking, fees, absences. */
	faqs?: Array< {
		question: string;
		answer: string;
	} >;
}

/** A category term as the site serves it — id, display name, slug. */
export type EcaTermMap = EcaCategoryRef[];

/** The shape `wp/v2/mcp_ai_eca` returns for one record (fields we consume). */
export interface RawEcaPost {
	id: number;
	slug: string;
	link?: string;
	date?: string;
	status?: string;
	featured_media?: number;
	title?: { rendered?: string } | string;
	content?: { rendered?: string } | string;
	excerpt?: { rendered?: string } | string;
	meta?: Record< string, unknown >;
	mcp_ai_eca_category?: number[];
}

/** Normaliser inputs that are not per-record. */
export interface EcaNormaliseContext {
	/** WordPress origin (`https://eca.directory`), no trailing slash. */
	origin: string;
	/**
	 * The public origin the rebuilt site serves from. Defaults to `origin`.
	 *
	 * The two-origin deploy (Proposal 135) keeps the CMS on the real domain
	 * while the forged site is previewed on a Velocity node URL, so `url` must
	 * follow the PUBLIC origin while `source.origin` keeps the CMS one.
	 */
	publicOrigin?: string;
	/** REST base (`wp/v2/mcp_ai_eca`). */
	restBase: string;
	config?: ForgeEca;
	/** Taxonomy terms, for `mcp_ai_eca_category` id → name. */
	terms?: EcaTermMap;
	/** When the fetch happened — recorded as `source.fetchedAt`. */
	fetchedAt?: string;
	/** Enrolment counts by activity id, when a build could see them (D2). */
	counts?: Record< number, { enrolled?: number; waitlisted?: number } >;
}

export const ECA_DAY_ORDER = [
	'Monday',
	'Tuesday',
	'Wednesday',
	'Thursday',
	'Friday',
	'Saturday',
	'Sunday',
] as const;

const DAY_ALIASES: Record< string, string > = {
	mon: 'Monday',
	monday: 'Monday',
	tue: 'Tuesday',
	tues: 'Tuesday',
	tuesday: 'Tuesday',
	wed: 'Wednesday',
	weds: 'Wednesday',
	wednesday: 'Wednesday',
	thu: 'Thursday',
	thur: 'Thursday',
	thurs: 'Thursday',
	thursday: 'Thursday',
	fri: 'Friday',
	friday: 'Friday',
	sat: 'Saturday',
	saturday: 'Saturday',
	sun: 'Sunday',
	sunday: 'Sunday',
};

/** Defaults, measured against eca.directory. */
export const ECA_DEFAULTS = {
	basePath: '/activities',
	timezone: 'UTC',
	currency: 'LKR',
	currencySymbol: 'Rs.',
	days: [ ...ECA_DAY_ORDER ] as string[],
	timeBands: [
		// Tuned to the live school day: lunch ECAs run 12:00–12:40, after-school
		// ECAs start 13:45. A 14:00 lunch boundary would file every after-school
		// activity under lunchtime.
		{ id: 'before-school', label: 'Before school', from: 0, to: 7 * 60 + 30 },
		{ id: 'morning', label: 'Morning', from: 7 * 60 + 30, to: 11 * 60 + 30 },
		{ id: 'lunch', label: 'Lunchtime', from: 11 * 60 + 30, to: 13 * 60 + 30 },
		{ id: 'after-school', label: 'After school', from: 13 * 60 + 30, to: 17 * 60 },
		{ id: 'evening', label: 'Evening', from: 17 * 60, to: 24 * 60 },
	] as ForgeEcaTimeBand[],
} as const;

// ---------------------------------------------------------------------------
// Text helpers
// ---------------------------------------------------------------------------

const NAMED_ENTITIES: Record< string, string > = {
	amp: '&',
	lt: '<',
	gt: '>',
	quot: '"',
	apos: "'",
	nbsp: ' ',
	hellip: '…',
	ndash: '–',
	mdash: '—',
	lsquo: '‘',
	rsquo: '’',
	ldquo: '“',
	rdquo: '”',
};

/**
 * Decode the entities WordPress actually emits.
 *
 * Numeric entities are the common case (`&#8211;` for an en dash,
 * `&#8217;` for a curly apostrophe) — they appear in `title.rendered` too, so
 * a card title can arrive as `SS Lion&#8217;s Mane`.
 */
export function htmlDecode( raw: string ): string {
	return String( raw ?? '' )
		.replace( /&#x([0-9a-f]+);/gi, ( _, hex: string ) => String.fromCodePoint( parseInt( hex, 16 ) ) )
		.replace( /&#(\d+);/g, ( _, dec: string ) => String.fromCodePoint( Number( dec ) ) )
		.replace( /&([a-z][a-z0-9]*);/gi, ( match, name: string ) => NAMED_ENTITIES[ name.toLowerCase() ] ?? match );
}

/** Strip tags and collapse whitespace — the plain-text plane. */
export function toPlainText( html: string ): string {
	return htmlDecode( String( html ?? '' ).replace( /<(script|style)[\s\S]*?<\/\1>/gi, ' ' ).replace( /<br\s*\/?>/gi, ' ' ).replace( /<\/(p|div|li|h[1-6])>/gi, ' ' ).replace( /<[^>]+>/g, ' ' ) )
		.replace( /\s+/g, ' ' )
		.trim();
}

/**
 * The LINE-preserving plane, for the labelled key/value stack.
 *
 * `toPlainText` collapses newlines, which makes a label regex over-capture:
 * `Venue: Room 23 Capacity: maximum 20 students` arrives as one run. The
 * source's `<br />` separators are the label boundaries, so they must survive
 * as `\n` for `readLabel` to stop at the right place.
 */
export function toLabelText( html: string ): string {
	return htmlDecode(
		String( html ?? '' )
			.replace( /<(script|style)[\s\S]*?<\/\1>/gi, ' ' )
			.replace( /<br\s*\/?>/gi, '\n' )
			.replace( /<\/(p|div|li|h[1-6]|tr)>/gi, '\n' )
			.replace( /<[^>]+>/g, ' ' ),
	)
		.replace( /[ \t]+/g, ' ' )
		.replace( / *\n */g, '\n' )
		.replace( /\n{2,}/g, '\n' )
		.trim();
}

/**
 * Remove editorial commentary that leaked in from the source booklet.
 *
 * eca.directory record `11155` publishes
 * `[Note: the remainder of this entry in the source booklet (day, time,
 * venue, capacity and teacher-in-charge) was not readable in the supplied PDF
 * and still needs to be confirmed.]` — an internal to-do, not site copy.
 */
export function stripImportNotes( html: string ): { html: string; stripped: number } {
	let stripped = 0;
	const out = String( html ?? '' ).replace( /\[Note:[\s\S]*?\]/gi, () => {
		stripped++;
		return '';
	} );
	return { html: out, stripped };
}

// ---------------------------------------------------------------------------
// Clock parsing
// ---------------------------------------------------------------------------

/**
 * Parse the clock formats the toolkit and its imports produce.
 *
 * Live meta uses `h:mm AM/PM` ("2:45 PM"); the schema documents `HH:MM`; the
 * prose block writes "2:45 p.m." and sometimes "14:45". All four parse.
 */
export function parseClockTime( raw: unknown ): { minutes: number; hhmm: string } | null {
	if ( raw === null || raw === undefined ) return null;
	const text = String( raw ).trim();
	if ( ! text ) return null;

	const m = text.match( /^(\d{1,2})[:.](\d{2})\s*(a\.?m\.?|p\.?m\.?)?$/i );
	if ( ! m ) return null;

	let hours = Number( m[ 1 ] );
	const minutes = Number( m[ 2 ] );
	const suffix = ( m[ 3 ] ?? '' ).replace( /\./g, '' ).toLowerCase();

	if ( minutes > 59 ) return null;

	if ( suffix === 'am' || suffix === 'pm' ) {
		if ( hours < 1 || hours > 12 ) return null;
		hours = ( hours % 12 ) + ( suffix === 'pm' ? 12 : 0 );
	} else if ( hours > 23 ) {
		return null;
	}

	const total = hours * 60 + minutes;
	return { minutes: total, hhmm: pad2( Math.floor( total / 60 ) ) + ':' + pad2( total % 60 ) };
}

function pad2( n: number ): string {
	return n < 10 ? '0' + String( n ) : String( n );
}

const WEEKDAY_ABBR = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
const MONTH_ABBR = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];
const MONTH_FULL = [
	'January',
	'February',
	'March',
	'April',
	'May',
	'June',
	'July',
	'August',
	'September',
	'October',
	'November',
	'December',
];

/** `2026, 9` → `September 2026`, for a calendar caption. */
export function monthLabel( year: number, month: number ): string {
	const name = MONTH_FULL[ month - 1 ];
	return name ? name + ' ' + year : String( year );
}

/**
 * `14:45` → `2:45 pm`. The school's own copy writes "2:45 p.m."; this is the
 * compact card form and stays unambiguous without the dots.
 */
export function formatClock( hhmm: string | null ): string | null {
	if ( ! hhmm ) return null;
	const match = /^(\d{2}):(\d{2})$/.exec( hhmm );
	if ( ! match ) return null;
	const hours = Number( match[ 1 ] );
	const suffix = hours < 12 ? 'am' : 'pm';
	const hour12 = hours % 12 === 0 ? 12 : hours % 12;
	return hour12 + ':' + match[ 2 ] + ' ' + suffix;
}

/** Resolve minutes-from-midnight to a config time-band id. */
export function timeBandFor( minutes: number | null, bands: ForgeEcaTimeBand[] = [ ...ECA_DEFAULTS.timeBands ] ): string | null {
	if ( minutes === null ) return null;
	for ( const band of bands ) {
		if ( minutes >= band.from && minutes < band.to ) return band.id;
	}
	return null;
}

/** Canonical English day name from `Tuesday`, `tue`, `TUESDAY`. */
export function canonicalDay( raw: unknown ): string | null {
	if ( raw === null || raw === undefined ) return null;
	const key = String( raw ).trim().toLowerCase();
	if ( ! key ) return null;
	return DAY_ALIASES[ key ] ?? null;
}

/**
 * Every day named in a separated run — `Wednesday & Thursday`, `Mon/Tue` — and
 * every day inside a RANGE — `Monday to Friday`, `Mon–Fri`.
 *
 * The source booklet's schedule tables carry two-day rows, and a single-day
 * model silently drops half the timetable. A range is the same failure one
 * level down: `ECA Day & Time: Monday to Friday` is the record for
 * `SS Lion's Mane` (id 11153) in the live CMS, and reading its leading word
 * alone put a five-day newsletter on the Monday page only — the splitter below
 * cuts on `& , / + and`, none of which appear in `Monday to Friday`.
 */
export function parseDays( raw: unknown ): string[] {
	if ( raw === null || raw === undefined ) return [];
	const text = String( raw ).trim();
	if ( ! text ) return [];

	const out: string[] = [];
	const add = ( day: string | null ): void => {
		if ( day && ! out.includes( day ) ) out.push( day );
	};

	// A RANGE first, so `Monday to Friday` expands to the run rather than to its
	// first word. Two day words with only whitespace and a range word between
	// them — a time (`Tuesday 2:45 p.m. – 3:30 p.m.`) never matches, because the
	// clock digits break the pattern.
	const range = /\b([A-Za-z]+)\s*(?:to|through|thru|until|till|[–—-])\s*([A-Za-z]+)\b/i.exec( text );
	if ( range ) {
		const from = canonicalDay( range[ 1 ] );
		const to = canonicalDay( range[ 2 ] );
		if ( from && to ) {
			const start = ECA_DAY_ORDER.indexOf( from as ( typeof ECA_DAY_ORDER )[ number ] );
			const end = ECA_DAY_ORDER.indexOf( to as ( typeof ECA_DAY_ORDER )[ number ] );
			if ( start >= 0 && end >= 0 ) {
				// Inclusive, and wrapping (`Friday to Monday` is a weekend run).
				for ( let index = start; ; index = ( index + 1 ) % ECA_DAY_ORDER.length ) {
					add( ECA_DAY_ORDER[ index ] );
					if ( index === end ) break;
				}
			}
		}
	}

	const parts = text.split( /\s*(?:&|,|\/|\+|\band\b)\s*/i );
	for ( const part of parts ) {
		// A part may be a whole run (`Tuesday 2:45 p.m.`) — take the leading word.
		const word = part.replace( /\s.*$/, '' ).replace( /[^A-Za-z].*$/, '' );
		add( canonicalDay( word ) );
	}
	return out;
}

// ---------------------------------------------------------------------------
// Year groups + stage
// ---------------------------------------------------------------------------

export interface ParsedYearGroups {
	from: number;
	to: number;
	/** The substring that produced the range, for the audit trail. */
	source: string;
	/** `meta` | `label` | `prose` | `title`. */
	rule: string;
	/** Years that could not be expressed as one contiguous range. */
	sparse: number[];
}

const YEAR_RANGE_PATTERNS: Array< { rule: string; re: RegExp }> = [
	// `Year 10 to Year 13`, `Year 1 and Year 2` — the word repeats.
	{ rule: 'prose', re: /\bYears?\s+(\d{1,2})\s*(?:-|–|—|to|&|and)\s*Years?\s+(\d{1,2})\b/i },
	// `Years 7-13`, `Year 3 to 6`, `Years 1 – 2`
	{ rule: 'prose', re: /\bYears?\s+(\d{1,2})\s*(?:-|–|—|to)\s*(\d{1,2})\b/i },
	// `Year 1 and 2`, `Years 3 & 4`
	{ rule: 'prose', re: /\bYears?\s+(\d{1,2})\s*(?:&|and)\s*(\d{1,2})\b/i },
	// `Y4-6`, `Y1–2`
	{ rule: 'title', re: /\bY\s?(\d{1,2})\s*(?:-|–|—|to)\s*(\d{1,2})\b/i },
	// A single year: `Year 5`
	{ rule: 'prose', re: /\bYears?\s+(\d{1,2})\b/i },
	// A single shorthand: `Y5`
	{ rule: 'title', re: /\bY\s?(\d{1,2})\b/i },
];

/**
 * Extract a year range from meta, a `Year Groups:` label line, prose, or a title.
 *
 * `_eca_year_groups` is empty for all 105 live records, so the prose/title
 * paths are not exotic — they are the only signal most records have.
 */
export function parseYearGroups( sources: { meta?: unknown; label?: string; prose?: string; title?: string } ): ParsedYearGroups | null {
	const meta = parseMetaYearGroups( sources.meta );
	if ( meta ) return meta;

	if ( sources.label ) {
		const fromLabel = matchAll( sources.label, 'label' );
		if ( fromLabel ) return fromLabel;
	}

	if ( sources.prose ) {
		const fromProse = matchAll( sources.prose, 'prose' );
		if ( fromProse ) return fromProse;
	}

	if ( sources.title ) {
		for ( const { rule, re } of YEAR_RANGE_PATTERNS ) {
			const m = sources.title.match( re );
			if ( m ) return toRange( m, rule === 'prose' ? 'title' : rule, m[ 0 ] );
		}
	}

	return null;
}

function matchAll( text: string, rule: string ): ParsedYearGroups | null {
	for ( const pattern of YEAR_RANGE_PATTERNS ) {
		// A title-only shorthand must not match prose that merely says "Y4".
		const m = text.match( pattern.re );
		if ( m ) return toRange( m, pattern.rule === 'prose' ? rule : 'title', m[ 0 ] );
	}
	return null;
}

function toRange( m: RegExpMatchArray, rule: string, source: string ): ParsedYearGroups | null {
	const from = Number( m[ 1 ] );
	const to = m[ 2 ] === undefined ? from : Number( m[ 2 ] );
	if ( ! Number.isFinite( from ) || ! Number.isFinite( to ) ) return null;
	if ( from < 1 || to > 14 || from > to ) return null;
	return { from, to, source, rule, sparse: [] };
}

/** `_eca_year_groups` may be an array, a CSV string, or empty — handle all three. */
function parseMetaYearGroups( raw: unknown ): ParsedYearGroups | null {
	if ( raw === null || raw === undefined || raw === '' ) return null;

	const years: number[] = [];
	const push = ( value: unknown ) => {
		const n = Number( value );
		if ( Number.isInteger( n ) && n >= 1 && n <= 14 ) years.push( n );
	};

	if ( Array.isArray( raw ) ) {
		raw.forEach( push );
	} else {
		const text = String( raw );
		// `1-6`, `7 to 13`, `1,2,3`
		const range = text.match( /^\s*(\d{1,2})\s*(?:-|–|—|to)\s*(\d{1,2})\s*$/i );
		if ( range ) {
			const from = Number( range[ 1 ] );
			const to = Number( range[ 2 ] );
			if ( from >= 1 && to <= 14 && from <= to ) {
				return { from, to, source: text.trim(), rule: 'meta', sparse: [] };
			}
		}
		text.split( /[,\s]+/ ).forEach( push );
	}

	if ( years.length === 0 ) return null;
	const sorted = [ ...new Set( years ) ].sort( ( a, b ) => a - b );
	const contiguous = sorted.every( ( y, i ) => i === 0 || y === sorted[ i - 1 ] + 1 );
	if ( contiguous ) {
		return { from: sorted[ 0 ], to: sorted[ sorted.length - 1 ], source: sorted.join( ',' ), rule: 'meta', sparse: [] };
	}
	return { from: sorted[ 0 ], to: sorted[ sorted.length - 1 ], source: sorted.join( ',' ), rule: 'meta', sparse: sorted };
}

const STAGE_PREFIX_TOKENS: Array< { token: RegExp; stage: string } > = [
	{ token: /\bSS\b/, stage: 'senior' },
	{ token: /\bJS\b/, stage: 'junior' },
	{ token: /\bJunior\b/i, stage: 'junior' },
	{ token: /\bPrimary\b/i, stage: 'junior' },
	{ token: /\bSenior\b/i, stage: 'senior' },
];

/** Default stage config — two stages, matching the source's SS / Junior split. */
export const ECA_DEFAULT_STAGES: ForgeEcaStage[] = [
	{
		id: 'junior-school',
		label: 'Junior School',
		yearGroups: { from: 1, to: 6 },
		match: [ 'junior', 'js', 'primary', 'prep', 'foundation' ],
		blurb: 'Activities for Junior School pupils.',
	},
	{
		id: 'senior-school',
		label: 'Senior School',
		yearGroups: { from: 7, to: 13 },
		match: [ 'senior', 'ss', 'secondary', 'sixth form' ],
		blurb: 'Activities for Senior School pupils.',
	},
];

/**
 * Map an activity onto config stages.
 *
 * Order is deliberate: an explicit year range beats a title token (a title can
 * say "SS" while the body says "Year 3 to 6"), and a title token beats the
 * slug prefix as a last resort. Returns an empty array for the 22/105 live
 * records with no signal — the UI shows them under "All stages", never hides
 * them.
 */
export function inferStages( input: {
	yearGroups: { from: number; to: number } | null;
	title: string;
	slug: string;
	stages?: ForgeEcaStage[];
} ): { stages: string[]; rule: string | null } {
	const stages = input.stages && input.stages.length > 0 ? input.stages : ECA_DEFAULT_STAGES;

	if ( input.yearGroups ) {
		const hits = stages.filter( ( stage ) => intersects( input.yearGroups as { from: number; to: number }, stage.yearGroups ) );
		if ( hits.length > 0 ) return { stages: hits.map( ( stage ) => stage.id ), rule: 'year-range' };
	}

	for ( const { token, stage } of STAGE_PREFIX_TOKENS ) {
		const hit = input.title.match( token );
		if ( ! hit ) continue;
		const mapped = stages.find( ( candidate ) => candidate.match.some( ( m ) => m.toLowerCase() === stage || m.toLowerCase() === hit[ 0 ].toLowerCase() ) );
		// No mapping means the operator renamed the stages — return nothing
		// rather than an id that is not in the config (a validate error).
		return mapped ? { stages: [ mapped.id ], rule: 'title-token' } : { stages: [], rule: null };
	}

	const slugPrefix = input.slug.split( '-' )[ 0 ].toLowerCase();
	const bySlug = stages.find( ( stage ) => stage.match.some( ( m ) => m.toLowerCase() === slugPrefix ) );
	if ( bySlug ) return { stages: [ bySlug.id ], rule: 'slug-prefix' };

	return { stages: [], rule: null };
}

function intersects( a: { from: number; to: number }, b: { from: number; to: number } ): boolean {
	return a.from <= b.to && b.from <= a.to;
}

/** The first canonical day name appearing anywhere in a run of text. */
function findDayWord( text: string | null ): string | null {
	return findDayWords( text )[ 0 ] ?? null;
}

/** Every canonical day name appearing anywhere in a run of text, Monday-first. */
function findDayWords( text: string | null ): string[] {
	if ( ! text ) return [];
	const out: string[] = [];
	for ( const day of ECA_DAY_ORDER ) {
		if ( new RegExp( '\\b' + day + '\\b', 'i' ).test( text ) ) out.push( day );
	}
	return out;
}

// ---------------------------------------------------------------------------
// Price, provider, venue
// ---------------------------------------------------------------------------

/** Normalise `_eca_cost_period` — live values are `term`/`year`/`month`/``. */
export function normalizePeriod( raw: unknown ): string | null {
	const key = String( raw ?? '' ).trim().toLowerCase().replace( /^per[_-]/, '' ).replace( /[\s-]+/g, '-' );
	if ( ! key ) return null;
	if ( key === 'term' || key === 'year' || key === 'month' ) return key;
	if ( key === 'one-time' || key === 'onetime' || key === 'once' || key === 'session' ) return 'one-time';
	return key;
}

/**
 * Parse a price out of prose — only used when `_eca_cost` is absent/0.
 *
 * Live prose carries `Rs. 22,500 per student per term`, `LKR 45,000`, and one
 * `free for new students`. The currency symbol is matched first so a stray
 * number ("2:45 p.m.") is never mistaken for money.
 */
export function parseProsePrice( text: string ): { amount: number; currency: string | null; raw: string } | null {
	// The lookbehind is load-bearing: without it `Years 7-13` matches "rs 7" and
	// a free club is published at Rs. 7. Verified on live record 11155.
	const m = text.match( /(?<![A-Za-z])(Rs\.?|LKR|USD|\$|£|€)\s?([\d,]+(?:\.\d{1,2})?)/i );
	if ( ! m ) return null;
	const amount = Number( m[ 2 ].replace( /,/g, '' ) );
	if ( ! Number.isFinite( amount ) || amount <= 0 ) return null;
	const symbol = m[ 1 ];
	const currency = /^rs\.?$/i.test( symbol ) ? 'LKR' : /^lkr$/i.test( symbol ) ? 'LKR' : /^usd$|^\$$/i.test( symbol ) ? 'USD' : null;
	return { amount, currency, raw: m[ 0 ] };
}

/** Format a price for a card (`Rs. 22,500 per term`). */
export function formatPrice( amount: number | null, currencySymbol: string, period: string | null ): string | null {
	if ( amount === null || amount <= 0 ) return null;
	const formatted = amount.toLocaleString( 'en-US' );
	const suffix = period === 'one-time' ? ' one-off' : period ? ' per ' + period : '';
	return currencySymbol + ' ' + formatted + suffix;
}

/**
 * Find an external provider in prose.
 *
 * Live patterns: `external provider Meu Labs`, `External provider at Ceylon
 * Riding Club, Port City, Colombo`, `run by the Mathematics Department`,
 * `led by Sixth Form students`.
 */
export function inferProvider( text: string ): { name: string; external: boolean } | null {
	const external = text.match( /external provider(?:\s+(?:is|at))?\s+([^.;\n]{2,60})/i );
	if ( external ) {
		const name = tidyName( external[ 1 ] );
		if ( name ) return { name, external: true };
	}
	// `run by <X>` catches far more than providers — "led by passionate
	// students" is a description, not an organisation — so a plain-language
	// branch demands an organisational noun.
	const runBy = text.match( /\b(?:run|led|managed|delivered|facilitated|coached)\s+by\s+(?:the\s+)?([A-Z][\w&'’. -]{2,60})/ );
	if ( runBy ) {
		const name = tidyName( runBy[ 1 ] );
		if ( name && ORG_HINT.test( name ) ) return { name, external: false };
	}
	return null;
}

const ORG_HINT = /\b(?:Department|Dept\.?|Club|Society|Academy|School|College|Institute|Centre|Center|Team|Unit|Ltd\.?|Limited|Labs?\.?|Foundation|Association|Trust|Council|Studio)\b/i;

function tidyName( raw: string ): string {
	return raw
		.split( /[,;(]/ )[ 0 ]
		.replace( /\s+(?:for|with|to|and|in|at|per)\b[\s\S]*$/i, '' )
		.replace( /[\s,;:.]+$/, '' )
		.replace( /\s+/g, ' ' )
		.trim();
}

/** Resolve a venue through the operator's alias map, else pass it through. */
export function resolveVenue( raw: unknown, aliases?: Record< string, string > ): string | null {
	const text = String( raw ?? '' ).replace( /\s+/g, ' ' ).trim();
	if ( ! text ) return null;
	if ( aliases ) {
		const hit = aliases[ text ] ?? aliases[ text.toLowerCase() ];
		if ( hit ) return hit;
	}
	return text;
}

// ---------------------------------------------------------------------------
// Booking + capacity
// ---------------------------------------------------------------------------

const BOOKING_MAP: Record< string, string > = {
	first_come_first_served: 'first-come',
	'first-come': 'first-come',
	preference_based: 'preference',
	preference: 'preference',
	preselected: 'invite',
	invite_only: 'invite',
	invite: 'invite',
	signup: 'signup',
	sign_up: 'signup',
	audition: 'audition',
	open: 'signup',
};

/** Normalise the live `_eca_booking_type` values (four of them, not three). */
export function normalizeBookingType( raw: unknown ): { type: string; rawType: string | null } {
	const key = String( raw ?? '' ).trim();
	if ( ! key ) return { type: 'other', rawType: null };
	return { type: BOOKING_MAP[ key.toLowerCase() ] ?? 'other', rawType: key };
}

/** Human label for a booking type, used on the detail page. */
export function bookingLabel( type: string, requiresAudition: boolean ): string {
	if ( requiresAudition ) return 'Audition required';
	switch ( type ) {
		case 'first-come':
			return 'First come, first served';
		case 'preference':
			return 'Preference-based allocation';
		case 'invite':
			return 'Invitation only';
		case 'signup':
			return 'Open sign-up';
		default:
			return 'Ask the school';
	}
}

const BOOKING_MODE_LABEL: Record< string, string > = {
	'first-come': 'Express interest',
	preference: 'Submit preferences',
	invite: 'View invitation',
	signup: 'Sign up',
	audition: 'Book an audition',
	other: 'Contact the school',
};

/** The CTA label for D1's declarative hand-off. */
export function bookingCtaLabel( type: string, requiresAudition: boolean, override?: string ): string {
	if ( override ) return override;
	if ( requiresAudition ) return 'Book an audition';
	return BOOKING_MODE_LABEL[ type ] ?? 'Contact the school';
}

/**
 * Build the registration URL from `profile.eca.booking.urlTemplate`.
 *
 * Tokens: `{slug}`, `{id}`, `{code}`, `{stage}`. An absent template means the
 * activity has no hand-off — the UI must then render the CTA as disabled copy,
 * never a dead link.
 */
export function bookingUrl( record: Pick< EcaRecord, 'id' | 'slug' | 'code' | 'stages' >, config?: ForgeEca ): string | null {
	const booking = config?.booking;
	const template = booking?.urlTemplate;
	if ( template ) {
		const url = template
			.replace( /\{slug\}/g, encodeURIComponent( record.slug ) )
			.replace( /\{id\}/g, String( record.id ) )
			.replace( /\{code\}/g, encodeURIComponent( record.code ?? '' ) )
			.replace( /\{stage\}/g, encodeURIComponent( record.stages[ 0 ] ?? '' ) );
		return /^https?:\/\//i.test( url ) ? url : null;
	}
	// A school booking platform (SOCS, SchoolsBuddy) exposes ONE parent login and
	// no per-activity deep link, so the hand-off is a single URL.
	const fallback = booking?.url;
	return fallback && /^https?:\/\//i.test( fallback ) ? fallback : null;
}

/** The stage of the booking cycle a family is currently in. */
export type EcaBookingPhase = 'before' | 'open' | 'closed' | 'allocations' | 'running' | 'unknown';

export interface EcaBookingState {
	phase: EcaBookingPhase;
	/** Headline for the banner. */
	label: string;
	/** The sentence under it, or null. */
	detail: string | null;
}

/**
 * A `YYYY-MM-DDTHH:MM` stamp in a specific IANA zone.
 *
 * The comparison in `bookingState` is lexicographic, so both sides must be in
 * the SAME zone — a UTC stamp against a Colombo window is 5h30 out.
 */
export function zonedStamp( date: Date, timeZone: string ): string {
	const parts = new Intl.DateTimeFormat( 'en-CA', {
		timeZone,
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
		hour12: false,
	} ).formatToParts( date );
	const get = ( type: string ) => parts.find( ( part ) => part.type === type )?.value ?? '00';
	const hour = get( 'hour' ) === '24' ? '00' : get( 'hour' );
	return get( 'year' ) + '-' + get( 'month' ) + '-' + get( 'day' ) + 'T' + hour + ':' + get( 'minute' );
}

/** `2026-08-26T07:00` → `Wed 26 Aug, 7:00 am`. Deterministic (parsed and formatted as UTC). */
export function formatStamp( stamp: string ): string {
	const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec( stamp.trim() );
	if ( ! match ) return stamp;
	const day = formatDate( match[ 1 ] + '-' + match[ 2 ] + '-' + match[ 3 ] );
	const hours = Number( match[ 4 ] );
	const suffix = hours < 12 ? 'am' : 'pm';
	const hour12 = hours % 12 === 0 ? 12 : hours % 12;
	return day + ', ' + hour12 + ':' + match[ 5 ] + ' ' + suffix;
}

/**
 * Which phase of the booking cycle `now` falls in.
 *
 * `now` must be a `zonedStamp()` for the site's timezone. The window is the one
 * fact a school actually lives by, so the catalogue states it rather than
 * saying "contact us".
 */
export function bookingState( now: string, window?: ForgeEcaBookingWindow, termStart?: string ): EcaBookingState {
	if ( ! window ) {
		return { phase: 'unknown', label: 'Ask about joining', detail: 'Booking dates are not published for this term.' };
	}
	if ( now < window.opens ) {
		return { phase: 'before', label: 'Booking opens ' + formatStamp( window.opens ), detail: 'Browse the catalogue now and shortlist what you like.' };
	}
	if ( now <= window.closes ) {
		return { phase: 'open', label: 'Booking open until ' + formatStamp( window.closes ), detail: window.note ?? null };
	}
	if ( window.allocations && now < window.allocations ) {
		return { phase: 'closed', label: 'Booking closed', detail: 'Allocations are published from ' + formatStamp( window.allocations ) + '.' };
	}
	if ( termStart && now < termStart ) {
		return {
			phase: 'allocations',
			label: 'Allocations published',
			detail: 'Activities begin ' + formatStamp( termStart + 'T00:00' ) + '. Ask the ECA coordinator about spare places.',
		};
	}
	return { phase: 'running', label: 'Term in progress', detail: 'Places may still be available — ask the ECA coordinator.' };
}

// ---------------------------------------------------------------------------
// Normalisation
// ---------------------------------------------------------------------------

function asInt( raw: unknown ): number | null {
	if ( raw === null || raw === undefined || raw === '' ) return null;
	const n = Number( raw );
	return Number.isFinite( n ) ? Math.trunc( n ) : null;
}

function asStringArray( raw: unknown ): string[] {
	if ( raw === null || raw === undefined || raw === '' ) return [];
	if ( Array.isArray( raw ) ) return raw.map( ( v ) => String( v ).trim() ).filter( Boolean );
	return String( raw )
		.split( /[,;]/ )
		.map( ( v ) => v.trim() )
		.filter( Boolean );
}

function rendered( value: { rendered?: string } | string | undefined ): string {
	if ( ! value ) return '';
	if ( typeof value === 'string' ) return value;
	return value.rendered ?? '';
}

/** A summary is the first sentence of the body, clipped on a word boundary. */
export function makeSummary( text: string, limit = 180 ): string {
	const firstPara = text.split( /(?<=[.!?])\s/ )[ 0 ] ?? text;
	const base = firstPara.length > limit ? firstPara.slice( 0, limit ) : firstPara;
	const clipped = base.length < firstPara.length ? base.replace( /\s+\S*$/, '' ) + '…' : base;
	return clipped.trim();
}

/** The labelled key/value stack an imported booklet leaves in the body. */
export function readLabel( text: string, labels: string[] ): string | null {
	for ( const label of labels ) {
		// Anchored to a LINE start: an unanchored `Time:` would match inside
		// `ECA Day & Time:` and return the whole schedule run.
		const re = new RegExp( '(?:^|\\n)[ \\t]*' + label + '\\s*:\\s*([^\\n]{1,80})', 'i' );
		const m = text.match( re );
		if ( m ) {
			// NOT tidyName: a year label is `Year 10 to Year 13`, and tidyName
			// truncates at connectors because provider names end there. A trailing
			// period is KEPT — `Start Time: 2:45 p.m.` needs it to parse.
			const value = m[ 1 ].replace( /[\s,;]+$/, '' ).replace( /\s+/g, ' ' ).trim();
			if ( value ) return value;
		}
	}
	return null;
}

/** `/activities`, `activities`, `activities/` → `/activities`. */
export function normalizeBasePath( raw: string ): string {
	const trimmed = String( raw ?? '' ).trim().replace( /\s+/g, '' );
	if ( ! trimmed ) return ECA_DEFAULTS.basePath;
	const withSlash = trimmed.startsWith( '/' ) ? trimmed : '/' + trimmed;
	return withSlash.replace( /\/+$/, '' ) || ECA_DEFAULTS.basePath;
}

/**
 * Normalise one REST record into `nvoos-eca/v1`.
 *
 * Precedence is meta → labelled prose → free prose → title. Every fallback is
 * appended to `inferred[]` with the rule that produced it.
 */
export function normalizeEca( post: RawEcaPost, ctx: EcaNormaliseContext ): EcaRecord {
	const config = ctx.config ?? {};
	const stages = config.stages && config.stages.length > 0 ? config.stages : ECA_DEFAULT_STAGES;
	const meta = post.meta ?? {};
	const inferred: EcaInference[] = [];

	const rawHtml = rendered( post.content );
	const { html: cleanedHtml, stripped: notesStripped } = stripImportNotes( rawHtml );
	if ( notesStripped > 0 ) {
		inferred.push( { field: 'descriptionHtml', value: 'import-note removed', rule: 'strip-import-note' } );
	}
	const descriptionText = toPlainText( cleanedHtml );
	const labelsText = toLabelText( cleanedHtml );
	const title = htmlDecode( rendered( post.title ) ).replace( /\s+/g, ' ' ).trim();

	// --- schedule -----------------------------------------------------------
	const schedule = normalizeSchedule( meta, labelsText, title, inferred, config );

	// --- year groups + stage ------------------------------------------------
	const parsedYears = parseYearGroups( {
		meta: meta._eca_year_groups,
		label: readLabel( labelsText, [ 'Year Groups?', 'Years?' ] ) ?? undefined,
		prose: descriptionText,
		title,
	} );
	const yearGroups = parsedYears ? { from: parsedYears.from, to: parsedYears.to } : null;
	if ( parsedYears ) {
		inferred.push( {
			field: 'yearGroups',
			value: parsedYears.from + '–' + parsedYears.to,
			rule: 'year-range:' + parsedYears.rule,
		} );
	}
	const stageResult = inferStages( { yearGroups, title, slug: post.slug, stages } );
	if ( stageResult.rule ) {
		inferred.push( { field: 'stages', value: stageResult.stages.join( ',' ), rule: stageResult.rule } );
	}

	// --- pricing ------------------------------------------------------------
	const pricing = normalizePricing( meta, descriptionText, config, inferred );

	// --- provider + venue ---------------------------------------------------
	const providerHit = inferProvider( descriptionText );
	const provider =
		typeof meta._eca_provider === 'string' && meta._eca_provider.trim()
			? String( meta._eca_provider ).trim()
			: providerHit
				? providerHit.name
				: null;
	if ( ! meta._eca_provider && providerHit ) {
		inferred.push( {
			field: 'provider',
			value: providerHit.name,
			rule: providerHit.external ? 'provider:external-prose' : 'provider:led-by-prose',
		} );
	}

	const venueFromProse = readLabel( labelsText, [ 'Venue', 'Location' ] );
	const venue = resolveVenue( meta._eca_venue, config.venueAliases ) ?? resolveVenue( venueFromProse, config.venueAliases );
	if ( ! meta._eca_venue && venueFromProse ) {
		inferred.push( { field: 'venue', value: String( venue ), rule: 'label:venue' } );
	}

	// --- booking ------------------------------------------------------------
	const booking = normalizeBookingType( meta._eca_booking_type );
	const requiresAudition = meta._eca_requires_audition === true || meta._eca_requires_audition === 1 || meta._eca_requires_audition === '1';

	// --- capacity -----------------------------------------------------------
	const counts = ctx.counts?.[ post.id ];
	const capacity = normalizeCapacity(
		asInt( meta._eca_max_students ),
		counts?.enrolled ?? null,
		counts?.waitlisted ?? null,
		ctx.fetchedAt ?? null,
	);

	// --- categories ---------------------------------------------------------
	const terms = ctx.terms ?? [];
	const categories = ( post.mcp_ai_eca_category ?? [] )
		.map( ( id ) => terms.find( ( term ) => term.id === Number( id ) ) )
		.filter( ( term ): term is EcaCategoryRef => Boolean( term ) );

	// --- identity -----------------------------------------------------------
	const rawCode = meta._eca_code === null || meta._eca_code === undefined ? '' : String( meta._eca_code ).trim();
	const code = rawCode ? ( /^ECA-/i.test( rawCode ) ? rawCode.toUpperCase() : 'ECA-' + rawCode ) : null;
	if ( rawCode && code !== rawCode ) {
		inferred.push( { field: 'code', value: String( code ), rule: 'code:normalise' } );
	}

	const basePath = normalizeBasePath( config.basePath ?? ECA_DEFAULTS.basePath );
	const publicOrigin = ( ctx.publicOrigin ?? ctx.origin ).replace( /\/+$/, '' );
	// The PUBLIC url, never the CMS one: the two-origin deploy serves the
	// rebuilt site from a different host while WordPress keeps the records.
	const url = publicOrigin + basePath + '/' + post.slug + '/';

	return {
		id: Number( post.id ),
		code,
		slug: post.slug,
		url,
		title,
		summary: makeSummary( descriptionText ),
		descriptionHtml: cleanedHtml,
		descriptionText,
		categories,
		type: meta._eca_type ? String( meta._eca_type ) : null,
		stages: stageResult.stages,
		yearGroups,
		schedule,
		venue,
		provider,
		staff: asStringArray( meta._eca_teachers ),
		capacity,
		pricing,
		booking: { ...booking, requiresAudition },
		status: meta._eca_status ? String( meta._eca_status ) : 'active',
		media: { id: asInt( post.featured_media ), alt: null },
		links: { ics: basePath + '/' + post.slug + '/schedule.ics' },
		source: { origin: ctx.origin.replace( /\/+$/, '' ), restBase: ctx.restBase, fetchedAt: ctx.fetchedAt ?? null },
		inferred,
	};
}

function normalizeSchedule(
	meta: Record< string, unknown >,
	labels: string,
	title: string,
	inferred: EcaInference[],
	config: ForgeEca,
): EcaSchedule {
	const dayLabelRaw = readLabel( labels, [ 'ECA Day & Time', 'Day' ] );
	// A day may arrive as a run (`Tuesday 2:45 p.m. – 3:30 p.m.`), a list
	// (`Wednesday & Thursday`) or a bare name. Meta wins, then the label, then
	// the title — a title only ever names one day.
	const metaDays = parseDays( meta._eca_day );
	const labelDays = parseDays( dayLabelRaw ); // also accepts `Tuesday 2:45 p.m.`
	const days = metaDays.length > 0 ? metaDays : labelDays.length > 0 ? labelDays : findDayWords( title );

	if ( metaDays.length === 0 && days.length > 0 ) {
		inferred.push( { field: 'schedule.days', value: days.join( ',' ), rule: labelDays.length > 0 ? 'label:day' : 'title:day' } );
	}
	const day = days[ 0 ] ?? null;

	let start = parseClockTime( meta._eca_start_time );
	let end = parseClockTime( meta._eca_end_time );

	if ( ! start ) {
		// `ECA Day & Time: Tuesday 2:45 p.m. – 3:30 p.m.`
		const range = labels.match( /(\d{1,2}[:.]\d{2}\s*(?:a\.?m\.?|p\.?m\.?)?)\s*(?:-|–|—|to)\s*(\d{1,2}[:.]\d{2}\s*(?:a\.?m\.?|p\.?m\.?)?)/i );
		if ( range ) {
			start = parseClockTime( range[ 1 ] );
			end = end ?? parseClockTime( range[ 2 ] );
			if ( start ) {
				inferred.push( { field: 'schedule.start', value: start.hhmm, rule: 'label:time-range' } );
			}
		}
	}
	if ( ! start ) {
		const startLabel = readLabel( labels, [ 'Start Time', 'Starts?' ] );
		start = parseClockTime( startLabel );
	}

	const durationMinutes = start && end ? ( end.minutes - start.minutes + 24 * 60 ) % ( 24 * 60 ) : null;

	return {
		day,
		days,
		start: start?.hhmm ?? null,
		end: end?.hhmm ?? null,
		startMinutes: start?.minutes ?? null,
		endMinutes: end?.minutes ?? null,
		durationMinutes: durationMinutes !== null && durationMinutes > 0 ? durationMinutes : null,
		timeOfDay: timeBandFor( start?.minutes ?? null, config.timeBands ?? [ ...ECA_DEFAULTS.timeBands ] ),
	};
}

function normalizePricing(
	meta: Record< string, unknown >,
	prose: string,
	config: ForgeEca,
	inferred: EcaInference[],
): EcaPricing {
	const symbol = config.currencySymbol ?? ECA_DEFAULTS.currencySymbol;
	const cost = asInt( meta._eca_cost );
	const period = normalizePeriod( meta._eca_cost_period );

	if ( cost !== null && cost > 0 ) {
		return {
			paid: true,
			amount: cost,
			currency: config.currency ?? ECA_DEFAULTS.currency,
			period,
			display: formatPrice( cost, symbol, period ),
		};
	}

	const proseprice = parseProsePrice( prose );
	if ( proseprice ) {
		const prosePeriod = /per\s+(term|year|month)/i.exec( prose )?.[ 1 ]?.toLowerCase() ?? null;
		inferred.push( { field: 'pricing.amount', value: String( proseprice.amount ), rule: 'prose:price' } );
		return {
			paid: true,
			amount: proseprice.amount,
			currency: proseprice.currency ?? config.currency ?? ECA_DEFAULTS.currency,
			period: normalizePeriod( prosePeriod ),
			display: formatPrice( proseprice.amount, symbol, normalizePeriod( prosePeriod ) ),
		};
	}

	return { paid: false, amount: null, currency: null, period: null, display: null };
}

function normalizeCapacity( max: number | null, enrolled: number | null, waitlisted: number | null, asOf: string | null ): EcaCapacity {
	const maxStudents = max !== null && max > 0 ? max : null;
	let state: EcaCapacity['state'] = 'unknown';
	if ( maxStudents !== null && enrolled !== null ) {
		if ( waitlisted !== null && waitlisted > 0 ) state = 'waitlist';
		else if ( enrolled >= maxStudents ) state = 'full';
		else state = 'open';
	}
	return {
		max: maxStudents,
		enrolled,
		waitlisted,
		state,
		asOf: enrolled === null ? null : asOf,
	};
}

// ---------------------------------------------------------------------------
// Facets + filtering
// ---------------------------------------------------------------------------

export interface EcaFacetValue {
	value: string;
	label: string;
	count: number;
}

export interface EcaFacets {
	total: number;
	day: EcaFacetValue[];
	stage: EcaFacetValue[];
	category: EcaFacetValue[];
	type: EcaFacetValue[];
	yearGroup: EcaFacetValue[];
	timeOfDay: EcaFacetValue[];
	availability: EcaFacetValue[];
	price: EcaFacetValue[];
}

export interface EcaFilter {
	day?: string[];
	stage?: string[];
	category?: string[];
	type?: string[];
	/** A single school year, e.g. `5`. */
	year?: number[];
	timeOfDay?: string[];
	availability?: Array< 'open' | 'full' | 'waitlist' | 'unknown' >;
	price?: Array< 'free' | 'paid' >;
	/** Free-text query — matched against title, summary, provider, venue. */
	q?: string;
}

const TYPE_LABELS: Record< string, string > = {
	club: 'Club',
	activity: 'Activity',
	society: 'Society',
	sport_academy: 'Sport academy',
	sport_squad: 'Sport squad',
};

const AVAILABILITY_LABELS: Record< string, string > = {
	open: 'Places available',
	full: 'Full',
	waitlist: 'Waitlist',
	unknown: 'Places not published',
};

/** Count every facet value, keeping the config's order (days, time bands). */
export function buildFacets( records: EcaRecord[], config?: ForgeEca ): EcaFacets {
	const days = config?.days ?? ECA_DEFAULTS.days;
	const bands = config?.timeBands ?? [ ...ECA_DEFAULTS.timeBands ];
	const stages = config?.stages && config.stages.length > 0 ? config.stages : ECA_DEFAULT_STAGES;

	const bucket = (): Map< string, number > => new Map();
	const dayFacet = bucket();
	const stage = bucket();
	const category = bucket();
	const type = bucket();
	const yearGroup = bucket();
	const timeOfDay = bucket();
	const availability = bucket();
	const price = bucket();
	// Term NAMES travel on the records, not in the profile: a slug would render
	// as "Arts Music" where the taxonomy says "Arts & Music".
	const categoryLabels: Record< string, string > = {};

	for ( const record of records ) {
		for ( const day of record.schedule.days ) bump( dayFacet, day );
		for ( const id of record.stages ) bump( stage, id );
		for ( const term of record.categories ) {
			bump( category, term.slug );
			categoryLabels[ term.slug ] = term.name;
		}
		if ( record.type ) bump( type, record.type );
		if ( record.yearGroups ) {
			for ( let year = record.yearGroups.from; year <= record.yearGroups.to; year++ ) bump( yearGroup, String( year ) );
		}
		if ( record.schedule.timeOfDay ) bump( timeOfDay, record.schedule.timeOfDay );
		bump( availability, record.capacity.state );
		bump( price, record.pricing.paid ? 'paid' : 'free' );
	}

	const ordered = ( map: Map< string, number >, order: string[], labels?: Record< string, string > ): EcaFacetValue[] => {
		const out: EcaFacetValue[] = [];
		for ( const value of order ) {
			const count = map.get( value );
			if ( count ) out.push( { value, label: labels?.[ value ] ?? humanise( value ), count } );
		}
		for ( const [ value, count ] of [ ...map.entries() ].sort( ( a, b ) => a[ 0 ].localeCompare( b[ 0 ] ) ) ) {
			if ( ! order.includes( value ) ) out.push( { value, label: labels?.[ value ] ?? humanise( value ), count } );
		}
		return out;
	};

	return {
		total: records.length,
		day: ordered( dayFacet, days ),
		stage: ordered( stage, stages.map( ( entry ) => entry.id ) ),
		category: ordered( category, [], categoryLabels ),
		type: ordered( type, Object.keys( TYPE_LABELS ), TYPE_LABELS ),
		yearGroup: ordered( yearGroup, Array.from( { length: 14 }, ( _, i ) => String( i + 1 ) ), undefined ).map( ( entry ) => ( {
			...entry,
			label: 'Year ' + entry.value,
		} ) ),
		timeOfDay: ordered( timeOfDay, bands.map( ( band ) => band.id ), Object.fromEntries( bands.map( ( band ) => [ band.id, band.label ] ) ) ),
		availability: ordered( availability, [ 'open', 'full', 'waitlist', 'unknown' ], AVAILABILITY_LABELS ),
		price: ordered( price, [ 'free', 'paid' ], { free: 'Free', paid: 'Paid' } ),
	};
}

function bump( map: Map< string, number >, key: string ): void {
	map.set( key, ( map.get( key ) ?? 0 ) + 1 );
}

function humanise( value: string ): string {
	return value.replace( /[-_]+/g, ' ' ).replace( /\b\w/g, ( c ) => c.toUpperCase() );
}

/**
 * Filter records. Values inside one facet OR together, facets AND together —
 * the Baymard expectation (a second click narrows, it never widens).
 */
export function filterEca( records: EcaRecord[], filter: EcaFilter ): EcaRecord[] {
	const query = filter.q?.trim().toLowerCase();
	const haystack = ( record: EcaRecord ): string =>
		[ record.title, record.summary, record.provider ?? '', record.venue ?? '', record.type ?? '', record.code ?? '' ]
			.join( ' ' )
			.toLowerCase();

	return records.filter( ( record ) => {
		if ( filter.day?.length && ! record.schedule.days.some( ( day ) => filter.day?.includes( day ) ) ) return false;
		if ( filter.stage?.length && record.stages.length > 0 && ! filter.stage.some( ( id ) => record.stages.includes( id ) ) ) return false;
		if ( filter.stage?.length && record.stages.length === 0 ) return false;
		if ( filter.category?.length && ! filter.category.some( ( slug ) => record.categories.some( ( term ) => term.slug === slug ) ) ) return false;
		if ( filter.type?.length && ( ! record.type || ! filter.type.includes( record.type ) ) ) return false;
		if ( filter.year?.length ) {
			if ( ! record.yearGroups ) return false;
			if ( ! filter.year.some( ( year ) => year >= ( record.yearGroups as { from: number; to: number } ).from && year <= ( record.yearGroups as { from: number; to: number } ).to ) ) return false;
		}
		if ( filter.timeOfDay?.length && ( ! record.schedule.timeOfDay || ! filter.timeOfDay.includes( record.schedule.timeOfDay ) ) ) return false;
		if ( filter.availability?.length && ! filter.availability.includes( record.capacity.state ) ) return false;
		if ( filter.price?.length && ! filter.price.includes( record.pricing.paid ? 'paid' : 'free' ) ) return false;
		if ( query ) {
			const terms = query.split( /\s+/ ).filter( Boolean );
			const text = haystack( record );
			if ( ! terms.every( ( term ) => text.includes( term ) ) ) return false;
		}
		return true;
	} );
}

// ---------------------------------------------------------------------------
// ICS (RFC 5545)
// ---------------------------------------------------------------------------

const ICS_DAY: Record< string, string > = {
	Sunday: 'SU',
	Monday: 'MO',
	Tuesday: 'TU',
	Wednesday: 'WE',
	Thursday: 'TH',
	Friday: 'FR',
	Saturday: 'SA',
};

/** Escape a TEXT value per RFC 5545 §3.3.11. */
export function escapeIcsText( value: string ): string {
	return String( value )
		.replace( /\\/g, '\\\\' )
		.replace( /;/g, '\\;' )
		.replace( /,/g, '\\,' )
		.replace( /\r?\n/g, '\\n' );
}

/**
 * Fold a content line to 75 octets (RFC 5545 §3.1).
 *
 * Counting must be in UTF-8 octets, not characters — a venue with an en dash
 * would otherwise fold one byte late and bust the limit.
 */
export function foldIcsLine( line: string ): string {
	const encoder = new TextEncoder();
	if ( encoder.encode( line ).length <= 75 ) return line;

	const out: string[] = [];
	let current = '';
	let bytes = 0;
	for ( const char of line ) {
		const size = encoder.encode( char ).length;
		// Continuation lines carry a leading space, so they only get 74.
		const limit = out.length === 0 ? 75 : 74;
		if ( bytes + size > limit ) {
			out.push( current );
			current = char;
			bytes = size;
		} else {
			current += char;
			bytes += size;
		}
	}
	if ( current ) out.push( current );
	return out.join( '\r\n ' );
}

interface Ymd {
	y: number;
	m: number;
	d: number;
}

function parseYmd( value: string ): Ymd | null {
	const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( value.trim() );
	if ( ! m ) return null;
	return { y: Number( m[ 1 ] ), m: Number( m[ 2 ] ), d: Number( m[ 3 ] ) };
}

function ymdToUtc( ymd: Ymd ): Date {
	return new Date( Date.UTC( ymd.y, ymd.m - 1, ymd.d ) );
}

function utcToYmd( date: Date ): Ymd {
	return { y: date.getUTCFullYear(), m: date.getUTCMonth() + 1, d: date.getUTCDate() };
}

function ymdStamp( ymd: Ymd ): string {
	return String( ymd.y ) + pad2( ymd.m ) + pad2( ymd.d );
}

/** The first date on/after `from` that falls on `day`. */
export function firstOccurrence( from: string, day: string ): string | null {
	const start = parseYmd( from );
	if ( ! start ) return null;
	const targetIndex = ECA_DAY_ORDER.indexOf( day as ( typeof ECA_DAY_ORDER )[ number ] );
	if ( targetIndex < 0 ) return null;
	const date = ymdToUtc( start );
	// Date#getUTCDay is Sunday-first; ECA_DAY_ORDER is Monday-first.
	const currentIndex = ( date.getUTCDay() + 6 ) % 7;
	date.setUTCDate( date.getUTCDate() + ( ( targetIndex - currentIndex + 7 ) % 7 ) );
	return stampYmd( utcToYmd( date ) );
}

function stampYmd( ymd: Ymd ): string {
	return String( ymd.y ) + '-' + pad2( ymd.m ) + '-' + pad2( ymd.d );
}

function addDays( ymd: string, days: number ): string {
	const parsed = parseYmd( ymd );
	if ( ! parsed ) return ymd;
	const date = ymdToUtc( parsed );
	date.setUTCDate( date.getUTCDate() + days );
	return stampYmd( utcToYmd( date ) );
}

export interface IcsOptions {
	config?: ForgeEca;
	/** Fixed so builds are reproducible; default is a stable epoch. */
	stamp?: string;
	/** Public URL of the activity page, for `URL:` and `DESCRIPTION`. */
	pageUrl?: string;
}

export const ICS_STABLE_STAMP = '20260101T000000Z';

/**
 * Build a single-activity calendar feed.
 *
 * D2: an activity with no known day/time yields an empty string — a feed with
 * a guessed time is worse than no feed, because families plan around it.
 */
export function buildIcs( record: EcaRecord, options: IcsOptions = {} ): string {
	const config = options.config ?? {};
	const { day, start, end } = record.schedule;
	if ( ! day || ! start ) return '';

	const timezone = config.timezone ?? ECA_DEFAULTS.timezone;
	const stamp = options.stamp ?? ICS_STABLE_STAMP;
	const startCompact = start.replace( ':', '' ) + '00';
	const endCompact = ( end ?? start ).replace( ':', '' ) + '00';

	const terms = ( config.terms ?? [] ).filter( ( term ) => parseYmd( term.start ) );
	const url = options.pageUrl ?? record.url;

	const lines: string[] = [
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//NV oOS//ECA//EN',
		'CALSCALE:GREGORIAN',
		'METHOD:PUBLISH',
		'X-WR-CALNAME:' + escapeIcsText( record.title ),
		'X-WR-TIMEZONE:' + timezone,
	];

	const emit = ( term: ForgeEcaTerm, index: number ) => {
		const first = firstOccurrence( term.start, day );
		if ( ! first ) return;
		const startDate = first.replace( /-/g, '' );
		const uid = record.slug + ( index > 0 ? '-' + index : '' ) + '@' + hostOf( url );
		const days = record.schedule.days.length > 0 ? record.schedule.days : [ day ];
		const byDay = days.map( ( entry ) => ICS_DAY[ entry ] ?? 'MO' ).join( ',' );
		lines.push( 'BEGIN:VEVENT' );
		lines.push( 'UID:' + uid );
		lines.push( 'DTSTAMP:' + stamp );
		lines.push( 'DTSTART;TZID=' + timezone + ':' + startDate + 'T' + startCompact );
		lines.push( 'DTEND;TZID=' + timezone + ':' + startDate + 'T' + endCompact );
		// A bounded term stops at its last day. An unbounded one recurs weekly
		// indefinitely — honest ("weekly until the school says otherwise") and far
		// better than withholding the feed for want of a term end.
		lines.push(
			'RRULE:FREQ=WEEKLY;BYDAY=' + byDay + ( term.end ? ';UNTIL=' + addDays( term.end, 1 ).replace( /-/g, '' ) + 'T000000Z' : '' ),
		);
		// Half-terms and holidays are REAL closures: without EXDATE the calendar
		// shows a club meeting during half-term week.
		const exdates = closureInstances( term, days, startCompact );
		if ( exdates.length > 0 ) lines.push( 'EXDATE;TZID=' + timezone + ':' + exdates.join( ',' ) );
		lines.push( 'SUMMARY:' + escapeIcsText( record.title ) );
		if ( record.venue ) lines.push( 'LOCATION:' + escapeIcsText( record.venue ) );
		if ( url ) lines.push( 'URL:' + url );
		const description = [ record.summary, record.provider ? 'Provider: ' + record.provider : '', record.code ? 'Code: ' + record.code : '' ]
			.filter( Boolean )
			.join( '\n' );
		if ( description ) lines.push( 'DESCRIPTION:' + escapeIcsText( description ) );
		lines.push( 'END:VEVENT' );
	};

	if ( terms.length > 0 ) {
		terms.forEach( ( term, index ) => emit( term, index ) );
	} else {
		// No term configured: the feed is withheld rather than guessed.
		return '';
	}

	lines.push( 'END:VCALENDAR' );
	return lines.map( foldIcsLine ).join( '\r\n' ) + '\r\n';
}

function hostOf( url: string ): string {
	const m = /^https?:\/\/([^/]+)/i.exec( url );
	return m ? m[ 1 ] : 'eca.local';
}

/** The canonical day name for a `YYYY-MM-DD` date. */
function weekdayName( ymd: string ): string | null {
	const parsed = parseYmd( ymd );
	if ( ! parsed ) return null;
	// getUTCDay is Sunday-first; ECA_DAY_ORDER is Monday-first.
	return ECA_DAY_ORDER[ ( ymdToUtc( parsed ).getUTCDay() + 6 ) % 7 ];
}

/**
 * EXDATE values for the term's closed weeks.
 *
 * One value per occurrence INSIDE a closure — an EXDATE must match the
 * recurrence instance exactly, so a two-day activity contributes both days.
 */
export function closureInstances( term: ForgeEcaTerm, days: string[], time: string ): string[] {
	const out: string[] = [];
	for ( const closure of term.closures ?? [] ) {
		if ( ! parseYmd( closure.from ) || ! parseYmd( closure.to ) ) continue;
		let cursor = closure.from;
		// A malformed range must not spin the build.
		for ( let guard = 0; cursor <= closure.to && guard < 400; guard++ ) {
			const weekday = weekdayName( cursor );
			if ( weekday && days.includes( weekday ) ) out.push( cursor.replace( /-/g, '' ) + 'T' + time );
			cursor = addDays( cursor, 1 );
		}
	}
	return out;
}

// ---------------------------------------------------------------------------
// Term calendar + timetable (Proposal 163 §P4)
//
// All of it is pure date maths, deliberately OUTSIDE the Astro pages: a
// calendar that is wrong only in the rendered HTML is untestable, and a wrong
// date here is the kind of error a family plans around.
// ---------------------------------------------------------------------------

/** A term with no end date still needs a bound: half a year is plenty. */
export const MAX_TERM_WEEKS = 26;

/** The label of the closure covering `date`, or null. */
export function closureFor( date: string, term: ForgeEcaTerm ): string | null {
	for ( const closure of term.closures ?? [] ) {
		if ( ! parseYmd( closure.from ) || ! parseYmd( closure.to ) ) continue;
		if ( date >= closure.from && date <= closure.to ) return closure.label ?? 'Closed';
	}
	return null;
}

/**
 * Every date this schedule meets inside a term, closures excluded.
 *
 * One activity yields one series per meeting day (a two-day activity meets
 * twice a week), so the result is de-duplicated and sorted.
 */
export function occurrenceDates( schedule: Pick< EcaSchedule, 'days' >, term: ForgeEcaTerm ): string[] {
	if ( ! parseYmd( term.start ) ) return [];
	const end = term.end && parseYmd( term.end ) ? term.end : addDays( term.start, MAX_TERM_WEEKS * 7 );
	const out: string[] = [];

	for ( const day of schedule.days ) {
		let cursor = firstOccurrence( term.start, day );
		// One guard for a malformed term, one for the open-ended cap.
		for ( let guard = 0; cursor && cursor <= end && guard < MAX_TERM_WEEKS * 2; guard++ ) {
			if ( ! closureFor( cursor, term ) ) out.push( cursor );
			cursor = addDays( cursor, 7 );
		}
	}

	return [ ...new Set( out ) ].sort();
}

/** One activity on one date, trimmed to what a calendar cell renders. */
export interface EcaCalendarItem {
	id: number;
	title: string;
	url: string;
	start: string | null;
	end: string | null;
}

/** One date in the term, with everything meeting that day. */
export interface EcaCalendarEntry {
	/** `YYYY-MM-DD`. */
	date: string;
	/** Closure label when the school is shut that day. */
	closure: string | null;
	items: EcaCalendarItem[];
}

/**
 * The whole term as a date-keyed agenda.
 *
 * Closure days are included even when nothing meets, so half-term renders as a
 * greyed row instead of silently vanishing (the most common school-calendar
 * complaint after "the club is on the wrong day").
 */
export function buildTermCalendar( records: EcaRecord[], term: ForgeEcaTerm ): EcaCalendarEntry[] {
	const map = new Map< string, EcaCalendarEntry >();
	const ensure = ( date: string ): EcaCalendarEntry => {
		const existing = map.get( date );
		if ( existing ) return existing;
		const created: EcaCalendarEntry = { date, closure: closureFor( date, term ), items: [] };
		map.set( date, created );
		return created;
	};

	for ( const record of records ) {
		for ( const date of occurrenceDates( record.schedule, term ) ) {
			ensure( date ).items.push( {
				id: record.id,
				title: record.title,
				url: record.url,
				start: record.schedule.start,
				end: record.schedule.end,
			} );
		}
	}

	for ( const closure of term.closures ?? [] ) {
		if ( ! parseYmd( closure.from ) || ! parseYmd( closure.to ) ) continue;
		let cursor = closure.from;
		for ( let guard = 0; cursor <= closure.to && guard < 400; guard++ ) {
			ensure( cursor );
			cursor = addDays( cursor, 1 );
		}
	}

	return [ ...map.values() ]
		.sort( ( a, b ) => a.date.localeCompare( b.date ) )
		.map( ( entry ) => ( { ...entry, items: entry.items.sort( ( a, b ) => ( a.start ?? 'zz' ).localeCompare( b.start ?? 'zz' ) ) } ) );
}

/**
 * A Monday-first month grid, padded to whole weeks with `null` cells.
 *
 * Monday-first on purpose: the whole format orders days Monday → Sunday, and a
 * calendar that starts on Sunday would put Saturday and Sunday at opposite
 * ends of the row from every other surface on the site.
 */
export function monthGrid( year: number, month: number ): Array< { date: string | null } > {
	const first = new Date( Date.UTC( year, month - 1, 1 ) );
	const offset = ( first.getUTCDay() + 6 ) % 7;
	const daysInMonth = new Date( Date.UTC( year, month, 0 ) ).getUTCDate();
	const cells: Array< { date: string | null } > = [];

	for ( let i = 0; i < offset; i++ ) cells.push( { date: null } );
	for ( let day = 1; day <= daysInMonth; day++ ) cells.push( { date: stampYmd( { y: year, m: month, d: day } ) } );
	while ( cells.length % 7 !== 0 ) cells.push( { date: null } );

	return cells;
}

/** The `{ year, month }` pairs a term spans, inclusive. */
export function monthsBetween( from: string, to: string ): Array< { year: number; month: number } > {
	const start = parseYmd( from );
	const end = parseYmd( to );
	if ( ! start || ! end ) return [];

	const out: Array< { year: number; month: number } > = [];
	let year = start.y;
	let month = start.m;
	while ( year < end.y || ( year === end.y && month <= end.m ) ) {
		out.push( { year, month } );
		month += 1;
		if ( month > 12 ) {
			month = 1;
			year += 1;
		}
	}
	return out;
}

/** `2026-09-08` → `Tue 8 Sep`. Fixed abbreviation tables, never ICU. */
export function formatDate( ymd: string ): string {
	const parsed = parseYmd( ymd );
	if ( ! parsed ) return ymd;
	// A build's output must not change with the runner's ICU version: en-GB
	// renders September as "Sept" on some Node builds and "Sep" on others.
	const weekday = WEEKDAY_ABBR[ new Date( Date.UTC( parsed.y, parsed.m - 1, parsed.d ) ).getUTCDay() ];
	return weekday + ' ' + parsed.d + ' ' + MONTH_ABBR[ parsed.m - 1 ];
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

export interface EcaValidationProblem {
	level: 'error' | 'warn';
	field: string;
	message: string;
}

/**
 * Per-record conformance. Errors fail the `verify --eca` gate; warnings are
 * reported and counted, because thin source data is normal (22 of 105 records
 * have no derivable stage) and must not block a launch.
 */
export function validateEcaRecord( record: EcaRecord, config?: ForgeEca ): EcaValidationProblem[] {
	const problems: EcaValidationProblem[] = [];
	const stages = config?.stages && config.stages.length > 0 ? config.stages : ECA_DEFAULT_STAGES;

	if ( ! Number.isInteger( record.id ) || record.id <= 0 ) {
		problems.push( { level: 'error', field: 'id', message: 'a record needs a positive integer id' } );
	}
	if ( ! record.slug ) problems.push( { level: 'error', field: 'slug', message: 'a record needs a slug' } );
	if ( ! record.title ) problems.push( { level: 'error', field: 'title', message: 'a record needs a title' } );
	if ( ! /^https?:\/\//i.test( record.url ) ) {
		problems.push( { level: 'error', field: 'url', message: 'url must be absolute: ' + record.url } );
	}

	for ( const id of record.stages ) {
		if ( ! stages.some( ( stage ) => stage.id === id ) ) {
			problems.push( { level: 'error', field: 'stages', message: 'unknown stage id `' + id + '` — not in profile.eca.stages' } );
		}
	}
	if ( record.stages.length === 0 ) {
		problems.push( { level: 'warn', field: 'stages', message: 'no derivable stage — record stays browseable under "all stages"' } );
	}

	const { startMinutes, endMinutes, day } = record.schedule;
	if ( startMinutes !== null && endMinutes !== null && endMinutes <= startMinutes ) {
		problems.push( { level: 'error', field: 'schedule.end', message: 'end time is not after start time' } );
	}
	if ( startMinutes !== null && ! day ) {
		problems.push( { level: 'warn', field: 'schedule.day', message: 'start time without a day — the schedule cannot render' } );
	}
	if ( startMinutes === null ) {
		problems.push( { level: 'warn', field: 'schedule.start', message: 'no start time — excluded from the timetable' } );
	}

	if ( record.pricing.paid && ( record.pricing.amount === null || record.pricing.amount <= 0 ) ) {
		problems.push( { level: 'error', field: 'pricing.amount', message: 'marked paid but the amount is missing' } );
	}
	if ( record.pricing.paid && ! record.pricing.period ) {
		problems.push( { level: 'warn', field: 'pricing.period', message: 'paid but the period is unknown (per term? per year?)' } );
	}
	if ( record.capacity.max === null ) {
		problems.push( { level: 'warn', field: 'capacity.max', message: 'capacity not published' } );
	}
	if ( record.status !== 'active' ) {
		problems.push( { level: 'warn', field: 'status', message: 'status is `' + record.status + '` — should it be in the catalogue?' } );
	}

	return problems;
}

/** Split a normalised catalogue into the gate's two buckets. */
export function summariseValidation(
	records: EcaRecord[],
	config?: ForgeEca,
): { errors: Array< { id: number; problems: EcaValidationProblem[] } >; warnings: number; total: number } {
	const errors: Array< { id: number; problems: EcaValidationProblem[] } > = [];
	let warnings = 0;
	for ( const record of records ) {
		const problems = validateEcaRecord( record, config );
		warnings += problems.filter( ( problem ) => problem.level === 'warn' ).length;
		const recordErrors = problems.filter( ( problem ) => problem.level === 'error' );
		if ( recordErrors.length > 0 ) errors.push( { id: record.id, problems: recordErrors } );
	}
	return { errors, warnings, total: records.length };
}
