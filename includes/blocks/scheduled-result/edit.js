/**
 * NV oOS — Scheduled Result block editor script.
 *
 * Uses ServerSideRender so the editor always shows the live latest envelope
 * via the same PHP renderer that powers the front end. Inspector controls
 * surface a schedule picker (sourced from /mcp-ai-pro/v1/schedules), render
 * mode, refresh interval and a "Trigger preview" affordance.
 *
 * @package WP_MCP_AI
 */

( function ( wp ) {
	if ( ! wp || ! wp.blocks ) {
		return;
	}

	const { registerBlockType }                                = wp.blocks;
	const { createElement: el, Fragment, useState, useEffect } = wp.element;
	const { InspectorControls }                                = wp.blockEditor || wp.editor;
	const { PanelBody, SelectControl, TextControl, ToggleControl, RangeControl, Button, Notice } = wp.components;
	const ServerSideRender = wp.serverSideRender || ( wp.components && wp.components.ServerSideRender );
	const apiFetch         = wp.apiFetch;
	const { __ }           = wp.i18n;

	registerBlockType(
		'mcp-ai-wpoos/scheduled-result',
		{
			apiVersion: 3,
			edit: function ( props ) {
				const { attributes, setAttributes }     = props;
				const [ schedules, setSchedules ]       = useState( [] );
				const [ previewError, setPreviewError ] = useState( '' );
				const [ refreshKey, setRefreshKey ]     = useState( 0 );

				useEffect(
					function () {
						apiFetch( { path: '/mcp-ai-pro/v1/schedules?selectable=1' } )
						.then(
							function ( items ) {
								if ( Array.isArray( items ) ) {
										setSchedules( items );
								}
							}
						)
						.catch(
							function () {
								setSchedules( [] );
							}
						);
					},
					[]
				);

				const scheduleOptions = [ { label: __( '— Select a schedule —', 'mcp-ai-wpoos' ), value: '' } ].concat(
					schedules.map(
						function ( s ) {
							return { label: s.name + ' (' + ( s.schedule_type || '' ) + ')', value: s.id };
						}
					)
				);

				const renderModeOptions = [
				{ label: __( 'Summary card', 'mcp-ai-wpoos' ), value: 'summary-card' },
				{ label: __( 'List', 'mcp-ai-wpoos' ), value: 'list' },
				{ label: __( 'Table', 'mcp-ai-wpoos' ), value: 'table' },
				{ label: __( 'Metric', 'mcp-ai-wpoos' ), value: 'metric' },
				{ label: __( 'Timeline', 'mcp-ai-wpoos' ), value: 'timeline' },
				{ label: __( 'Raw', 'mcp-ai-wpoos' ), value: 'raw' },
				];

				function triggerPreview() {
					if ( ! attributes.scheduleId ) {
						return;
					}
					setPreviewError( '' );
					apiFetch(
						{
							path: '/mcp-ai-pro/v1/schedules/' + encodeURIComponent( attributes.scheduleId ) + '/preview',
							method: 'POST',
						}
					)
						.then(
							function () {
								setRefreshKey( refreshKey + 1 );
							}
						)
						.catch(
							function ( err ) {
								setPreviewError( ( err && err.message ) || __( 'Preview failed.', 'mcp-ai-wpoos' ) );
							}
						);
				}

				const inspector = el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Scheduled Result', 'mcp-ai-wpoos' ), initialOpen: true },
						el(
							SelectControl,
							{
								label: __( 'Schedule', 'mcp-ai-wpoos' ),
								value: attributes.scheduleId || '',
								options: scheduleOptions,
								onChange: function ( v ) {
									setAttributes( { scheduleId: v } );
								},
							}
						),
						el(
							SelectControl,
							{
								label: __( 'Render mode', 'mcp-ai-wpoos' ),
								value: attributes.renderMode,
								options: renderModeOptions,
								onChange: function ( v ) {
									setAttributes( { renderMode: v } );
								},
							}
						),
						el(
							TextControl,
							{
								label: __( 'Title (overrides schedule name)', 'mcp-ai-wpoos' ),
								value: attributes.title || '',
								onChange: function ( v ) {
									setAttributes( { title: v } );
								},
							}
						),
						el(
							ToggleControl,
							{
								label: __( 'Show last-run timestamp', 'mcp-ai-wpoos' ),
								checked: ! ! attributes.showLastRun,
								onChange: function ( v ) {
									setAttributes( { showLastRun: ! ! v } );
								},
							}
						),
						el(
							RangeControl,
							{
								label: __( 'Auto-refresh interval (seconds, 0 = off)', 'mcp-ai-wpoos' ),
								min: 0,
								max: 3600,
								step: 30,
								value: attributes.refreshIntervalSec || 0,
								onChange: function ( v ) {
									setAttributes( { refreshIntervalSec: v ? parseInt( v, 10 ) : 0 } );
								},
							}
						),
						el(
							RangeControl,
							{
								label: __( 'Truncate raw text (chars, 0 = off)', 'mcp-ai-wpoos' ),
								min: 0,
								max: 4000,
								step: 100,
								value: attributes.truncateChars || 0,
								onChange: function ( v ) {
									setAttributes( { truncateChars: v ? parseInt( v, 10 ) : 0 } );
								},
							}
						),
						el(
							Button,
							{
								variant: 'secondary',
								onClick: triggerPreview,
								disabled: ! attributes.scheduleId,
							},
							__( 'Trigger preview', 'mcp-ai-wpoos' )
						),
						previewError ? el( Notice, { status : 'error', isDismissible : false }, previewError ) : null
					)
				);

				const body = ServerSideRender
				? el(
					ServerSideRender,
					{
						block: 'mcp-ai-wpoos/scheduled-result',
						attributes: attributes,
						key: refreshKey,
					}
				)
				: el( 'p', {}, __( 'Server-side render unavailable.', 'mcp-ai-wpoos' ) );

				return el( Fragment, {}, inspector, body );
			},
			save: function () {
				// Dynamic block — rendered by PHP.
				return null;
			},
		}
	);
} )( window.wp );
