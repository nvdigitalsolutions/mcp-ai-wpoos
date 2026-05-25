/**
 * Manifest Auto-Generator
 *
 * Reads the template analysis report and TypeScript interfaces to
 * automatically produce a per-toolkit JSON manifest that the toolkit-shell
 * addon can render.
 *
 * @since 1.2.0
 * @package NV_oOS_Bin
 * @license GPL-3.0-or-later
 */

import fs from 'node:fs';
import path from 'node:path';

/**
 * @typedef {object} ManifestGenOptions
 * @property {string} toolkit     Toolkit slug.
 * @property {string} label       Human-readable label.
 * @property {string} [icon]      Dashicon slug.
 * @property {string} [restNamespace] REST namespace (default: mcp-ai-pro/v1).
 * @property {string} [capability] WP capability (default: edit_posts).
 * @property {string} outputPath  Where to write the manifest JSON.
 * @property {object} analysis    The analysis report from template-analyzer.
 */

/**
 * Generate a manifest JSON from an analysis report + TypeScript interfaces.
 *
 * @param {ManifestGenOptions} options
 * @returns {{manifest: object, resourcesMapped: number, warnings: string[]}}
 */
export function generateManifest( options ) {
	const {
		toolkit,
		label,
		icon = 'admin-generic',
		restNamespace = 'mcp-ai-pro/v1',
		capability = 'edit_posts',
		outputPath,
		analysis,
	} = options;

	const resources = [];
	const views     = [];
	const warnings  = [];

	// Strategy 1: Map TS interfaces to resources.
	const tsInterfaces = analysis.typescript_interfaces || [];
	const apiCalls     = analysis.api_calls || [];

	// Group API calls by endpoint path prefix.
	const grouped = groupApiCallsByResource( apiCalls );

	for ( const [ resourceName, calls ] of Object.entries( grouped ) ) {
		// Try to match with a TS interface.
		const matchingIface = findMatchingInterface( resourceName, tsInterfaces );

		let fields = [];
		if ( matchingIface ) {
			fields = ifaceFieldsToManifestFields( matchingIface.fields );
		} else {
			// Infer basic fields from API call patterns.
			fields = inferDefaultFields( resourceName );
			warnings.push( `No TypeScript interface found for "${ resourceName }" — using inferred fields` );
		}

		resources.push( {
			name:        resourceName,
			label:       toLabel( resourceName ),
			endpoint:    calls[ 0 ]?.endpoint || `/${ resourceName }`,
			primary_key: 'id',
			fields,
		} );

		// Generate default views.
		views.push( {
			name:     `${ resourceName }_list`,
			type:     'table',
			resource: resourceName,
			default:  views.length === 0,
			label:    `All ${ toLabel( resourceName ).toLowerCase() }`,
		} );
	}

	// Strategy 2: If no API calls found, create a "generic" resource.
	if ( resources.length === 0 ) {
		const fallbackFields = tsInterfaces.length > 0
			? ifaceFieldsToManifestFields( tsInterfaces[ 0 ].fields )
			: [
				{ name: 'id',    type: 'integer', label: 'ID',    readonly: true },
				{ name: 'title', type: 'string',  label: 'Title', required: true },
			];
		resources.push( {
			name:        toolkit,
			label:       label,
			endpoint:    `/${ toolkit }`,
			primary_key: 'id',
			fields:      fallbackFields,
		} );
		views.push( {
			name:     `${ toolkit }_list`,
			type:     'table',
			resource: toolkit,
			default:  true,
			label:    `All ${ label.toLowerCase() }`,
		} );
		warnings.push( 'No API calls detected — generated fallback resource from TypeScript interfaces or defaults' );
	}

	// Strategy 3: Add kanban view if enum fields are found.
	for ( const resource of resources ) {
		const enumFields = resource.fields.filter( f => f.type === 'enum' && f.options && f.options.length > 1 );
		if ( enumFields.length > 0 && ! views.some( v => v.resource === resource.name && v.type === 'kanban' ) ) {
			views.push( {
				name:     `${ resource.name }_kanban`,
				type:     'kanban',
				resource: resource.name,
				group_by: enumFields[ 0 ].name,
				label:    `${ toLabel( resource.name ) } Kanban`,
			} );
		}
	}

	const manifest = {
		$schema:        'https://nvdigitalsolutions.com/schemas/toolkit-spa-manifest-v1.json',
		version:        '1.0',
		toolkit,
		label,
		icon,
		rest_namespace: restNamespace,
		capability,
		resources,
		views,
	};

	// Write to disk.
	if ( outputPath ) {
		fs.mkdirSync( path.dirname( outputPath ), { recursive: true } );
		fs.writeFileSync( outputPath, JSON.stringify( manifest, null, '\t' ) + '\n', 'utf-8' );
	}

	return {
		manifest,
		resourcesMapped: resources.length,
		warnings,
	};
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function groupApiCallsByResource( apiCalls ) {
	const grouped = {};
	for ( const call of apiCalls ) {
		const resourceName = inferResourceName( call.endpoint );
		if ( ! grouped[ resourceName ] ) {
			grouped[ resourceName ] = [];
		}
		grouped[ resourceName ].push( call );
	}
	return grouped;
}

function inferResourceName( endpoint ) {
	// /api/users -> users, /mcp-ai-pro/v1/contacts -> contacts, /v1/products -> products
	const parts = endpoint.split( '/' ).filter( Boolean );
	// Find the last segment before any placeholder like {id}.
	for ( let i = parts.length - 1; i >= 0; i-- ) {
		if ( ! parts[ i ].startsWith( '{' ) && ! /^(v\d+|api|wp-json|mcp-ai|mcp-ai-pro)$/.test( parts[ i ] ) ) {
			return parts[ i ].toLowerCase().replace( /[^a-z0-9_]/g, '_' );
		}
	}
	return 'data';
}

function findMatchingInterface( resourceName, tsInterfaces ) {
	// Try exact match first (plural → singular).
	const singular = resourceName.replace( /s$/, '' );
	const exact = tsInterfaces.find( i => i.name.toLowerCase() === resourceName.toLowerCase() );
	if ( exact ) return exact;
	const sing = tsInterfaces.find( i => i.name.toLowerCase() === singular.toLowerCase() );
	if ( sing ) return sing;
	// Partial match.
	const partial = tsInterfaces.find( i =>
		i.name.toLowerCase().includes( resourceName.toLowerCase() ) ||
		resourceName.toLowerCase().includes( i.name.toLowerCase() )
	);
	return partial || null;
}

function ifaceFieldsToManifestFields( ifaceFields ) {
	return ifaceFields.map( f => {
		const field = {
			name:     f.name,
			type:     mapFieldType( f.inferred || 'string' ),
			label:    toLabel( f.name ),
			required: f.required || false,
			readonly: f.name === 'id' || f.name === 'created_at' || f.name === 'updated_at',
		};
		if ( f.inferred === 'enum' && f.enumValues ) {
			field.options = f.enumValues;
		}
		if ( f.tsType && f.tsType.includes( 'reference' ) ) {
			field.type = 'reference';
		}
		return field;
	} );
}

function mapFieldType( inferred ) {
	const map = {
		string:   'string',
		number:   'number',
		integer:  'integer',
		boolean:  'boolean',
		email:    'email',
		url:      'url',
		date:     'date',
		datetime: 'datetime',
		enum:     'enum',
		text:     'text',
	};
	return map[ inferred ] || 'string';
}

function inferDefaultFields( resourceName ) {
	return [
		{ name: 'id',         type: 'integer',  label: 'ID',        readonly: true },
		{ name: 'title',      type: 'string',   label: 'Title',     required: true },
		{ name: 'status',     type: 'enum',     label: 'Status',    options: [ 'active', 'inactive' ] },
		{ name: 'created_at', type: 'datetime', label: 'Created',   readonly: true },
		{ name: 'updated_at', type: 'datetime', label: 'Updated',   readonly: true },
	];
}

function toLabel( name ) {
	return name
		.replace( /[_-]/g, ' ' )
		.replace( /\b\w/g, c => c.toUpperCase() )
		.trim();
}
