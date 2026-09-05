/* jshint esversion: 6 */
/**
 * Standalone verification of the JS theme engine parity (no browser).
 * Run: node scripts/verify-theme-engine.js
 *
 * Loads content-graph-icons.js + content-graph-theme.js with minimal
 * browser stubs, then checks:
 *   1. ensureContrast parity with PHP's verified outputs
 *   2. colorForType resolves every palette type and passes >= 3:1
 *   3. icon data URIs are well-formed
 *   4. hashHue is stable
 */

global.window = {};
global.document = { body: { className: '' } };
global.matchMedia = null; // theme engine guards with window.matchMedia &&

require( '../assets/js/content-graph-icons.js' );
require( '../assets/js/content-graph-theme.js' );

var theme = global.window.nvoosContentGraphTheme;
var fail = 0;

function check( label, cond ) {
	console.log( ( cond ? 'ok   ' : 'FAIL ' ) + label );
	if ( ! cond ) { fail++; }
}

check( 'theme engine loaded', !! theme );
check( 'icon registry loaded', !! global.window.nvoosContentGraphIcons.catalog.doc );

// Visual config mirroring the PHP delivery shape.
var visual = {
	theme: 'dark',
	color_by: 'type',
	show_icons: true,
	icon_mode: 'filled',
	node_shapes: false,
	show_legend: true,
	min_label_zoom: 0.35,
	edge_style: 'plain',
	edge_labels: 'hover',
	size_min: 12,
	size_max: 60,
	label_font_size: 10,
	anim_enabled: true,
	type_colors: {},
	type_icons: {},
	type_palette: {
		post: '#3498db', page: '#2ecc71', term: '#f39c12', topic: '#9b59b6',
		entity: '#e74c3c', person: '#e67e22', place: '#1abc9c', organization: '#2980b9',
		user: '#c0392b', media: '#7f8c8d', memory: '#f1c40f', agent: '#16a085',
		wing: '#8e44ad', room: '#27ae60'
	},
	type_icon_map: { post: 'doc', page: 'page', term: 'tag' },
	community_palette: [ '#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f', '#edc948', '#b07aa1', '#ff9da7', '#9c755f', '#bab0ac', '#86bcb6', '#d37295' ],
	degree_ramp: [ '#440154', '#3b528b', '#21918c', '#5ec962', '#fde725' ],
	themes: {
		dark: { canvas: '#0f0f1a', surface: '#1a1a2e', node_label: '#e0e0ff', edge: '#5b6478', edge_hierarchy: '#7c9ff2', edge_similarity: '#2ecc9e', edge_reference: '#e0a94f', edge_authorship: '#b58ce0', edge_label: '#b8bcd4', border: '#2a2a4a', selection: '#ffffff', accent: '#7c9ff2', muted: '#8b8fa3' },
		light: { canvas: '#f7f8fa', surface: '#ffffff', node_label: '#1e293b', edge: '#9aa1b2', edge_hierarchy: '#3f5fae', edge_similarity: '#0f8a6d', edge_reference: '#a06c14', edge_authorship: '#7c4dbb', edge_label: '#4b5563', border: '#d7dbe4', selection: '#2271b1', accent: '#2271b1', muted: '#6b7280' }
	},
	edge_families: {
		hierarchical: [ 'belongs_to', 'in_category' ],
		similarity: [ 'related_to', 'similar' ],
		reference: [ 'links_to', 'references' ],
		authorship: [ 'authored_by', 'created' ]
	},
	shape_map: { post: 'round-rectangle', entity: 'diamond' }
};

// 1. PHP/JS parity on the known-corrected values from verify-contrast.php.
check( 'parity: post light correction', theme.ensureContrast( '#3498db', '#f7f8fa' ) === '#2e95da' );
check( 'parity: term light correction', theme.ensureContrast( '#f39c12', '#f7f8fa' ) === '#c57d0a' );
check( 'parity: memory light correction', theme.ensureContrast( '#f1c40f', '#f7f8fa' ) === '#aa8a0a' );
check( 'parity: luminance white', Math.abs( theme.luminance( '#ffffff' ) - 1 ) < 0.0001 );
check( 'parity: contrast white/black', Math.abs( theme.contrastRatio( '#ffffff', '#000000' ) - 21 ) < 0.01 );

// 2. Every palette type resolves to a color that passes 3:1 on both themes.
var themesOk = true;
[ 'dark', 'light' ].forEach( function ( t ) {
	visual.theme = t;
	Object.keys( visual.type_palette ).forEach( function ( type ) {
		var color = theme.colorForType( type, visual );
		if ( theme.contrastRatio( color, visual.themes[ t ].canvas ) < 3.0 ) { themesOk = false; console.log( '  low contrast: ' + type + ' ' + color + ' on ' + t ); }
	} );
} );
check( 'all types pass 3:1 on both themes', themesOk );

// 3. Icons are well-formed data URIs.
visual.theme = 'dark';
var icon = theme.iconDataUri( visual, 'post', '#3498db', visual.themes.dark );
check( 'icon data uri well-formed', /^data:image\/svg\+xml/.test( icon ) && icon.indexOf( 'viewBox' ) !== -1 );
var mono = theme.iconDataUri( visual, 'weird_custom_type', '#123456', visual.themes.dark );
check( 'monogram fallback for unknown type', /^data:image\/svg\+xml/.test( mono ) && decodeURIComponent( mono ).indexOf( '<text' ) !== -1 );

// 4. hashHue stability + nodeColor dispatcher.
check( 'hashHue stable', theme.hashHue( 'widget' ) === theme.hashHue( 'widget' ) );
visual.color_by = 'community';
check( 'community color resolves', /^#[0-9a-f]{6}$/.test( theme.communityColor( 'c1', visual ) ) );
visual.color_by = 'degree';
check( 'degree color resolves', /^#[0-9a-f]{6}$/.test( theme.degreeColor( 12, 50, visual ) ) );
visual.color_by = 'type';

// 5. Stylesheet builds and layout presets exist.
var sheet = theme.buildStylesheet( visual, { cy: null } );
check( 'stylesheet has node/edge/selected rules', sheet.length >= 4 && sheet[ 0 ].selector === 'node' && sheet[ 1 ].selector === 'edge' );
var presets = theme.layoutPresets( visual );
check( 'layout presets include fcose-balanced', !! presets[ 'fcose-balanced' ] );

// 6. Edge family coloring.
check( 'edge family hierarchical', theme.edgeFamilyColor( 'in_category', visual.themes.dark, visual ) === '#7c9ff2' );
check( 'edge family fallback', theme.edgeFamilyColor( 'mystery', visual.themes.dark, visual ) === '#5b6478' );

console.log( '\n' + ( fail ? 'FAILURES: ' + fail : 'ALL THEME ENGINE CHECKS PASSED' ) );
process.exit( fail ? 1 : 0 );
