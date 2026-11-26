// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// eca.test.ts — the `nvoos-eca/v1` normaliser (Proposal 163, Phase 0).
//
// Fixtures are the LIVE records captured from `wp/v2/mcp_ai_eca` on
// eca.directory (2026-11-24), trimmed only where noted. They are the cases
// that broke the assumptions in the proposal's research pass:
//
//   11183  full meta + a labelled prose stack (`Year Groups: Year 10 to Year 13`)
//   11155  thin meta (no day/time/venue) + imported `[Note: …]` commentary
//   10850  paid, prose-only price, external provider, no venue
//   10801  free, no year signal anywhere → stage must stay EMPTY, not guessed
//
// A green run here means the format survives real data, not a happy path.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	bookingCtaLabel,
	bookingLabel,
	bookingState,
	bookingUrl,
	buildFacets,
	buildIcs,
	buildTermCalendar,
	canonicalDay,
	closureFor,
	closureInstances,
	ECA_DEFAULT_STAGES,
	escapeIcsText,
	filterEca,
	firstOccurrence,
	foldIcsLine,
	formatDate,
	formatPrice,
	formatStamp,
	htmlDecode,
	inferProvider,
	inferStages,
	makeSummary,
	monthGrid,
	monthsBetween,
	normalizeBasePath,
	normalizeBookingType,
	normalizeEca,
	normalizePeriod,
	occurrenceDates,
	parseClockTime,
	parseDays,
	parseProsePrice,
	parseYearGroups,
	readLabel,
	resolveVenue,
	stripImportNotes,
	summariseValidation,
	timeBandFor,
	toLabelText,
	toPlainText,
	validateEcaRecord,
	zonedStamp,
} from '../lib/eca.ts';
import type { EcaRecord, ForgeEca, RawEcaPost } from '../lib/eca.ts';

const CTX = {
	origin: 'https://eca.directory',
	restBase: 'wp/v2/mcp_ai_eca',
	fetchedAt: '2026-11-24T00:00:00Z',
};

const KADENCE_EMPTY = {
	_kad_post_transparent: '',
	_kad_post_title: '',
	_kad_post_layout: '',
	_kad_post_sidebar_id: '',
	_kad_post_content_style: '',
	_kad_post_vertical_padding: '',
	_kad_post_feature: '',
	_kad_post_feature_position: '',
	_kad_post_header: false,
	_kad_post_footer: false,
	_kad_post_classname: '',
};

/** 11183 — SS society, every meta field populated, labelled prose stack. */
const RECORD_FULL: RawEcaPost = {
	id: 11183,
	slug: 'ss-fobisia-student-event-leaders-academy',
	link: 'https://eca.directory/eca/ss-fobisia-student-event-leaders-academy/',
	title: { rendered: 'SS FOBISIA Student Event Leaders Academy' },
	content: {
		rendered:
			'<p>Would you like to play an important role in one of the biggest international events hosted by our school? The FOBISIA Student Event Leaders Academy will prepare students to become part of the student leadership and volunteer team for the FOBISIA Secondary Mathematics Competition 2027.</p>\n' +
			'<p>This society will run only in the Michaelmas Term 2026 and Lent Term 2027.</p>\n' +
			'<p>ECA Day &amp; Time: Tuesday 2:45 p.m. &#8211; 3:30 p.m.<br />\n' +
			'Year Groups: Year 10 to Year 13<br />\n' +
			'Venue: Room 23<br />\n' +
			'Capacity: maximum 20 students<br />\n' +
			'Teachers in Charge: Dr. Jins Jose, Ms. Ummehani Hamza<br />\n' +
			'New ECA introduced in Michaelmas Term 2026.</p>\n',
	},
	meta: {
		...KADENCE_EMPTY,
		_eca_code: 'ECA-007',
		_eca_type: 'society',
		_eca_day: 'Tuesday',
		_eca_start_time: '2:45 PM',
		_eca_end_time: '3:30 PM',
		_eca_venue: 'Room 23',
		_eca_max_students: 20,
		_eca_year_groups: '',
		_eca_teachers: '',
		_eca_is_paid: null,
		_eca_cost: 0,
		_eca_cost_period: '',
		_eca_requires_audition: null,
		_eca_booking_type: 'preference_based',
		_eca_status: 'active',
	},
	mcp_ai_eca_category: [],
};

/** 11155 — thin meta, an import note, stage only from the prose year range. */
const RECORD_THIN: RawEcaPost = {
	id: 11155,
	slug: 'ss-maths-mastery-hub',
	title: { rendered: 'SS Maths Mastery Hub' },
	content: {
		rendered:
			'<p>A new ECA introduced this term. The Maths Mastery Hub is an afterschool club run by the Mathematics Department for students in Years 7-13. It is a dedicated space to:<br />\n' +
			'&#8211; Clear doubts and strengthen mathematical concepts.<br />\n' +
			'&#8211; Receive extra support for IGCSE and A Level preparation<br />\n' +
			'[Note: the remainder of this entry in the source booklet (day, time, venue, capacity and teacher-in-charge) was not readable in the supplied PDF and still needs to be confirmed.]</p>\n' +
			'<p>Year Groups: Year 7 to Year 13</p>\n',
	},
	meta: {
		...KADENCE_EMPTY,
		_eca_code: 'ECA-028',
		_eca_type: 'club',
		_eca_day: '',
		_eca_start_time: '',
		_eca_end_time: '',
		_eca_venue: '',
		_eca_max_students: 0,
		_eca_year_groups: '',
		_eca_teachers: '',
		_eca_is_paid: null,
		_eca_cost: 0,
		_eca_cost_period: '',
		_eca_requires_audition: null,
		_eca_booking_type: 'preference_based',
		_eca_status: 'active',
	},
	mcp_ai_eca_category: [],
};

/** 10850 — paid, price only in prose, external provider, no venue. */
const RECORD_PAID: RawEcaPost = {
	id: 10850,
	slug: 'ai-for-kids-stem-tuesday',
	title: { rendered: 'AI for Kids (STEM) Tuesday' },
	content: {
		rendered:
			'<p>Hands-on AI and coding program with external provider Meu Labs. Paid ECA: Rs. 22,500 per student per term.</p>\n',
	},
	meta: {
		...KADENCE_EMPTY,
		_eca_code: '30',
		_eca_type: 'club',
		_eca_day: 'Tuesday',
		_eca_start_time: '1:45 PM',
		_eca_end_time: '3:00 PM',
		_eca_venue: '',
		_eca_max_students: 0,
		_eca_year_groups: null,
		_eca_teachers: null,
		_eca_is_paid: null,
		_eca_cost: 22500,
		_eca_cost_period: 'term',
		_eca_requires_audition: null,
		_eca_booking_type: 'first_come_first_served',
		_eca_status: 'active',
	},
	mcp_ai_eca_category: [ 39 ],
};

/** 10801 — free, no year signal anywhere: the stage must stay empty. */
const RECORD_NO_STAGE: RawEcaPost = {
	id: 10801,
	slug: 'cub-scouts',
	title: { rendered: 'Cub Scouts' },
	content: { rendered: '<p>Builds character, teaches moral values, and encourages responsibility. Boys Only.</p>' },
	meta: {
		...KADENCE_EMPTY,
		_eca_code: '10',
		_eca_type: 'society',
		_eca_day: 'Thursday',
		_eca_start_time: '1:45 PM',
		_eca_end_time: '3:00 PM',
		_eca_venue: 'Outdoor Play Area',
		_eca_max_students: 0,
		_eca_year_groups: null,
		_eca_is_paid: null,
		_eca_cost: 0,
		_eca_cost_period: '',
		_eca_requires_audition: null,
		_eca_booking_type: 'preference_based',
		_eca_status: 'active',
	},
	mcp_ai_eca_category: [],
};

/** The title shape of live records 10927/10885/10875 (`Y4-6`, `Y1-2`, `Y3-4`). */
const RECORD_TITLE_YEARS: RawEcaPost = {
	id: 10927,
	slug: 'sailing-y4-6-saturday-beginner',
	title: { rendered: 'Sailing (Y4-6 Saturday Beginner)' },
	content: { rendered: '<p>An introduction to sailing for younger pupils.</p>' },
	meta: {
		...KADENCE_EMPTY,
		_eca_code: 'ECA-031',
		_eca_type: 'club',
		_eca_day: 'Saturday',
		_eca_start_time: '8:00 AM',
		_eca_end_time: '10:00 AM',
		_eca_venue: 'Colombo Rowing Club',
		_eca_max_students: 12,
		_eca_year_groups: '',
		_eca_is_paid: null,
		_eca_cost: 25000,
		_eca_cost_period: 'month',
		_eca_requires_audition: null,
		_eca_booking_type: 'first_come_first_served',
		_eca_status: 'active',
	},
	mcp_ai_eca_category: [ 35 ],
};

const TERMS = [ { id: 'michaelmas-2026', label: 'Michaelmas 2026', start: '2026-09-01', end: '2026-12-11' } ];

const normalise = ( post: RawEcaPost, config?: ForgeEca ): EcaRecord =>
	normalizeEca( post, { ...CTX, config, terms: [ { id: 35, name: 'Sports', slug: 'sports' }, { id: 39, name: 'Technology', slug: 'technology' } ] } );

describe( 'htmlDecode', () => {
	it( 'decodes the numeric entities WordPress emits in titles', () => {
		expect( htmlDecode( 'SS Lion&#8217;s Mane' ) ).toBe( 'SS Lion’s Mane' );
		expect( htmlDecode( 'a &#8211; b' ) ).toBe( 'a – b' );
	} );

	it( 'decodes named entities and leaves unknown ones alone', () => {
		expect( htmlDecode( 'Fish &amp; Chips' ) ).toBe( 'Fish & Chips' );
		expect( htmlDecode( '&notarealentity;' ) ).toBe( '&notarealentity;' );
	} );

	it( 'decodes hex entities', () => {
		expect( htmlDecode( '&#x2013;' ) ).toBe( '–' );
	} );
} );

describe( 'toPlainText / toLabelText', () => {
	const html = '<p>First line<br />\nVenue: Room 23<br />\nCapacity: 20</p>';

	it( 'collapses everything to one space for search', () => {
		expect( toPlainText( html ) ).toBe( 'First line Venue: Room 23 Capacity: 20' );
	} );

	it( 'keeps the br boundaries so a label cannot over-capture', () => {
		expect( toLabelText( html ).split( '\n' ) ).toEqual( [ 'First line', 'Venue: Room 23', 'Capacity: 20' ] );
	} );

	it( 'drops script and style content', () => {
		expect( toPlainText( '<script>var x = 1;</script><p>Real</p>' ) ).toBe( 'Real' );
	} );
} );

describe( 'stripImportNotes', () => {
	it( 'removes the booklet note and counts it', () => {
		const input = '<p>Body.</p><p>[Note: the remainder of this entry in the source booklet … still needs to be confirmed.]</p>';
		const result = stripImportNotes( input );
		expect( result.stripped ).toBe( 1 );
		expect( result.html ).not.toContain( '[Note:' );
		expect( result.html ).toContain( 'Body.' );
	} );

	it( 'is a no-op on clean copy', () => {
		expect( stripImportNotes( '<p>Clean</p>' ) ).toEqual( { html: '<p>Clean</p>', stripped: 0 } );
	} );
} );

describe( 'parseClockTime', () => {
	it( 'parses the live meta format (h:mm AM/PM)', () => {
		expect( parseClockTime( '2:45 PM' ) ).toEqual( { minutes: 885, hhmm: '14:45' } );
		expect( parseClockTime( '1:45 PM' ) ).toEqual( { minutes: 825, hhmm: '13:45' } );
		expect( parseClockTime( '8:00 AM' ) ).toEqual( { minutes: 480, hhmm: '08:00' } );
	} );

	it( 'parses 24-hour values', () => {
		expect( parseClockTime( '14:45' ) ).toEqual( { minutes: 885, hhmm: '14:45' } );
	} );

	it( 'parses the dotted prose form', () => {
		expect( parseClockTime( '2:45 p.m.' ) ).toEqual( { minutes: 885, hhmm: '14:45' } );
	} );

	it( 'wraps noon and midnight correctly', () => {
		expect( parseClockTime( '12:00 PM' )?.minutes ).toBe( 720 );
		expect( parseClockTime( '12:30 AM' )?.minutes ).toBe( 30 );
	} );

	it( 'rejects impossible values instead of guessing', () => {
		expect( parseClockTime( '13:00 PM' ) ).toBeNull();
		expect( parseClockTime( '2:75 PM' ) ).toBeNull();
		expect( parseClockTime( '' ) ).toBeNull();
		expect( parseClockTime( null ) ).toBeNull();
	} );
} );

describe( 'timeBandFor', () => {
	it( 'files 12:00 as lunch and 13:45 as after school', () => {
		expect( timeBandFor( 12 * 60 ) ).toBe( 'lunch' );
		expect( timeBandFor( 13 * 60 + 45 ) ).toBe( 'after-school' );
		expect( timeBandFor( 14 * 60 + 45 ) ).toBe( 'after-school' );
	} );

	it( 'respects a configured band set', () => {
		expect( timeBandFor( 13 * 60 + 45, [ { id: 'custom', label: 'Custom', from: 0, to: 24 * 60 } ] ) ).toBe( 'custom' );
	} );

	it( 'returns null for an unknown time', () => {
		expect( timeBandFor( null ) ).toBeNull();
	} );
} );

describe( 'canonicalDay', () => {
	it( 'accepts full names and abbreviations in any case', () => {
		expect( canonicalDay( 'Tuesday' ) ).toBe( 'Tuesday' );
		expect( canonicalDay( 'tue' ) ).toBe( 'Tuesday' );
		expect( canonicalDay( 'THURS' ) ).toBe( 'Thursday' );
	} );

	it( 'returns null rather than a guess', () => {
		expect( canonicalDay( 'someday' ) ).toBeNull();
		expect( canonicalDay( '' ) ).toBeNull();
		expect( canonicalDay( undefined ) ).toBeNull();
	} );
} );

describe( 'parseYearGroups', () => {
	it( 'reads the repeated-word label form', () => {
		expect( parseYearGroups( { label: 'Year 10 to Year 13' } ) ).toMatchObject( { from: 10, to: 13 } );
		expect( parseYearGroups( { label: 'Year 1 and Year 2' } ) ).toMatchObject( { from: 1, to: 2 } );
	} );

	it( 'reads a prose dash range', () => {
		expect( parseYearGroups( { prose: 'open to students in Years 7-13 at the school' } ) ).toMatchObject( { from: 7, to: 13 } );
	} );

	it( 'reads the title shorthand', () => {
		expect( parseYearGroups( { title: 'Sailing (Y4-6 Saturday Beginner)' } ) ).toMatchObject( { from: 4, to: 6, rule: 'title' } );
	} );

	it( 'reads a single year', () => {
		expect( parseYearGroups( { prose: 'For Year 5 pupils' } ) ).toMatchObject( { from: 5, to: 5 } );
	} );

	it( 'prefers meta over prose', () => {
		expect( parseYearGroups( { meta: [ 3, 4, 5 ], prose: 'Years 9-11' } ) ).toMatchObject( { from: 3, to: 5, rule: 'meta' } );
	} );

	it( 'reads a CSV string from meta', () => {
		expect( parseYearGroups( { meta: '7,8,9' } ) ).toMatchObject( { from: 7, to: 9 } );
	} );

	it( 'returns null when there is no signal', () => {
		expect( parseYearGroups( { title: 'Cub Scouts', prose: 'Builds character.' } ) ).toBeNull();
	} );
} );

describe( 'inferStages', () => {
	it( 'maps a year range onto the configured stage', () => {
		expect( inferStages( { yearGroups: { from: 10, to: 13 }, title: 'x', slug: 'x' } ) ).toEqual( { stages: [ 'senior-school' ], rule: 'year-range' } );
		expect( inferStages( { yearGroups: { from: 1, to: 2 }, title: 'x', slug: 'x' } ) ).toEqual( { stages: [ 'junior-school' ], rule: 'year-range' } );
	} );

	it( 'lets the year range beat a conflicting title token', () => {
		// A title can say SS while the body says Year 3 to 6; the years win.
		expect( inferStages( { yearGroups: { from: 3, to: 6 }, title: 'SS Chess', slug: 'ss-chess' } ).stages ).toEqual( [ 'junior-school' ] );
	} );

	it( 'falls back to a title token', () => {
		expect( inferStages( { yearGroups: null, title: 'SS Chess Club', slug: 'chess' } ) ).toEqual( { stages: [ 'senior-school' ], rule: 'title-token' } );
		expect( inferStages( { yearGroups: null, title: 'Junior Choir', slug: 'choir' } ).stages ).toEqual( [ 'junior-school' ] );
	} );

	it( 'falls back to the slug prefix', () => {
		expect( inferStages( { yearGroups: null, title: 'Chinese Club', slug: 'ss-chinese-club' } ) ).toEqual( { stages: [ 'senior-school' ], rule: 'slug-prefix' } );
	} );

	it( 'returns no stage rather than a guess', () => {
		expect( inferStages( { yearGroups: null, title: 'Cub Scouts', slug: 'cub-scouts' } ) ).toEqual( { stages: [], rule: null } );
	} );

	it( 'honours a custom stage config', () => {
		const stages = [ { id: 'primary', label: 'Primary', yearGroups: { from: 1, to: 6 }, match: [ 'primary' ] } ];
		expect( inferStages( { yearGroups: { from: 2, to: 4 }, title: 'x', slug: 'x', stages } ).stages ).toEqual( [ 'primary' ] );
	} );
} );

describe( 'parseProsePrice', () => {
	it( 'reads the live Rs. form', () => {
		expect( parseProsePrice( 'Paid ECA: Rs. 22,500 per student per term.' ) ).toMatchObject( { amount: 22500, currency: 'LKR' } );
	} );

	it( 'reads LKR and USD', () => {
		expect( parseProsePrice( 'Cost: LKR 45,000 per term' ) ).toMatchObject( { amount: 45000, currency: 'LKR' } );
		expect( parseProsePrice( 'Fees: USD 120' ) ).toMatchObject( { amount: 120, currency: 'USD' } );
	} );

	it( 'does not mistake a time for money', () => {
		expect( parseProsePrice( 'Tuesday 2:45 p.m. – 3:30 p.m.' ) ).toBeNull();
	} );

	it( 'does not read `Years 7-13` as Rs. 7 (live regression, record 11155)', () => {
		expect( parseProsePrice( 'for students in Years 7-13 at the school' ) ).toBeNull();
	} );

	it( 'returns null when free', () => {
		expect( parseProsePrice( 'This club is free for new students.' ) ).toBeNull();
	} );
} );

describe( 'formatPrice', () => {
	it( 'formats with the period', () => {
		expect( formatPrice( 22500, 'Rs.', 'term' ) ).toBe( 'Rs. 22,500 per term' );
		expect( formatPrice( 6250, 'Rs.', 'month' ) ).toBe( 'Rs. 6,250 per month' );
	} );

	it( 'omits the period when unknown', () => {
		expect( formatPrice( 1000, 'Rs.', null ) ).toBe( 'Rs. 1,000' );
	} );

	it( 'is null for free', () => {
		expect( formatPrice( null, 'Rs.', null ) ).toBeNull();
		expect( formatPrice( 0, 'Rs.', 'term' ) ).toBeNull();
	} );
} );

describe( 'normalizePeriod', () => {
	it( 'normalises the live period values', () => {
		expect( normalizePeriod( 'term' ) ).toBe( 'term' );
		expect( normalizePeriod( 'year' ) ).toBe( 'year' );
		expect( normalizePeriod( 'month' ) ).toBe( 'month' );
		expect( normalizePeriod( 'per_term' ) ).toBe( 'term' );
	} );

	it( 'is null for empty', () => {
		expect( normalizePeriod( '' ) ).toBeNull();
		expect( normalizePeriod( null ) ).toBeNull();
	} );
} );

describe( 'inferProvider', () => {
	it( 'reads the explicit external-provider phrasing', () => {
		expect( inferProvider( 'Hands-on program with external provider Meu Labs. Paid ECA.' ) ).toEqual( { name: 'Meu Labs', external: true } );
	} );

	it( 'truncates an address after the organisation name', () => {
		expect( inferProvider( 'External provider at Ceylon Riding Club, Port City, Colombo' ) ).toEqual( { name: 'Ceylon Riding Club', external: true } );
	} );

	it( 'accepts a department, which is an organisation', () => {
		expect( inferProvider( 'run by the Mathematics Department for students in Years 7-13.' ) ).toEqual( {
			name: 'Mathematics Department',
			external: false,
		} );
	} );

	it( 'rejects a plain-language led-by that is not an organisation', () => {
		expect( inferProvider( 'led by passionate students, this society introduces students to debating.' ) ).toBeNull();
	} );

	it( 'returns null when nothing matches', () => {
		expect( inferProvider( 'A quiet club for readers.' ) ).toBeNull();
	} );
} );

describe( 'resolveVenue / readLabel', () => {
	it( 'applies the operator alias map', () => {
		expect( resolveVenue( 'SS 18', { 'SS 18': 'Senior School, Room 18' } ) ).toBe( 'Senior School, Room 18' );
		expect( resolveVenue( 'Room 4' ) ).toBe( 'Room 4' );
	} );

	it( 'returns null for an empty venue', () => {
		expect( resolveVenue( '' ) ).toBeNull();
		expect( resolveVenue( null ) ).toBeNull();
	} );

	it( 'anchors labels to a line start', () => {
		const labels = 'ECA Day & Time: Tuesday 2:45 p.m.\nStart Time: 2:45 p.m.\nVenue: Room 23';
		expect( readLabel( labels, [ 'Start Time' ] ) ).toBe( '2:45 p.m.' );
		expect( readLabel( labels, [ 'Venue' ] ) ).toBe( 'Room 23' );
	} );

	it( 'does not return the whole run after an inline label', () => {
		expect( readLabel( 'Venue: Room 23 Capacity: 20', [ 'Venue' ] ) ).toBe( 'Room 23 Capacity: 20' );
		expect( readLabel( 'line\nVenue: Room 23\nCapacity: 20', [ 'Venue' ] ) ).toBe( 'Room 23' );
	} );
} );

describe( 'normalizeBookingType', () => {
	it( 'maps all four live values', () => {
		expect( normalizeBookingType( 'preference_based' ).type ).toBe( 'preference' );
		expect( normalizeBookingType( 'first_come_first_served' ).type ).toBe( 'first-come' );
		expect( normalizeBookingType( 'preselected' ).type ).toBe( 'invite' );
		expect( normalizeBookingType( 'signup' ).type ).toBe( 'signup' );
	} );

	it( 'keeps the raw value for an unknown type', () => {
		expect( normalizeBookingType( 'ballot' ) ).toEqual( { type: 'other', rawType: 'ballot' } );
	} );
} );

describe( 'booking labels + hand-off', () => {
	it( 'labels the allocation rule', () => {
		expect( bookingLabel( 'first-come', false ) ).toBe( 'First come, first served' );
		expect( bookingLabel( 'preference', false ) ).toBe( 'Preference-based allocation' );
		expect( bookingLabel( 'other', true ) ).toBe( 'Audition required' );
	} );

	it( 'labels the CTA by mode', () => {
		expect( bookingCtaLabel( 'first-come', false ) ).toBe( 'Express interest' );
		expect( bookingCtaLabel( 'audition', true ) ).toBe( 'Book an audition' );
		expect( bookingCtaLabel( 'first-come', false, 'Register now' ) ).toBe( 'Register now' );
	} );

	it( 'substitutes the URL template tokens', () => {
		const record = { id: 42, slug: 'chess-club', code: 'ECA-042', stages: [ 'senior-school' ] };
		expect( bookingUrl( record, { booking: { urlTemplate: 'https://school.test/join/{slug}?code={code}' } } ) ).toBe(
			'https://school.test/join/chess-club?code=ECA-042',
		);
	} );

	it( 'is null without a template, so the UI never emits a dead link', () => {
		expect( bookingUrl( { id: 1, slug: 'a', code: null, stages: [] } ) ).toBeNull();
		expect( bookingUrl( { id: 1, slug: 'a', code: null, stages: [] }, { booking: { urlTemplate: '/relative' } } ) ).toBeNull();
	} );
} );

describe( 'normalizeBasePath', () => {
	it( 'normalises to one leading slash, no trailing slash', () => {
		expect( normalizeBasePath( 'activities' ) ).toBe( '/activities' );
		expect( normalizeBasePath( '/activities/' ) ).toBe( '/activities' );
		expect( normalizeBasePath( '' ) ).toBe( '/activities' );
	} );
} );

describe( 'makeSummary', () => {
	it( 'takes the first sentence', () => {
		expect( makeSummary( 'First sentence. Second sentence.' ) ).toBe( 'First sentence.' );
	} );

	it( 'clips on a word boundary and marks the elision', () => {
		const summary = makeSummary( 'word '.repeat( 80 ) + 'end', 40 );
		expect( summary.length ).toBeLessThanOrEqual( 41 );
		expect( summary.endsWith( '…' ) ).toBe( true );
	} );
} );

describe( 'normalizeEca — the full record', () => {
	const record = normalise( RECORD_FULL );

	it( 'reads the schedule from meta', () => {
		expect( record.schedule ).toMatchObject( {
			day: 'Tuesday',
			start: '14:45',
			end: '15:30',
			durationMinutes: 45,
			timeOfDay: 'after-school',
		} );
	} );

	it( 'reads the year range from the label, not the prose', () => {
		expect( record.yearGroups ).toEqual( { from: 10, to: 13 } );
		expect( record.stages ).toEqual( [ 'senior-school' ] );
	} );

	it( 'carries the venue, capacity and code', () => {
		expect( record.venue ).toBe( 'Room 23' );
		expect( record.capacity.max ).toBe( 20 );
		expect( record.capacity.state ).toBe( 'unknown' );
		expect( record.code ).toBe( 'ECA-007' );
		expect( record.type ).toBe( 'society' );
	} );

	it( 'is free and preference-based', () => {
		expect( record.pricing.paid ).toBe( false );
		expect( record.booking ).toMatchObject( { type: 'preference', rawType: 'preference_based', requiresAudition: false } );
	} );

	it( 'records every derivation it made', () => {
		const fields = record.inferred.map( ( entry ) => entry.field );
		expect( fields ).toContain( 'yearGroups' );
		expect( fields ).toContain( 'stages' );
		expect( record.inferred.every( ( entry ) => Boolean( entry.rule ) ) ).toBe( true );
	} );

	it( 'builds a public url from the public origin, not the CMS link', () => {
		expect( record.url ).toBe( 'https://eca.directory/activities/ss-fobisia-student-event-leaders-academy/' );
		expect( record.source.origin ).toBe( 'https://eca.directory' );
	} );
} );

describe( 'normalizeEca — the thin record', () => {
	const record = normalise( RECORD_THIN );

	it( 'strips the import note from the published body', () => {
		expect( record.descriptionHtml ).not.toContain( '[Note:' );
		expect( record.inferred.some( ( entry ) => entry.rule === 'strip-import-note' ) ).toBe( true );
	} );

	it( 'still derives the stage from the labelled year range', () => {
		expect( record.yearGroups ).toEqual( { from: 7, to: 13 } );
		expect( record.stages ).toEqual( [ 'senior-school' ] );
	} );

	it( 'leaves the schedule unknown instead of inventing one', () => {
		expect( record.schedule.day ).toBeNull();
		expect( record.schedule.start ).toBeNull();
		expect( record.schedule.timeOfDay ).toBeNull();
		expect( record.capacity.max ).toBeNull();
	} );

	it( 'finds the internal provider from the prose', () => {
		expect( record.provider ).toBe( 'Mathematics Department' );
	} );

	it( 'stays free — the year range is not a price', () => {
		expect( record.pricing.paid ).toBe( false );
		expect( record.pricing.amount ).toBeNull();
	} );
} );

describe( 'normalizeEca — the paid record', () => {
	const record = normalise( RECORD_PAID );

	it( 'prices from the meta cost', () => {
		expect( record.pricing ).toMatchObject( { paid: true, amount: 22500, period: 'term', display: 'Rs. 22,500 per term' } );
	} );

	it( 'finds the external provider', () => {
		expect( record.provider ).toBe( 'Meu Labs' );
	} );

	it( 'normalises a bare code', () => {
		expect( record.code ).toBe( 'ECA-30' );
		expect( record.inferred.some( ( entry ) => entry.rule === 'code:normalise' ) ).toBe( true );
	} );

	it( 'resolves the category term', () => {
		expect( record.categories ).toEqual( [ { id: 39, name: 'Technology', slug: 'technology' } ] );
	} );

	it( 'computes the duration across the range', () => {
		expect( record.schedule.durationMinutes ).toBe( 75 );
	} );
} );

describe( 'normalizeEca — no stage signal', () => {
	const record = normalise( RECORD_NO_STAGE );

	it( 'keeps the stage empty rather than guessing', () => {
		expect( record.stages ).toEqual( [] );
		expect( record.yearGroups ).toBeNull();
	} );

	it( 'is still a valid, browseable record', () => {
		const errors = validateEcaRecord( record ).filter( ( problem ) => problem.level === 'error' );
		expect( errors ).toEqual( [] );
	} );

	it( 'warns that it needs an operator decision', () => {
		expect( validateEcaRecord( record ).some( ( problem ) => problem.field === 'stages' && problem.level === 'warn' ) ).toBe( true );
	} );
} );

describe( 'normalizeEca — title year shorthand', () => {
	it( 'maps Y4-6 to the junior stage', () => {
		const record = normalise( RECORD_TITLE_YEARS );
		expect( record.yearGroups ).toEqual( { from: 4, to: 6 } );
		expect( record.stages ).toEqual( [ 'junior-school' ] );
		expect( record.pricing.period ).toBe( 'month' );
	} );
} );

describe( 'buildFacets', () => {
	const records = [ normalise( RECORD_FULL ), normalise( RECORD_PAID ), normalise( RECORD_NO_STAGE ), normalise( RECORD_TITLE_YEARS ) ];
	const facets = buildFacets( records );

	it( 'counts every record', () => {
		expect( facets.total ).toBe( 4 );
	} );

	it( 'orders days Monday-first and drops empty days', () => {
		expect( facets.day.map( ( entry ) => entry.value ) ).toEqual( [ 'Tuesday', 'Thursday', 'Saturday' ] );
		expect( facets.day.find( ( entry ) => entry.value === 'Tuesday' )?.count ).toBe( 2 );
	} );

	it( 'counts each year in a range', () => {
		const year10 = facets.yearGroup.find( ( entry ) => entry.value === '10' );
		expect( year10?.count ).toBe( 1 );
		expect( year10?.label ).toBe( 'Year 10' );
	} );

	it( 'counts the unset capacity as its own bucket', () => {
		expect( facets.availability ).toEqual( [ { value: 'unknown', label: 'Places not published', count: 4 } ] );
	} );

	it( 'splits free and paid', () => {
		expect( facets.price ).toEqual( [
			{ value: 'free', label: 'Free', count: 2 },
			{ value: 'paid', label: 'Paid', count: 2 },
		] );
	} );

	it( 'labels the type facet', () => {
		expect( facets.type.find( ( entry ) => entry.value === 'club' )?.label ).toBe( 'Club' );
	} );

	it( 'labels a category with the term name, not the slug', () => {
		expect( facets.category ).toEqual( [
			{ value: 'sports', label: 'Sports', count: 1 },
			{ value: 'technology', label: 'Technology', count: 1 },
		] );
	} );
} );

describe( 'filterEca', () => {
	const records = [ normalise( RECORD_FULL ), normalise( RECORD_PAID ), normalise( RECORD_NO_STAGE ) ];

	it( 'filters by day', () => {
		expect( filterEca( records, { day: [ 'Tuesday' ] } ).map( ( record ) => record.id ) ).toEqual( [ 11183, 10850 ] );
	} );

	it( 'ORs within a facet and ANDs across facets', () => {
		expect( filterEca( records, { day: [ 'Tuesday', 'Thursday' ] } ) ).toHaveLength( 3 );
		expect( filterEca( records, { day: [ 'Tuesday' ], price: [ 'paid' ] } ).map( ( record ) => record.id ) ).toEqual( [ 10850 ] );
	} );

	it( 'filters by stage and excludes the unknown-stage record', () => {
		expect( filterEca( records, { stage: [ 'senior-school' ] } ).map( ( record ) => record.id ) ).toEqual( [ 11183 ] );
	} );

	it( 'filters by school year', () => {
		expect( filterEca( records, { year: [ 11 ] } ).map( ( record ) => record.id ) ).toEqual( [ 11183 ] );
		expect( filterEca( records, { year: [ 2 ] } ) ).toEqual( [] );
	} );

	it( 'matches free text across title, summary and provider', () => {
		expect( filterEca( records, { q: 'labs' } ).map( ( record ) => record.id ) ).toEqual( [ 10850 ] );
		expect( filterEca( records, { q: 'fobisia event' } ).map( ( record ) => record.id ) ).toEqual( [ 11183 ] );
	} );

	it( 'returns everything when no filter is set', () => {
		expect( filterEca( records, {} ) ).toHaveLength( 3 );
	} );
} );

describe( 'ICS', () => {
	const record = normalise( RECORD_FULL );
	const config: ForgeEca = { timezone: 'Asia/Colombo', terms: TERMS };

	it( 'firstOccurrence lands on the requested weekday', () => {
		// 2024-01-01 was a Monday.
		expect( firstOccurrence( '2024-01-01', 'Monday' ) ).toBe( '2024-01-01' );
		expect( firstOccurrence( '2024-01-01', 'Tuesday' ) ).toBe( '2024-01-02' );
		expect( firstOccurrence( '2024-01-01', 'Sunday' ) ).toBe( '2024-01-07' );
	} );

	it( 'emits a weekly recurring event bounded by the term', () => {
		const ics = buildIcs( record, { config } );
		expect( ics ).toContain( 'BEGIN:VCALENDAR' );
		expect( ics ).toContain( 'DTSTART;TZID=Asia/Colombo:' );
		expect( ics ).toContain( 'T144500' );
		expect( ics ).toContain( 'RRULE:FREQ=WEEKLY;BYDAY=TU;UNTIL=20261212T000000Z' );
		expect( ics ).toContain( 'LOCATION:Room 23' );
		expect( ics ).toContain( 'UID:ss-fobisia-student-event-leaders-academy@eca.directory' );
	} );

	it( 'starts the series on a Tuesday inside the term', () => {
		const ics = buildIcs( record, { config } );
		const match = /DTSTART;TZID=Asia\/Colombo:(\d{4})(\d{2})(\d{2})T/.exec( ics );
		expect( match ).not.toBeNull();
		const date = new Date( Date.UTC( Number( match?.[ 1 ] ), Number( match?.[ 2 ] ) - 1, Number( match?.[ 3 ] ) ) );
		expect( date.getUTCDay() ).toBe( 2 );
		expect( date.getTime() ).toBeGreaterThanOrEqual( Date.UTC( 2026, 8, 1 ) );
	} );

	it( 'is reproducible — no DTSTAMP from the wall clock', () => {
		expect( buildIcs( record, { config } ) ).toBe( buildIcs( record, { config } ) );
	} );

	it( 'withholds the feed when there are no terms', () => {
		expect( buildIcs( record, { config: { timezone: 'Asia/Colombo' } } ) ).toBe( '' );
	} );

	it( 'withholds the feed when the schedule is unknown', () => {
		expect( buildIcs( normalise( RECORD_THIN ), { config } ) ).toBe( '' );
	} );

	it( 'uses CRLF line endings', () => {
		expect( buildIcs( record, { config } ).endsWith( '\r\n' ) ).toBe( true );
	} );
} );

describe( 'ICS escaping + folding', () => {
	it( 'escapes the four RFC 5545 specials', () => {
		expect( escapeIcsText( 'a, b; c\\d\ne' ) ).toBe( 'a\\, b\\; c\\\\d\\ne' );
	} );

	it( 'folds at 75 octets and never splits a multi-byte character', () => {
		const line = 'LOCATION:' + 'Sri Suramyarama – Rajagiriya '.repeat( 6 );
		const folded = foldIcsLine( line );
		const encoder = new TextEncoder();
		for ( const physical of folded.split( '\r\n' ) ) {
			expect( encoder.encode( physical ).length ).toBeLessThanOrEqual( 75 );
		}
		// Unfolding restores the original.
		expect( folded.replace( /\r\n /g, '' ) ).toBe( line );
	} );

	it( 'leaves a short line alone', () => {
		expect( foldIcsLine( 'BEGIN:VCALENDAR' ) ).toBe( 'BEGIN:VCALENDAR' );
	} );
} );

describe( 'validateEcaRecord', () => {
	const record = normalise( RECORD_FULL );

	it( 'passes a complete record', () => {
		const errors = validateEcaRecord( record ).filter( ( problem ) => problem.level === 'error' );
		expect( errors ).toEqual( [] );
	} );

	it( 'rejects an unknown stage id', () => {
		const broken: EcaRecord = { ...record, stages: [ 'middle-school' ] };
		expect( validateEcaRecord( broken ).some( ( problem ) => problem.level === 'error' && problem.field === 'stages' ) ).toBe( true );
	} );

	it( 'rejects a paid record with no amount', () => {
		const broken: EcaRecord = { ...record, pricing: { paid: true, amount: null, currency: null, period: null, display: null } };
		expect( validateEcaRecord( broken ).some( ( problem ) => problem.level === 'error' ) ).toBe( true );
	} );

	it( 'rejects an end time before the start', () => {
		const broken: EcaRecord = { ...record, schedule: { ...record.schedule, startMinutes: 900, endMinutes: 600 } };
		expect( validateEcaRecord( broken ).some( ( problem ) => problem.level === 'error' ) ).toBe( true );
	} );

	it( 'rejects a relative url', () => {
		const broken: EcaRecord = { ...record, url: '/activities/x/' };
		expect( validateEcaRecord( broken ).some( ( problem ) => problem.level === 'error' && problem.field === 'url' ) ).toBe( true );
	} );

	it( 'summariseValidation separates errors from warnings', () => {
		const summary = summariseValidation( [ record, normalise( RECORD_NO_STAGE ) ] );
		expect( summary.total ).toBe( 2 );
		expect( summary.errors ).toEqual( [] );
		expect( summary.warnings ).toBeGreaterThan( 0 );
	} );
} );

describe( 'public-origin split', () => {
	it( 'bakes the public origin into url while keeping the CMS origin in source', () => {
		const record = normalizeEca( RECORD_FULL, {
			...CTX,
			publicOrigin: 'https://eca-6797003.cloudwaysapps.com',
		} );
		expect( record.url ).toBe( 'https://eca-6797003.cloudwaysapps.com/activities/ss-fobisia-student-event-leaders-academy/' );
		expect( record.source.origin ).toBe( 'https://eca.directory' );
	} );
} );

describe( 'ECA_DEFAULT_STAGES', () => {
	it( 'splits years 1–6 from years 7–13', () => {
		expect( ECA_DEFAULT_STAGES ).toHaveLength( 2 );
		expect( ECA_DEFAULT_STAGES[ 0 ] ).toMatchObject( { id: 'junior-school', yearGroups: { from: 1, to: 6 } } );
		expect( ECA_DEFAULT_STAGES[ 1 ] ).toMatchObject( { id: 'senior-school', yearGroups: { from: 7, to: 13 } } );
	} );
} );

// ---------------------------------------------------------------------------
// Facts taken from the Michaelmas 2026-27 booklet and the school's term dates
// (2026-08-24 term opens · 2026-10-19…23 half-term · 2026-12-15 term ends).
// ---------------------------------------------------------------------------

const MICHAELMAS = {
	id: 'michaelmas-2026',
	label: 'Michaelmas Term 2026',
	// ECAs begin Mon 7 Sep 2026 — AFTER the term opens, so the series starts here.
	start: '2026-09-07',
	end: '2026-12-15',
	closures: [ { from: '2026-10-19', to: '2026-10-23', label: 'Half term' } ],
};

describe( 'parseDays', () => {
	it( 'reads a two-day row', () => {
		expect( parseDays( 'Wednesday & Thursday' ) ).toEqual( [ 'Wednesday', 'Thursday' ] );
		expect( parseDays( 'Mon/Tue' ) ).toEqual( [ 'Monday', 'Tuesday' ] );
		expect( parseDays( 'Friday and Saturday' ) ).toEqual( [ 'Friday', 'Saturday' ] );
	} );

	it( 'reads a single day out of a schedule run', () => {
		expect( parseDays( 'Tuesday 2:45 p.m. – 3:30 p.m.' ) ).toEqual( [ 'Tuesday' ] );
	} );

	it( 'expands a RANGE to every day it covers', () => {
		// `ECA Day & Time: Monday to Friday` is a real record in the live CMS
		// (SS Lion's Mane, id 11153). Reading its leading word put a five-day
		// activity on the Monday page alone.
		expect( parseDays( 'Monday to Friday' ) ).toEqual( [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ] );
		expect( parseDays( 'Mon–Fri' ) ).toEqual( [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ] );
		expect( parseDays( 'Tue - Thurs' ) ).toEqual( [ 'Tuesday', 'Wednesday', 'Thursday' ] );
	} );

	it( 'wraps a range that crosses the weekend', () => {
		expect( parseDays( 'Friday to Monday' ) ).toEqual( [ 'Friday', 'Saturday', 'Sunday', 'Monday' ] );
	} );

	it( 'does not read a clock range as a day range', () => {
		expect( parseDays( 'Tuesday 2:45 p.m. - 3:30 p.m.' ) ).toEqual( [ 'Tuesday' ] );
		// A hyphenated word that merely starts with a day name is not a range.
		expect( parseDays( 'Monday- Junior School' ) ).toEqual( [ 'Monday' ] );
	} );

	it( 'deduplicates and ignores noise', () => {
		expect( parseDays( 'Tuesday & Tuesday' ) ).toEqual( [ 'Tuesday' ] );
		expect( parseDays( '' ) ).toEqual( [] );
		expect( parseDays( null ) ).toEqual( [] );
	} );
} );

describe( 'multi-day schedules', () => {
	it( 'keeps both days from meta and treats the first as primary', () => {
		const record = normalise( {
			...RECORD_FULL,
			meta: { ...RECORD_FULL.meta, _eca_day: 'Wednesday & Thursday' },
		} );
		expect( record.schedule.days ).toEqual( [ 'Wednesday', 'Thursday' ] );
		expect( record.schedule.day ).toBe( 'Wednesday' );
	} );

	it( 'falls back to a two-day label when meta is empty', () => {
		const record = normalise( {
			...RECORD_NO_STAGE,
			content: { rendered: '<p>ECA Day &amp; Time: Wednesday &amp; Thursday 12:00 - 12:40pm<br />\nVenue: SS 18</p>' },
			meta: { ...RECORD_NO_STAGE.meta, _eca_day: '', _eca_start_time: '', _eca_end_time: '', _eca_venue: '' },
		} );
		expect( record.schedule.days ).toEqual( [ 'Wednesday', 'Thursday' ] );
		expect( record.inferred.some( ( entry ) => entry.rule === 'label:day' ) ).toBe( true );
	} );

	it( 'counts both days in the facets and matches either in a filter', () => {
		const twoDay = normalise( {
			...RECORD_FULL,
			meta: { ...RECORD_FULL.meta, _eca_day: 'Wednesday & Thursday' },
		} );
		const facets = buildFacets( [ twoDay ] );
		expect( facets.day.map( ( entry ) => entry.value ) ).toEqual( [ 'Wednesday', 'Thursday' ] );
		expect( filterEca( [ twoDay ], { day: [ 'Thursday' ] } ) ).toHaveLength( 1 );
		// `day` stays the primary, so the Tuesday landing excludes it.
		expect( filterEca( [ twoDay ], { day: [ 'Tuesday' ] } ) ).toHaveLength( 0 );
	} );
} );

describe( 'ICS — terms, closures and multi-day', () => {
	const record = normalise( RECORD_FULL );
	const config: ForgeEca = { timezone: 'Asia/Colombo', terms: [ MICHAELMAS ] };

	it( 'starts on the ECA start date, not the term opening', () => {
		const ics = buildIcs( record, { config } );
		// 2026-09-07 is a Monday; the activity runs Tuesdays, so the first is 09-08.
		expect( ics ).toContain( 'DTSTART;TZID=Asia/Colombo:20260908T144500' );
	} );

	it( 'bounds the recurrence at the term end', () => {
		expect( buildIcs( record, { config } ) ).toContain( 'UNTIL=20261216T000000Z' );
	} );

	it( 'excludes the half-term Tuesday', () => {
		const ics = buildIcs( record, { config } );
		expect( ics ).toContain( 'EXDATE;TZID=Asia/Colombo:20261020T144500' );
	} );

	it( 'recurs indefinitely when the term has no end — never withholds', () => {
		const open = { ...MICHAELMAS, end: undefined };
		const ics = buildIcs( record, { config: { timezone: 'Asia/Colombo', terms: [ open ] } } );
		expect( ics ).toContain( 'RRULE:FREQ=WEEKLY;BYDAY=TU' );
		expect( ics ).not.toContain( 'UNTIL=' );
	} );

	it( 'emits a two-day BYDAY for a twice-weekly activity', () => {
		const twoDay = normalise( {
			...RECORD_FULL,
			meta: { ...RECORD_FULL.meta, _eca_day: 'Wednesday & Thursday' },
		} );
		const ics = buildIcs( twoDay, { config } );
		expect( ics ).toContain( 'BYDAY=WE,TH' );
		// Half-term contributes one EXDATE per meeting day.
		expect( ics ).toContain( '20261021T144500' );
		expect( ics ).toContain( '20261022T144500' );
	} );

	it( 'closureInstances only yields the days the activity actually meets', () => {
		// 2026-10-19 (Mon) to 2026-10-23 (Fri) — Tuesday only.
		expect( closureInstances( MICHAELMAS, [ 'Tuesday' ], '144500' ) ).toEqual( [ '20261020T144500' ] );
		expect( closureInstances( MICHAELMAS, [ 'Monday', 'Friday' ], '080000' ) ).toEqual( [
			'20261019T080000',
			'20261023T080000',
		] );
	} );

	it( 'ignores a malformed closure instead of throwing', () => {
		const broken = { ...MICHAELMAS, closures: [ { from: 'nope', to: 'nope' }, { from: '2026-10-19', to: '2026-10-19' } ] };
		expect( closureInstances( broken, [ 'Monday' ], '080000' ) ).toEqual( [ '20261019T080000' ] );
	} );
} );

describe( 'booking hand-off for a school platform', () => {
	const record = { id: 42, slug: 'chess-club', code: 'ECA-042', stages: [ 'senior-school' ] };
	const SOCS = 'https://www.socscms.com/login/29451/parent';

	it( 'uses a single platform URL when there is no per-activity template', () => {
		expect( bookingUrl( record, { booking: { mode: 'external', url: SOCS } } ) ).toBe( SOCS );
	} );

	it( 'prefers the template over the platform URL', () => {
		expect(
			bookingUrl( record, { booking: { urlTemplate: 'https://school.test/{slug}', url: SOCS } } ),
		).toBe( 'https://school.test/chess-club' );
	} );

	it( 'rejects a relative platform URL', () => {
		expect( bookingUrl( record, { booking: { url: '/join' } } ) ).toBeNull();
	} );
} );

describe( 'booking window', () => {
	const WINDOW = { opens: '2026-08-26T07:00', closes: '2026-08-29T23:59', allocations: '2026-09-02T00:00' };

	it( 'reports the cold start', () => {
		expect( bookingState( '2026-08-01T09:00', undefined ) ).toMatchObject( { phase: 'unknown' } );
	} );

	it( 'counts down to the window', () => {
		const state = bookingState( '2026-08-25T12:00', WINDOW, MICHAELMAS.start );
		expect( state.phase ).toBe( 'before' );
		expect( state.label ).toContain( 'Wed 26 Aug' );
	} );

	it( 'is open inside the window, inclusive of both ends', () => {
		expect( bookingState( '2026-08-26T07:00', WINDOW ).phase ).toBe( 'open' );
		expect( bookingState( '2026-08-29T23:59', WINDOW ).phase ).toBe( 'open' );
	} );

	it( 'closes, then publishes allocations, then runs', () => {
		expect( bookingState( '2026-08-30T09:00', WINDOW, MICHAELMAS.start ).phase ).toBe( 'closed' );
		expect( bookingState( '2026-09-03T09:00', WINDOW, MICHAELMAS.start ).phase ).toBe( 'allocations' );
		expect( bookingState( '2026-09-08T09:00', WINDOW, MICHAELMAS.start ).phase ).toBe( 'running' );
	} );

	it( 'renders the teacher note while booking is open', () => {
		const state = bookingState( '2026-08-27T09:00', { ...WINDOW, note: 'Fees are paid to the provider.' } );
		expect( state.detail ).toBe( 'Fees are paid to the provider.' );
	} );
} );

describe( 'zonedStamp / formatStamp', () => {
	it( 'converts an instant into the site zone', () => {
		// 01:30 UTC is 07:00 in Colombo (UTC+5:30).
		expect( zonedStamp( new Date( '2026-08-26T01:30:00Z' ), 'Asia/Colombo' ) ).toBe( '2026-08-26T07:00' );
	} );

	it( 'formats a stamp deterministically', () => {
		expect( formatStamp( '2026-08-26T07:00' ) ).toBe( 'Wed 26 Aug, 7:00 am' );
		expect( formatStamp( '2026-12-15T23:59' ) ).toBe( 'Tue 15 Dec, 11:59 pm' );
	} );

	it( 'passes an unparseable stamp through', () => {
		expect( formatStamp( 'soon' ) ).toBe( 'soon' );
	} );
} );

// ---------------------------------------------------------------------------
// P4 — the term calendar. Dates below are the real Michaelmas 2026-27 ones:
// ECAs start Mon 7 Sep, half-term 19–23 Oct, term ends Tue 15 Dec.
// ---------------------------------------------------------------------------

describe( 'occurrenceDates', () => {
	it( 'walks weekly from the first matching day and skips the half-term', () => {
		// 15 Tuesdays fall between 2026-09-08 and 2026-12-15; 20 Oct is half-term.
		const dates = occurrenceDates( { days: [ 'Tuesday' ] }, MICHAELMAS );
		expect( dates ).toHaveLength( 14 );
		expect( dates[ 0 ] ).toBe( '2026-09-08' );
		expect( dates ).not.toContain( '2026-10-20' );
		expect( dates[ dates.length - 1 ] ).toBe( '2026-12-15' );
	} );

	it( 'yields one series per meeting day', () => {
		const wed = occurrenceDates( { days: [ 'Wednesday' ] }, MICHAELMAS );
		const thu = occurrenceDates( { days: [ 'Thursday' ] }, MICHAELMAS );
		const both = occurrenceDates( { days: [ 'Wednesday', 'Thursday' ] }, MICHAELMAS );
		// Wednesday and Thursday have different counts in one term, so the only
		// honest assertion is union == sum of the parts (and no overlap).
		expect( both.length ).toBe( wed.length + thu.length );
		expect( both ).not.toContain( '2026-10-21' );
		expect( both ).not.toContain( '2026-10-22' );
	} );

	it( 'bounds an open-ended term instead of looping', () => {
		const open = { ...MICHAELMAS, end: undefined };
		const dates = occurrenceDates( { days: [ 'Tuesday' ] }, open );
		expect( dates.length ).toBeGreaterThan( 20 );
		expect( dates.length ).toBeLessThanOrEqual( 26 );
	} );

	it( 'returns nothing for an unknown schedule', () => {
		expect( occurrenceDates( { days: [] }, MICHAELMAS ) ).toEqual( [] );
		expect( occurrenceDates( { days: [ 'Tuesday' ] }, { ...MICHAELMAS, start: 'nope' } ) ).toEqual( [] );
	} );
} );

describe( 'closureFor', () => {
	it( 'names the closure covering a date', () => {
		expect( closureFor( '2026-10-20', MICHAELMAS ) ).toBe( 'Half term' );
		expect( closureFor( '2026-10-19', MICHAELMAS ) ).toBe( 'Half term' );
		expect( closureFor( '2026-10-23', MICHAELMAS ) ).toBe( 'Half term' );
	} );

	it( 'is null outside one', () => {
		expect( closureFor( '2026-10-26', MICHAELMAS ) ).toBeNull();
	} );
} );

describe( 'buildTermCalendar', () => {
	const records = [ normalise( RECORD_FULL ), normalise( RECORD_TITLE_YEARS ) ];
	const calendar = buildTermCalendar( records, MICHAELMAS );

	it( 'keys every meeting date once', () => {
		const dates = calendar.map( ( entry ) => entry.date );
		expect( new Set( dates ).size ).toBe( dates.length );
		expect( dates ).toEqual( [ ...dates ].sort() );
	} );

	it( 'carries the closure on half-term days even with nothing meeting', () => {
		const monday = calendar.find( ( entry ) => entry.date === '2026-10-19' );
		expect( monday?.closure ).toBe( 'Half term' );
		expect( monday?.items ).toEqual( [] );
	} );

	it( 'lists a day in start-time order', () => {
		const tuesday = calendar.find( ( entry ) => entry.date === '2026-09-08' );
		expect( tuesday?.items.length ).toBeGreaterThan( 0 );
		const starts = tuesday?.items.map( ( item ) => item.start ?? '' ) ?? [];
		expect( starts ).toEqual( [ ...starts ].sort() );
	} );
} );

describe( 'month grid', () => {
	it( 'pads a Monday-first month to whole weeks', () => {
		// 2026-09-01 is a Tuesday, so the first Monday-first cell is empty.
		const cells = monthGrid( 2026, 9 );
		expect( cells[ 0 ].date ).toBeNull();
		expect( cells[ 1 ].date ).toBe( '2026-09-01' );
		expect( cells.length % 7 ).toBe( 0 );
		expect( cells.filter( ( cell ) => cell.date ).length ).toBe( 30 );
	} );

	it( 'starts a Monday month with no padding', () => {
		const cells = monthGrid( 2026, 6 );
		expect( cells[ 0 ].date ).toBe( '2026-06-01' );
	} );

	it( 'lists the months a term spans', () => {
		expect( monthsBetween( '2026-09-07', '2026-12-15' ) ).toEqual( [
			{ year: 2026, month: 9 },
			{ year: 2026, month: 10 },
			{ year: 2026, month: 11 },
			{ year: 2026, month: 12 },
		] );
		expect( monthsBetween( '2027-01-05', '2027-01-06' ) ).toEqual( [ { year: 2027, month: 1 } ] );
		expect( monthsBetween( 'nope', '2026-12-15' ) ).toEqual( [] );
	} );

	it( 'formats a date deterministically', () => {
		expect( formatDate( '2026-09-08' ) ).toBe( 'Tue 8 Sep' );
		expect( formatDate( 'nope' ) ).toBe( 'nope' );
	} );
} );
