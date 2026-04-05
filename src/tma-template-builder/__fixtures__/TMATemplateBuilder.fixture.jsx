/**
 * React Cosmos 7 fixture – TMATemplateBuilder: all interactive states.
 *
 * Each named export is a separate fixture shown in the Cosmos sidebar.
 * `useFixtureInput` creates a live-editable field in the Cosmos props panel.
 * `useFixtureSelect` creates a dropdown in the Cosmos props panel.
 *
 * Run the playground:
 *   npm run cosmos:tma         → http://localhost:5001
 *
 * The MOCK_FETCH decorator below intercepts fetch() so no live WordPress
 * REST API is needed during development.
 *
 * @package WP_MCP_AI
 * @since   1.1.3
 */

import { useFixtureInput, useFixtureSelect } from 'react-cosmos/client';
import { TMATemplateBuilder } from '../components/TMATemplateBuilder';

/* ── Shared mock data ── */

const ALL_TEMPLATES = [
	{
		slug:         'default',
		name:         'Content Manager (Default)',
		description:  'Full-featured CMS template with analytics, content editor, tools, media library, shop, and settings.',
		icon:         '📋',
		accent_color: '#2481cc',
		toolkit:      '',
	},
	{
		slug:         'ai_chat',
		name:         'AI Chat',
		description:  'Clean conversational interface powered by the AI assistant. Perfect for customer-facing chatbots.',
		icon:         '💬',
		accent_color: '#4CAF50',
		toolkit:      'chat_channels',
	},
	{
		slug:         'ecommerce',
		name:         'E-Commerce Store',
		description:  'Shop assistant with product search, order tracking, and AI-powered recommendations.',
		icon:         '🛒',
		accent_color: '#9c27b0',
		toolkit:      'ecommerce',
	},
	{
		slug:         'crm',
		name:         'CRM Assistant',
		description:  'Customer management with contact lookup, lead pipeline, and AI-powered follow-up drafts.',
		icon:         '👥',
		accent_color: '#e65100',
		toolkit:      'crm',
	},
	{
		slug:         'analytics',
		name:         'Analytics Dashboard',
		description:  'Real-time site analytics with Chart.js visualisations and AI-powered insights.',
		icon:         '📊',
		accent_color: '#00796b',
		toolkit:      'analytics',
	},
	{
		slug:         'booking',
		name:         'Calendar Booking',
		description:  'Appointment scheduling with availability calendar, booking form, and confirmation flow.',
		icon:         '📅',
		accent_color: '#1565c0',
		toolkit:      'calendar_booking',
	},
];

/** Patch window.fetch inside Cosmos to return mock template data. */
const patchFetch = ( slug ) => {
	window.__cosmosOrigFetch = window.__cosmosOrigFetch || window.fetch;
	window.fetch = () =>
		Promise.resolve( {
			ok:   true,
			json: () => Promise.resolve( slug ? ALL_TEMPLATES.filter( ( t ) => t.slug === slug ) : ALL_TEMPLATES ),
		} );
};
const restoreFetch = () => {
	if ( window.__cosmosOrigFetch ) {
		window.fetch = window.__cosmosOrigFetch;
	}
};

/* ── Base config shared across fixtures ── */
const BASE_CONFIG = {
	ajaxUrl:        '',
	nonce:          'cosmos-dev-nonce',
	templatesUrl:   '/mock/templates',   // intercepted by patchFetch
	saveUrl:        '',                  // no save in Cosmos
	activeTemplate: 'default',
	previewBaseUrl: '',                  // no live preview in Cosmos
};

/* ────────────────────────────────────────────────────────────────────────── */
/*  Fixture 1 – Global picker (default state)                                */
/* ────────────────────────────────────────────────────────────────────────── */

export const GlobalPicker = () => {
	// Live-editable in Cosmos props panel.
	const [ activeTemplate ] = useFixtureSelect( 'Active template', {
		options:      ALL_TEMPLATES.map( ( t ) => t.slug ),
		defaultValue: 'default',
	} );

	patchFetch();

	return (
		<div style={ { fontFamily: 'sans-serif', padding: '16px', background: '#f6f7f7', minHeight: '100vh' } }>
			<TMATemplateBuilder
				config={ { ...BASE_CONFIG, activeTemplate } }
			/>
		</div>
	);
};

/* ────────────────────────────────────────────────────────────────────────── */
/*  Fixture 2 – Embedded / per-connection mode                               */
/* ────────────────────────────────────────────────────────────────────────── */

export const EmbeddedConnectionMode = () => {
	const [ initialSlug ] = useFixtureSelect( 'Initial template', {
		options:      ALL_TEMPLATES.map( ( t ) => t.slug ),
		defaultValue: 'ai_chat',
	} );
	const [ connectionId ] = useFixtureInput( 'Connection ID', 'bot-001' );

	patchFetch();

	return (
		<div style={ { fontFamily: 'sans-serif', padding: '16px', background: '#f6f7f7', minHeight: '100vh' } }>
			{ /* Simulate the hidden input the PHP form reads on submit. */ }
			<input type="hidden" id="telegram_mini_app_template" defaultValue={ initialSlug } />
			<TMATemplateBuilder
				config={ BASE_CONFIG }
				connectionId={ connectionId }
				initialSlug={ initialSlug }
				embeddedMode={ true }
			/>
		</div>
	);
};

/* ────────────────────────────────────────────────────────────────────────── */
/*  Fixture 3 – Loading state                                                */
/* ────────────────────────────────────────────────────────────────────────── */

export const LoadingState = () => {
	// Hang the fetch forever so the loading spinner stays visible.
	window.fetch = () => new Promise( () => {} );

	return (
		<div style={ { fontFamily: 'sans-serif', padding: '16px', background: '#f6f7f7', minHeight: '100vh' } }>
			<TMATemplateBuilder config={ BASE_CONFIG } />
		</div>
	);
};

/* ────────────────────────────────────────────────────────────────────────── */
/*  Fixture 4 – Error state                                                  */
/* ────────────────────────────────────────────────────────────────────────── */

export const ErrorState = () => {
	const [ errorMessage ] = useFixtureInput( 'Error message', 'Network request failed (HTTP 500)' );

	window.fetch = () => Promise.reject( new Error( errorMessage ) );

	return (
		<div style={ { fontFamily: 'sans-serif', padding: '16px', background: '#f6f7f7', minHeight: '100vh' } }>
			<TMATemplateBuilder config={ BASE_CONFIG } />
		</div>
	);
};

/* ────────────────────────────────────────────────────────────────────────── */
/*  Fixture 5 – Single template preview pane                                 */
/* ────────────────────────────────────────────────────────────────────────── */

export const WithPreviewPane = () => {
	const [ previewSlug ] = useFixtureSelect( 'Template to preview', {
		options:      ALL_TEMPLATES.map( ( t ) => t.slug ),
		defaultValue: 'ecommerce',
	} );
	const [ previewUrl ] = useFixtureInput( 'Preview base URL (optional)', '' );

	patchFetch();

	// Auto-open the preview by manipulating the config.
	return (
		<div style={ { fontFamily: 'sans-serif', padding: '16px', background: '#f6f7f7', minHeight: '100vh' } }>
			<TMATemplateBuilder
				config={ { ...BASE_CONFIG, activeTemplate: previewSlug, previewBaseUrl: previewUrl } }
			/>
			<p style={ { marginTop: '12px', color: '#999', fontSize: '12px' } }>
				💡 Click "👁 Preview" on any card to toggle the live iframe preview pane.
				Set a Preview base URL above to load the live Mini App.
			</p>
		</div>
	);
};

/* ────────────────────────────────────────────────────────────────────────── */
/*  Fixture 6 – Custom template metadata (design iteration)                  */
/* ────────────────────────────────────────────────────────────────────────── */

export const CustomTemplateCard = () => {
	const [ icon        ] = useFixtureInput( 'Icon', '🎨' );
	const [ name        ] = useFixtureInput( 'Name', 'Custom Template' );
	const [ description ] = useFixtureInput( 'Description', 'A brand new template for a specific use-case.' );
	const [ accentColor ] = useFixtureInput( 'Accent colour', '#ff6b35' );
	const [ toolkit     ] = useFixtureSelect( 'Toolkit', {
		options:      [ '', 'chat_channels', 'ecommerce', 'crm', 'analytics', 'calendar_booking' ],
		defaultValue: '',
	} );

	const customTemplate = {
		slug:         'custom',
		name,
		description,
		icon,
		accent_color: accentColor,
		toolkit,
	};

	window.fetch = () =>
		Promise.resolve( {
			ok:   true,
			json: () => Promise.resolve( [ ...ALL_TEMPLATES, customTemplate ] ),
		} );

	return (
		<div style={ { fontFamily: 'sans-serif', padding: '16px', background: '#f6f7f7', minHeight: '100vh' } }>
			<p style={ { marginBottom: '12px', color: '#555', fontSize: '13px' } }>
				✏️ Edit the fields in the Cosmos props panel (right sidebar) to design your custom template card in real-time.
			</p>
			<TMATemplateBuilder config={ BASE_CONFIG } />
		</div>
	);
};

/* ── Cleanup on hot-reload ── */
if ( module.hot ) {
	module.hot.dispose( restoreFetch );
}
