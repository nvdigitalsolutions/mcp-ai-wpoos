/* jshint esversion: 6 */
/**
 * NV oOS Content Graph — Icon Glyph Registry
 *
 * Stroke-based SVG glyph geometry (24x24 viewBox) for the visual
 * experience system. Glyphs are consumed by content-graph-theme.js,
 * which wraps them into `data:image/svg+xml` URIs for Cytoscape's
 * `background-image` node style.
 *
 * Each glyph is a single path string rendered with stroke = current
 * color, no fill. This lets one geometry serve every icon mode:
 *   - filled:  white glyph on the colored node
 *   - outline: colored glyph on a canvas/surface-colored node
 *   - high:    white glyph, heavier stroke, on the colored node
 *
 * @package NvoosContentGraph
 * @since   1.0.4
 */
( function () {
	'use strict';

	window.nvoosContentGraphIcons = {
		catalog: {
			doc: {
				label: 'Document',
				d: 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z M14 2v6h6 M8 13h8 M8 17h6'
			},
			page: {
				label: 'Page',
				d: 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z M14 2v6h6'
			},
			tag: {
				label: 'Tag',
				d: 'M20 13l-7.5 7.5a2 2 0 0 1-2.8 0L3 13.8V3h10.8L20 13z M8 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2z'
			},
			bulb: {
				label: 'Idea',
				d: 'M9 18h6 M10 21h4 M12 3a6 6 0 0 1 3.5 10.9c-.7.5-1 1.1-1.2 2.1h-4.6c-.2-1-.5-1.6-1.2-2.1A6 6 0 0 1 12 3z'
			},
			cube: {
				label: 'Entity',
				d: 'M12 2l8 4.5v9L12 20l-8-4.5v-9L12 2z M12 11l8-4.5 M12 11L4 6.5 M12 11v9'
			},
			user: {
				label: 'Person',
				d: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M4 21c1.2-3.6 4.2-5.5 8-5.5s6.8 1.9 8 5.5'
			},
			'user-round': {
				label: 'User',
				d: 'M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0-18 0 M12 13a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z M5.8 20c1-2.8 3.4-4.4 6.2-4.4s5.2 1.6 6.2 4.4'
			},
			pin: {
				label: 'Place',
				d: 'M12 21s-7-5.5-7-11a7 7 0 0 1 14 0c0 5.5-7 11-7 11z M12 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6z'
			},
			building: {
				label: 'Organization',
				d: 'M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16 M16 9h4v12 M8 7h2 M12 7h2 M8 11h2 M12 11h2 M8 15h2 M12 15h2 M3 21h18'
			},
			image: {
				label: 'Media',
				d: 'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z M8 10a2 2 0 1 0 0-4 2 2 0 0 0 0 4z M3 17l6-6 4 4 5-5 3 3'
			},
			brain: {
				label: 'Memory',
				d: 'M12 5a3 3 0 0 0-3 3 3 3 0 0 0-1 5.8A3 3 0 0 0 9 19a3 3 0 0 0 3 1.5A3 3 0 0 0 15 19a3 3 0 0 0 1-5.2A3 3 0 0 0 15 8a3 3 0 0 0-3-3z'
			},
			bot: {
				label: 'Agent',
				d: 'M4 15a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4z M9 16h.01 M15 16h.01 M12 8V6 M9 6h6 M12 6v7'
			},
			grid: {
				label: 'Grid',
				d: 'M4 4h6v6H4z M14 4h6v6h-6z M4 14h6v6H4z M14 14h6v6h-6z'
			},
			door: {
				label: 'Room',
				d: 'M4 21V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v16 M20 8h-1 M20 12h-1 M20 16h-1 M14 12.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z'
			},
			cart: {
				label: 'Product',
				d: 'M3 3h2l2.4 12.2a1 1 0 0 0 1 .8h9.7a1 1 0 0 0 1-.8L21 8H6 M8 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2z M18 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2z'
			},
			calendar: {
				label: 'Event',
				d: 'M4 6h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1z M8 3v5 M16 3v5 M3 10h18'
			},
			link: {
				label: 'Link',
				d: 'M10 14a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1 M14 10a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1'
			},
			code: {
				label: 'Code',
				d: 'M8 8l-4 4 4 4 M16 8l4 4-4 4 M13 5l-2 14'
			},
			video: {
				label: 'Video',
				d: 'M4 6h11a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z M17 10l5-3v10l-5-3'
			},
			audio: {
				label: 'Audio',
				d: 'M9 18V6l10-2v12 M6 21a3 3 0 1 0 0-6 3 3 0 0 0 0 6z M16 19a3 3 0 1 0 0-6 3 3 0 0 0 0 6z'
			},
			file: {
				label: 'File',
				d: 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z M14 2v6h6'
			},
			star: {
				label: 'Star',
				d: 'M12 3l2.7 5.5 6 .9-4.3 4.2 1 6-5.4-2.8L6.6 19.6l1-6L3.3 9.4l6-.9L12 3z'
			},
			dot: {
				label: 'Dot',
				d: 'M12 12m-5 0a5 5 0 1 0 10 0a5 5 0 1 0-10 0'
			},
			external: {
				label: 'External',
				d: 'M14 4h6v6 M20 4L10 14 M20 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h5'
			}
		}
	};
}() );
